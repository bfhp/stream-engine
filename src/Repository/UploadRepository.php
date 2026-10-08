<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\Upload;

readonly class UploadRepository
{
    public const string PURPOSE_FORUM_ATTACHMENT = 'forum-attachment';
    public const string PURPOSE_FORUM_ATTACHMENT_RESERVED = 'forum-attachment-reserved';
    public const string PURPOSE_FORUM_ATTACHMENT_DELETING = 'forum-attachment-deleting';

    public function __construct(
        private PdoDatabase $db
    ) {

    }
    public function create(array $data): Upload
    {
        $this->db->execute(
            "INSERT INTO uploads (user_id, path, mime, size, original_name, purpose, created_at)
             VALUES (?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP())",
            [
                $data['user_id'],
                $data['path'],
                $data['mime'],
                $data['size'],
                $data['original_name'],
                $data['purpose'] ?? null,
            ]
        );

        $id = $this->db->lastInsertId();

        return new Upload(
            $id,
            $data['user_id'],
            $data['path'],
            $data['mime'],
            $data['size'],
            $data['original_name'],
            time(),
            $data['purpose'] ?? null,
        );
    }

    /**
     * Uses index: PRIMARY(id)
     */
    public function findById(int $id): ?Upload
    {
        $row = $this->db->fetchOne(
            "SELECT id, user_id, path, mime, size, original_name, created_at, purpose FROM uploads WHERE id = ?",
            [$id]
        );

        if (!$row) {
            return null;
        }

        return new Upload(
            (int)$row['id'],
            $row['user_id'] !== null ? (int)$row['user_id'] : null,
            $row['path'],
            (string)($row['mime'] ?? ''),
            (int)($row['size'] ?? 0),
            (string)($row['original_name'] ?? ''),
            (int)$row['created_at'],
            isset($row['purpose']) ? (string) $row['purpose'] : null,
        );
    }

    /**
     * Uses index: PRIMARY(id)
     *
     * @param int[] $ids
     * @return array<int, Upload>
     */
    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll(
            "SELECT id, user_id, path, mime, size, original_name, created_at, purpose FROM uploads WHERE id IN ($placeholders)",
            $ids
        );

        $result = [];
        foreach ($rows as $row) {
            $upload = new Upload(
                (int)$row['id'],
                $row['user_id'] !== null ? (int)$row['user_id'] : null,
                $row['path'],
                (string)($row['mime'] ?? ''),
                (int)($row['size'] ?? 0),
                (string)($row['original_name'] ?? ''),
                (int)$row['created_at'],
                isset($row['purpose']) ? (string) $row['purpose'] : null,
            );
            $result[$upload->id] = $upload;
        }

        return $result;
    }

    /**
     * Uses index: uploads_user_id_size_index (user_id, size)
     */
    public function getUserUsage(int $userId): int
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(size), 0) AS total FROM uploads WHERE user_id = ?",
            [$userId]
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Refreshes a pending forum attachment before a topic write. The state
     * transition and timestamp update make this race safely with the cleanup
     * claim: whichever UPDATE gets the row lock first decides whether the
     * attachment is still available to the form.
     */
    public function reserveOwnedForumAttachment(int $id, int $userId): ?Upload
    {
        $this->db->execute(
            "UPDATE uploads
             SET purpose = ?, created_at = UNIX_TIMESTAMP()
             WHERE id = ? AND user_id = ? AND purpose IN (?, ?)",
            [
                self::PURPOSE_FORUM_ATTACHMENT_RESERVED,
                $id,
                $userId,
                self::PURPOSE_FORUM_ATTACHMENT,
                self::PURPOSE_FORUM_ATTACHMENT_RESERVED,
            ]
        );

        $upload = $this->findById($id);

        return $upload !== null
            && $upload->userId === $userId
            && $upload->purpose === self::PURPOSE_FORUM_ATTACHMENT_RESERVED
                ? $upload
                : null;
    }

    /** @return int[] */
    public function findOrphanedForumAttachmentIds(int $createdBefore, int $limit): array
    {
        $limit = max(1, min($limit, 1000));
        $rows = $this->db->fetchAll(
            "SELECT u.id
             FROM uploads u
             WHERE u.purpose IN (?, ?, ?)
               AND u.created_at < ?
               AND NOT EXISTS (
                   SELECT 1
                   FROM feed_metadata fm
                   WHERE fm.name = 'attachment_upload_ids'
                     AND JSON_VALID(fm.content)
                     AND JSON_CONTAINS(fm.content, CAST(u.id AS CHAR), '$')
               )
             ORDER BY u.created_at ASC, u.id ASC
             LIMIT $limit",
            [
                self::PURPOSE_FORUM_ATTACHMENT,
                self::PURPOSE_FORUM_ATTACHMENT_RESERVED,
                self::PURPOSE_FORUM_ATTACHMENT_DELETING,
                $createdBefore,
            ]
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Claims one still-orphaned row for filesystem deletion. Repeating the
     * NOT EXISTS condition closes the gap between the candidate SELECT and
     * this write; resetting created_at lets a crashed worker retry later.
     */
    public function claimOrphanedForumAttachment(int $id, int $createdBefore): ?Upload
    {
        $updated = $this->db->execute(
            "UPDATE uploads AS u
             SET purpose = ?, created_at = UNIX_TIMESTAMP()
             WHERE u.id = ?
               AND u.purpose IN (?, ?, ?)
               AND u.created_at < ?
               AND NOT EXISTS (
                   SELECT 1
                   FROM feed_metadata fm
                   WHERE fm.name = 'attachment_upload_ids'
                     AND JSON_VALID(fm.content)
                     AND JSON_CONTAINS(fm.content, CAST(u.id AS CHAR), '$')
               )",
            [
                self::PURPOSE_FORUM_ATTACHMENT_DELETING,
                $id,
                self::PURPOSE_FORUM_ATTACHMENT,
                self::PURPOSE_FORUM_ATTACHMENT_RESERVED,
                self::PURPOSE_FORUM_ATTACHMENT_DELETING,
                $createdBefore,
            ]
        );

        return $updated === 1 ? $this->findById($id) : null;
    }

    public function releaseOrphanedForumAttachment(int $id): void
    {
        $this->db->execute(
            'UPDATE uploads SET purpose = ? WHERE id = ? AND purpose = ?',
            [self::PURPOSE_FORUM_ATTACHMENT, $id, self::PURPOSE_FORUM_ATTACHMENT_DELETING]
        );
    }

    public function deleteClaimedForumAttachment(int $id): bool
    {
        return $this->db->execute(
            'DELETE FROM uploads WHERE id = ? AND purpose = ?',
            [$id, self::PURPOSE_FORUM_ATTACHMENT_DELETING]
        ) === 1;
    }

    public function relocatePath(string $from, string $to, bool $directory): void
    {
        if (! $directory) {
            $this->db->execute('UPDATE uploads SET path = ? WHERE path = ?', [$to, $from]);

            return;
        }

        $this->db->execute(
            "UPDATE uploads
             SET path = CONCAT(?, SUBSTRING(path, ?))
             WHERE path = ? OR LEFT(path, ?) = CONCAT(?, '/')",
            [$to, strlen($from) + 1, $from, strlen($from) + 1, $from]
        );
    }

    public function deletePath(string $path, bool $directory): void
    {
        if (! $directory) {
            $this->db->execute('DELETE FROM uploads WHERE path = ?', [$path]);

            return;
        }

        $this->db->execute(
            "DELETE FROM uploads
             WHERE path = ? OR LEFT(path, ?) = CONCAT(?, '/')",
            [$path, strlen($path) + 1, $path]
        );
    }

    public function copyPath(string $from, string $to, bool $directory): void
    {
        if (! $directory) {
            $this->db->execute(
                "INSERT INTO uploads (user_id, path, mime, size, original_name, created_at)
                 SELECT user_id, ?, mime, size, original_name, UNIX_TIMESTAMP()
                 FROM uploads WHERE path = ?",
                [$to, $from]
            );

            return;
        }

        $this->db->execute(
            "INSERT INTO uploads (user_id, path, mime, size, original_name, created_at)
             SELECT user_id, CONCAT(?, SUBSTRING(path, ?)), mime, size, original_name, UNIX_TIMESTAMP()
             FROM uploads
             WHERE path = ? OR LEFT(path, ?) = CONCAT(?, '/')",
            [$to, strlen($from) + 1, $from, strlen($from) + 1, $from]
        );
    }
}

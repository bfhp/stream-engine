<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\Upload;

readonly class UploadRepository
{
    public function __construct(
        private PdoDatabase $db
    ) {

    }
    public function create(array $data): Upload
    {
        $this->db->execute(
            "INSERT INTO uploads (user_id, path, mime, size, original_name, created_at)
             VALUES (?, ?, ?, ?, ?, UNIX_TIMESTAMP())",
            [
                $data['user_id'],
                $data['path'],
                $data['mime'],
                $data['size'],
                $data['original_name']
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
            time()
        );
    }

    /**
     * Uses index: PRIMARY(id)
     */
    public function findById(int $id): ?Upload
    {
        $row = $this->db->fetchOne(
            "SELECT id, user_id, path, mime, size, original_name, created_at FROM uploads WHERE id = ?",
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
            (int)$row['created_at']
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
            "SELECT id, user_id, path, mime, size, original_name, created_at FROM uploads WHERE id IN ($placeholders)",
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
                (int)$row['created_at']
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
}

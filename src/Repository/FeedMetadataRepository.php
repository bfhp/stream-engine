<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use RuntimeException;
use InvalidArgumentException;
use StreamEngine\Core\PdoDatabase;
use Throwable;

final readonly class FeedMetadataRepository
{
    private const int MAX_NAME_LENGTH = 64;

    private const int MAX_CONTENT_LENGTH = 65535;

    public function __construct(
        private PdoDatabase $db
    ) {

    }

    /**
     * Uses index: PRIMARY(feed_id, name)
     *
     * @param int[] $feedIds
     * @return array<int, array<string, string>>
     */
    public function findByFeedIds(array $feedIds): array
    {
        $feedIds = array_values(array_unique(array_map('intval', $feedIds)));

        if ($feedIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($feedIds), '?'));
        $rows = $this->db->fetchAll(
            "
            SELECT feed_id, name, content
            FROM feed_metadata
            WHERE feed_id IN ($placeholders)
            ORDER BY feed_id, name
            ",
            $feedIds
        );

        $result = [];
        foreach ($rows as $row) {
            $feedId = (int) $row['feed_id'];
            $result[$feedId] ??= [];
            $result[$feedId][$row['name']] = $row['content'];
        }

        return $result;
    }

    /**
     * Uses index: PRIMARY(feed_id, name)
     *
     * @param array<string, scalar|null> $metadata
     */
    public function replaceForFeed(int $feedId, array $metadata): void
    {
        $metadata = $this->normalize($metadata);

        $this->db->begin();

        try {
            $this->db->execute(
                'DELETE FROM feed_metadata WHERE feed_id = ?',
                [$feedId]
            );

            foreach ($metadata as $name => $content) {
                $this->db->execute(
                    "
                    INSERT INTO feed_metadata (feed_id, name, content, created_at, updated_at)
                    VALUES (?, ?, ?, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
                    ",
                    [$feedId, $name, $content]
                );
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw new RuntimeException('Failed to update feed metadata: ' . $exception->getMessage());
        }
    }

    /**
     * @param array<string, scalar|null> $metadata
     * @return array<string, string>
     */
    private function normalize(array $metadata): array
    {
        $result = [];

        foreach ($metadata as $name => $content) {
            $name = mb_strtolower(trim($name));

            if ($name === '' || $content === null) {
                continue;
            }

            if (! preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $name)) {
                throw new InvalidArgumentException('Invalid feed metadata name: '.$name);
            }

            if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
                throw new InvalidArgumentException('Feed metadata name is too long: '.$name);
            }

            if (! is_scalar($content)) {
                throw new InvalidArgumentException('Feed metadata content must be scalar: '.$name);
            }

            $content = trim((string) $content);

            if ($content === '') {
                continue;
            }

            if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
                throw new InvalidArgumentException('Feed metadata content is too long: '.$name);
            }

            $result[$name] = $content;
        }

        return $result;
    }
}

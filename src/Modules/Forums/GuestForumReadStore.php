<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Forums;

/**
 * Guest bulk-read watermarks, independent of the bounded per-topic history.
 * Key 0 covers the whole forum; other keys cover individual sections.
 */
final class GuestForumReadStore
{
    private const string COOKIE_NAME = 'forum_reads';

    private const int TTL = 60 * 60 * 24 * 365;

    /** @param int[]|null $forumIds Null means every section. */
    public function markAsRead(?array $forumIds): void
    {
        $map = $forumIds === null ? [] : $this->readMap();
        $now = time();
        foreach ($forumIds ?? [0] as $id) {
            $map[$id] = $now;
        }

        // Bound cookie growth by section count, never by topic count. Keep
        // the global watermark even when older individual sections roll off.
        arsort($map);
        $global = $map[0] ?? null;
        $map = array_slice($map, 0, 100, true);
        if ($global !== null) {
            $map[0] = $global;
        }
        $encoded = json_encode($map, JSON_FORCE_OBJECT);
        $_COOKIE[self::COOKIE_NAME] = $encoded;
        setcookie(self::COOKIE_NAME, $encoded, [
            'expires' => $now + self::TTL,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public function findReadAt(int $forumId): ?int
    {
        $map = $this->readMap();
        $readAt = max($map[0] ?? 0, $map[$forumId] ?? 0);

        return $readAt > 0 ? $readAt : null;
    }

    /** @return array<int, int> */
    private function readMap(): array
    {
        $decoded = json_decode($_COOKIE[self::COOKIE_NAME] ?? '', true);
        if (! is_array($decoded)) {
            return [];
        }
        $map = [];
        foreach ($decoded as $id => $readAt) {
            if (is_numeric($id) && (int) $id >= 0 && is_int($readAt) && $readAt > 0) {
                $map[(int) $id] = $readAt;
            }
        }

        return $map;
    }
}

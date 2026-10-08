<?php

declare(strict_types=1);

namespace StreamEngine\Core;

/**
 * Best-effort view deduplication for anonymous visitors.
 *
 * Guests have no user id for the server-side feed_views table, so their last
 * view time per feed is kept in a bounded cookie. Losing or clearing the
 * cookie can count a view again; that is preferable to storing IP addresses
 * or growing a server-side row for every anonymous feed visit.
 */
final class GuestFeedViewStore
{
    private const string COOKIE_NAME = 'feed_views';
    private const int COOKIE_TTL = 60 * 60 * 24 * 30;
    private const int MAX_ENTRIES = 100;

    /** Claims a view when this guest has not viewed the feed within $window. */
    public function claim(int $feedId, int $window, ?int $now = null): bool
    {
        $now ??= time();
        $views = $this->viewMap();
        $lastViewedAt = $views[$feedId] ?? null;

        if ($lastViewedAt !== null && $lastViewedAt > $now - $window) {
            return false;
        }

        $views[$feedId] = $now;
        $this->writeMap($this->prune($views));

        return true;
    }

    /** @return array<int, int> feed id => last viewed timestamp */
    private function viewMap(): array
    {
        $raw = $_COOKIE[self::COOKIE_NAME] ?? '';
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $views = [];
        foreach ($decoded as $feedId => $viewedAt) {
            if (is_numeric($feedId) && is_numeric($viewedAt)) {
                $views[(int) $feedId] = (int) $viewedAt;
            }
        }

        return $views;
    }

    /**
     * @param array<int, int> $views
     * @return array<int, int>
     */
    private function prune(array $views): array
    {
        if (count($views) <= self::MAX_ENTRIES) {
            return $views;
        }

        asort($views, SORT_NUMERIC);

        return array_slice($views, -self::MAX_ENTRIES, null, true);
    }

    /** @param array<int, int> $views */
    private function writeMap(array $views): void
    {
        $encoded = json_encode($views, JSON_FORCE_OBJECT);
        if (! is_string($encoded)) {
            return;
        }

        $_COOKIE[self::COOKIE_NAME] = $encoded;

        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE_NAME, $encoded, [
            'expires' => time() + self::COOKIE_TTL,
            'path' => '/',
            'secure' => ! in_array($_SERVER['HTTPS'] ?? '', ['', 'off'], true),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

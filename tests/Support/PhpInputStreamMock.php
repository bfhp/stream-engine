<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Stream wrapper that stands in for the "php" protocol so tests can control
 * what file_get_contents('php://input') returns. Register/restore around a
 * single test to avoid leaking into unrelated file:// or php://... usage.
 *
 * Both calls are idempotent, so restoring twice - in a `finally` and again in
 * tearDown, say - is safe rather than a PHP notice per test.
 */
final class PhpInputStreamMock
{
    public static string $body = '';

    /** @var resource|null set by PHP's streams API */
    public $context;

    private int $position = 0;

    public function stream_open(): bool
    {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr(self::$body, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen(self::$body);
    }

    public function stream_stat(): array
    {
        return [];
    }

    private static bool $registered = false;

    public static function register(string $body): void
    {
        self::$body = $body;

        if (self::$registered) {
            // Already ours - just swap the body. Re-unregistering would push a
            // second entry onto PHP's saved-wrapper stack, so the matching
            // restore() would only get back to *this* mock.
            return;
        }

        stream_wrapper_unregister('php');
        stream_wrapper_register('php', self::class);
        self::$registered = true;
    }

    /**
     * Idempotent on purpose. stream_wrapper_restore() raises a notice when the
     * protocol isn't currently overridden, so a test that restores in a `finally`
     * *and* in tearDown - a reasonable belt-and-braces - used to emit one per
     * test. Tracking it here means neither caller has to know about the other.
     */
    public static function restore(): void
    {
        if (! self::$registered) {
            return;
        }

        stream_wrapper_restore('php');
        self::$registered = false;
        self::$body = '';
    }
}

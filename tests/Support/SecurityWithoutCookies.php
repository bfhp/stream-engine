<?php

declare(strict_types=1);

namespace Tests\Support;

use StreamEngine\Core\Security;

/**
 * Security with the one thing a unit test can't have taken out: the cookie
 * header.
 *
 * `Security::getCsrfToken()` decides a token and then persists it with
 * setcookie(), which writes a header - not something PHPUnit can observe, and
 * something it will complain about once any test has produced output. The
 * parent calls the write through `static::`, so overriding it here redirects
 * that half into an array while leaving everything else - generation, the
 * in-request $_COOKIE write, the return value - running for real.
 *
 * Reset() before each test: these are statics, and they outlive a test case.
 */
final class SecurityWithoutCookies extends Security
{
    /** @var list<string> every token this would have sent to the browser */
    public static array $written = [];

    protected static function writeCsrfCookie(string $csrfToken): void
    {
        self::$written[] = $csrfToken;
    }

    public static function reset(): void
    {
        self::$written = [];
    }
}

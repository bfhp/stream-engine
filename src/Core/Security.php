<?php

namespace StreamEngine\Core;

use RuntimeException;
use Random\RandomException;
use StreamEngine\Core\Exceptions\ValidationException;

class Security
{
    private const string CSRF_COOKIE_NAME = 'csrfToken';

    private const int CSRF_COOKIE_LIFETIME_HOURS = 24;

    private static function generateCsrfToken(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (RandomException $e) {
            error_log($e->getMessage());
            throw new RuntimeException("Couldn't generate csrf token");
        }
    }

    /**
     * The token for this request, issuing one if the visitor hasn't got it yet.
     *
     * Stable within a request: it writes the new value straight into $_COOKIE
     * so a second call returns the same token. That is load-bearing rather than
     * an optimisation - a form rendered earlier in the request would otherwise
     * carry a token the next call has already replaced.
     *
     * The `is_string` check is not paranoia: cookie *names* are attacker
     * controlled, and `Cookie: csrfToken[]=x` makes $_COOKIE['csrfToken'] an
     * array. `empty()` says such a value is fine, and this method is typed
     * `: string`, so it used to be a TypeError - the same shape of bug already
     * fixed in verifyCsrf(). A non-string is treated as no token at all and a
     * fresh one is issued over it.
     */
    public static function getCsrfToken(): string
    {
        $existing = $_COOKIE[self::CSRF_COOKIE_NAME] ?? null;

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $csrfToken = self::generateCsrfToken();
        $_COOKIE[self::CSRF_COOKIE_NAME] = $csrfToken;

        static::writeCsrfCookie($csrfToken);

        return $csrfToken;
    }

    /**
     * Persisting the token, split from deciding it.
     *
     * Two jobs that fail for different reasons and are worth separating on
     * that alone; it also gives tests something to override, since setcookie()
     * writes a header and headers are not a thing a unit test can have. Called
     * through `static::` so a subclass actually receives it.
     */
    protected static function writeCsrfCookie(string $csrfToken): void
    {
        setcookie(self::CSRF_COOKIE_NAME, $csrfToken, [
            'expires' => time() + self::CSRF_COOKIE_LIFETIME_HOURS * 60 * 60,
            'path' => '/',
            'secure' => true,      // Mandatory HTTPS
            'httponly' => false,     // On-page JS is able to read
            'samesite' => 'Lax',
        ]);
    }

    public static function checkAndCreateCsrfToken(): void
    {
        self::getCsrfToken();
    }

    /**
     * Both sides are checked for being strings before they reach
     * hash_equals(), which is typed `string, string` and raises a TypeError on
     * anything else - a 500 rather than the 400 this is supposed to produce.
     * That was reachable by anyone: cookie names are attacker-controlled, and
     * `Cookie: csrfToken[]=x` makes $_COOKIE['csrfToken'] an *array*. The
     * header side goes through `$_SERVER['HTTP_X_CSRF_TOKEN'] ?? null` at every
     * call site, so it is a string or null today, but it is typed loosely and
     * costs nothing to cover here.
     *
     * A wrong type is treated as a missing token rather than as its own error:
     * there is no legitimate request that sends one.
     *
     * @throws ValidationException
     */
    public static function verifyCsrf(mixed $receivedToken, TranslationManager $tm): void
    {
        $cookieToken = $_COOKIE[self::CSRF_COOKIE_NAME] ?? '';

        if (! is_string($cookieToken) || ! is_string($receivedToken)) {
            throw new ValidationException($tm->trans('security.csrf_missing'));
        }

        if ($cookieToken === '' || $receivedToken === '') {
            throw new ValidationException($tm->trans('security.csrf_missing'));
        }

        if (! hash_equals($cookieToken, $receivedToken)) {
            throw new ValidationException($tm->trans('security.csrf_invalid'));
        }
    }
}

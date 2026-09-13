<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Security;
use StreamEngine\Core\TranslationManager;
use Tests\Support\SecurityWithoutCookies;

final class SecurityTest extends TestCase
{
    private array $cookieBackup = [];
    private TranslationManager $tm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cookieBackup = $_COOKIE;
        $_COOKIE = [];
        $this->tm = new TranslationManager('ru', 'en');
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookieBackup;

        parent::tearDown();
    }

    /* ===============================
       Issuing a token
    =============================== */

    /**
     * The half of the mechanism that had no tests at all: verifyCsrf() checks a
     * token, but nothing checked that a correct one is ever produced.
     */
    public function testGetCsrfTokenIssuesA64CharHexTokenWhenThereIsNone(): void
    {
        SecurityWithoutCookies::reset();

        $token = SecurityWithoutCookies::getCsrfToken();

        // 32 random bytes, hex-encoded.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        // Written into $_COOKIE for the rest of this request, not just returned.
        $this->assertSame($token, $_COOKIE['csrfToken']);
        // And sent to the browser exactly once.
        $this->assertSame([$token], SecurityWithoutCookies::$written);
    }

    /**
     * Stable within a request. Rotating on a second call would invalidate a
     * token already rendered into a form earlier in the same response.
     */
    public function testGetCsrfTokenDoesNotRotateWithinARequest(): void
    {
        SecurityWithoutCookies::reset();

        $first = SecurityWithoutCookies::getCsrfToken();
        $second = SecurityWithoutCookies::getCsrfToken();

        $this->assertSame($first, $second);
        // The second call issued nothing - one cookie per request, not two.
        $this->assertCount(1, SecurityWithoutCookies::$written);
    }

    public function testGetCsrfTokenKeepsAnExistingCookie(): void
    {
        SecurityWithoutCookies::reset();
        $_COOKIE['csrfToken'] = 'already-issued';

        $this->assertSame('already-issued', SecurityWithoutCookies::getCsrfToken());
        $this->assertSame([], SecurityWithoutCookies::$written);
    }

    /**
     * Cookie names are attacker-controlled, so `Cookie: csrfToken[]=x` makes
     * this an array. `empty()` considered that a usable token and the method is
     * typed `: string`, so it was a TypeError - the same shape of bug as the one
     * fixed in verifyCsrf(). A fresh token is issued over it instead.
     *
     * @param mixed $cookie
     */
    #[DataProvider('unusableCookieProvider')]
    public function testGetCsrfTokenReplacesAnUnusableCookie(mixed $cookie): void
    {
        SecurityWithoutCookies::reset();
        $_COOKIE['csrfToken'] = $cookie;

        $token = SecurityWithoutCookies::getCsrfToken();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame([$token], SecurityWithoutCookies::$written);
    }

    /** @return array<string, array{mixed}> */
    public static function unusableCookieProvider(): array
    {
        return [
            'an array' => [['x']],
            'an empty string' => [''],
        ];
    }

    public function testCheckAndCreateCsrfTokenIssuesOneWithoutReturningIt(): void
    {
        SecurityWithoutCookies::reset();

        // What StreamEngine::handleRequest() calls at the end of every HTML
        // render, so that the page's JS has a token to send back.
        SecurityWithoutCookies::checkAndCreateCsrfToken();

        $this->assertCount(1, SecurityWithoutCookies::$written);
        $this->assertSame(SecurityWithoutCookies::$written[0], $_COOKIE['csrfToken']);
    }

    /**
     * The two halves have to agree: a token straight out of getCsrfToken()
     * must satisfy verifyCsrf() when the client sends it back.
     */
    public function testAnIssuedTokenVerifies(): void
    {
        SecurityWithoutCookies::reset();

        $token = SecurityWithoutCookies::getCsrfToken();

        $this->expectNotToPerformAssertions();

        Security::verifyCsrf($token, $this->tm);
    }

    /* ===============================
       Verifying one
    =============================== */

    public function testVerifyCsrfThrowsWhenCookieOrHeaderIsMissing(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->tm->trans('security.csrf_missing'));

        Security::verifyCsrf(null, $this->tm);
    }

    public function testVerifyCsrfThrowsWhenTokenDoesNotMatch(): void
    {
        $_COOKIE['csrfToken'] = 'expected-token';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->tm->trans('security.csrf_invalid'));

        Security::verifyCsrf('wrong-token', $this->tm);
    }

    public function testVerifyCsrfAcceptsMatchingToken(): void
    {
        $_COOKIE['csrfToken'] = 'matching-token';

        $this->expectNotToPerformAssertions();

        Security::verifyCsrf('matching-token', $this->tm);
    }

    /**
     * Cookie names are attacker-controlled, and `Cookie: csrfToken[]=x` makes
     * $_COOKIE['csrfToken'] an array. hash_equals() is typed `string, string`,
     * so that used to be a TypeError - a 500 on any endpoint with a CSRF check,
     * reachable by anyone.
     */
    public function testVerifyCsrfRejectsAnArrayCookieInsteadOfCrashing(): void
    {
        $_COOKIE['csrfToken'] = ['x'];

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->tm->trans('security.csrf_missing'));

        Security::verifyCsrf('x', $this->tm);
    }

    public function testVerifyCsrfRejectsANonStringReceivedToken(): void
    {
        $_COOKIE['csrfToken'] = 'expected-token';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->tm->trans('security.csrf_missing'));

        Security::verifyCsrf(['expected-token'], $this->tm);
    }

    public function testVerifyCsrfRejectsAnEmptyStringOnEitherSide(): void
    {
        $_COOKIE['csrfToken'] = '';

        try {
            Security::verifyCsrf('something', $this->tm);
            $this->fail('An empty cookie must not pass');
        } catch (ValidationException $e) {
            $this->assertSame($this->tm->trans('security.csrf_missing'), $e->getMessage());
        }

        $_COOKIE['csrfToken'] = 'expected-token';

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->tm->trans('security.csrf_missing'));

        Security::verifyCsrf('', $this->tm);
    }

    /**
     * Right length, wrong value - so this can only pass by reaching
     * hash_equals(), not by tripping the emptiness guard in front of it.
     */
    public function testVerifyCsrfComparesFullValueNotJustPresence(): void
    {
        $_COOKIE['csrfToken'] = str_repeat('a', 64);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->tm->trans('security.csrf_invalid'));

        Security::verifyCsrf(str_repeat('a', 63).'b', $this->tm);
    }
}

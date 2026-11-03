<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Service\AuthenticationRateLimiter;
use Tests\Support\InMemoryRateLimitStore;

final class AuthenticationRateLimiterTest extends TestCase
{
    private function limiter(InMemoryRateLimitStore $store, int &$now): AuthenticationRateLimiter
    {
        return new AuthenticationRateLimiter(
            $store,
            new TranslationManager('en', 'en'),
            static function () use (&$now): int {
                return $now;
            },
        );
    }

    public function testLoginAccountLimitAppliesAcrossDifferentIpsAndExpires(): void
    {
        $now = 1_700_000_000;
        $store = new InMemoryRateLimitStore();
        $limiter = $this->limiter($store, $now);

        for ($attempt = 1; $attempt <= AuthenticationRateLimiter::LOGIN_ACCOUNT_LIMIT; $attempt++) {
            $limiter->consumeLogin(' User@Example.com ', '192.0.2.'.$attempt);
        }

        try {
            $limiter->consumeLogin('user@example.com', '198.51.100.1');
            self::fail('The account limit must reject the next attempt.');
        } catch (ValidationException $e) {
            self::assertSame(429, $e->getHttpCode());
            self::assertSame('rate_limited', $e->getCodeName());
        }

        $now += AuthenticationRateLimiter::LOGIN_WINDOW_SECONDS;
        $limiter->consumeLogin('user@example.com', '198.51.100.2');
        self::assertTrue(true);
    }

    public function testLoginIpLimitAppliesAcrossDifferentAccounts(): void
    {
        $now = 1_700_000_000;
        $limiter = $this->limiter(new InMemoryRateLimitStore(), $now);

        for ($attempt = 1; $attempt <= AuthenticationRateLimiter::LOGIN_IP_LIMIT; $attempt++) {
            $limiter->consumeLogin("user$attempt@example.com", '192.0.2.10');
        }

        $this->expectException(ValidationException::class);
        $limiter->consumeLogin('another@example.com', '192.0.2.10');
    }

    public function testSuccessfulLoginClearsOnlyTheAccountBucket(): void
    {
        $now = 1_700_000_000;
        $store = new InMemoryRateLimitStore();
        $limiter = $this->limiter($store, $now);

        $limiter->consumeLogin('user@example.com', '192.0.2.10');
        $limiter->loginSucceeded('USER@example.com');

        self::assertCount(1, $store->buckets);
        self::assertStringStartsWith('login-ip:', array_key_first($store->buckets));
    }

    public function testPasswordResetLimitsAccountAcrossIpsAndIpAcrossAccounts(): void
    {
        $now = 1_700_000_000;
        $accountLimiter = $this->limiter(new InMemoryRateLimitStore(), $now);

        for ($attempt = 1; $attempt <= AuthenticationRateLimiter::RESET_ACCOUNT_LIMIT; $attempt++) {
            $accountLimiter->consumePasswordReset('user@example.com', '192.0.2.'.$attempt);
        }
        try {
            $accountLimiter->consumePasswordReset('user@example.com', '198.51.100.1');
            self::fail('The reset account limit must reject the next request.');
        } catch (ValidationException $e) {
            self::assertSame(429, $e->getHttpCode());
        }

        $ipLimiter = $this->limiter(new InMemoryRateLimitStore(), $now);
        for ($attempt = 1; $attempt <= AuthenticationRateLimiter::RESET_IP_LIMIT; $attempt++) {
            $ipLimiter->consumePasswordReset("user$attempt@example.com", '203.0.113.8');
        }

        $this->expectException(ValidationException::class);
        $ipLimiter->consumePasswordReset('another@example.com', '203.0.113.8');
    }

    public function testStoredSubjectsAreHashesNotEmailOrIp(): void
    {
        $now = 1_700_000_000;
        $store = new InMemoryRateLimitStore();
        $this->limiter($store, $now)->consumePasswordReset('user@example.com', '192.0.2.44');

        foreach (array_keys($store->buckets) as $key) {
            self::assertStringNotContainsString('user@example.com', $key);
            self::assertStringNotContainsString('192.0.2.44', $key);
            self::assertMatchesRegularExpression('/\Areset-(?:account|ip):[a-f0-9]{64}\z/', $key);
        }
    }
}

<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use Closure;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Repository\RateLimitStore;

final readonly class AuthenticationRateLimiter
{
    public const int LOGIN_ACCOUNT_LIMIT = 5;
    public const int LOGIN_IP_LIMIT = 50;
    public const int LOGIN_WINDOW_SECONDS = 15 * 60;
    public const int RESET_ACCOUNT_LIMIT = 3;
    public const int RESET_IP_LIMIT = 10;
    public const int RESET_WINDOW_SECONDS = 60 * 60;

    private const string LOGIN_ACCOUNT_SCOPE = 'login-account';
    private const string LOGIN_IP_SCOPE = 'login-ip';
    private const string RESET_ACCOUNT_SCOPE = 'reset-account';
    private const string RESET_IP_SCOPE = 'reset-ip';

    public function __construct(
        private RateLimitStore $store,
        private TranslationManager $tm,
        private ?Closure $clock = null,
    ) {
    }

    /** @throws ValidationException */
    public function consumeLogin(string $email, string $clientIp): void
    {
        $accountHash = $this->accountHash($email);
        $ipHash = $this->ipHash($clientIp);
        $now = $this->now();

        // Consume both buckets before deciding, so attempts against an
        // already-limited account still contribute to the source IP limit.
        $accountAttempts = $this->store->consume(
            self::LOGIN_ACCOUNT_SCOPE,
            $accountHash,
            self::LOGIN_ACCOUNT_LIMIT,
            self::LOGIN_WINDOW_SECONDS,
            $now,
        );
        $ipAttempts = $this->store->consume(
            self::LOGIN_IP_SCOPE,
            $ipHash,
            self::LOGIN_IP_LIMIT,
            self::LOGIN_WINDOW_SECONDS,
            $now,
        );

        if ($accountAttempts > self::LOGIN_ACCOUNT_LIMIT || $ipAttempts > self::LOGIN_IP_LIMIT) {
            $this->reject();
        }
    }

    public function loginSucceeded(string $email): void
    {
        $this->store->delete(self::LOGIN_ACCOUNT_SCOPE, $this->accountHash($email));
    }

    /** @throws ValidationException */
    public function consumePasswordReset(string $email, string $clientIp): void
    {
        $now = $this->now();
        $accountAttempts = $this->store->consume(
            self::RESET_ACCOUNT_SCOPE,
            $this->accountHash($email),
            self::RESET_ACCOUNT_LIMIT,
            self::RESET_WINDOW_SECONDS,
            $now,
        );
        $ipAttempts = $this->store->consume(
            self::RESET_IP_SCOPE,
            $this->ipHash($clientIp),
            self::RESET_IP_LIMIT,
            self::RESET_WINDOW_SECONDS,
            $now,
        );

        if ($accountAttempts > self::RESET_ACCOUNT_LIMIT || $ipAttempts > self::RESET_IP_LIMIT) {
            $this->reject();
        }
    }

    /** @throws ValidationException */
    private function reject(): never
    {
        throw new ValidationException(
            $this->tm->trans('auth.rate_limited'),
            429,
            'rate_limited'
        );
    }

    private function accountHash(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    private function ipHash(string $clientIp): string
    {
        $clientIp = trim($clientIp);
        $packed = filter_var($clientIp, FILTER_VALIDATE_IP) !== false
            ? inet_pton($clientIp)
            : false;

        return hash('sha256', $packed !== false ? $packed : 'unknown');
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : time();
    }
}

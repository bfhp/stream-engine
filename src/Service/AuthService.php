<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use DateInvalidTimeZoneException;
use DateTimeZone;
use Exception;
use Random\RandomException;
use RuntimeException;
use StreamEngine\Domain\User;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;

final class AuthService
{
    private const string COOKIE_NAME = 'SE_AUTH';
    private const int SESSION_LIFETIME_DAYS = 30;

    public function __construct(
        private readonly UserRepository        $userRepository,
        private readonly UserSessionRepository $sessionRepository
    ) {

    }

    /**
     * @throws Exception
     */
    public function login(string $email, string $password): bool
    {
        $row = $this->userRepository->findByEmail($email);

        if (!$row) {
            return false;
        }

        if ((int) $row['id'] === User::SYSTEM_USER_ID) {
            return false;
        }

        if (!$row['is_active']) {
            return false;
        }

        if (!password_verify($password, $row['password_hash'])) {
            return false;
        }

        $this->createSession((int)$row['id']);

        return true;
    }

    /**
     * @throws Exception
     */
    public function loginByUserId(int $userId): void
    {
        if ($userId === User::SYSTEM_USER_ID) {
            throw new RuntimeException('The system account cannot start a user session.');
        }

        $this->createSession($userId);
    }

    /**
     * @throws RandomException
     */
    private function createSession(int $userId): void
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $expiresAt = time() + self::SESSION_LIFETIME_DAYS * 86400;

        $this->sessionRepository->create(
            userId: $userId,
            tokenHash: $tokenHash,
            userAgent: $_SERVER['HTTP_USER_AGENT'] ?? '',
            ip: $_SERVER['REMOTE_ADDR'] ?? '',
            expiresAt: $expiresAt,
        );

        setcookie(
            self::COOKIE_NAME,
            $token,
            [
                'expires' => $expiresAt,
                'path' => '/',
                'secure' => true,
                'httponly' => true,
                'samesite' => 'Lax'
            ]
        );
    }

    public function logout(): void
    {
        if (!isset($_COOKIE[self::COOKIE_NAME])) {
            return;
        }

        $tokenHash = hash('sha256', $_COOKIE[self::COOKIE_NAME]);

        $this->sessionRepository->deleteByToken($tokenHash);

        setcookie(self::COOKIE_NAME, '', time() - 3600, '/');
    }

    public function currentUser(): User
    {
        if (!isset($_COOKIE[self::COOKIE_NAME])) {
            return $this->guest();
        }

        $token = $_COOKIE[self::COOKIE_NAME];
        $tokenHash = hash('sha256', $token);

        $session = $this->sessionRepository->findValid($tokenHash);

        if (!$session) {
            return $this->guest();
        }

        // Throttled, not unconditional: this fires on every authenticated
        // request (including each poll the messenger makes and every other
        // API call), and the column it writes is only ever read at
        // UserService::ONLINE_WINDOW_SECONDS resolution. Session *expiry*
        // is unaffected - that's `expires_at`, set once at login and never
        // slid forward, so skipping a write can't log anyone out early.
        $this->sessionRepository->touchIfStale(
            (int)$session['id'],
            UserService::PRESENCE_TOUCH_INTERVAL_SECONDS
        );

        $user = $this->userRepository
            ->findById((int)$session['user_id']);

        return $user ?? $this->guest();
    }

    public function guest(): User
    {
        return new User(
            id: 0,
            email: ''
        );
    }

    public function getUserTimezone(?User $user = null): DateTimeZone
    {
        $default = $user !== null && !$user->isGuest() ? $user->timezone : 'UTC';

        if (empty($_COOKIE['tz'])) {
            try {
                return new DateTimeZone($default);
            } catch (DateInvalidTimeZoneException $e) {
                throw new RuntimeException('Unable to set timezone (' . $e->getMessage() . ')');
            }
        }

        $tz = trim($_COOKIE['tz']);

        // Quick format validation (to cut out junk)
        if (!preg_match('/^[A-Za-z_\/+-]+$/', $tz)) {
            try {
                return new DateTimeZone($default);
            } catch (DateInvalidTimeZoneException $e) {
                throw new RuntimeException('Unable to set timezone (' . $e->getMessage() . ')');
            }
        }

        // Checking the list of real time zones
        static $validTimezones = null;

        if ($validTimezones === null) {
            $validTimezones = DateTimeZone::listIdentifiers();
        }

        if (!in_array($tz, $validTimezones, true)) {
            try {
                return new DateTimeZone($default);
            } catch (DateInvalidTimeZoneException $e) {
                throw new RuntimeException('Unable to set timezone (' . $e->getMessage() . ')');
            }
        }

        if ($user !== null && !$user->isGuest() && $user->timezone !== $tz) {
            $this->userRepository->updateTimezone($user->id, $tz);
        }

        try {
            return new DateTimeZone($tz);
        } catch (Exception $e) {
            error_log('Unable to set timezone (' . $e->getMessage() . ')');
            try {
                return new DateTimeZone($default);
            } catch (DateInvalidTimeZoneException $e) {
                throw new RuntimeException('Unable to set timezone (' . $e->getMessage() . ')');
            }
        }
    }
}

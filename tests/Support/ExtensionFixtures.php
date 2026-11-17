<?php

declare(strict_types=1);

// Loaded with require_once and excluded from the Composer classmap: anything
// in the classmap that extends Extension is discovered at bootstrap, and a
// fixture that accepts every password must never become active on a dev site.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace Tests\Support\Extensions;

use Closure;
use RuntimeException;
use StreamEngine\Core\RequestContext;
use StreamEngine\Extension\Extension;
use StreamEngine\Repository\UserRepository;

/** Records calls in order across fixtures so tests can assert who was asked. */
final class CallLog
{
    /** @var list<string> */
    public static array $calls = [];

    public static int $constructed = 0;

    public static function reset(): void
    {
        self::$calls = [];
        self::$constructed = 0;
    }
}

final class AAbstainingExtension extends Extension
{
    public function __construct()
    {
        CallLog::$constructed++;
    }

    public function probe(string $value): ?string
    {
        CallLog::$calls[] = self::class;

        return null;
    }
}

final class BAnsweringExtension extends Extension
{
    public function __construct()
    {
        CallLog::$constructed++;
    }

    public function probe(string $value): ?string
    {
        CallLog::$calls[] = self::class;

        return "B:$value";
    }
}

final class CAnsweringExtension extends Extension
{
    public function __construct()
    {
    }

    public function probe(string $value): ?string
    {
        CallLog::$calls[] = self::class;

        return "C:$value";
    }
}

final class ZAnsweringExtension extends Extension
{
    public function __construct()
    {
    }

    public function probe(string $value): ?string
    {
        CallLog::$calls[] = self::class;

        return "Z:$value";
    }
}

final class ThrowingExtension extends Extension
{
    public function __construct()
    {
    }

    public function probe(string $value): ?string
    {
        CallLog::$calls[] = self::class;

        throw new RuntimeException('probe exploded');
    }
}

final class FalseAnsweringExtension extends Extension
{
    public function __construct()
    {
    }

    public function probe(string $value): bool
    {
        CallLog::$calls[] = self::class;

        return false;
    }
}

final class PrivatePointExtension extends Extension
{
    public function __construct()
    {
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function probe(string $value): string
    {
        CallLog::$calls[] = self::class;

        return 'private';
    }
}

final class DependentExtension extends Extension
{
    public function __construct(public readonly UserRepository $users)
    {
    }

    public function repository(): UserRepository
    {
        return $this->users;
    }
}

final class RequestContextExtension extends Extension
{
    public function __construct(public readonly RequestContext $context)
    {
    }
}

/** Uses the inherited constructor, so it receives every shared service. */
final class PropertyProbeExtension extends Extension
{
    /** @return array{0: object, 1: object, 2: object} */
    public function probeProperties(): array
    {
        return [$this->db, $this->accessService, $this->userRepository];
    }
}

abstract class AbstractSiteExtension extends Extension
{
}

/** Answers verifyPasswordFallback with whatever the test configures. */
final class PasswordFallbackExtension extends Extension
{
    public function __construct()
    {
    }

    /** @var (Closure(int, string, string, string): ?bool)|null */
    public static ?Closure $handler = null;

    /** @var list<array{userId: int, email: string, password: string, clientIp: string}> */
    public static array $requests = [];

    public function verifyPasswordFallback(int $userId, string $email, #[\SensitiveParameter] string $password, string $clientIp): ?bool
    {
        self::$requests[] = compact('userId', 'email', 'password', 'clientIp');

        return self::$handler !== null ? (self::$handler)($userId, $email, $password, $clientIp) : null;
    }
}

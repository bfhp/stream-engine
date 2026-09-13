<?php

declare(strict_types=1);

namespace Tests\Core\Installation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Installation\InstallationConfig;

final class InstallationConfigTest extends TestCase
{
    public function testValuesAreNormalized(): void
    {
        $config = new InstallationConfig(
            ' My site ',
            'RU',
            ' Admin@Example.COM ',
            ' Administrator ',
            'long-enough-password',
        );

        self::assertSame('My site', $config->siteName);
        self::assertSame('ru', $config->locale);
        self::assertSame('admin@example.com', $config->adminEmail);
        self::assertSame('Administrator', $config->adminName);
    }

    public function testMalformedUtf8IsRejectedBeforeItReachesTheDatabase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('administrator name is not valid UTF-8');

        new InstallationConfig(
            'Site',
            'ru',
            'admin@example.com',
            "\xD1\xD0\xB5\xD0\xB0\xD0",
            'long-enough-password',
        );
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRejected(array $values, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new InstallationConfig(...$values);
    }

    public static function invalidValues(): iterable
    {
        $valid = [
            'siteName' => 'Site',
            'locale' => 'en',
            'adminEmail' => 'admin@example.com',
            'adminName' => 'Admin',
            'adminPassword' => 'long-enough-password',
        ];

        yield 'site name' => [array_replace($valid, ['siteName' => '']), 'Site name'];
        yield 'locale' => [array_replace($valid, ['locale' => '../ru']), 'Locale'];
        yield 'email' => [array_replace($valid, ['adminEmail' => 'bad']), 'email is invalid'];
        yield 'reserved email' => [array_replace($valid, ['adminEmail' => 'system@localhost.invalid']), 'reserved'];
        yield 'name' => [array_replace($valid, ['adminName' => '']), 'Administrator name'];
        yield 'password' => [array_replace($valid, ['adminPassword' => 'short']), 'password'];
    }
}

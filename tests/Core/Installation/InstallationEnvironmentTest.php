<?php

declare(strict_types=1);

namespace Tests\Core\Installation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Installation\InstallationEnvironment;

final class InstallationEnvironmentTest extends TestCase
{
    public function testValuesAreNormalizedAndSecretsAreGenerated(): void
    {
        $config = new InstallationEnvironment(
            'PROD',
            'https://example.com/',
            ' db.internal ',
            '3307',
            'stream_engine',
            ' app ',
            'p@ss $word',
        );

        $values = $config->values([]);

        self::assertSame('prod', $values['APP_ENV']);
        self::assertSame('https://example.com', $values['SITE_URL']);
        self::assertSame('db.internal', $values['DB_HOST']);
        self::assertSame('3307', $values['DB_PORT']);
        self::assertSame('stream_engine', $values['DB_NAME']);
        self::assertSame('app', $values['DB_USERNAME']);
        self::assertSame('p@ss $word', $values['DB_PASSWORD']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $values['APP_SECRET']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $values['CRON_KEY']);
        self::assertSame('no-cron', $values['CRON_MODE']);
    }

    public function testExistingSecretsAndCronModeArePreserved(): void
    {
        $config = new InstallationEnvironment('dev', 'http://localhost:5000', 'db', 3306, 'app', 'user', '');

        $values = $config->values([
            'APP_SECRET' => 'existing-app-secret',
            'CRON_KEY' => 'existing-cron-key',
            'CRON_MODE' => 'os',
        ]);

        self::assertSame('existing-app-secret', $values['APP_SECRET']);
        self::assertSame('existing-cron-key', $values['CRON_KEY']);
        self::assertSame('os', $values['CRON_MODE']);
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRejected(array $values, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new InstallationEnvironment(...$values);
    }

    public static function invalidValues(): iterable
    {
        $valid = [
            'appEnvironment' => 'prod',
            'siteUrl' => 'https://example.com',
            'dbHost' => 'localhost',
            'dbPort' => 3306,
            'dbName' => 'app',
            'dbUsername' => 'user',
            'dbPassword' => 'password',
        ];

        yield 'environment' => [array_replace($valid, ['appEnvironment' => 'production']), 'environment'];
        yield 'site URL' => [array_replace($valid, ['siteUrl' => 'example.com']), 'Site URL'];
        yield 'host' => [array_replace($valid, ['dbHost' => "db\nhost"]), 'Database host'];
        yield 'port' => [array_replace($valid, ['dbPort' => 70000]), 'Database port'];
        yield 'name' => [array_replace($valid, ['dbName' => 'app;port=1']), 'Database name'];
        yield 'username' => [array_replace($valid, ['dbUsername' => '']), 'Database username'];
        yield 'password' => [array_replace($valid, ['dbPassword' => "bad\npassword"]), 'Database password'];
    }
}

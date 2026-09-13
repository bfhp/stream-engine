<?php

declare(strict_types=1);

namespace Tests\Core\Installation;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\Installation\InstallationConfig;
use StreamEngine\Core\Installation\InstallationEnvironment;
use StreamEngine\Core\Installation\InstallationPreflightException;
use StreamEngine\Core\Installation\InstallationPreflightReport;
use StreamEngine\Core\Installation\InstallationState;
use StreamEngine\Core\Installation\PreflightCheck;
use StreamEngine\Core\Installation\WebInstaller;

final class WebInstallerTest extends TestCase
{
    private string $languagesDirectory;

    protected function setUp(): void
    {
        $this->languagesDirectory = dirname(__DIR__, 3).'/src/Lang';
    }

    public function testBrowserWithoutClaimCannotOpenInstallerInSimpleMode(): void
    {
        $called = false;
        $installer = new WebInstaller(
            null,
            $this->languagesDirectory,
            function () use (&$called): void {
                $called = true;
            },
            browserAuthorized: false,
        );

        $response = $installer->handle('GET', [], 'csrf');

        self::assertSame(409, $response->status);
        self::assertStringContainsString('Another browser owns', $response->body);
        self::assertFalse($called);
    }

    public function testSimpleModeUsesBrowserClaimWithoutTokenField(): void
    {
        $installer = new WebInstaller(null, $this->languagesDirectory, static function (): void {
        });

        $response = $installer->handle('GET', [], 'csrf-secret');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('browser owns the one-hour installation session', $response->body);
        self::assertStringNotContainsString('name="installation_token"', $response->body);
    }

    public function testGetRendersProtectedFormWithoutPasswords(): void
    {
        $installer = new WebInstaller('server-secret', $this->languagesDirectory, static function (): void {
        });

        $response = $installer->handle('GET', [], 'csrf-secret');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('action="/install"', $response->body);
        self::assertStringContainsString('value="csrf-secret"', $response->body);
        self::assertStringNotContainsString('server-secret', $response->body);
        self::assertStringNotContainsString('name="admin_password" value=', $response->body);
    }

    public function testGetRendersSystemPreflightReport(): void
    {
        $report = new InstallationPreflightReport([
            new PreflightCheck('php', 'PHP version', true, 'PHP 8.3 is supported.'),
            new PreflightCheck('extensions', 'PHP extensions', false, 'Missing: intl.'),
        ]);
        $installer = new WebInstaller(
            null,
            $this->languagesDirectory,
            static function (): void {
            },
            preflightReport: $report,
        );

        $response = $installer->handle('GET', [], 'csrf-secret');

        self::assertSame(200, $response->status);
        self::assertStringContainsString('Preflight checks', $response->body);
        self::assertStringContainsString('Passed: PHP version', $response->body);
        self::assertStringContainsString('Failed: PHP extensions', $response->body);
        self::assertStringContainsString('Missing: intl.', $response->body);
    }

    public function testInvalidPostReportsFieldsAndDoesNotRunInstallation(): void
    {
        $called = false;
        $installer = new WebInstaller('server-secret', $this->languagesDirectory, function () use (&$called): void {
            $called = true;
        });

        $response = $installer->handle('POST', [
            'csrf_token' => 'wrong',
            'installation_token' => 'wrong',
            'site_url' => 'https://example.com',
            'db_host' => 'db.internal',
            'db_port' => '3306',
            'db_name' => 'app',
            'db_username' => 'user',
            'db_password' => 'database-password',
            'site_name' => '<Site>',
            'locale' => 'missing',
            'admin_email' => 'invalid',
            'admin_name' => '',
            'admin_password' => 'long-password-one',
            'admin_password_confirmation' => 'long-password-two',
        ], 'csrf-secret');

        self::assertSame(422, $response->status);
        self::assertStringContainsString('The form expired.', $response->body);
        self::assertStringContainsString('installation token is invalid', $response->body);
        self::assertStringContainsString('Administrator email is invalid.', $response->body);
        self::assertStringContainsString('Administrator passwords do not match.', $response->body);
        self::assertStringContainsString('value="&lt;Site&gt;"', $response->body);
        self::assertStringNotContainsString('long-password-one', $response->body);
        self::assertStringNotContainsString('database-password', $response->body);
        self::assertFalse($called);
    }

    public function testValidPostRunsSharedInstallerAndRedirectsHome(): void
    {
        $received = null;
        $receivedEnvironment = null;
        $installer = new WebInstaller(
            'server-secret',
            $this->languagesDirectory,
            function (
                InstallationConfig $config,
                InstallationEnvironment $environment,
            ) use (&$received, &$receivedEnvironment): InstallationState {
                $received = $config;
                $receivedEnvironment = $environment;

                return InstallationState::start('0.1.0')->complete();
            },
        );

        $response = $installer->handle('POST', $this->validInput(), 'csrf-secret');

        self::assertSame(303, $response->status);
        self::assertSame(['Location' => '/'], $response->headers);
        self::assertInstanceOf(InstallationConfig::class, $received);
        self::assertSame('admin@example.com', $received->adminEmail);
        self::assertInstanceOf(InstallationEnvironment::class, $receivedEnvironment);
        self::assertSame('db.internal', $receivedEnvironment->dbHost);
    }

    public function testSimpleModeAcceptsValidPostWithoutInstallationToken(): void
    {
        $called = false;
        $installer = new WebInstaller(
            null,
            $this->languagesDirectory,
            function () use (&$called): InstallationState {
                $called = true;

                return InstallationState::start('0.1.0')->complete();
            },
        );
        $input = $this->validInput();
        unset($input['installation_token']);

        $response = $installer->handle('POST', $input, 'csrf-secret');

        self::assertSame(303, $response->status);
        self::assertTrue($called);
    }

    public function testInstallationFailureIsLoggedButNotExposed(): void
    {
        $logged = null;
        $installer = new WebInstaller(
            'server-secret',
            $this->languagesDirectory,
            static fn () => throw new RuntimeException('database password is secret'),
            static function (string $message) use (&$logged): void {
                $logged = $message;
            },
        );

        $response = $installer->handle('POST', $this->validInput(), 'csrf-secret');

        self::assertSame(503, $response->status);
        self::assertStringContainsString('Check the PHP error log', $response->body);
        self::assertStringNotContainsString('database password is secret', $response->body);
        self::assertSame('Web installation failed: database password is secret', $logged);
    }

    public function testPreflightFailureReturnsActionableReportWithoutGenericFailure(): void
    {
        $report = new InstallationPreflightReport([
            new PreflightCheck('database', 'Database', false, 'Database connection failed'),
        ]);
        $installer = new WebInstaller(
            'server-secret',
            $this->languagesDirectory,
            static fn () => throw new InstallationPreflightException($report),
        );

        $response = $installer->handle('POST', $this->validInput(), 'csrf-secret');

        self::assertSame(422, $response->status);
        self::assertStringContainsString('Fix the failed preflight checks', $response->body);
        self::assertStringContainsString('Failed: Database', $response->body);
        self::assertStringContainsString('Database connection failed', $response->body);
        self::assertStringNotContainsString('Check the PHP error log', $response->body);
    }

    public function testUnsupportedMethodIsRejected(): void
    {
        $installer = new WebInstaller('server-secret', $this->languagesDirectory, static function (): void {
        });

        $response = $installer->handle('DELETE', [], 'csrf-secret');

        self::assertSame(405, $response->status);
        self::assertSame(['Allow' => 'GET, POST'], $response->headers);
    }

    /** @return array<string, string> */
    private function validInput(): array
    {
        return [
            'csrf_token' => 'csrf-secret',
            'installation_token' => 'server-secret',
            'site_url' => 'https://example.com',
            'db_host' => 'db.internal',
            'db_port' => '3306',
            'db_name' => 'app',
            'db_username' => 'user',
            'db_password' => 'database-password',
            'site_name' => 'Example site',
            'locale' => 'ru',
            'admin_email' => 'admin@example.com',
            'admin_name' => 'Administrator',
            'admin_password' => 'long-enough-password',
            'admin_password_confirmation' => 'long-enough-password',
        ];
    }
}

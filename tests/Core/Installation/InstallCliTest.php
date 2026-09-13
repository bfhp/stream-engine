<?php

declare(strict_types=1);

namespace Tests\Core\Installation;

use PHPUnit\Framework\TestCase;

final class InstallCliTest extends TestCase
{
    public function testInteractiveInstallerPromptsAgainAfterInvalidInput(): void
    {
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['CMS_ADMIN_PASSWORD'] = '';
        $environment['CMS_ADMIN_PASSWORD_FILE'] = '';

        $process = proc_open(
            [
                PHP_BINARY,
                dirname(__DIR__, 3).'/bin/install.php',
                '--app-env=dev',
                '--site-url=http://localhost:5000',
                '--db-host=db',
                '--db-port=3306',
                '--db-name=app',
                '--db-username=user',
                '--db-password=password',
            ],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 3),
            $environment,
        );
        self::assertIsResource($process);

        fwrite($pipes[0], implode("\n", [
            'Example site',
            'ru',
            'admin@example.com',
            'Administrator',
            'first-password',
            'second-password',
            '',
        ]));
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(1, proc_close($process), $output);
        self::assertStringContainsString('Administrator passwords do not match.', $output);
        self::assertStringContainsString('Please enter Administrator password again.', $output);
        self::assertSame(1, substr_count($output, 'Site name [Stream Engine]:'));
        self::assertSame(2, substr_count($output, 'Administrator password:'));
        self::assertStringContainsString('Could not read administrator password.', $output);
    }
}

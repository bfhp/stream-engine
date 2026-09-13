<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use Throwable;

final readonly class InstallationPreflight
{
    private const array REQUIRED_EXTENSIONS = [
        'curl',
        'dom',
        'fileinfo',
        'gd',
        'intl',
        'libxml',
        'mbstring',
        'memcached',
        'pdo',
        'pdo_mysql',
        'simplexml',
        'xmlreader',
        'zip',
    ];

    /** @param array<string, mixed> $currentEnvironment */
    public function __construct(
        private string $projectRoot,
        private array $currentEnvironment,
    ) {
    }

    public function system(): InstallationPreflightReport
    {
        $checks = [];
        $checks[] = new PreflightCheck(
            'php',
            'PHP version',
            PHP_VERSION_ID >= 80300,
            PHP_VERSION_ID >= 80300 ? PHP_VERSION.' is supported.' : PHP_VERSION.' is installed; PHP 8.3 or newer is required.',
        );

        $missing = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $extension): bool => ! extension_loaded($extension),
        ));
        $checks[] = new PreflightCheck(
            'extensions',
            'PHP extensions',
            $missing === [],
            $missing === [] ? 'All required extensions are loaded.' : 'Missing: '.implode(', ', $missing).'.',
        );

        $environmentFile = $this->projectRoot.'/.env';
        $environmentReady = is_dir($this->projectRoot)
            && is_writable($this->projectRoot)
            && (! file_exists($environmentFile) || (is_file($environmentFile) && is_readable($environmentFile)));
        $checks[] = new PreflightCheck(
            'environment',
            'Environment file',
            $environmentReady,
            $environmentReady
                ? 'The project directory can create or replace .env.'
                : 'The project directory must be writable and an existing .env must be readable.',
        );

        $storage = $this->projectRoot.'/storage';
        $storageReady = is_dir($storage)
            ? is_writable($storage)
            : ! file_exists($storage) && is_dir($this->projectRoot) && is_writable($this->projectRoot);
        $checks[] = new PreflightCheck(
            'storage',
            'Installation storage',
            $storageReady,
            $storageReady ? 'The storage directory is writable or can be created.' : 'The storage directory must be writable or creatable.',
        );

        $checks[] = $this->releaseFilesCheck();

        return new InstallationPreflightReport($checks);
    }

    public function run(InstallationEnvironment $environment): InstallationPreflightReport
    {
        $checks = $this->system()->checks;
        $releaseReady = false;
        foreach ($checks as $check) {
            if ($check->code === 'release') {
                $releaseReady = $check->passed;
                break;
            }
        }

        if (! extension_loaded('pdo_mysql')) {
            $checks[] = new PreflightCheck('database', 'Database', false, 'Cannot connect without the pdo_mysql extension.');
        } elseif (! $releaseReady) {
            $checks[] = new PreflightCheck('database', 'Database', false, 'Not checked because the release installation files are invalid.');
        } else {
            try {
                $runtimeEnvironment = array_replace($this->currentEnvironment, [
                    'DB_HOST' => $environment->dbHost,
                    'DB_PORT' => (string) $environment->dbPort,
                    'DB_NAME' => $environment->dbName,
                    'DB_USERNAME' => $environment->dbUsername,
                    'DB_PASSWORD' => $environment->dbPassword,
                ]);
                InstallerFactory::create($this->projectRoot, $runtimeEnvironment)->assertDatabaseCanBeInstalled();
                $checks[] = new PreflightCheck('database', 'Database', true, 'Connection and current schema are suitable for installation.');
            } catch (Throwable $e) {
                $checks[] = new PreflightCheck('database', 'Database', false, $e->getMessage());
            }
        }

        return new InstallationPreflightReport($checks);
    }

    private function releaseFilesCheck(): PreflightCheck
    {
        try {
            $engineRoot = InstallerFactory::engineRoot();
            $snapshot = SchemaSnapshot::load($engineRoot.'/resources/install/manifest.json');
            foreach ($snapshot->migrations as $migration) {
                $file = $engineRoot.'/migrations/'.$migration['file'];
                if (! is_file($file)) {
                    throw new \RuntimeException(sprintf('Release migration "%s" does not exist.', $migration['file']));
                }
                if (hash_file('sha256', $file) !== $migration['checksum']) {
                    throw new \RuntimeException(sprintf('Release migration "%s" checksum does not match its manifest.', $migration['file']));
                }
            }

            return new PreflightCheck('release', 'Release files', true, sprintf('Schema snapshot for %s is valid.', $snapshot->release));
        } catch (Throwable $e) {
            return new PreflightCheck('release', 'Release files', false, $e->getMessage());
        }
    }
}

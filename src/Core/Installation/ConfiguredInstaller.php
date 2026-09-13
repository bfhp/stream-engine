<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use RuntimeException;

final readonly class ConfiguredInstaller
{
    /** @param array<string, mixed> $currentEnvironment */
    public function __construct(
        private string $projectRoot,
        private array $currentEnvironment,
    ) {
    }

    public function install(
        InstallationConfig $config,
        InstallationEnvironment $environment,
    ): InstallationState {
        return $this->withLock(function () use ($config, $environment): InstallationState {
            $states = new InstallationStateStore($this->projectRoot.'/storage/installation.json');
            $existing = $states->load();
            if ($existing?->status === InstallationState::STATUS_READY) {
                return $existing;
            }

            $preflight = (new InstallationPreflight($this->projectRoot, $this->currentEnvironment))
                ->run($environment);
            if (! $preflight->passes()) {
                throw new InstallationPreflightException($preflight);
            }

            $values = $environment->values($this->currentEnvironment);
            $runtimeEnvironment = array_replace($this->currentEnvironment, $values);

            // PdoDatabase connects in the factory, so invalid credentials do
            // not replace a previously usable .env file.
            $installer = InstallerFactory::create($this->projectRoot, $runtimeEnvironment);
            (new EnvironmentFile($this->projectRoot.'/.env'))->update($values);

            return $installer->install($config);
        });
    }

    /** @param callable(): InstallationState $operation */
    private function withLock(callable $operation): InstallationState
    {
        $storage = $this->projectRoot.'/storage';
        if (! is_dir($storage) && ! mkdir($storage, 0775, true) && ! is_dir($storage)) {
            throw new RuntimeException('Could not create the installation storage directory.');
        }

        $lock = fopen($storage.'/configured-installation.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Could not open the configured installation lock.');
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another configured installation process is already running.');
        }

        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

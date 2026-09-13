<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use JsonException;
use RuntimeException;

final readonly class InstallationStateStore
{
    public function __construct(
        private string $stateFile,
    ) {
    }

    public function load(): ?InstallationState
    {
        if (! is_file($this->stateFile)) {
            return null;
        }

        $json = file_get_contents($this->stateFile);
        if ($json === false) {
            throw new RuntimeException(sprintf('Could not read installation state "%s".', $this->stateFile));
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Installation state "%s" is not valid JSON.', $this->stateFile), 0, $e);
        }

        if (! is_array($data)) {
            throw new RuntimeException(sprintf('Installation state "%s" has invalid structure.', $this->stateFile));
        }

        try {
            return InstallationState::fromArray($data);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException(sprintf('Installation state "%s" has invalid structure.', $this->stateFile), 0, $e);
        }
    }

    public function save(InstallationState $state): void
    {
        $this->ensureDirectoryExists();

        try {
            $json = json_encode(
                $state->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            )."\n";
        } catch (JsonException $e) {
            throw new RuntimeException('Could not encode installation state.', 0, $e);
        }

        $temporaryFile = tempnam(dirname($this->stateFile), '.installation-');
        if ($temporaryFile === false) {
            throw new RuntimeException(sprintf('Could not create a temporary installation state beside "%s".', $this->stateFile));
        }

        try {
            if (file_put_contents($temporaryFile, $json, LOCK_EX) === false) {
                throw new RuntimeException(sprintf('Could not write installation state "%s".', $this->stateFile));
            }

            if (! rename($temporaryFile, $this->stateFile)) {
                throw new RuntimeException(sprintf('Could not replace installation state "%s".', $this->stateFile));
            }
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    /**
     * The non-blocking lock is suitable for both CLI and HTTP callers: a web
     * request must report that another installer owns the operation instead
     * of waiting until the request timeout.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function withLock(callable $operation): mixed
    {
        $this->ensureDirectoryExists();
        $lockFile = $this->stateFile.'.lock';
        $lock = fopen($lockFile, 'c');
        if ($lock === false) {
            throw new RuntimeException(sprintf('Could not open installation lock "%s".', $lockFile));
        }

        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another installation process is already running.');
        }

        try {
            return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function ensureDirectoryExists(): void
    {
        $directory = dirname($this->stateFile);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create installation state directory "%s".', $directory));
        }
    }
}

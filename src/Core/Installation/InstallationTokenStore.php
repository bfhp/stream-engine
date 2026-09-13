<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use RuntimeException;

final readonly class InstallationTokenStore
{
    /** @param null|callable(string): void $log */
    public function __construct(
        private string $tokenFile,
        private mixed $log = null,
    ) {
    }

    public function load(): ?string
    {
        $this->ensureDirectoryExists();

        return $this->withLock(fn (): ?string => is_file($this->tokenFile) ? $this->read() : null);
    }

    public function create(): string
    {
        $this->ensureDirectoryExists();

        return $this->withLock(function (): string {
            if (is_file($this->tokenFile)) {
                return $this->read();
            }

            $token = bin2hex(random_bytes(32));
            $temporaryFile = tempnam(dirname($this->tokenFile), '.installation-token-');
            if ($temporaryFile === false) {
                throw new RuntimeException('Could not create the web installation token.');
            }

            try {
                if (! chmod($temporaryFile, 0600) || file_put_contents($temporaryFile, $token."\n", LOCK_EX) === false) {
                    throw new RuntimeException('Could not write the web installation token.');
                }
                if (! rename($temporaryFile, $this->tokenFile)) {
                    throw new RuntimeException('Could not save the web installation token.');
                }
            } finally {
                if (is_file($temporaryFile)) {
                    unlink($temporaryFile);
                }
            }

            $this->writeLog(sprintf(
                'Stream Engine web installation token: %s (also stored in %s)',
                $token,
                $this->tokenFile,
            ));

            return $token;
        });
    }

    public function delete(): void
    {
        if (! is_file($this->tokenFile)) {
            return;
        }

        $this->withLock(function (): void {
            if (is_file($this->tokenFile) && ! unlink($this->tokenFile)) {
                throw new RuntimeException('Could not delete the web installation token.');
            }
        });
    }

    private function read(): string
    {
        $token = file_get_contents($this->tokenFile);
        if ($token === false || preg_match('/^[a-f0-9]{64}$/', trim($token)) !== 1) {
            throw new RuntimeException('The web installation token file is invalid.');
        }

        return trim($token);
    }

    /** @template T @param callable(): T $operation @return T */
    private function withLock(callable $operation): mixed
    {
        $lock = fopen($this->tokenFile.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Could not open the web installation token lock.');
        }

        if (! flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('Could not lock the web installation token.');
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
        $directory = dirname($this->tokenFile);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the web installation token directory.');
        }
    }

    private function writeLog(string $message): void
    {
        if ($this->log !== null) {
            ($this->log)($message);
        } else {
            error_log($message);
        }
    }
}

<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use JsonException;
use RuntimeException;

final readonly class InstallationClaimStore
{
    public const int TTL = 3600;

    public function __construct(
        private string $claimFile,
    ) {
    }

    public function acquire(): ?string
    {
        $this->ensureDirectoryExists();

        return $this->withLock(function (): ?string {
            if ($this->activeHash() !== null) {
                return null;
            }

            $claim = bin2hex(random_bytes(32));
            $this->write(hash('sha256', $claim), time() + self::TTL);

            return $claim;
        });
    }

    public function owns(?string $claim): bool
    {
        if ($claim === null || preg_match('/^[a-f0-9]{64}$/', $claim) !== 1) {
            return false;
        }

        $this->ensureDirectoryExists();

        return $this->withLock(function () use ($claim): bool {
            $hash = $this->activeHash();

            return $hash !== null && hash_equals($hash, hash('sha256', $claim));
        });
    }

    public function delete(): void
    {
        if (! is_file($this->claimFile)) {
            return;
        }

        $this->withLock(function (): void {
            if (is_file($this->claimFile) && ! unlink($this->claimFile)) {
                throw new RuntimeException('Could not delete the browser installation claim.');
            }
        });
    }

    private function activeHash(): ?string
    {
        if (! is_file($this->claimFile)) {
            return null;
        }

        $json = file_get_contents($this->claimFile);
        try {
            $data = $json !== false ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $e) {
            throw new RuntimeException('The browser installation claim file is invalid.', 0, $e);
        }
        if (! is_array($data)
            || ! isset($data['hash'], $data['expires_at'])
            || ! is_string($data['hash'])
            || preg_match('/^[a-f0-9]{64}$/', $data['hash']) !== 1
            || ! is_int($data['expires_at'])) {
            throw new RuntimeException('The browser installation claim file is invalid.');
        }

        if ($data['expires_at'] <= time()) {
            if (! unlink($this->claimFile)) {
                throw new RuntimeException('Could not expire the browser installation claim.');
            }

            return null;
        }

        return $data['hash'];
    }

    private function write(string $hash, int $expiresAt): void
    {
        try {
            $json = json_encode([
                'hash' => $hash,
                'expires_at' => $expiresAt,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $e) {
            throw new RuntimeException('Could not encode the browser installation claim.', 0, $e);
        }

        $temporaryFile = tempnam(dirname($this->claimFile), '.installation-claim-');
        if ($temporaryFile === false) {
            throw new RuntimeException('Could not create the browser installation claim.');
        }

        try {
            if (! chmod($temporaryFile, 0600) || file_put_contents($temporaryFile, $json, LOCK_EX) === false) {
                throw new RuntimeException('Could not write the browser installation claim.');
            }
            if (! rename($temporaryFile, $this->claimFile)) {
                throw new RuntimeException('Could not save the browser installation claim.');
            }
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    /** @template T @param callable(): T $operation @return T */
    private function withLock(callable $operation): mixed
    {
        $lock = fopen($this->claimFile.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Could not open the browser installation claim lock.');
        }
        if (! flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('Could not lock the browser installation claim.');
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
        $directory = dirname($this->claimFile);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the browser installation claim directory.');
        }
    }
}

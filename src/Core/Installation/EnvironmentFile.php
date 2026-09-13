<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use RuntimeException;

final readonly class EnvironmentFile
{
    public function __construct(
        private string $file,
    ) {
    }

    /** @param array<string, string> $values */
    public function update(array $values): void
    {
        foreach ($values as $key => $value) {
            if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1
                || ! mb_check_encoding($value, 'UTF-8')
                || str_contains($value, "\n")
                || str_contains($value, "\r")
                || str_contains($value, "\0")) {
                throw new RuntimeException(sprintf('Environment value "%s" cannot be written safely.', $key));
            }
        }

        $directory = dirname($this->file);
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException(sprintf('Environment directory "%s" is not writable.', $directory));
        }

        $contents = is_file($this->file) ? file_get_contents($this->file) : '';
        if ($contents === false) {
            throw new RuntimeException(sprintf('Could not read environment file "%s".', $this->file));
        }

        $remaining = $values;
        $lines = preg_split('/\R/', $contents) ?: [];
        $result = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=/', $line, $matches) === 1
                && array_key_exists($matches[1], $values)) {
                $key = $matches[1];
                if (array_key_exists($key, $remaining)) {
                    $result[] = $key.'='.self::quote($remaining[$key]);
                    unset($remaining[$key]);
                }

                continue;
            }
            $result[] = $line;
        }

        while ($result !== [] && end($result) === '') {
            array_pop($result);
        }
        if ($result !== [] && $remaining !== []) {
            $result[] = '';
        }
        foreach ($remaining as $key => $value) {
            $result[] = $key.'='.self::quote($value);
        }

        $temporaryFile = tempnam($directory, '.env-');
        if ($temporaryFile === false) {
            throw new RuntimeException('Could not create a temporary environment file.');
        }

        $permissions = is_file($this->file) ? (fileperms($this->file) & 0777) : 0600;
        try {
            if (! chmod($temporaryFile, $permissions)
                || file_put_contents($temporaryFile, implode("\n", $result)."\n", LOCK_EX) === false) {
                throw new RuntimeException('Could not write the environment file.');
            }
            if (! rename($temporaryFile, $this->file)) {
                throw new RuntimeException('Could not replace the environment file.');
            }
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }

    private static function quote(string $value): string
    {
        return '"'.str_replace(
            ['\\', '"', '$'],
            ['\\\\', '\\"', '\\$'],
            $value,
        ).'"';
    }
}

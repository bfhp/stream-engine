<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use JsonException;
use RuntimeException;

final readonly class SchemaSnapshot
{
    /**
     * @param list<array{version:string,file:string,checksum:string}> $migrations
     */
    private function __construct(
        public int $format,
        public string $release,
        public string $schemaFile,
        public string $schemaChecksum,
        public array $migrations,
    ) {
    }

    public static function load(string $manifestFile): self
    {
        $json = file_get_contents($manifestFile);
        if ($json === false) {
            throw new RuntimeException(sprintf('Could not read installation manifest "%s".', $manifestFile));
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Installation manifest "%s" is not valid JSON.', $manifestFile), 0, $e);
        }

        if (! is_array($data)
            || ($data['format'] ?? null) !== 1
            || ! is_string($data['release'] ?? null)
            || trim($data['release']) === ''
            || ! is_string($data['schema'] ?? null)
            || basename($data['schema']) !== $data['schema']
            || ! self::isChecksum($data['schema_checksum'] ?? null)
            || ! is_array($data['migrations'] ?? null)) {
            throw new RuntimeException(sprintf('Installation manifest "%s" has invalid structure.', $manifestFile));
        }

        $migrations = [];
        foreach ($data['migrations'] as $migration) {
            if (! is_array($migration)
                || ! is_string($migration['version'] ?? null)
                || ! preg_match('/^\d{14}_[a-z0-9_]+$/', $migration['version'])
                || ! is_string($migration['file'] ?? null)
                || $migration['file'] !== $migration['version'].'.sql'
                || ! self::isChecksum($migration['checksum'] ?? null)) {
                throw new RuntimeException(sprintf('Installation manifest "%s" has an invalid migration record.', $manifestFile));
            }

            $migrations[] = [
                'version' => $migration['version'],
                'file' => $migration['file'],
                'checksum' => $migration['checksum'],
            ];
        }

        $versions = array_column($migrations, 'version');
        $sortedVersions = $versions;
        sort($sortedVersions);
        if ($versions !== $sortedVersions || count($versions) !== count(array_unique($versions))) {
            throw new RuntimeException(sprintf('Installation manifest "%s" migrations must be unique and sorted.', $manifestFile));
        }

        $schemaFile = dirname($manifestFile).'/'.$data['schema'];
        if (! is_file($schemaFile)) {
            throw new RuntimeException(sprintf('Installation schema "%s" does not exist.', $schemaFile));
        }

        $actualChecksum = hash_file('sha256', $schemaFile);
        if ($actualChecksum !== $data['schema_checksum']) {
            throw new RuntimeException(sprintf('Installation schema "%s" checksum does not match its manifest.', $schemaFile));
        }

        return new self(
            format: 1,
            release: $data['release'],
            schemaFile: $schemaFile,
            schemaChecksum: $data['schema_checksum'],
            migrations: $migrations,
        );
    }

    private static function isChecksum(mixed $checksum): bool
    {
        return is_string($checksum) && preg_match('/^[a-f0-9]{64}$/', $checksum) === 1;
    }
}

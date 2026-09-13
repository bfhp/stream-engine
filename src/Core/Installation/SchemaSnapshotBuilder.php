<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use JsonException;
use PDO;
use RuntimeException;
use StreamEngine\Core\Migrations\MigrationRunner;

final readonly class SchemaSnapshotBuilder
{
    public function __construct(
        private PDO $pdo,
        private MigrationRunner $migrations,
    ) {
    }

    public function build(string $outputDirectory, string $release): SchemaSnapshot
    {
        $release = trim($release);
        if ($release === '') {
            throw new RuntimeException('Snapshot release must not be empty.');
        }

        $schema = $this->dumpSchema();
        $migrationManifest = $this->migrations->manifest();
        if ($migrationManifest === []) {
            throw new RuntimeException('A schema snapshot cannot be built without migrations.');
        }

        $this->ensureDirectoryExists($outputDirectory);
        $schemaFile = rtrim($outputDirectory, '/').'/schema.sql';
        $manifestFile = rtrim($outputDirectory, '/').'/manifest.json';
        $this->writeAtomically($schemaFile, $schema);

        try {
            $manifest = json_encode([
                'format' => 1,
                'release' => $release,
                'schema' => basename($schemaFile),
                'schema_checksum' => hash('sha256', $schema),
                'migrations' => $migrationManifest,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $e) {
            throw new RuntimeException('Could not encode installation manifest.', 0, $e);
        }

        $this->writeAtomically($manifestFile, $manifest);

        return SchemaSnapshot::load($manifestFile);
    }

    private function dumpSchema(): string
    {
        $unsupported = $this->pdo->query(
            "SELECT
                (SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()) AS triggers_count,
                (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()) AS routines_count,
                (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE()) AS events_count"
        );
        $unsupportedCounts = $unsupported?->fetch(PDO::FETCH_ASSOC);
        if (! is_array($unsupportedCounts)) {
            throw new RuntimeException('Could not inspect unsupported objects in the snapshot database.');
        }
        if (array_sum(array_map('intval', $unsupportedCounts)) !== 0) {
            throw new RuntimeException('Schema snapshots do not support database triggers, routines or events yet.');
        }

        $statement = $this->pdo->query(
            "SELECT TABLE_NAME, TABLE_TYPE
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
             ORDER BY TABLE_TYPE = 'VIEW', TABLE_NAME"
        );
        if ($statement === false) {
            throw new RuntimeException('Could not list tables in the snapshot database.');
        }

        $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($objects === []) {
            throw new RuntimeException('Cannot build an installation snapshot from an empty database.');
        }

        $definitions = [];
        foreach ($objects as $object) {
            $name = (string) ($object['TABLE_NAME'] ?? '');
            $type = (string) ($object['TABLE_TYPE'] ?? '');
            if ($name === '' || ! in_array($type, ['BASE TABLE', 'VIEW'], true)) {
                throw new RuntimeException('The snapshot database returned an invalid schema object.');
            }

            $quotedName = '`'.str_replace('`', '``', $name).'`';
            $create = $this->pdo->query(($type === 'VIEW' ? 'SHOW CREATE VIEW ' : 'SHOW CREATE TABLE ').$quotedName);
            if ($create === false) {
                throw new RuntimeException(sprintf('Could not inspect schema object "%s".', $name));
            }

            $row = $create->fetch(PDO::FETCH_ASSOC);
            $definition = is_array($row)
                ? ($row[$type === 'VIEW' ? 'Create View' : 'Create Table'] ?? null)
                : null;
            if (! is_string($definition) || $definition === '') {
                throw new RuntimeException(sprintf('Schema object "%s" has no CREATE statement.', $name));
            }

            // A schema-only snapshot must not depend on how many rows happened
            // to exist in the database used to prepare the release.
            $definition = preg_replace('/\sAUTO_INCREMENT=\d+\b/', '', $definition) ?? $definition;
            // View definers are deployment-specific and often do not exist on
            // the server where the release is installed.
            $definition = preg_replace('/\bDEFINER=`[^`]*`@`[^`]*`\s*/', '', $definition) ?? $definition;
            $definitions[] = $definition.';';
        }

        return implode("\n", [
            '-- Generated installation schema. Do not edit by hand.',
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS = 0;',
            '',
            implode("\n\n", $definitions),
            '',
            'SET FOREIGN_KEY_CHECKS = 1;',
            '',
        ]);
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create snapshot directory "%s".', $directory));
        }
    }

    private function writeAtomically(string $file, string $contents): void
    {
        $temporaryFile = tempnam(dirname($file), '.snapshot-');
        if ($temporaryFile === false) {
            throw new RuntimeException(sprintf('Could not create a temporary file beside "%s".', $file));
        }

        try {
            if (file_put_contents($temporaryFile, $contents, LOCK_EX) === false || ! rename($temporaryFile, $file)) {
                throw new RuntimeException(sprintf('Could not write snapshot file "%s".', $file));
            }
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }
    }
}

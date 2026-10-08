<?php

declare(strict_types=1);

namespace StreamEngine\Core\Migrations;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonException;
use PDOException;
use RuntimeException;
use StreamEngine\Core\PdoDatabase;

final readonly class MigrationRunner
{
    /** @var list<string> The first directory is used when creating migrations. */
    private array $migrationsPaths;

    /** @param string|list<string> $migrationsPath */
    public function __construct(
        private ?PdoDatabase $db,
        string|array $migrationsPath,
        private string $stateFile,
    ) {
        $this->migrationsPaths = array_values((array) $migrationsPath);
        if ($this->migrationsPaths === []) {
            throw new RuntimeException('At least one migration directory is required.');
        }
    }

    /**
     * @return array<int, array{version: string, file: string}>
     */
    public function pending(): array
    {
        $state = $this->loadState();
        $applied = $this->appliedByVersion($state);
        $pending = [];

        foreach ($this->migrationFiles() as $version => $file) {
            if (!isset($applied[$version])) {
                $pending[] = [
                    'version' => $version,
                    'file' => basename($file),
                ];
            }
        }

        return $pending;
    }

    /**
     * @return array<int, array{version: string, file: string, checksum: string, applied_at: string}>
     */
    public function applied(): array
    {
        return $this->loadState()['applied'];
    }

    /**
     * Describes the migration files that a release schema snapshot replaces.
     *
     * @return list<array{version:string,file:string,checksum:string}>
     */
    public function manifest(): array
    {
        $manifest = [];
        foreach ($this->migrationFiles() as $version => $file) {
            $manifest[] = [
                'version' => $version,
                'file' => basename($file),
                'checksum' => $this->checksum($file),
            ];
        }

        return $manifest;
    }

    /**
     * @return array<int, array{version: string, file: string, checksum: string, applied_at: string}>
     * @throws DateMalformedStringException
     */
    public function migrate(): array
    {
        if ($this->db === null) {
            throw new RuntimeException('Database connection is required to run migrations.');
        }

        $lock = $this->acquireLock();

        try {
            $state = $this->loadState();
            $applied = $this->appliedByVersion($state);
            $this->assertAppliedFilesWereNotChanged($applied);

            $appliedNow = [];

            foreach ($this->migrationFiles() as $version => $file) {
                if (isset($applied[$version])) {
                    continue;
                }

                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new RuntimeException(sprintf('Could not read migration "%s".', $file));
                }

                foreach (SqlScript::statements($sql) as $statement) {
                    try {
                        $result = $this->db->getPdo()->exec($statement);
                    } catch (PDOException $e) {
                        throw new RuntimeException(sprintf('Could not execute migration "%s".', basename($file)), 0, $e);
                    }

                    if ($result === false) {
                        throw new RuntimeException(sprintf('Could not execute migration "%s".', basename($file)));
                    }
                }

                $record = [
                    'version' => $version,
                    'file' => basename($file),
                    'checksum' => $this->checksum($file),
                    'applied_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
                ];

                $state['applied'][] = $record;
                $applied[$version] = $record;
                $this->saveState($state);

                $appliedNow[] = $record;
            }

            return $appliedNow;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function create(string $name): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $name) ?? '', '_'));
        if ($slug === '') {
            throw new RuntimeException('Migration name must contain at least one letter or digit.');
        }

        $this->ensureMigrationsDirectoryExists();

        $filename = date('YmdHis').'_'.$slug.'.sql';
        $path = rtrim($this->migrationsPaths[0], '/').'/'.$filename;

        if (file_put_contents($path, "-- Write migration SQL here.\n") === false) {
            throw new RuntimeException(sprintf('Could not create migration "%s".', $path));
        }

        return $path;
    }

    /**
     * @return array<int, array{version: string, file: string, checksum: string, applied_at: string}>
     * @throws DateMalformedStringException
     */
    public function baselineThrough(string $version): array
    {
        $state = $this->loadState();
        $applied = $this->appliedByVersion($state);
        $baselined = [];
        $found = false;

        foreach ($this->migrationFiles() as $migrationVersion => $file) {
            if ($migrationVersion > $version) {
                continue;
            }

            $found = $found || $migrationVersion === $version;

            if (isset($applied[$migrationVersion])) {
                continue;
            }

            $record = [
                'version' => $migrationVersion,
                'file' => basename($file),
                'checksum' => $this->checksum($file),
                'applied_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            ];

            $state['applied'][] = $record;
            $applied[$migrationVersion] = $record;
            $baselined[] = $record;
        }

        if (! $found) {
            throw new RuntimeException(sprintf('Migration "%s" was not found.', $version));
        }

        if ($baselined !== []) {
            $this->saveState($state);
        }

        return $baselined;
    }

    /**
     * Records the migrations incorporated into an installation schema
     * snapshot without executing their SQL. Migrations not listed in the
     * manifest remain pending and run normally.
     *
     * @param list<array{version:string,file:string,checksum:string}> $snapshotMigrations
     * @return array<int, array{version: string, file: string, checksum: string, applied_at: string}>
     * @throws DateMalformedStringException
     */
    public function baselineSnapshot(array $snapshotMigrations): array
    {
        if ($snapshotMigrations === []) {
            throw new RuntimeException('Installation snapshot migration manifest must not be empty.');
        }

        $lock = $this->acquireLock();

        try {
            $files = $this->migrationFiles();

            foreach ($snapshotMigrations as $record) {
                $file = $files[$record['version']] ?? null;
                if ($file === null
                    || basename($file) !== $record['file']
                    || $this->checksum($file) !== $record['checksum']) {
                    throw new RuntimeException(sprintf(
                        'Installation snapshot migration "%s" does not match the available migration file.',
                        $record['file'],
                    ));
                }
            }

            $state = $this->loadState();
            $applied = $this->appliedByVersion($state);
            $this->assertAppliedFilesWereNotChanged($applied);
            $baselined = [];

            foreach ($snapshotMigrations as $record) {
                if (isset($applied[$record['version']])) {
                    if ($applied[$record['version']]['file'] !== $record['file']
                        || $applied[$record['version']]['checksum'] !== $record['checksum']) {
                        throw new RuntimeException(sprintf(
                            'Applied migration "%s" conflicts with the installation snapshot.',
                            $record['file'],
                        ));
                    }

                    continue;
                }

                $appliedRecord = [
                    ...$record,
                    'applied_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
                ];
                $state['applied'][] = $appliedRecord;
                $applied[$record['version']] = $appliedRecord;
                $baselined[] = $appliedRecord;
            }

            if ($baselined !== []) {
                $this->saveState($state);
            }

            return $baselined;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array{applied: array<int, array{version: string, file: string, checksum: string, applied_at: string}>}
     */
    private function loadState(): array
    {
        if (!is_file($this->stateFile)) {
            return ['applied' => []];
        }

        $json = file_get_contents($this->stateFile);
        if ($json === false) {
            throw new RuntimeException(sprintf('Could not read migration state "%s".', $this->stateFile));
        }

        try {
            $state = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(sprintf('Migration state "%s" is not valid JSON.', $this->stateFile), 0, $e);
        }

        if (!is_array($state) || !isset($state['applied']) || !is_array($state['applied'])) {
            throw new RuntimeException(sprintf('Migration state "%s" has invalid structure.', $this->stateFile));
        }

        return $state;
    }

    /**
     * @param array{applied: array<int, array{version: string, file: string, checksum: string, applied_at: string}>} $state
     */
    private function saveState(array $state): void
    {
        $this->ensureStateDirectoryExists();

        try {
            $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Could not encode migration state.', 0, $e);
        }

        if (file_put_contents($this->stateFile, $json."\n", LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Could not write migration state "%s".', $this->stateFile));
        }
    }

    /**
     * @return resource
     */
    private function acquireLock()
    {
        $this->ensureStateDirectoryExists();

        $lock = fopen($this->stateFile.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException(sprintf('Could not open migration lock "%s".', $this->stateFile.'.lock'));
        }

        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('Could not acquire migration lock.');
        }

        return $lock;
    }

    private function ensureStateDirectoryExists(): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create migration state directory "%s".', $dir));
        }
    }

    /**
     * @return array<string, string>
     */
    private function migrationFiles(): array
    {
        $files = [];
        foreach ($this->migrationsPaths as $directory) {
            foreach (glob(rtrim($directory, '/').'/*.sql') ?: [] as $file) {
                $files[realpath($file)] = realpath($file);
            }
        }

        $migrations = [];
        foreach ($files as $file) {
            $basename = basename($file);
            if (!preg_match('/^(\d{14}_[a-z0-9_]+)\.sql$/', $basename, $matches)) {
                throw new RuntimeException(sprintf('Migration file "%s" must match YYYYMMDDHHMMSS_name.sql.', $basename));
            }

            $version = $matches[1];
            if (isset($migrations[$version])) {
                throw new RuntimeException(sprintf('Duplicate migration version "%s".', $version));
            }

            $migrations[$version] = $file;
        }

        ksort($migrations);

        return $migrations;
    }

    private function ensureMigrationsDirectoryExists(): void
    {
        if (!is_dir($this->migrationsPaths[0]) && !mkdir($this->migrationsPaths[0], 0775, true) && !is_dir($this->migrationsPaths[0])) {
            throw new RuntimeException(sprintf('Could not create migrations directory "%s".', $this->migrationsPaths[0]));
        }
    }

    /**
     * @param array{applied: array<int, array{version: string, file: string, checksum: string, applied_at: string}>} $state
     * @return array<string, array{version: string, file: string, checksum: string, applied_at: string}>
     */
    private function appliedByVersion(array $state): array
    {
        $applied = [];

        foreach ($state['applied'] as $record) {
            foreach (['version', 'file', 'checksum', 'applied_at'] as $key) {
                if (!isset($record[$key]) || !is_string($record[$key])) {
                    throw new RuntimeException(sprintf('Migration state "%s" has invalid record structure.', $this->stateFile));
                }
            }

            $applied[$record['version']] = $record;
        }

        return $applied;
    }

    /**
     * @param array<string, array{version: string, file: string, checksum: string, applied_at: string}> $applied
     */
    private function assertAppliedFilesWereNotChanged(array $applied): void
    {
        $files = $this->migrationFiles();
        foreach ($applied as $record) {
            $file = $files[$record['version']] ?? null;
            if ($file === null) {
                continue;
            }

            $checksum = $this->checksum($file);
            if ($checksum !== $record['checksum']) {
                throw new RuntimeException(sprintf(
                    'Applied migration "%s" was changed after execution (expected checksum %s, got %s).',
                    $record['file'],
                    $record['checksum'],
                    $checksum
                ));
            }
        }
    }

    private function checksum(string $file): string
    {
        $checksum = hash_file('sha256', $file);
        if ($checksum === false) {
            throw new RuntimeException(sprintf('Could not calculate checksum for "%s".', $file));
        }

        return $checksum;
    }

}

<?php

declare(strict_types=1);

use StreamEngine\Core\Config;
use StreamEngine\Core\Installation\ReleaseVersion;
use StreamEngine\Core\Installation\SchemaSnapshotBuilder;
use StreamEngine\Core\Migrations\MigrationRunner;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\StreamEngine;

require __DIR__.'/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__.'/..')->safeLoad();

try {
    $options = getopt('', ['release:', 'output:']);
    if ($options === false) {
        throw new RuntimeException('Could not parse command-line options.');
    }

    $root = dirname(__DIR__);
    $engineRoot = dirname((new ReflectionClass(StreamEngine::class))->getFileName(), 2);
    $release = trim((string) ($options['release'] ?? ReleaseVersion::fromPackageJson($root.'/package.json')));
    $output = (string) ($options['output'] ?? $root.'/resources/install');
    $db = new PdoDatabase(new Config($_ENV), logQueries: false);
    $tableCount = $db->getPdo()->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
    )?->fetchColumn();
    if ($tableCount === false || $tableCount === null) {
        throw new RuntimeException('Could not inspect the schema-build database.');
    }
    if ((int) $tableCount !== 0) {
        throw new RuntimeException('Schema snapshots must be built in an empty disposable database.');
    }

    $migrationState = sys_get_temp_dir().'/stream_engine_schema_build_'.bin2hex(random_bytes(8)).'.json';
    $runner = new MigrationRunner(
        $db,
        [$root.'/migrations', $engineRoot.'/migrations'],
        $migrationState,
    );

    try {
        $runner->migrate();
        $snapshot = (new SchemaSnapshotBuilder($db->getPdo(), $runner))->build($output, $release);
    } finally {
        @unlink($migrationState);
        @unlink($migrationState.'.lock');
    }
    echo sprintf(
        "Built installation schema for %s with %d baselined migrations.\n%s\n",
        $snapshot->release,
        count($snapshot->migrations),
        $snapshot->schemaFile,
    );
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}

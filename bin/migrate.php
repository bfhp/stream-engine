<?php

declare(strict_types=1);

use JetBrains\PhpStorm\NoReturn;
use StreamEngine\Core\Config;
use StreamEngine\Core\Migrations\MigrationRunner;
use StreamEngine\StreamEngine;
use StreamEngine\Core\PdoDatabase;

require __DIR__.'/../vendor/autoload.php';

$root = dirname(__DIR__);
try {
    $arguments = array_slice($argv, 1);
    foreach ($arguments as $argument) {
        if (str_starts_with($argument, '--')) {
            throw new RuntimeException("Unknown option: $argument");
        }
    }
    $command = array_shift($arguments) ?? 'migrate';
    match ($command) {
        'migrate' => migrate(runner($root, true)),
        'check' => check(runner($root)),
        'status' => status(runner($root)),
        'make' => make(runner($root), $arguments),
        'baseline' => baseline(runner($root), $arguments),
        default => usage(1),
    };
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}

function runner(string $root, bool $connect = false): MigrationRunner
{
    $engineRoot = dirname((new ReflectionClass(StreamEngine::class))->getFileName(), 2);

    if ($connect) {
        Dotenv\Dotenv::createImmutable($root)->load();
    }

    return new MigrationRunner(
        $connect ? new PdoDatabase(new Config($_ENV)) : null,
        [$root.'/migrations', $engineRoot.'/migrations'],
        $root.'/storage/migrations.json',
    );
}

function check(MigrationRunner $runner): void
{
    $pending = $runner->pending();
    if ($pending === []) {
        return;
    }

    fwrite(STDERR, sprintf(
        "WARNING: %d pending database migration%s.\n",
        count($pending),
        count($pending) === 1 ? '' : 's',
    ));

    foreach ($pending as $migration) {
        fwrite(STDERR, '  - '.$migration['file']."\n");
    }

    fwrite(STDERR, "Run \"composer migrate\" before serving the updated application.\n");
}

/**
 * @throws DateMalformedStringException
 */
function migrate(MigrationRunner $runner): void
{
    $applied = $runner->migrate();

    if ($applied === []) {
        echo "Nothing to migrate.\n";

        return;
    }

    foreach ($applied as $migration) {
        echo 'Applied '.$migration['file']."\n";
    }
}

function status(MigrationRunner $runner): void
{
    $applied = $runner->applied();
    $pending = $runner->pending();

    echo 'Applied: '.count($applied)."\n";
    echo 'Pending: '.count($pending)."\n";

    foreach ($pending as $migration) {
        echo '  - '.$migration['file']."\n";
    }
}

/**
 * @param array<int, string> $arguments
 */
function make(MigrationRunner $runner, array $arguments): void
{
    $name = trim(implode(' ', $arguments));
    if ($name === '') {
        throw new RuntimeException('Usage: php bin/migrate.php make <name>');
    }

    echo 'Created '.$runner->create($name)."\n";
}

/**
 * @param array<int, string> $arguments
 * @throws DateMalformedStringException
 */
function baseline(MigrationRunner $runner, array $arguments): void
{
    $version = trim($arguments[0] ?? '');
    if ($version === '') {
        throw new RuntimeException('Usage: php bin/migrate.php baseline <version>');
    }

    $baselined = $runner->baselineThrough($version);

    if ($baselined === []) {
        echo "Nothing to baseline.\n";

        return;
    }

    foreach ($baselined as $migration) {
        echo 'Baselined '.$migration['file']."\n";
    }
}

#[NoReturn]
function usage(int $exitCode = 0): void
{
    echo "Usage:\n";
    echo "  php bin/migrate.php migrate\n";
    echo "  php bin/migrate.php check\n";
    echo "  php bin/migrate.php status\n";
    echo "  php bin/migrate.php make <name>\n";
    echo "  php bin/migrate.php baseline <version>\n";

    exit($exitCode);
}

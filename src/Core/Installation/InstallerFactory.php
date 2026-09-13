<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use ReflectionClass;
use StreamEngine\Core\Config;
use StreamEngine\Core\Migrations\MigrationRunner;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\StreamEngine;

final class InstallerFactory
{
    /** @param array<string, mixed> $environment */
    public static function create(string $projectRoot, array $environment): Installer
    {
        $engineRoot = dirname((new ReflectionClass(StreamEngine::class))->getFileName(), 2);
        $db = new PdoDatabase(new Config($environment), logQueries: false);

        return new Installer(
            db: $db,
            migrations: new MigrationRunner(
                $db,
                [$projectRoot.'/migrations', $engineRoot.'/migrations'],
                $projectRoot.'/storage/migrations.json',
            ),
            states: new InstallationStateStore($projectRoot.'/storage/installation.json'),
            snapshot: SchemaSnapshot::load($engineRoot.'/resources/install/manifest.json'),
            languagesDirectory: $engineRoot.'/src/Lang',
        );
    }

    public static function engineRoot(): string
    {
        return dirname((new ReflectionClass(StreamEngine::class))->getFileName(), 2);
    }
}

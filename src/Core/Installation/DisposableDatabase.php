<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use PDO;
use PDOException;
use RuntimeException;
use StreamEngine\Core\Config;
use StreamEngine\Core\PdoDatabase;

final class DisposableDatabase
{
    private bool $dropped = false;

    private function __construct(
        private readonly PDO $server,
        private readonly Config $config,
        private readonly string $name,
    ) {
    }

    public static function create(Config $config): self
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;charset=utf8mb4',
            $config->dbHost(),
            $config->dbPort(),
        );

        try {
            $server = new PDO(
                $dsn,
                $config->dbUserName(),
                $config->dbPassword(),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
            $name = 'stream_engine_schema_build_'.bin2hex(random_bytes(8));
            $server->exec(sprintf(
                'CREATE DATABASE %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                self::quoteIdentifier($name),
            ));
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Could not create the disposable schema-build database. '
                .'Grant the database user privileges for stream_engine_schema_build_% databases.',
                0,
                $e,
            );
        }

        return new self($server, $config, $name);
    }

    public function connect(): PdoDatabase
    {
        return new PdoDatabase(new Config([
            'APP_ENV' => $this->config->appEnvironment(),
            'DB_HOST' => $this->config->dbHost(),
            'DB_PORT' => $this->config->dbPort(),
            'DB_NAME' => $this->name,
            'DB_USERNAME' => $this->config->dbUserName(),
            'DB_PASSWORD' => $this->config->dbPassword(),
        ]), logQueries: false);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function drop(): void
    {
        if ($this->dropped) {
            return;
        }

        try {
            $this->server->exec('DROP DATABASE '.self::quoteIdentifier($this->name));
            $this->dropped = true;
        } catch (PDOException $e) {
            throw new RuntimeException(sprintf(
                'Could not drop disposable schema-build database "%s".',
                $this->name,
            ), 0, $e);
        }
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}

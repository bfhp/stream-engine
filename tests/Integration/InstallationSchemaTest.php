<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use StreamEngine\Core\Installation\ConfiguredInstaller;
use StreamEngine\Core\Installation\InstallationConfig;
use StreamEngine\Core\Installation\InstallationEnvironment;
use StreamEngine\Core\Installation\InstallationPreflightException;
use StreamEngine\Core\Installation\InstallationState;
use StreamEngine\Core\Installation\InstallationStateStore;
use StreamEngine\Core\Installation\Installer;
use StreamEngine\Core\Installation\SchemaSnapshot;
use StreamEngine\Core\Migrations\MigrationRunner;
use StreamEngine\Core\Migrations\SqlScript;
use StreamEngine\Core\PdoDatabase;

final class InstallationSchemaTest extends TestCase
{
    public function testReleaseSnapshotInstallsIntoAnEmptyMariaDb(): void
    {
        $root = dirname(__DIR__, 2);
        $this->withEmptyDatabase(function (PDO $databasePdo) use ($root): void {
            $snapshot = SchemaSnapshot::load($root.'/resources/install/manifest.json');
            $this->executeScript($databasePdo, $snapshot->schemaFile);

            $tables = $this->tables($databasePdo);
            self::assertContains('users', $tables);
            self::assertContains('pages', $tables);
            self::assertContains('settings', $tables);
            self::assertNotContains('user_social_accounts', $tables);

            $stateFile = sys_get_temp_dir().'/stream_engine_install_baseline_'.bin2hex(random_bytes(8)).'.json';
            try {
                $runner = new MigrationRunner(null, $root.'/migrations', $stateFile);
                self::assertCount(count($snapshot->migrations), $runner->baselineSnapshot($snapshot->migrations));
                self::assertSame(
                    array_values(array_diff(
                        array_column($runner->manifest(), 'version'),
                        array_column($snapshot->migrations, 'version'),
                    )),
                    array_column($runner->pending(), 'version'),
                );
            } finally {
                @unlink($stateFile);
                @unlink($stateFile.'.lock');
            }
        });
    }

    public function testSquashedInitialMigrationCreatesOnlyDeclaredSchemaAndReferenceData(): void
    {
        $root = dirname(__DIR__, 2);
        $this->withEmptyDatabase(function (PDO $databasePdo) use ($root): void {
            $this->executeScript($databasePdo, $root.'/migrations/20260912000000_initial.sql');

            $tables = $this->tables($databasePdo);
            self::assertContains('users', $tables);
            self::assertNotContains('user_social_accounts', $tables);
            self::assertSame(
                ['subscriber', 'member', 'moderator', 'owner'],
                $databasePdo->query('SELECT name FROM membership_roles ORDER BY role_level')->fetchAll(PDO::FETCH_COLUMN),
            );
        });
    }

    public function testInstallerCompletesAUsableInstallationFromTheReleaseSnapshot(): void
    {
        $root = dirname(__DIR__, 2);
        $this->withEmptyDatabase(function (PDO $pdo) use ($root): void {
            $db = $this->database($pdo);
            $stateFile = sys_get_temp_dir().'/stream_engine_install_state_'.bin2hex(random_bytes(8)).'.json';
            $migrationFile = sys_get_temp_dir().'/stream_engine_install_migrations_'.bin2hex(random_bytes(8)).'.json';
            $siteMigrations = sys_get_temp_dir().'/stream_engine_site_migrations_'.bin2hex(random_bytes(8));
            mkdir($siteMigrations);
            file_put_contents(
                $siteMigrations.'/20990101000000_create_site_extension_probe.sql',
                'CREATE TABLE site_extension_probe (id INT PRIMARY KEY);',
            );
            try {
                $snapshot = SchemaSnapshot::load($root.'/resources/install/manifest.json');
                $migrations = new MigrationRunner($db, [$root.'/migrations', $siteMigrations], $migrationFile);
                $state = (new Installer(
                    $db,
                    $migrations,
                    new InstallationStateStore($stateFile),
                    $snapshot,
                    $root.'/src/Lang',
                ))->install(new InstallationConfig(
                    siteName: 'Installed site',
                    locale: 'ru',
                    adminEmail: 'owner@example.com',
                    adminName: 'Owner',
                    adminPassword: 'a-secure-password',
                ));

                self::assertSame(InstallationState::STATUS_READY, $state->status);
                self::assertSame([], $migrations->pending());
                self::assertContains('site_extension_probe', $this->tables($pdo));
                self::assertSame('Installed site', $pdo->query(
                    "SELECT setting_value FROM settings WHERE setting_key = 'site_name'"
                )->fetchColumn());
                self::assertSame(['system', 'admin'], $pdo->query(
                    'SELECT username FROM users ORDER BY id'
                )->fetchAll(PDO::FETCH_COLUMN));
                $adminHash = $pdo->query(
                    "SELECT password_hash FROM users WHERE email = 'owner@example.com'"
                )->fetchColumn();
                self::assertIsString($adminHash);
                self::assertTrue(password_verify('a-secure-password', $adminHash));
                self::assertSame(
                    ['article.show', 'article', 'Welcome to Stream Engine'],
                    $pdo->query('SELECT action, feed_type, page_name FROM pages WHERE id = 1')
                        ->fetch(PDO::FETCH_NUM),
                );
                self::assertSame(
                    [
                        'article',
                        1,
                        'welcome-to-stream-engine',
                        'Welcome to Stream Engine',
                        'public',
                    ],
                    $pdo->query(
                        'SELECT type, owner_id, slug, title, visibility
                         FROM feeds WHERE id = (SELECT feed_id FROM pages WHERE id = 1)'
                    )->fetch(PDO::FETCH_NUM),
                );
                self::assertStringContainsString(
                    'Your new website is installed and ready to use.',
                    (string) $pdo->query(
                        'SELECT content FROM feeds WHERE id = (SELECT feed_id FROM pages WHERE id = 1)'
                    )->fetchColumn(),
                );
                self::assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM membership_roles')->fetchColumn());
            } finally {
                foreach ([$stateFile, $stateFile.'.lock', $migrationFile, $migrationFile.'.lock'] as $file) {
                    @unlink($file);
                }
                @unlink($siteMigrations.'/20990101000000_create_site_extension_probe.sql');
                @rmdir($siteMigrations);
            }
        });
    }

    public function testConfiguredInstallerCreatesEnvironmentFileAndInstalls(): void
    {
        $root = dirname(__DIR__, 2);
        $this->withEmptyDatabase(function (PDO $pdo, array $connection) use ($root): void {
            $projectRoot = sys_get_temp_dir().'/stream_engine_configured_install_'.bin2hex(random_bytes(8));
            mkdir($projectRoot);
            mkdir($projectRoot.'/migrations');
            mkdir($projectRoot.'/storage');

            try {
                $state = (new ConfiguredInstaller($projectRoot, []))->install(
                    new InstallationConfig(
                        'Configured site',
                        'ru',
                        'configured@example.com',
                        'Configured Owner',
                        'a-secure-password',
                    ),
                    new InstallationEnvironment(
                        'prod',
                        'https://configured.example.com',
                        $connection['host'],
                        $connection['port'],
                        $connection['database'],
                        $connection['username'],
                        $connection['password'],
                    ),
                );

                self::assertSame(InstallationState::STATUS_READY, $state->status);
                self::assertSame('Configured site', $pdo->query(
                    "SELECT setting_value FROM settings WHERE setting_key = 'site_name'"
                )->fetchColumn());
                $environment = \Dotenv\Dotenv::parse((string) file_get_contents($projectRoot.'/.env'));
                self::assertSame('prod', $environment['APP_ENV']);
                self::assertSame('https://configured.example.com', $environment['SITE_URL']);
                self::assertSame($connection['database'], $environment['DB_NAME']);
                self::assertSame($connection['password'], $environment['DB_PASSWORD']);
                self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $environment['APP_SECRET']);
                self::assertSame(0600, fileperms($projectRoot.'/.env') & 0777);
            } finally {
                foreach (glob($projectRoot.'/storage/*') ?: [] as $file) {
                    @unlink($file);
                }
                @unlink($projectRoot.'/.env');
                @rmdir($projectRoot.'/storage');
                @rmdir($projectRoot.'/migrations');
                @rmdir($projectRoot);
            }
        });
    }

    public function testConfiguredInstallerDoesNotWriteEnvironmentForAnIncompatibleDatabase(): void
    {
        $this->withEmptyDatabase(function (PDO $pdo, array $connection): void {
            $projectRoot = sys_get_temp_dir().'/stream_engine_preflight_install_'.bin2hex(random_bytes(8));
            mkdir($projectRoot);
            mkdir($projectRoot.'/migrations');
            mkdir($projectRoot.'/storage');
            $pdo->exec('CREATE TABLE unrelated_table (id INT PRIMARY KEY)');

            try {
                try {
                    (new ConfiguredInstaller($projectRoot, []))->install(
                        new InstallationConfig('Site', 'ru', 'owner@example.com', 'Owner', 'a-secure-password'),
                        new InstallationEnvironment(
                            'prod',
                            'https://example.com',
                            $connection['host'],
                            $connection['port'],
                            $connection['database'],
                            $connection['username'],
                            $connection['password'],
                        ),
                    );
                    self::fail('The incompatible database should fail preflight.');
                } catch (InstallationPreflightException $e) {
                    self::assertStringContainsString(
                        'The configured database is neither empty nor an exact installation schema.',
                        $e->getMessage(),
                    );
                }

                self::assertFileDoesNotExist($projectRoot.'/.env');
                self::assertFileDoesNotExist($projectRoot.'/storage/installation.json');
                self::assertSame(['unrelated_table'], $this->tables($pdo));
            } finally {
                foreach (glob($projectRoot.'/storage/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($projectRoot.'/storage');
                @rmdir($projectRoot.'/migrations');
                @rmdir($projectRoot);
            }
        });
    }

    /** @param callable(PDO, array{host:string,port:int,database:string,username:string,password:string}): void $test */
    private function withEmptyDatabase(callable $test): void
    {
        $dsn = getenv('INSTALL_TEST_DB_DSN');
        if (! is_string($dsn) || $dsn === '') {
            self::markTestSkipped('Set INSTALL_TEST_DB_DSN to run the destructive, isolated installation schema test.');
        }

        $user = (string) (getenv('INSTALL_TEST_DB_USERNAME') ?: 'root');
        $password = (string) (getenv('INSTALL_TEST_DB_PASSWORD') ?: '');
        $server = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database = 'stream_engine_install_test_'.bin2hex(random_bytes(6));
        $quotedDatabase = '`'.$database.'`';
        $server->exec("CREATE DATABASE {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        try {
            preg_match('/(?:^|[;:])host=([^;]+)/', $dsn, $hostMatch);
            preg_match('/(?:^|;)port=([0-9]+)/', $dsn, $portMatch);
            $test(
                new PDO(
                    $dsn.';dbname='.$database,
                    $user,
                    $password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                ),
                [
                    'host' => $hostMatch[1] ?? '127.0.0.1',
                    'port' => isset($portMatch[1]) ? (int) $portMatch[1] : 3306,
                    'database' => $database,
                    'username' => $user,
                    'password' => $password,
                ],
            );
        } finally {
            $server->exec("DROP DATABASE {$quotedDatabase}");
        }
    }

    private function executeScript(PDO $pdo, string $file): void
    {
        foreach (SqlScript::statements((string) file_get_contents($file)) as $statement) {
            $pdo->exec($statement);
        }
    }

    private function database(PDO $pdo): PdoDatabase
    {
        $reflection = new ReflectionClass(PdoDatabase::class);
        /** @var PdoDatabase $db */
        $db = $reflection->newInstanceWithoutConstructor();
        (new ReflectionProperty(PdoDatabase::class, 'pdo'))->setValue($db, $pdo);
        (new ReflectionProperty(PdoDatabase::class, 'queryLog'))->setValue($db, []);

        return $db;
    }

    /** @return list<string> */
    private function tables(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_COLUMN);
    }
}

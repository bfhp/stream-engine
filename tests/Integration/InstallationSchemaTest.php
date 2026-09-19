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
            self::assertContains('feeds_parent_type_slug_unique', $this->indexes($databasePdo, 'feeds'));

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

    public function testFeedSlugScopeMigrationResolvesCollisionsAndEnforcesScopes(): void
    {
        $root = dirname(__DIR__, 2);
        $this->withEmptyDatabase(function (PDO $pdo) use ($root): void {
            $this->executeScript($pdo, $root.'/migrations/20260912000000_initial.sql');
            $pdo->exec(
                "INSERT INTO users (email, nick, username, password_hash, created_at, is_active)
                 VALUES ('slug-owner@example.com', 'Slug owner', 'slug-owner', 'hash', 1, 1)"
            );
            $ownerId = (int) $pdo->lastInsertId();

            $parentA = $this->insertFeed($pdo, $ownerId, 'container', 'parent-a');
            $parentB = $this->insertFeed($pdo, $ownerId, 'container', 'parent-b');

            $this->insertFeed($pdo, $ownerId, 'article', 'shared-article', $parentA);
            $renamedArticleId = $this->insertFeed($pdo, $ownerId, 'article', 'shared-article', $parentA);
            $this->insertFeed($pdo, $ownerId, 'community', 'shared-community');
            $renamedCommunityId = $this->insertFeed($pdo, $ownerId, 'community', 'shared-community');

            $this->executeScript($pdo, $root.'/migrations/20260915000000_enforce_feed_slug_scopes.sql');

            self::assertSame(
                'shared-article--feed-'.$renamedArticleId,
                $pdo->query('SELECT slug FROM feeds WHERE id = '.$renamedArticleId)->fetchColumn(),
            );
            self::assertSame(
                'shared-community--feed-'.$renamedCommunityId,
                $pdo->query('SELECT slug FROM feeds WHERE id = '.$renamedCommunityId)->fetchColumn(),
            );

            $this->assertFeedInsertRejected(
                fn (): int => $this->insertFeed($pdo, $ownerId, 'article', 'shared-article', $parentA),
            );
            $this->assertFeedInsertRejected(
                fn (): int => $this->insertFeed($pdo, $ownerId, 'community', 'shared-community'),
            );

            self::assertGreaterThan(0, $this->insertFeed($pdo, $ownerId, 'article', 'shared-article', $parentB));
            self::assertGreaterThan(0, $this->insertFeed($pdo, $ownerId, 'article-section', 'shared-article', $parentA));
            self::assertGreaterThan(0, $this->insertFeed($pdo, $ownerId, 'community', 'shared-community', $parentA));
            self::assertGreaterThan(0, $this->insertFeed($pdo, $ownerId, 'article', null, $parentA));
            self::assertGreaterThan(0, $this->insertFeed($pdo, $ownerId, 'article', null, $parentA));

            self::assertGreaterThan(0, $this->insertFeed($pdo, $ownerId, 'forum', 'shared-forum', $parentA));
            self::assertGreaterThan(0, $this->insertFeed($pdo, $ownerId, 'forum', 'shared-forum', $parentB));
        });
    }

    public function testShowActionMigrationMakesTheResolverModeExplicit(): void
    {
        $root = dirname(__DIR__, 2);
        $this->withEmptyDatabase(function (PDO $pdo) use ($root): void {
            $this->executeScript($pdo, $root.'/migrations/20260912000000_initial.sql');
            $actions = ['article.show', 'community.show', 'user.post-show', 'community.post-show'];

            $insert = $pdo->prepare(
                'INSERT INTO pages
                 (pattern, action, feed_type, list_feed_type, term_vocabulary, feed_id, updated)
                 VALUES (?, ?, ?, ?, ?, ?, 1)'
            );
            foreach ($actions as $action) {
                $insert->execute(['fixed', $action, 'legacy-type', 'legacy-list', 'legacy-vocabulary', 42]);
                $insert->execute(['{slug}', $action, 'expected-type', 'legacy-list', 'legacy-vocabulary', null]);
            }

            $this->executeScript($pdo, $root.'/migrations/20260919000000_split_page_show_actions.sql');

            self::assertSame(
                [
                    ['article.show-id', null, null, null, 42],
                    ['article.show-slug', 'expected-type', null, null, null],
                    ['community.show-id', null, null, null, 42],
                    ['community.show-slug', 'expected-type', null, null, null],
                    ['user.post-show-id', null, null, null, 42],
                    ['user.post-show-slug', 'expected-type', null, null, null],
                    ['community.post-show-id', null, null, null, 42],
                    ['community.post-show-slug', 'expected-type', null, null, null],
                ],
                $pdo->query(
                    'SELECT action, feed_type, list_feed_type, term_vocabulary, feed_id FROM pages ORDER BY id'
                )->fetchAll(PDO::FETCH_NUM),
            );
        });
    }

    public function testRootPageRepairMigrationBreaksAParentCycle(): void
    {
        $root = dirname(__DIR__, 2);
        $this->withEmptyDatabase(function (PDO $pdo) use ($root): void {
            $this->executeScript($pdo, $root.'/migrations/20260912000000_initial.sql');
            $pdo->exec(
                "INSERT INTO pages (id, parent, pattern, action, updated)
                 VALUES (1, NULL, '', 'article.show-id', 1),
                        (2, 1, 'contacts', 'feedback.show', 1)"
            );
            $pdo->exec("UPDATE pages SET parent = 2, pattern = 'home' WHERE id = 1");

            $this->executeScript($pdo, $root.'/migrations/20260919010000_repair_root_page_parent.sql');

            self::assertSame(
                [[1, null, ''], [2, 1, 'contacts']],
                $pdo->query('SELECT id, parent, pattern FROM pages ORDER BY id')->fetchAll(PDO::FETCH_NUM),
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
                    ['article.show-id', null, 'Welcome to Stream Engine'],
                    $pdo->query('SELECT action, feed_type, page_name FROM pages WHERE id = 1')
                        ->fetch(PDO::FETCH_NUM),
                );
                self::assertSame(
                    [1, 'profile', 'profile.show', 'Ваш профиль', 'noindex', 'authenticated'],
                    $pdo->query(
                        "SELECT parent, pattern, action, page_name, changefreq, access_rule
                         FROM pages WHERE action = 'profile.show'"
                    )->fetch(PDO::FETCH_NUM),
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
                $messages = require $root.'/src/Lang/ru.php';
                self::assertSame(
                    [
                        ['user', 'internal', '/admin/', null, $messages['view.nav.administration'], 'admin', 100],
                        ['user', 'divider', null, null, null, 'admin', 110],
                        ['user', 'action', null, 'logout', $messages['view.nav.logout'], 'authenticated', 120],
                    ],
                    $pdo->query(
                        'SELECT menu_group, type, url, action, label, access_rule, sort_order
                         FROM menu ORDER BY sort_order'
                    )->fetchAll(PDO::FETCH_NUM),
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

    /** @return list<string> */
    private function indexes(PDO $pdo, string $table): array
    {
        $statement = $pdo->prepare(
            'SELECT DISTINCT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $statement->execute([$table]);

        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function insertFeed(
        PDO $pdo,
        int $ownerId,
        string $type,
        ?string $slug,
        ?int $parentId = null,
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO feeds (parent_id, type, owner_id, slug, title, content, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, 1)'
        );
        $statement->execute([$parentId, $type, $ownerId, $slug, $type, '']);

        return (int) $pdo->lastInsertId();
    }

    /** @param callable(): int $insert */
    private function assertFeedInsertRejected(callable $insert): void
    {
        try {
            $insert();
            self::fail('The database should reject a duplicate feed slug in the declared scope.');
        } catch (\PDOException $exception) {
            self::assertSame('23000', $exception->getCode());
        }
    }
}

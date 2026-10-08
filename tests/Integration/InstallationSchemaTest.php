<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use StreamEngine\Core\Config;
use StreamEngine\Core\Installation\ConfiguredInstaller;
use StreamEngine\Core\Installation\DisposableDatabase;
use StreamEngine\Core\Installation\InstallationConfig;
use StreamEngine\Core\Installation\InstallationEnvironment;
use StreamEngine\Core\Installation\InstallationPreflightException;
use StreamEngine\Core\Installation\InstallationState;
use StreamEngine\Core\Installation\InstallationStateStore;
use StreamEngine\Core\Installation\Installer;
use StreamEngine\Core\Installation\SchemaSnapshot;
use StreamEngine\Core\Migrations\MigrationRunner;
use StreamEngine\Core\Migrations\SqlScript;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\UploadRepository;

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

    public function testBlogFriendRoleMigrationMaterializesOnlyReciprocalMemberships(): void
    {
        $root = dirname(__DIR__, 2);
        $this->withEmptyDatabase(function (PDO $pdo) use ($root): void {
            $this->executeScript($pdo, $root.'/migrations/20260912000000_initial.sql');

            $pdo->exec("
                INSERT INTO users (id, email, password_hash, created_at) VALUES
                    (10, 'a@example.test', 'x', 1),
                    (20, 'b@example.test', 'x', 1),
                    (30, 'c@example.test', 'x', 1),
                    (40, 'd@example.test', 'x', 1)
            ");
            $pdo->exec("
                INSERT INTO feeds (id, owner_id, type, created_at, updated_at) VALUES
                    (101, 10, 'blog', 1, 1),
                    (102, 20, 'blog', 1, 1),
                    (103, 30, 'blog', 1, 1),
                    (200, 40, 'community', 1, 1)
            ");
            $pdo->exec("
                INSERT INTO memberships (container_id, user_id, joined_at, membership_role_id) VALUES
                    (101, 20, 1, 2),
                    (102, 10, 1, 2),
                    (101, 30, 1, 2),
                    (200, 20, 1, 2),
                    (200, 30, 1, 1)
            ");

            $this->executeScript($pdo, $root.'/migrations/20261008010000_materialize_blog_friend_roles.sql');

            $rows = $pdo->query("
                SELECT CONCAT(m.container_id, ':', m.user_id) AS relationship, mr.name AS role_name
                FROM memberships m
                JOIN membership_roles mr ON mr.id = m.membership_role_id
                ORDER BY m.container_id, m.user_id
            ")->fetchAll(PDO::FETCH_KEY_PAIR);

            self::assertSame([
                '101:20' => 'member',
                '101:30' => 'subscriber',
                '102:10' => 'member',
                '200:20' => 'member',
                '200:30' => 'subscriber',
            ], $rows);
        });
    }

    public function testUploadPurposeMigrationAddsTheForumCleanupIndex(): void
    {
        $root = dirname(__DIR__, 2);
        $this->withEmptyDatabase(function (PDO $pdo) use ($root): void {
            $this->executeScript($pdo, $root.'/migrations/20260912000000_initial.sql');
            $this->executeScript($pdo, $root.'/migrations/20261008020000_track_upload_purpose.sql');

            $columns = $pdo->query('SHOW COLUMNS FROM uploads')->fetchAll(PDO::FETCH_COLUMN);
            self::assertContains('purpose', $columns);
            self::assertContains('uploads_purpose_created_at_index', $this->indexes($pdo, 'uploads'));

            $pdo->exec("
                INSERT INTO users (id, email, password_hash, created_at, is_active)
                VALUES (10, 'upload-owner@example.test', 'x', 1, 1)
            ");
            $pdo->exec("
                INSERT INTO feeds (id, owner_id, type, created_at, updated_at)
                VALUES (100, 10, 'forum-post', 1, 1)
            ");
            $pdo->exec("
                INSERT INTO uploads
                    (id, user_id, path, mime, size, original_name, purpose, created_at)
                VALUES
                    (41, 10, '10/orphan.pdf', 'application/pdf', 1, 'orphan.pdf', 'forum-attachment', 1),
                    (42, 10, '10/attached.pdf', 'application/pdf', 1, 'attached.pdf', 'forum-attachment', 1),
                    (43, 10, '10/draft.pdf', 'application/pdf', 1, 'draft.pdf', 'forum-attachment', 1)
            ");
            $pdo->exec("
                INSERT INTO feed_metadata (feed_id, name, content, created_at, updated_at)
                VALUES (100, 'attachment_upload_ids', '[42]', 1, 1)
            ");

            $uploads = new UploadRepository($this->database($pdo));
            self::assertSame([41, 43], $uploads->findOrphanedForumAttachmentIds(100, 100));

            // A form submission refreshes the draft before cleanup claims it.
            self::assertSame(43, $uploads->reserveOwnedForumAttachment(43, 10)?->id);
            self::assertSame([41], $uploads->findOrphanedForumAttachmentIds(100, 100));

            // The referenced row is never claimable even though it is old.
            self::assertNull($uploads->claimOrphanedForumAttachment(42, 100));
            self::assertSame(41, $uploads->claimOrphanedForumAttachment(41, 100)?->id);
        });
    }

    public function testCronObservabilityMigrationAddsTaskDiagnosticsAndSchedulerHeartbeat(): void
    {
        $root = dirname(__DIR__, 2);
        $this->withEmptyDatabase(function (PDO $pdo) use ($root): void {
            $this->executeScript($pdo, $root.'/migrations/20260912000000_initial.sql');
            $this->executeScript($pdo, $root.'/migrations/20261007000000_add_cron_observability.sql');

            $columns = $pdo->query('SHOW COLUMNS FROM cron_runs')->fetchAll(PDO::FETCH_COLUMN);
            self::assertContains('last_started_at', $columns);
            self::assertContains('last_finished_at', $columns);
            self::assertContains('last_status', $columns);
            self::assertContains('last_duration_ms', $columns);
            self::assertContains('last_error', $columns);
            self::assertContains('consecutive_failures', $columns);
            self::assertContains('cron_scheduler_state', $this->tables($pdo));
        });
    }

    public function testCronModeMigrationSeedsTheDatabaseSetting(): void
    {
        $root = dirname(__DIR__, 2);
        $this->withEmptyDatabase(function (PDO $pdo) use ($root): void {
            $this->executeScript($pdo, $root.'/migrations/20260912000000_initial.sql');
            $this->executeScript($pdo, $root.'/migrations/20261007010000_move_cron_mode_to_settings.sql');

            self::assertSame('os', $pdo->query(
                "SELECT setting_value FROM settings WHERE setting_key = 'cron.mode'"
            )->fetchColumn());
        });
    }

    public function testCronTaskControlsMigrationAddsEnablementAndTrigger(): void
    {
        $root = dirname(__DIR__, 2);
        $this->withEmptyDatabase(function (PDO $pdo) use ($root): void {
            $this->executeScript($pdo, $root.'/migrations/20260912000000_initial.sql');
            $this->executeScript($pdo, $root.'/migrations/20261007000000_add_cron_observability.sql');
            $this->executeScript($pdo, $root.'/migrations/20261007020000_add_cron_task_controls.sql');

            $columns = $pdo->query('SHOW COLUMNS FROM cron_runs')->fetchAll(PDO::FETCH_COLUMN);
            self::assertContains('is_enabled', $columns);
            self::assertContains('last_trigger', $columns);
        });
    }

    public function testCronManualRequestMigrationAddsQueueState(): void
    {
        $root = dirname(__DIR__, 2);
        $this->withEmptyDatabase(function (PDO $pdo) use ($root): void {
            $this->executeScript($pdo, $root.'/migrations/20260912000000_initial.sql');
            $this->executeScript($pdo, $root.'/migrations/20261007000000_add_cron_observability.sql');
            $this->executeScript($pdo, $root.'/migrations/20261007020000_add_cron_task_controls.sql');
            $this->executeScript($pdo, $root.'/migrations/20261007030000_add_cron_manual_request_state.sql');

            $columns = $pdo->query('SHOW COLUMNS FROM cron_runs')->fetchAll(PDO::FETCH_COLUMN);
            self::assertContains('manual_requested_at', $columns);
        });
    }

    public function testCronHistoryMigrationRenamesTaskStateAndCreatesHistorySchema(): void
    {
        $root = dirname(__DIR__, 2);
        $this->withEmptyDatabase(function (PDO $pdo) use ($root): void {
            $this->executeScript($pdo, $root.'/migrations/20260912000000_initial.sql');
            $this->executeScript($pdo, $root.'/migrations/20261007000000_add_cron_observability.sql');
            $this->executeScript($pdo, $root.'/migrations/20261007020000_add_cron_task_controls.sql');
            $this->executeScript($pdo, $root.'/migrations/20261007030000_add_cron_manual_request_state.sql');
            $this->executeScript($pdo, $root.'/migrations/20261007040000_split_cron_tasks_and_run_history.sql');

            self::assertNotContains('cron_runs', $this->tables($pdo));
            self::assertContains('cron_tasks', $this->tables($pdo));
            self::assertContains('cron_run_history', $this->tables($pdo));
            $taskColumns = $pdo->query('SHOW COLUMNS FROM cron_tasks')->fetchAll(PDO::FETCH_COLUMN);
            self::assertContains('active_run_id', $taskColumns);
            $historyColumns = $pdo->query('SHOW COLUMNS FROM cron_run_history')->fetchAll(PDO::FETCH_COLUMN);
            self::assertContains('requested_by_user_id', $historyColumns);
            self::assertContains('duration_ms', $historyColumns);
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
                self::assertSame('os', $pdo->query(
                    "SELECT setting_value FROM settings WHERE setting_key = 'cron.mode'"
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
                    ['feedContentWidth' => 'contained'],
                    json_decode((string) $pdo->query(
                        'SELECT settings FROM pages WHERE id = 1'
                    )->fetchColumn(), true),
                );
                self::assertSame(
                    [1, 'profile', 'profile.show', 'Ваш профиль', 'noindex', 'authenticated'],
                    $pdo->query(
                        "SELECT parent, pattern, action, page_name, changefreq, access_rule
                         FROM pages WHERE action = 'profile.show'"
                    )->fetch(PDO::FETCH_NUM),
                );
                $persistedActions = $pdo->query('SELECT action FROM pages ORDER BY id')
                    ->fetchAll(PDO::FETCH_COLUMN);
                $publicActions = array_column((new ModuleRegistry())->pageActions(), 'action');
                self::assertSame(
                    [],
                    array_values(array_diff($persistedActions, $publicActions)),
                    'A fresh installation must not persist module-owned runtime page actions.',
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
                self::assertStringNotContainsString(
                    'class="container"',
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
        preg_match('/(?:^|[;:])host=([^;]+)/', $dsn, $hostMatch);
        preg_match('/(?:^|;)port=([0-9]+)/', $dsn, $portMatch);
        $host = $hostMatch[1] ?? '127.0.0.1';
        $port = isset($portMatch[1]) ? (int) $portMatch[1] : 3306;
        $temporaryDatabase = DisposableDatabase::create(new Config([
            'APP_ENV' => 'test',
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_USERNAME' => $user,
            'DB_PASSWORD' => $password,
        ]));

        try {
            $database = $temporaryDatabase->connect();
            $test(
                $database->getPdo(),
                [
                    'host' => $host,
                    'port' => $port,
                    'database' => $temporaryDatabase->name(),
                    'username' => $user,
                    'password' => $password,
                ],
            );
        } finally {
            $temporaryDatabase->drop();
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

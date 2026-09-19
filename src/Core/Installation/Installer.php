<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use PDO;
use RuntimeException;
use StreamEngine\Core\Migrations\MigrationRunner;
use StreamEngine\Core\Migrations\SqlScript;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\User;
use StreamEngine\Service\AccessService;
use Throwable;

final readonly class Installer
{
    public const string SYSTEM_EMAIL = 'system@localhost.invalid';

    private const string WELCOME_SLUG = 'welcome-to-stream-engine';
    private const string WELCOME_TITLE = 'Welcome to Stream Engine';
    private const string WELCOME_DESCRIPTION = 'Your new Stream Engine website is ready.';
    private const string WELCOME_CONTENT = <<<'HTML'
        <div class="container">
            <h1>Welcome to Stream Engine</h1>
            <p>Your new website is installed and ready to use.</p>
            <h2>What to do next</h2>
            <ul>
                <li>Sign in with the administrator account you created during installation.</li>
                <li>Review the site settings and customize the website for your project.</li>
                <li>Edit or replace this welcome article with your own home page content.</li>
            </ul>
            <p>Enjoy building with Stream Engine.</p>
        </div>
        HTML;

    private const array REQUIRED_TABLES = [
        'feeds',
        'membership_roles',
        'pages',
        'settings',
        'users',
    ];

    public function __construct(
        private PdoDatabase $db,
        private MigrationRunner $migrations,
        private InstallationStateStore $states,
        private SchemaSnapshot $snapshot,
        private string $languagesDirectory,
    ) {
    }

    public function install(InstallationConfig $config): InstallationState
    {
        return $this->states->withLock(function () use ($config): InstallationState {
            $previous = $this->states->load();
            if ($previous?->status === InstallationState::STATUS_READY) {
                return $previous;
            }
            if ($previous !== null && $previous->release !== $this->snapshot->release) {
                throw new RuntimeException(sprintf(
                    'Installation state belongs to release "%s", but the snapshot is "%s".',
                    $previous->release,
                    $this->snapshot->release,
                ));
            }

            $state = $previous ?? InstallationState::start($this->snapshot->release);
            $this->states->save($state);

            try {
                $this->assertLocaleExists($config->locale);
                $state = $state->advanceTo('schema');
                $this->states->save($state);

                if ($this->schemaObjects() === []) {
                    $this->applySnapshot();
                } else {
                    $this->assertExistingSchemaCanBeAdopted($previous);
                }

                $state = $state->advanceTo('baseline');
                $this->states->save($state);
                $this->migrations->baselineSnapshot($this->snapshot->migrations);
                $this->migrations->migrate();

                $state = $state->advanceTo('seed');
                $this->states->save($state);
                $this->seed($config);

                $state = $state->advanceTo('verify');
                $this->states->save($state);
                $this->verify($config);

                $state = $state->complete();
                $this->states->save($state);

                return $state;
            } catch (Throwable $e) {
                $this->states->save($state->fail($state->stage.'_failed'));
                throw $e;
            }
        });
    }

    public function assertDatabaseCanBeInstalled(): void
    {
        $previous = $this->states->load();
        if ($previous?->status === InstallationState::STATUS_READY) {
            return;
        }
        if ($previous !== null && $previous->release !== $this->snapshot->release) {
            throw new RuntimeException(sprintf(
                'Installation state belongs to release "%s", but the snapshot is "%s".',
                $previous->release,
                $this->snapshot->release,
            ));
        }

        if ($this->schemaObjects() !== []) {
            $this->assertExistingSchemaCanBeAdopted($previous);
        }
    }

    private function assertLocaleExists(string $locale): void
    {
        if (! is_file(rtrim($this->languagesDirectory, '/').'/'.$locale.'.php')) {
            throw new RuntimeException(sprintf('Locale "%s" is not available.', $locale));
        }
    }

    private function applySnapshot(): void
    {
        $sql = file_get_contents($this->snapshot->schemaFile);
        if ($sql === false) {
            throw new RuntimeException(sprintf('Could not read installation schema "%s".', $this->snapshot->schemaFile));
        }

        foreach (SqlScript::statements($sql) as $statement) {
            $result = $this->db->getPdo()->exec($statement);
            if ($result === false) {
                throw new RuntimeException('Could not execute the installation schema.');
            }
        }
    }

    private function assertExistingSchemaCanBeAdopted(?InstallationState $previous): void
    {
        $actual = $this->schemaObjects();
        $expected = $this->snapshotSchemaObjects();
        if ($actual !== $expected) {
            throw new RuntimeException('The configured database is neither empty nor an exact installation schema.');
        }

        // With no installation state, only a fully baselined schema may be
        // adopted. This is the explicit bridge for a schema prepared with the
        // migration command before the installer was run.
        if ($previous === null && $this->migrations->pending() !== []) {
            throw new RuntimeException('The existing schema has no matching migration baseline.');
        }
    }

    /** @return list<string> */
    private function schemaObjects(): array
    {
        $statement = $this->db->getPdo()->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
        );
        if ($statement === false) {
            throw new RuntimeException('Could not inspect the configured database.');
        }

        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    /** @return list<string> */
    private function snapshotSchemaObjects(): array
    {
        $sql = file_get_contents($this->snapshot->schemaFile);
        if ($sql === false) {
            throw new RuntimeException(sprintf('Could not read installation schema "%s".', $this->snapshot->schemaFile));
        }

        preg_match_all('/CREATE\s+(?:TABLE|VIEW)\s+`([^`]+)`/i', $sql, $matches);
        $objects = array_values(array_unique(array_map('strval', $matches[1] ?? [])));
        sort($objects);

        return $objects;
    }

    private function seed(InstallationConfig $config): void
    {
        $pdo = $this->db->getPdo();
        $pdo->beginTransaction();

        try {
            $this->seedMembershipRoles($pdo);
            $this->seedSystemUser($pdo);
            $this->seedAdministrator($pdo, $config);
            $this->seedSettings($pdo, $config);
            $welcomeFeedId = $this->seedWelcomeArticle($pdo);
            $this->seedRootPage($pdo, $welcomeFeedId);
            $this->seedProfilePage($pdo, $config->locale);
            $this->seedUserMenu($pdo, $config->locale);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function seedMembershipRoles(PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO membership_roles (name, role_level) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE role_level = VALUES(role_level)'
        );
        foreach (['subscriber' => 0, 'member' => 1, 'moderator' => 2, 'owner' => 3] as $name => $level) {
            $statement->execute([$name, $level]);
        }
    }

    private function seedSystemUser(PDO $pdo): void
    {
        $statement = $pdo->prepare('SELECT email, username, role FROM users WHERE id = ?');
        $statement->execute([User::SYSTEM_USER_ID]);
        $existing = $statement->fetch(PDO::FETCH_ASSOC);

        if (is_array($existing)) {
            if ($existing['email'] !== self::SYSTEM_EMAIL
                || $existing['username'] !== 'system'
                || $existing['role'] !== AccessService::ROLE_USER) {
                throw new RuntimeException('User id 1 is already occupied by a non-system account.');
            }

            return;
        }

        $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $insert = $pdo->prepare(
            'INSERT INTO users (id, email, nick, username, password_hash, role, created_at, is_active)
             VALUES (?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP(), 1)'
        );
        $insert->execute([
            User::SYSTEM_USER_ID,
            self::SYSTEM_EMAIL,
            'System',
            'system',
            $passwordHash,
            AccessService::ROLE_USER,
        ]);
    }

    private function seedAdministrator(PDO $pdo, InstallationConfig $config): void
    {
        $find = $pdo->prepare('SELECT id, role, is_active FROM users WHERE email = ?');
        $find->execute([$config->adminEmail]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            if ($existing['role'] !== AccessService::ROLE_ADMIN || (int) $existing['is_active'] !== 1) {
                throw new RuntimeException('Administrator email is already used by a non-administrator account.');
            }

            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO users (email, nick, username, password_hash, role, created_at, is_active)
             VALUES (?, ?, ?, ?, ?, UNIX_TIMESTAMP(), 1)'
        );
        $insert->execute([
            $config->adminEmail,
            $config->adminName,
            'admin',
            password_hash($config->adminPassword, PASSWORD_DEFAULT),
            AccessService::ROLE_ADMIN,
        ]);
    }

    private function seedSettings(PDO $pdo, InstallationConfig $config): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at)
             VALUES (?, ?, UNIX_TIMESTAMP())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = UNIX_TIMESTAMP()'
        );
        $statement->execute(['site_name', $config->siteName]);
        $statement->execute(['locale', $config->locale]);
    }

    private function seedWelcomeArticle(PDO $pdo): int
    {
        $find = $pdo->prepare('SELECT id, type FROM feeds WHERE owner_id = ? AND slug = ?');
        $find->execute([User::SYSTEM_USER_ID, self::WELCOME_SLUG]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            if ($existing['type'] !== 'article') {
                throw new RuntimeException('The welcome article slug is already used by another feed type.');
            }

            return (int) $existing['id'];
        }

        $insert = $pdo->prepare(
            'INSERT INTO feeds (
                parent_id, type, owner_id, slug, title, description, content,
                visibility, created_at, updated_at
             ) VALUES (NULL, \'article\', ?, ?, ?, ?, ?, \'public\', UNIX_TIMESTAMP(), UNIX_TIMESTAMP())'
        );
        $insert->execute([
            User::SYSTEM_USER_ID,
            self::WELCOME_SLUG,
            self::WELCOME_TITLE,
            self::WELCOME_DESCRIPTION,
            self::WELCOME_CONTENT,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function seedRootPage(PDO $pdo, int $welcomeFeedId): void
    {
        $existing = $pdo->query('SELECT parent, pattern, action, feed_id FROM pages WHERE id = 1')
            ->fetch(PDO::FETCH_ASSOC);
        if (is_array($existing)) {
            if ($existing['parent'] !== null || $existing['pattern'] !== '') {
                throw new RuntimeException('Page id 1 is already occupied by a non-root page.');
            }

            if ($existing['action'] === 'article.show' && (int) $existing['feed_id'] === $welcomeFeedId) {
                return;
            }

            // Allows an installation that reached the old seed stage but not
            // verification to resume after the default home page changed.
            if ($existing['action'] === 'users.list' && $existing['feed_id'] === null) {
                $update = $pdo->prepare(
                    "UPDATE pages SET
                        action = 'article.show', page_name = ?, settings = '{}',
                        feed_type = 'article', feed_id = ?, changefreq = 'monthly', updated = UNIX_TIMESTAMP()
                     WHERE id = 1"
                );
                $update->execute([self::WELCOME_TITLE, $welcomeFeedId]);

                return;
            }

            throw new RuntimeException('Page id 1 is already occupied by another root page.');
        }

        $statement = $pdo->prepare(
            "INSERT INTO pages (
                id, parent, pattern, action, page_name, settings, feed_type,
                feed_id, changefreq, updated, access_rule
             ) VALUES (1, NULL, '', 'article.show', ?, '{}', 'article', ?, 'monthly', UNIX_TIMESTAMP(), 'public')"
        );
        $statement->execute([self::WELCOME_TITLE, $welcomeFeedId]);
    }

    private function seedUserMenu(PDO $pdo, string $locale): void
    {
        $messages = require rtrim($this->languagesDirectory, '/').'/'.$locale.'.php';
        if (! is_array($messages)) {
            throw new RuntimeException(sprintf('Locale "%s" does not contain a valid language catalog.', $locale));
        }

        $administrationLabel = $messages['view.nav.administration'] ?? null;
        $logoutLabel = $messages['view.nav.logout'] ?? null;
        if (! is_string($administrationLabel) || ! is_string($logoutLabel)) {
            throw new RuntimeException(sprintf('Locale "%s" does not define the user menu labels.', $locale));
        }

        $find = $pdo->prepare(
            'SELECT id FROM menu
             WHERE parent IS NULL AND menu_group = ? AND type = ?
               AND page_id IS NULL AND url <=> ? AND action <=> ?
               AND label <=> ? AND access_rule = ?
             LIMIT 1'
        );
        $insert = $pdo->prepare(
            'INSERT INTO menu (
                parent, menu_group, type, page_id, url,
                action, label, access_rule, sort_order
             ) VALUES (NULL, ?, ?, NULL, ?, ?, ?, ?, ?)'
        );

        foreach ([
            ['user', 'internal', '/admin/', null, $administrationLabel, AccessService::ACCESS_ADMIN, 100],
            ['user', 'divider', null, null, null, AccessService::ACCESS_ADMIN, 110],
            ['user', 'action', null, 'logout', $logoutLabel, AccessService::ACCESS_AUTHENTICATED, 120],
        ] as $item) {
            $find->execute(array_slice($item, 0, 6));
            if ($find->fetchColumn() !== false) {
                continue;
            }

            $insert->execute($item);
        }
    }

    private function seedProfilePage(PDO $pdo, string $locale): void
    {
        $messages = require rtrim($this->languagesDirectory, '/').'/'.$locale.'.php';
        $pageName = is_array($messages) ? ($messages['profile.title'] ?? null) : null;
        if (! is_string($pageName)) {
            throw new RuntimeException(sprintf('Locale "%s" does not define the profile page name.', $locale));
        }

        $find = $pdo->query(
            "SELECT parent, pattern, action, page_name, changefreq, access_rule
             FROM pages
             WHERE action = 'profile.show' OR (parent = 1 AND pattern = 'profile')"
        );
        $existing = $find->fetchAll(PDO::FETCH_ASSOC);
        if ($existing !== []) {
            if (count($existing) === 1
                && (int) $existing[0]['parent'] === 1
                && $existing[0]['pattern'] === 'profile'
                && $existing[0]['action'] === 'profile.show'
                && $existing[0]['page_name'] === $pageName
                && $existing[0]['changefreq'] === 'noindex'
                && $existing[0]['access_rule'] === AccessService::ACCESS_AUTHENTICATED) {
                return;
            }

            throw new RuntimeException('The default profile page route is already occupied.');
        }

        $statement = $pdo->prepare(
            "INSERT INTO pages (
                parent, pattern, action, page_name, settings, changefreq, updated, access_rule
             ) VALUES (1, 'profile', 'profile.show', ?, '{}', 'noindex', UNIX_TIMESTAMP(), ?)"
        );
        $statement->execute([$pageName, AccessService::ACCESS_AUTHENTICATED]);
    }

    private function verify(InstallationConfig $config): void
    {
        $tables = $this->schemaObjects();
        foreach (self::REQUIRED_TABLES as $table) {
            if (! in_array($table, $tables, true)) {
                throw new RuntimeException(sprintf('Required table "%s" is missing after installation.', $table));
            }
        }
        if ($this->migrations->pending() !== []) {
            throw new RuntimeException('Pending migrations remain after installation.');
        }

        $pdo = $this->db->getPdo();
        $system = $pdo->query('SELECT COUNT(*) FROM users WHERE id = 1 AND username = \'system\'')->fetchColumn();
        $admin = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND role = 'admin' AND is_active = 1");
        $admin->execute([$config->adminEmail]);
        $rootPage = $pdo->query(
            "SELECT COUNT(*)
             FROM pages p
             INNER JOIN feeds f ON f.id = p.feed_id
             WHERE p.id = 1 AND p.parent IS NULL AND p.pattern = ''
               AND p.action = 'article.show' AND p.feed_type = 'article'
               AND f.type = 'article' AND f.owner_id = 1 AND f.visibility = 'public'"
        )->fetchColumn();
        $profilePage = $pdo->query(
            "SELECT COUNT(*) FROM pages
             WHERE parent = 1 AND pattern = 'profile' AND action = 'profile.show'
               AND changefreq = 'noindex' AND access_rule = 'authenticated'"
        )->fetchColumn();
        if ((int) $system !== 1
            || (int) $admin->fetchColumn() !== 1
            || (int) $rootPage !== 1
            || (int) $profilePage !== 1) {
            throw new RuntimeException('Required initial data is missing after installation.');
        }
    }
}

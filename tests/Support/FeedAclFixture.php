<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use StreamEngine\Domain\User;
use StreamEngine\Service\AccessService;

/**
 * An in-memory SQLite database that exercises every rule of the row-level
 * feed ACL, for comparing a query with LegacyFeedAcl.
 *
 * Rather than a few hand-picked rows it crosses the dimensions the rules
 * depend on: publication type x visibility x kind of container x owner;
 * comments, replies and replies to replies on those publications; and
 * gallery photos in every processing state, in public and private albums of
 * blogs, communities and a context galleries do not belong to, with
 * comments of their own. Creation times repeat on purpose, so every ordered
 * comparison also checks the id tie-breaker.
 */
final class FeedAclFixture
{
    public const int GUEST = 0;
    public const int OWNER = 2;
    public const int CONTAINER_MODERATOR = 3;
    public const int MEMBER = 4;
    public const int LOW_ROLE = 5;
    public const int OUTSIDER = 9;
    public const int GLOBAL_MODERATOR = 20;
    public const int ADMIN = 21;

    /** Feed ids of the named rows the coverage test checks. */
    public array $named = [];

    private int $nextId = 100;

    public function __construct(public readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, User>
     */
    public static function users(): array
    {
        return [
            'guest' => new User(id: self::GUEST, email: ''),
            'owner' => new User(id: self::OWNER, email: 'owner@example.test'),
            'container moderator' => new User(id: self::CONTAINER_MODERATOR, email: 'cm@example.test'),
            'member' => new User(id: self::MEMBER, email: 'member@example.test'),
            'low role' => new User(id: self::LOW_ROLE, email: 'low@example.test'),
            'outsider' => new User(id: self::OUTSIDER, email: 'outsider@example.test'),
            'global moderator' => new User(id: self::GLOBAL_MODERATOR, email: 'gm@example.test', role: AccessService::ROLE_MODERATOR),
            'admin' => new User(id: self::ADMIN, email: 'admin@example.test', role: AccessService::ROLE_ADMIN),
        ];
    }

    public static function create(): self
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(<<<'SQL'
            CREATE TABLE users (id INTEGER PRIMARY KEY, nick TEXT, avatar_url TEXT, username TEXT);
            CREATE TABLE feeds (
                id INTEGER PRIMARY KEY,
                parent_id INTEGER,
                owner_id INTEGER NOT NULL,
                type TEXT NOT NULL,
                slug TEXT,
                title TEXT,
                content TEXT,
                description TEXT,
                image_url TEXT,
                container_id INTEGER,
                container_type TEXT,
                visibility TEXT NOT NULL,
                position INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER,
                updated_at INTEGER,
                rating_sum INTEGER NOT NULL DEFAULT 0,
                rating_count INTEGER NOT NULL DEFAULT 0,
                views INTEGER NOT NULL DEFAULT 0,
                rating_avg REAL NOT NULL DEFAULT 0
            );
            CREATE TABLE feed_metadata (feed_id INTEGER, name TEXT, content TEXT, PRIMARY KEY (feed_id, name));
            CREATE TABLE membership_roles (id INTEGER PRIMARY KEY, role_level INTEGER NOT NULL);
            CREATE TABLE memberships (
                container_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                membership_role_id INTEGER NOT NULL,
                PRIMARY KEY (container_id, user_id)
            );
            SQL);

        $fixture = new self($pdo);
        $fixture->populate();

        return $fixture;
    }

    /**
     * Every feed type in the fixture.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_column($this->pdo->query('SELECT DISTINCT type FROM feeds ORDER BY type')->fetchAll(), 'type');
    }

    private function populate(): void
    {
        foreach ([self::OWNER, self::CONTAINER_MODERATOR, self::MEMBER, self::LOW_ROLE, self::OUTSIDER, self::GLOBAL_MODERATOR, self::ADMIN] as $id) {
            $this->pdo->exec("INSERT INTO users (id, nick, username) VALUES ($id, 'user$id', 'user$id')");
        }
        // Role levels 0 (e.g. pending), 1 (member) and 2 (moderator).
        $this->pdo->exec('INSERT INTO membership_roles (id, role_level) VALUES (1, 0), (2, 1), (3, 2)');

        // Containers are their own container, as communities and forums are.
        $containers = [
            'open' => $this->container('public', self::OUTSIDER),
            'members' => $this->container('members', self::OUTSIDER),
            'private' => $this->container('private', self::OUTSIDER),
            'owner private' => $this->container('private', self::OWNER),
        ];
        // A public container inside a members-only one.
        $containers['nested'] = $this->add('community', 'public', self::OUTSIDER, container: $containers['members']);

        $this->member($containers['open'], self::OWNER, 3);
        $this->member($containers['members'], self::MEMBER, 2);
        $this->member($containers['members'], self::CONTAINER_MODERATOR, 3);
        $this->member($containers['members'], self::LOW_ROLE, 1);
        $this->member($containers['private'], self::MEMBER, 2);
        $this->member($containers['private'], self::CONTAINER_MODERATOR, 3);
        // The author belongs to the private container only as a plain member.
        $this->member($containers['private'], self::OWNER, 2);
        $this->member($containers['owner private'], self::MEMBER, 2);
        $this->member($containers['nested'], self::LOW_ROLE, 2);

        $containerKinds = ['none' => null, 'self' => 'self', ...$containers, 'missing' => 99999];

        foreach (['article', 'blog-post', 'lib-page'] as $type) {
            foreach (['public', 'members', 'private'] as $visibility) {
                foreach ($containerKinds as $kind => $container) {
                    foreach ([self::OWNER, self::OUTSIDER] as $owner) {
                        $id = $this->add($type, $visibility, $owner, container: $container);
                        $this->named["$type $visibility in $kind by $owner"] = $id;

                        if ($type === 'article') {
                            $this->discussion($id);
                        }
                    }
                }
            }
        }

        // A comment whose publication does not exist.
        $this->named['orphan comment'] = $this->add('comment', 'public', self::OUTSIDER, parent: 99998);

        $this->galleries($containers);
    }

    /** A root comment, a reply and a reply to the reply; each has its own visibility, which the ACL ignores. */
    private function discussion(int $publication): void
    {
        $root = $this->add('comment', 'public', self::OUTSIDER, parent: $publication);
        $hidden = $this->add('comment', 'private', self::MEMBER, parent: $publication);
        $reply = $this->add('comment', 'public', self::MEMBER, parent: $root);
        $this->add('comment', 'members', self::OWNER, parent: $hidden);
        $this->add('comment', 'public', self::OUTSIDER, parent: $reply);
        $this->named["comment on $publication"] = $root;
        $this->named["reply on $publication"] = $reply;
    }

    /**
     * @param array<string, int> $containers
     */
    private function galleries(array $containers): void
    {
        $contexts = [
            'public blog' => $this->add('blog', 'public', self::OWNER),
            'private blog' => $this->add('blog', 'private', self::OWNER),
            'open community' => $containers['open'],
            'members community' => $containers['members'],
            'private community' => $containers['private'],
            // Galleries belong to blogs and communities only.
            'article' => $this->add('article', 'public', self::OWNER),
        ];

        foreach ($contexts as $contextName => $context) {
            $inCommunity = str_contains($contextName, 'community');

            foreach (['public', 'members', 'private'] as $albumVisibility) {
                $album = $this->add('gallery-album', $albumVisibility, self::OWNER, parent: $context, container: $inCommunity ? $context : null);

                foreach (['ready', 'processing', null, 'ready private'] as $state) {
                    $photo = $this->add(
                        'gallery-photo',
                        $state === 'ready private' ? 'private' : 'public',
                        self::OWNER,
                        parent: $album,
                        container: $inCommunity ? $context : null,
                    );
                    if ($state !== null) {
                        $this->pdo->exec("INSERT INTO feed_metadata (feed_id, name, content) VALUES ($photo, 'gallery.state', '".explode(' ', $state)[0]."')");
                    }
                    $this->named["photo $state in $albumVisibility album of $contextName"] = $photo;

                    $comment = $this->add('comment', 'public', self::OUTSIDER, parent: $photo);
                    $this->add('comment', 'public', self::MEMBER, parent: $comment);
                    $this->named["comment on photo $state in $albumVisibility album of $contextName"] = $comment;
                }
            }
        }

        // A photo's own container is checked on its own, apart from the
        // album's context: ready photos in a public album of the public blog,
        // but inside each closed container.
        $album = $this->add('gallery-album', 'public', self::OWNER, parent: $contexts['public blog']);
        foreach (['members', 'private', 'owner private'] as $kind) {
            $photo = $this->add('gallery-photo', 'public', self::OWNER, parent: $album, container: $containers[$kind]);
            $this->pdo->exec("INSERT INTO feed_metadata (feed_id, name, content) VALUES ($photo, 'gallery.state', 'ready')");
            $this->named["photo ready in public blog, $kind container"] = $photo;
            $this->add('comment', 'public', self::OUTSIDER, parent: $photo);
        }
    }

    private function container(string $visibility, int $owner): int
    {
        return $this->add('community', $visibility, $owner, container: 'self');
    }

    private function member(int $container, int $user, int $roleId): void
    {
        $this->pdo->exec("INSERT INTO memberships (container_id, user_id, membership_role_id) VALUES ($container, $user, $roleId)");
    }

    private function add(string $type, string $visibility, int $owner, ?int $parent = null, int|string|null $container = null): int
    {
        $id = $this->nextId++;
        $containerId = $container === 'self' ? $id : $container;
        // Repeating creation times: ordered results also check the id tie-breaker.
        $createdAt = 1000 + $id % 7;
        $statement = $this->pdo->prepare(
            'INSERT INTO feeds (id, parent_id, owner_id, type, slug, title, content, container_id, visibility, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$id, $parent, $owner, $type, "$type-$id", "$type $id", 'text', $containerId, $visibility, $createdAt, $createdAt]);

        return $id;
    }
}

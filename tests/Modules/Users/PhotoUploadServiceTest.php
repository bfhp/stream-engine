<?php

declare(strict_types=1);

namespace Tests\Modules\Users;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\FileProcessing\FileStorage;
use StreamEngine\Core\FileProcessing\ImageProcessor;
use StreamEngine\Core\FileProcessing\MimeDetector;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Users\CommunityService;
use StreamEngine\Modules\Users\PhotoService;
use StreamEngine\Modules\Users\PhotoUploadService;
use StreamEngine\Repository\FeedMetadataRepository;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Repository\SettingsRepository;
use StreamEngine\Repository\UploadRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\SettingsService;
use StreamEngine\Service\UploadService;
use Tests\Support\ArrayCache;

final class PhotoUploadServiceTest extends TestCase
{
    private PdoDatabase $db;
    private FeedRepository $repository;
    private FeedService $feeds;
    private PhotoService $albums;
    private UploadService $uploads;
    private PhotoUploadService $service;
    private string $dir;
    private User $owner;

    protected function setUp(): void
    {
        $pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($pdo instanceof \Pdo\Sqlite) {
            $pdo->createFunction('UNIX_TIMESTAMP', time(...), 0);
        } else {
            $pdo->sqliteCreateFunction('UNIX_TIMESTAMP', time(...), 0);
        }
        $pdo->exec(<<<'SQL'
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                nick TEXT,
                avatar_url TEXT,
                username TEXT
            );
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
            CREATE TABLE feed_metadata (feed_id INTEGER, name TEXT, content TEXT, created_at INTEGER, updated_at INTEGER, PRIMARY KEY(feed_id, name));
            CREATE TABLE membership_roles (
                id INTEGER PRIMARY KEY,
                role_level INTEGER NOT NULL
            );
            CREATE TABLE memberships (
                container_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                membership_role_id INTEGER NOT NULL,
                PRIMARY KEY (container_id, user_id)
            );

            CREATE TABLE uploads (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, path TEXT, mime TEXT, size INTEGER, original_name TEXT, purpose TEXT, audio_title TEXT, audio_artist TEXT, created_at INTEGER);
            INSERT INTO users (id, nick, username) VALUES (1, 'Alice', 'alice');
            INSERT INTO feeds (id, parent_id, owner_id, type, slug, title, visibility) VALUES (10, NULL, 1, 'blog', 'alice', 'Alice', 'public'), (20, 10, 1, 'gallery-album', 'summer', 'Summer', 'public');
            SQL);
        $this->db = new class ($pdo) extends PdoDatabase {
            public function __construct(PDO $pdo)
            {
                (new ReflectionProperty(PdoDatabase::class, 'pdo'))->setValue($this, $pdo);
            }
            public function fetchOne(string $sql, array $params = []): ?array
            {
                // SQLite serializes writes; MySQL acquires the explicit row locks.
                return parent::fetchOne(str_replace(' FOR UPDATE', '', $sql), $params);
            }
        };
        $this->dir = sys_get_temp_dir().'/published-photo-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        imagepng(imagecreatetruecolor(40, 20), $this->dir.'/input.png');
        $this->repository = new FeedRepository($this->db);
        $access = new AccessService(new MembershipRepository($this->db), $this->repository);
        $tm = new TranslationManager('en');
        $tree = new PageTree([
            new \StreamEngine\Domain\Page(1, null, '', 'Home', null, null, null, null, ['GET'], 'html', 'public', 'home.index'),
            \StreamEngine\Domain\Page::runtime(2, 1, 'users', 'users.list', 'public', null, null, null),
            \StreamEngine\Domain\Page::runtime(3, 2, '{username}', 'user.show', 'public', 'blog', null, null),
        ]);
        $tree->add(\StreamEngine\Domain\Page::runtime(4, 1, 'communities', 'community.main', 'public', null, null, null));
        \StreamEngine\Modules\Users\UsersController::registerRuntimePages($tree, $tm);
        $this->feeds = new FeedService($this->repository, new UrlGenerator($tree, $this->repository, new ArrayCache()), $access, new Formatter($tm, 'en'), $tm, new FeedMetadataRepository($this->db));
        $communities = $this->createStub(CommunityService::class);
        $communities->method('canPost')->willReturn(true);
        $this->albums = new PhotoService($this->feeds, $access, $communities, $this->repository);
        $settingsDb = $this->createStub(PdoDatabase::class);
        $settingsDb->method('fetchAll')->willReturn([]);
        $this->uploads = new UploadService(new UploadRepository($this->db), new MimeDetector(), new FileStorage($this->dir.'/uploads'), new ImageProcessor($this->dir), $access, $tm, new SettingsService(new SettingsRepository($settingsDb)));
        $this->service = new PhotoUploadService($this->db, $this->albums, $this->feeds, $this->uploads);
        $this->owner = new User(1, '', username: 'alice');
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->dir);
        }
    }

    private function publish(): \StreamEngine\Domain\Feed
    {
        return $this->service->publish(10, 20, $this->owner, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $this->dir.'/input.png', 'name' => 'Photo.png'], 'My photo', 'Caption', '12345678-1234-4234-8234-123456789abc');
    }

    public function testPublicationIsAtomicAndRetryDoesNotDuplicatePhotosOrFiles(): void
    {
        $first = $this->publish();
        $second = $this->publish();
        self::assertSame($first->id, $second->id);
        self::assertSame('ready', $first->metadata['gallery.state']);
        self::assertSame('/users/alice/photos/summer/'.$first->id.'/', $second->canonicalUrl);
        self::assertSame(2, (int) $this->db->fetchOne('SELECT COUNT(*) AS n FROM uploads')['n']);
        self::assertCount(2, glob($this->dir.'/uploads-private/1/*'));
        self::assertFalse($this->db->inTransaction());
        $file = $this->service->getFile(10, 20, $first->id, 'preview', new User(0, ''));
        self::assertSame(UploadRepository::PURPOSE_GALLERY_PHOTO, $file->purpose);
        self::assertSame('image/webp', $file->mime);
    }

    public function testPrivateAlbumAndContextFilterPhotoBeforeListingAndCounting(): void
    {
        $photo = $this->publish();
        $guest = new User(0, '');
        self::assertNotNull($this->repository->findById($photo->id, $guest));
        $this->db->execute("INSERT INTO feeds (id, parent_id, owner_id, type, title, visibility) VALUES (500, ?, 99, 'comment', 'Comment', 'public')", [$photo->id]);
        self::assertNotNull($this->repository->findById(500, $guest));
        foreach ([20, 10] as $id) {
            $this->db->execute("UPDATE feeds SET visibility = 'private' WHERE id = ?", [$id]);
            self::assertNull($this->repository->findById($photo->id, $guest));
            self::assertNull($this->repository->findById(500, $guest));
            self::assertSame(0, $this->repository->countByType('gallery-photo', $guest));
            $this->db->execute("UPDATE feeds SET visibility = 'public' WHERE id = ?", [$id]);
        }
        $this->db->execute("UPDATE feeds SET visibility = 'private' WHERE id = 20");
        $this->expectException(NotFoundException::class);
        $this->service->getFile(10, 20, $photo->id, 'original', $guest);
    }

    public function testFailedFeedPublicationRollsBackUploadsAndRemovesPrivateFiles(): void
    {
        $feeds = $this->createMock(FeedService::class);
        $feeds->expects(self::once())->method('createFeed')->willThrowException(new RuntimeException('Publication failed'));
        $service = new PhotoUploadService($this->db, $this->albums, $feeds, $this->uploads);
        try {
            $service->publish(10, 20, $this->owner, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $this->dir.'/input.png', 'name' => 'Photo.png'], 'Photo', '', '12345678-1234-4234-8234-123456789abc');
            self::fail('Expected publication failure');
        } catch (RuntimeException $error) {
            self::assertSame('Publication failed', $error->getMessage());
        }
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) AS n FROM uploads')['n']);
        self::assertSame([], glob($this->dir.'/uploads-private/1/*'));
        self::assertFalse($this->db->inTransaction());
    }

    public function testTamperedUploadOwnershipIsRejected(): void
    {
        $photo = $this->publish();
        $this->db->execute('UPDATE uploads SET user_id = 99 WHERE id = ?', [(int) $photo->metadata['gallery.original_upload_id']]);
        $this->expectException(NotFoundException::class);
        $this->service->getFile(10, 20, $photo->id, 'preview', $this->owner);
    }

    public function testCommunityPublicationKeepsContextAndCanonicalUrl(): void
    {
        $this->db->execute("INSERT INTO feeds (id, parent_id, owner_id, type, slug, title, visibility, container_id, container_type) VALUES (30, NULL, 1, 'community', 'devs', 'Devs', 'public', NULL, NULL), (40, 30, 1, 'gallery-album', 'events', 'Events', 'public', 30, 'community')");
        $photo = $this->service->publish(30, 40, $this->owner, ['error' => UPLOAD_ERR_OK, 'tmp_name' => $this->dir.'/input.png', 'name' => 'Photo.png'], 'Event', '', '12345678-1234-4234-8234-123456789abc');
        $loaded = $this->feeds->getFeedById($photo->id, $this->owner);
        self::assertSame(30, $loaded->containerId);
        self::assertSame('/communities/devs/photos/events/'.$photo->id.'/', $loaded->canonicalUrl);
        $this->expectException(NotFoundException::class);
        $this->service->getFile(10, 20, $photo->id, 'original', $this->owner);
    }

    public function testFriendsOnlyPhotoInheritsBlogMembershipAccess(): void
    {
        $this->db->execute("UPDATE feeds SET visibility = 'members', container_id = 10, container_type = 'blog' WHERE id = 20");
        $this->db->execute("INSERT INTO membership_roles (id, role_level) VALUES (1, 1)");
        $this->db->execute("INSERT INTO memberships (container_id, user_id, membership_role_id) VALUES (10, 2, 1)");
        $photo = $this->publish();
        self::assertSame('members', $photo->visibility);
        self::assertSame(10, $photo->containerId);
        self::assertNull($this->repository->findById($photo->id, new User(0, '')));
        self::assertNotNull($this->repository->findById($photo->id, new User(2, '')));
        $this->db->execute('DELETE FROM memberships WHERE user_id = 2');
        self::assertNull($this->repository->findById($photo->id, new User(2, '')));
    }

    public function testIncompleteMetadataDoesNotBecomeAReadyImage(): void
    {
        $photo = $this->publish();
        $this->db->execute("DELETE FROM feed_metadata WHERE feed_id = ? AND name = 'gallery.width'", [$photo->id]);
        $this->expectException(NotFoundException::class);
        $this->service->getFile(10, 20, $photo->id, 'preview', $this->owner);
    }

    public function testAnotherBlogOwnerCannotUploadIntoAlbum(): void
    {
        $this->expectException(\StreamEngine\Core\Exceptions\ForbiddenException::class);
        $this->service->publish(10, 20, new User(2, ''), [], 'Photo', '', '12345678-1234-4234-8234-123456789abc');
    }
}

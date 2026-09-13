<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\GuestFeedReadStore;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Repository\FeedFavoriteRepository;
use StreamEngine\Repository\FeedMetadataRepository;
use StreamEngine\Repository\FeedRatingRepository;
use StreamEngine\Repository\FeedReadRepository;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

final class FeedServiceTest extends TestCase
{
    private array $cookieBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->cookieBackup = $_COOKIE;
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookieBackup;

        parent::tearDown();
    }

    private function makeService(
        FeedRepository $repository,
        ?UrlGenerator $urlGenerator = null,
        ?AccessService $accessService = null,
        ?Formatter $formatter = null,
        ?FeedMetadataRepository $metadataRepository = null,
        ?FeedRatingRepository $ratingRepository = null,
        ?FeedFavoriteRepository $favoriteRepository = null,
        ?FeedReadRepository $readRepository = null,
        ?GuestFeedReadStore $guestReadStore = null,
    ): FeedService {
        return new FeedService(
            $repository,
            $urlGenerator ?? $this->makeUrlGenerator(),
            $accessService ?? $this->createStub(AccessService::class),
            $formatter ?? new Formatter(new TranslationManager('ru', 'en'), 'ru'),
            new TranslationManager('ru', 'en'),
            $metadataRepository,
            $ratingRepository,
            $favoriteRepository,
            $readRepository,
            $guestReadStore,
        );
    }

    private function trans(string $key, array $params = []): string
    {
        return (new TranslationManager('ru', 'en'))->trans($key, $params);
    }

    private function makeFormatter(): Formatter
    {
        return new Formatter(new TranslationManager('ru', 'en'), 'ru');
    }

    private function makeUrlGenerator(?array $feeds = null): UrlGenerator
    {
        $pages = [
            new Page(
                id: 1,
                parentId: null,
                pattern: '',
                pageName: 'Root',
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'raw',
                accessRule: AccessService::ACCESS_PUBLIC
            ),
            new Page(
                id: 2,
                parentId: 1,
                pattern: 'articles',
                pageName: 'Articles',
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'raw',
                accessRule: AccessService::ACCESS_PUBLIC
            ),
            new Page(
                id: 3,
                parentId: 2,
                pattern: '{slug}',
                pageName: 'Article',
                settings: null,
                feedType: 'article',
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'raw',
                accessRule: AccessService::ACCESS_PUBLIC
            ),
            new Page(
                id: 4,
                parentId: 1,
                pattern: 'books',
                pageName: 'Books',
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'raw',
                accessRule: AccessService::ACCESS_PUBLIC
            ),
            new Page(
                id: 5,
                parentId: 4,
                pattern: '{slug}',
                pageName: 'Book',
                settings: null,
                feedType: 'publication',
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'raw',
                accessRule: AccessService::ACCESS_PUBLIC
            ),
            new Page(
                id: 6,
                parentId: 5,
                pattern: '{slug}',
                pageName: 'Book page',
                settings: null,
                feedType: 'chapter',
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'raw',
                accessRule: AccessService::ACCESS_PUBLIC
            ),
            // deleteFeed() tests exercise a 'blog-post' feed (getFeedById()
            // resolves canonicalUrl unconditionally), so a blog/blog-post
            // page pair needs to exist here too - otherwise those tests log
            // a (harmless, caught) "Feed not found"/"No page defined"
            // warning via error_log() when that lookup fails. Same reasoning
            // as BlogPostServiceTest's own copy of this pair.
            new Page(
                id: 7,
                parentId: 1,
                pattern: 'blog',
                pageName: 'Blog',
                settings: null,
                feedType: 'blog',
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'raw',
                accessRule: AccessService::ACCESS_PUBLIC
            ),
            new Page(
                id: 8,
                parentId: 7,
                pattern: '{slug}',
                pageName: 'Blog post',
                settings: null,
                feedType: 'blog-post',
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'raw',
                accessRule: AccessService::ACCESS_PUBLIC
            ),
        ];

        return new UrlGenerator(
            new PageTree($pages),
            new FakeFeedRepository($feeds ?? []),
            new ArrayCache()
        );
    }

    private function makeFeed(
        int $id,
        int $ownerId = 1,
        ?int $parentId = null,
        ?int $createdAt = null,
        string $type = 'article',
    ): Feed {
        return new Feed(
            id: $id,
            parentId: $parentId,
            ownerId: $ownerId,
            type: $type,
            slug: 'feed-'.$id,
            title: 'Feed '.$id,
            description: null,
            imageUrl: null,
            content: '<p>Body</p>',
            containerId: null,
            visibility: 'public',
            position: $id,
            createdAt: $createdAt,
            relevance: null,
            canonicalUrl: null,
            authorDisplayName: 'Author '.$ownerId,
            authorAvatarUrl: '/uploads/avatar-'.$ownerId.'.webp',
        );
    }

    public function testListFeedsCanFilterByTypeAndTitle(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);

        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'f.title LIKE ?')
                    && str_contains($sql, 'AND f.type = ?')),
                ['%мастер%', 'publication', 7, 7]
            )
            ->willReturn([]);

        $service = $this->makeService($repository);
        $result = $service->listFeeds(
            user: $user,
            limit: 20,
            offset: 0,
            type: 'publication',
            title: 'мастер'
        );

        $this->assertSame([], $result['data']);
        $this->assertSame('publication', $result['meta']['type']);
        $this->assertSame('мастер', $result['meta']['title']);
    }

    public function testGetTopRatedFeedsByTypeOrdersByRatingAvgDescending(): void
    {
        $createdAt = time() - 4000;
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);

        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'f.type = ?')
                    && str_contains($sql, 'ORDER BY f.rating_avg DESC, f.id DESC')
                    && str_contains($sql, 'LIMIT 50')),
                ['publication', 7, 7]
            )
            ->willReturn([
                [
                    'id' => 58,
                    'parent_id' => null,
                    'owner_id' => 1,
                    'type' => 'publication',
                    'slug' => 'feed-58',
                    'title' => 'Book 58',
                    'content' => null,
                    'description' => null,
                    'image_url' => null,
                    'container_id' => null,
                    'visibility' => 'public',
                    'position' => 0,
                    'created_at' => $createdAt,
                    'rating_sum' => 20,
                    'rating_count' => 4,
                    'nick' => 'Author 1',
                    'avatar_url' => '/uploads/avatar-1.webp',
                ],
            ]);

        $service = $this->makeService(
            $repository,
            $this->makeUrlGenerator([58 => $this->makeFeed(id: 58, createdAt: $createdAt, type: 'publication')])
        );

        $result = $service->getTopRatedFeedsByType('publication', $user);

        $this->assertCount(1, $result);
        $this->assertSame(58, $result[0]->id);
        $this->assertSame(5.0, $result[0]->ratingAverage());
    }

    public function testGetFeedByIdAddsCanonicalUrlAndFormattedTime(): void
    {
        $createdAt = time() - 4000;
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $accessService = $this->createMock(AccessService::class);
        $formatter = $this->makeFormatter();
        $repository = new FeedRepository($db);

        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn([
                'id' => 10,
                'parent_id' => null,
                'owner_id' => 1,
                'type' => 'article',
                'slug' => 'feed-10',
                'title' => 'Feed 10',
                'content' => '<p>Body</p>',
                'description' => null,
                'image_url' => null,
                'container_id' => null,
                'visibility' => 'public',
                'position' => 10,
                'created_at' => $createdAt,
                'nick' => 'Author 1',
                'avatar_url' => '/uploads/avatar-1.webp',
            ]);

        $accessService
            ->expects($this->once())
            ->method('canAccessFeed')
            ->with(
                $user,
                $this->callback(static fn (Feed $feed): bool => $feed->id === 10)
            )
            ->willReturn(true);

        $feed = $this->makeFeed(id: 10, createdAt: $createdAt);
        $service = $this->makeService(
            $repository,
            $this->makeUrlGenerator([10 => $feed]),
            $accessService,
            $formatter
        );
        $result = $service->getFeedById(10, $user);

        $this->assertSame('/articles/feed-10/', $result->canonicalUrl);
        $this->assertSame(
            $formatter->relative(new \DateTimeImmutable('@'.$createdAt)),
            $result->createdAtLabel
        );
        $this->assertSame(
            $formatter->datetime(new \DateTimeImmutable('@'.$createdAt)),
            $result->createdAtTitle
        );
    }

    public function testGetFeedByIdAddsMetadata(): void
    {
        $createdAt = time() - 4000;
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $feedDb = $this->createMock(PdoDatabase::class);
        $metadataDb = $this->createMock(PdoDatabase::class);
        $accessService = $this->createMock(AccessService::class);
        $repository = new FeedRepository($feedDb);

        $feedDb
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn([
                'id' => 10,
                'parent_id' => null,
                'owner_id' => 1,
                'type' => 'publication',
                'slug' => 'feed-10',
                'title' => 'Feed 10',
                'content' => '<p>Body</p>',
                'description' => null,
                'image_url' => null,
                'container_id' => null,
                'visibility' => 'public',
                'position' => 10,
                'created_at' => $createdAt,
                'nick' => 'Author 1',
                'avatar_url' => '/uploads/avatar-1.webp',
            ]);

        $metadataDb
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'feed_metadata')),
                [10]
            )
            ->willReturn([
                [
                    'feed_id' => 10,
                    'name' => 'book-type',
                    'content' => 'fragment',
                ],
                [
                    'feed_id' => 10,
                    'name' => 'publication-year',
                    'content' => '1967',
                ],
            ]);

        $accessService
            ->expects($this->once())
            ->method('canAccessFeed')
            ->willReturn(true);

        $service = $this->makeService(
            $repository,
            $this->makeUrlGenerator([10 => $this->makeFeed(id: 10, createdAt: $createdAt, type: 'publication')]),
            $accessService,
            metadataRepository: new FeedMetadataRepository($metadataDb)
        );

        $result = $service->getFeedById(10, $user);

        $this->assertSame('fragment', $result->metadata['book-type']);
        $this->assertSame('1967', $result->metadata['publication-year']);
    }

    public function testGetFeedByParentAndSlugAddsDecorations(): void
    {
        $createdAt = time() - 4000;
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);

        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'f.parent_id = ?')
                    && str_contains($sql, 'f.slug = ?')
                    && str_contains($sql, 'f.type = ?')),
                [58, '2', 'chapter', 7, 7]
            )
            ->willReturn([
                'id' => 60,
                'parent_id' => 58,
                'owner_id' => 1,
                'type' => 'chapter',
                'slug' => '2',
                'title' => null,
                'content' => '<p>Page 2</p>',
                'description' => null,
                'image_url' => null,
                'container_id' => null,
                'visibility' => 'public',
                'position' => 2,
                'created_at' => $createdAt,
                'nick' => 'Author 1',
                'avatar_url' => '/uploads/avatar-1.webp',
            ]);

        $parent = $this->makeFeed(id: 58, type: 'publication');
        $page = $this->makeFeed(id: 60, parentId: 58, createdAt: $createdAt, type: 'chapter');
        $page = new Feed(
            id: $page->id,
            parentId: $page->parentId,
            ownerId: $page->ownerId,
            type: $page->type,
            slug: '2',
            title: $page->title,
            description: $page->description,
            imageUrl: $page->imageUrl,
            content: $page->content,
            containerId: $page->containerId,
            visibility: $page->visibility,
            position: $page->position,
            createdAt: $page->createdAt,
            relevance: null,
            canonicalUrl: null,
        );
        $service = $this->makeService($repository, $this->makeUrlGenerator([58 => $parent, 60 => $page]));

        $result = $service->getFeedByParentAndSlug(58, '2', $user, 'chapter');

        $this->assertSame(60, $result->id);
        $this->assertSame('/books/feed-58/2/', $result->canonicalUrl);
        $this->assertNotNull($result->createdAtLabel);
    }

    public function testGetFeedsByTermAddsCanonicalUrls(): void
    {
        $createdAt = time() - 4000;
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);

        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'JOIN feed_term_links')
                    && str_contains($sql, 'JOIN feed_terms')
                    && str_contains($sql, 'ft.vocabulary = ?')
                    && str_contains($sql, 'ft.slug = ?')
                    && str_contains($sql, 'f.type = ?')),
                ['author', 'kandyba_viktor_mihajilovich', 'publication', 7, 7]
            )
            ->willReturn([
                [
                    'id' => 58,
                    'parent_id' => null,
                    'owner_id' => 1,
                    'type' => 'publication',
                    'slug' => 'tajiny_kazahskih_shamanov',
                    'title' => 'Тайны казахских шаманов',
                    'content' => null,
                    'description' => null,
                    'image_url' => null,
                    'container_id' => null,
                    'visibility' => 'public',
                    'position' => 0,
                    'created_at' => $createdAt,
                    'nick' => 'Author 1',
                    'avatar_url' => '/uploads/avatar-1.webp',
                ],
            ]);

        $feed = $this->makeFeed(id: 58, createdAt: $createdAt, type: 'publication');
        $service = $this->makeService($repository, $this->makeUrlGenerator([58 => $feed]));

        $result = $service->getFeedsByTerm('author', 'kandyba_viktor_mihajilovich', $user, 'publication', 100, 0);

        $this->assertCount(1, $result);
        $this->assertSame(58, $result[0]->id);
        $this->assertSame('/books/feed-58/', $result[0]->canonicalUrl);
    }

    public function testGetCommentsDecoratesRootsAndChildrenAndBuildsPaginationMeta(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $rootOneCreatedAt = time() - 4000;
        $rootTwoCreatedAt = time() - 8000;
        $rootThreeCreatedAt = time() - 12000;
        $replyOneCreatedAt = time() - 3000;
        $replyTwoCreatedAt = time() - 5000;

        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->exactly(2))
            ->method('fetchAll')
            ->willReturnOnConsecutiveCalls(
                [
                    [
                        'id' => 101,
                        'parent_id' => 55,
                        'owner_id' => 1,
                        'type' => 'comment',
                        'slug' => null,
                        'title' => 'Comment 101',
                        'content' => '<p>Root 1</p>',
                        'description' => null,
                        'image_url' => null,
                        'container_id' => null,
                        'visibility' => 'public',
                        'position' => 1,
                        'created_at' => $rootOneCreatedAt,
                        'nick' => 'Author 1',
                        'avatar_url' => '/uploads/avatar-1.webp',
                        'total_count' => 3,
                    ],
                    [
                        'id' => 102,
                        'parent_id' => 55,
                        'owner_id' => 1,
                        'type' => 'comment',
                        'slug' => null,
                        'title' => 'Comment 102',
                        'content' => '<p>Root 2</p>',
                        'description' => null,
                        'image_url' => null,
                        'container_id' => null,
                        'visibility' => 'public',
                        'position' => 2,
                        'created_at' => $rootTwoCreatedAt,
                        'nick' => 'Author 1',
                        'avatar_url' => '/uploads/avatar-1.webp',
                        'total_count' => 3,
                    ],
                    [
                        'id' => 103,
                        'parent_id' => 55,
                        'owner_id' => 1,
                        'type' => 'comment',
                        'slug' => null,
                        'title' => 'Comment 103',
                        'content' => '<p>Root 3</p>',
                        'description' => null,
                        'image_url' => null,
                        'container_id' => null,
                        'visibility' => 'public',
                        'position' => 3,
                        'created_at' => $rootThreeCreatedAt,
                        'nick' => 'Author 1',
                        'avatar_url' => '/uploads/avatar-1.webp',
                        'total_count' => 3,
                    ],
                ],
                [
                    [
                        'id' => 201,
                        'parent_id' => 101,
                        'owner_id' => 1,
                        'type' => 'comment',
                        'slug' => null,
                        'title' => 'Reply 201',
                        'content' => '<p>Reply 1</p>',
                        'description' => null,
                        'image_url' => null,
                        'container_id' => null,
                        'visibility' => 'public',
                        'position' => 1,
                        'created_at' => $replyOneCreatedAt,
                        'nick' => 'Author 1',
                        'avatar_url' => '/uploads/avatar-1.webp',
                        'total_count' => 4,
                        'row_num' => 1,
                    ],
                    [
                        'id' => 202,
                        'parent_id' => 101,
                        'owner_id' => 1,
                        'type' => 'comment',
                        'slug' => null,
                        'title' => 'Reply 202',
                        'content' => '<p>Reply 2</p>',
                        'description' => null,
                        'image_url' => null,
                        'container_id' => null,
                        'visibility' => 'public',
                        'position' => 2,
                        'created_at' => $replyTwoCreatedAt,
                        'nick' => 'Author 1',
                        'avatar_url' => '/uploads/avatar-1.webp',
                        'total_count' => 4,
                        'row_num' => 2,
                    ],
                ]
            );

        $repository = new FeedRepository($db);
        $formatter = $this->makeFormatter();
        $service = $this->makeService($repository, formatter: $formatter);
        $result = $service->getComments(parentFeedId: 55, cursor: null, user: $user, limit: 2, childLimit: 2);

        $this->assertCount(2, $result['items']);
        $this->assertSame(1, $result['meta']['next_count']);
        $nextCursor = json_decode(base64_decode($result['meta']['next_cursor']), true);
        $this->assertSame(102, $nextCursor['id']);
        $this->assertSame($rootTwoCreatedAt, $nextCursor['created_at']);

        $this->assertSame(
            $formatter->relative(new \DateTimeImmutable('@'.$rootOneCreatedAt)),
            $result['items'][0]->createdAtLabel
        );
        $this->assertNotEmpty($result['items'][1]->createdAtLabel);

        $children = $result['items'][0]->children;

        $this->assertCount(2, $children['items']);
        $this->assertSame(2, $children['meta']['next_count']);

        $this->assertSame(
            $formatter->relative(new \DateTimeImmutable('@'.$replyOneCreatedAt)),
            $children['items'][0]->createdAtLabel
        );
    }

    public function testDecodeCommentsCursorRejectsInvalidPayload(): void
    {
        $service = $this->makeService(new FeedRepository($this->createStub(PdoDatabase::class)));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('feed.cursor_invalid'));

        $service->getComments(
            parentFeedId: 1,
            cursor: base64_encode(json_encode(['id' => 123])),
            user: new User(id: 1, email: 'user@example.com', role: AccessService::ROLE_USER)
        );
    }

    public function testCreateCommentCreatesSanitizedCommentForParentFeed(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $createdAt = time() - 10;

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeService($repository, $this->makeUrlGenerator());

        $db
            ->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(
                [
                    'id' => 55,
                    'parent_id' => null,
                    'owner_id' => 1,
                    'type' => 'article',
                    'slug' => 'feed-55',
                    'title' => 'Feed 55',
                    'content' => '<p>Body</p>',
                    'description' => null,
                    'image_url' => null,
                    'container_id' => null,
                    'visibility' => 'public',
                    'position' => 1,
                    'created_at' => $createdAt - 100,
                    'nick' => 'Author 1',
                    'avatar_url' => '/uploads/avatar-1.webp',
                ],
                [
                    'id' => 201,
                    'parent_id' => 55,
                    'owner_id' => 7,
                    'type' => 'comment',
                    'slug' => null,
                    'title' => '',
                    'content' => "Hello<br />\n&lt;b&gt;world&lt;/b&gt;",
                    'description' => null,
                    'image_url' => null,
                    'container_id' => null,
                    'visibility' => 'public',
                    'position' => 1,
                    'created_at' => $createdAt,
                    'nick' => 'Author 7',
                    'avatar_url' => '/uploads/avatar-7.webp',
                ]
            );

        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INSERT INTO'),
                    $this->stringContains('feeds')
                ),
                $this->callback(static function (array $params): bool {
                    return $params[0] === 55
                        && $params[1] === 7
                        && $params[2] === ''
                        && $params[3] === 'comment'
                        && $params[4] === null
                        && $params[5] === "Hello<br />\n&lt;b&gt;world&lt;/b&gt;";
                })
            );

        $db
            ->expects($this->once())
            ->method('lastInsertId')
            ->willReturn(201);

        $comment = $service->createComment(55, " Hello\n<b>world</b> ", $user);

        $this->assertSame(201, $comment->id);
        $this->assertSame('comment', $comment->type);
        $this->assertSame("Hello<br />\n&lt;b&gt;world&lt;/b&gt;", $comment->content);
        $this->assertNotNull($comment->createdAtLabel);
        $this->assertNotNull($comment->createdAtTitle);
    }

    public function testCreateCommentRejectsEmptyContent(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeService($repository, $this->makeUrlGenerator());

        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn([
                'id' => 55,
                'parent_id' => null,
                'owner_id' => 1,
                'type' => 'article',
                'slug' => 'feed-55',
                'title' => 'Feed 55',
                'content' => '<p>Body</p>',
                'description' => null,
                'image_url' => null,
                'container_id' => null,
                'visibility' => 'public',
                'position' => 1,
                'created_at' => time() - 100,
                'nick' => 'Author 1',
                'avatar_url' => '/uploads/avatar-1.webp',
            ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('feed.comment_empty'));

        $service->createComment(55, '   ', $user);
    }

    public function testRateFeedThrowsForbiddenExceptionForGuest(): void
    {
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('fetchOne');

        // Guest check happens before the feed even gets to touch a rating
        // repository, so the default (null) is fine here.
        $service = $this->makeService(new FeedRepository($db), $this->makeUrlGenerator());

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage($this->trans('feed.forbidden'));

        $service->rateFeed(58, 4, $guest);
    }

    public function testRateFeedThrowsValidationExceptionWhenRatingUnavailable(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('fetchOne');

        $service = $this->makeService(new FeedRepository($db), $this->makeUrlGenerator());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('feed.rating_unavailable'));

        $service->rateFeed(58, 4, $user);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function invalidRatingValueProvider(): array
    {
        return [
            'too low' => [0],
            'too high' => [6],
        ];
    }

    #[DataProvider('invalidRatingValueProvider')]
    public function testRateFeedRejectsOutOfRangeValues(int $value): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('fetchOne');

        $ratingDb = $this->createMock(PdoDatabase::class);
        $ratingDb->expects($this->never())->method('begin');

        $service = $this->makeService(
            new FeedRepository($db),
            $this->makeUrlGenerator(),
            ratingRepository: new FeedRatingRepository($ratingDb)
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('feed.rating_invalid'));

        $service->rateFeed(58, $value, $user);
    }

    public function testRateFeedThrowsWhenTargetFeedIsMissing(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchOne')->willReturn(null);

        $ratingDb = $this->createMock(PdoDatabase::class);
        $ratingDb->expects($this->never())->method('begin');

        $service = $this->makeService(
            new FeedRepository($db),
            $this->makeUrlGenerator(),
            ratingRepository: new FeedRatingRepository($ratingDb)
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('feed.rating_target_not_found'));

        $service->rateFeed(58, 4, $user);
    }

    public function testRateFeedSubmitsVoteAndReturnsRefreshedFeed(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $createdAt = time() - 4000;

        $bookRow = [
            'id' => 58,
            'parent_id' => null,
            'owner_id' => 1,
            'type' => 'publication',
            'slug' => 'feed-58',
            'title' => 'Book 58',
            'content' => null,
            'description' => null,
            'image_url' => null,
            'container_id' => null,
            'visibility' => 'public',
            'position' => 0,
            'created_at' => $createdAt,
            'rating_sum' => 12,
            'rating_count' => 3,
            'nick' => 'Author 1',
            'avatar_url' => '/uploads/avatar-1.webp',
        ];

        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturn($bookRow);

        $accessService = $this->createMock(AccessService::class);
        $accessService->expects($this->once())->method('canAccessFeed')->willReturn(true);

        // FeedRatingRepository is final, so it's exercised through its own
        // mocked PdoDatabase instead of being mocked directly.
        $ratingDb = $this->createMock(PdoDatabase::class);
        $ratingDb->expects($this->once())->method('begin');
        $ratingDb->expects($this->once())->method('commit');
        $ratingDb->expects($this->never())->method('rollback');
        $ratingDb->expects($this->once())->method('fetchOne')->willReturn(null);

        $ratingExecuted = [];
        $ratingDb
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(static function (string $sql, array $params) use (&$ratingExecuted): int {
                $ratingExecuted[] = [$sql, $params];

                return 1;
            });

        $service = $this->makeService(
            new FeedRepository($db),
            $this->makeUrlGenerator([58 => $this->makeFeed(id: 58, createdAt: $createdAt, type: 'publication')]),
            $accessService,
            ratingRepository: new FeedRatingRepository($ratingDb)
        );

        $result = $service->rateFeed(58, 4, $user);

        $this->assertSame(58, $result->id);
        $this->assertSame(12, $result->ratingSum);
        $this->assertSame(3, $result->ratingCount);
        $this->assertSame(4.0, $result->ratingAverage());

        $this->assertStringContainsString('INSERT INTO', $ratingExecuted[0][0]);
        $this->assertStringContainsString('feed_ratings', $ratingExecuted[0][0]);
        $this->assertSame([58, 7, 4], $ratingExecuted[0][1]);
    }

    public function testGetUserRatingValueReturnsNullWithoutARatingRepository(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $service = $this->makeService(new FeedRepository($this->createStub(PdoDatabase::class)));

        $this->assertNull($service->getUserRatingValue(58, $user));
    }

    public function testGetUserRatingValueReturnsNullForGuest(): void
    {
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);
        $ratingDb = $this->createMock(PdoDatabase::class);
        $ratingDb->expects($this->never())->method('fetchOne');

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            ratingRepository: new FeedRatingRepository($ratingDb)
        );

        $this->assertNull($service->getUserRatingValue(58, $guest));
    }

    public function testGetUserRatingValueDelegatesToRatingRepository(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $ratingDb = $this->createMock(PdoDatabase::class);
        $ratingDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('feed_ratings'), [58, 7])
            ->willReturn(['value' => '4']);

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            ratingRepository: new FeedRatingRepository($ratingDb)
        );

        $this->assertSame(4, $service->getUserRatingValue(58, $user));
    }

    /**
     * FeedService::getRatingTotalsByParentAndType() - the community
     * sidebar's "Рейтинг" card aggregate (community's own posts, not a
     * vote on the community feed itself - see UsersController::
     * buildCommunityRatingViewData()). Admin viewer so buildAclCondition()
     * takes its `1=1` no-extra-params branch, keeping the expected SQL
     * params to just [parentId, type].
     */
    public function testGetRatingTotalsByParentAndTypeSumsAcrossParentPosts(): void
    {
        $admin = new User(id: 1, email: 'admin@example.com', role: AccessService::ROLE_ADMIN);
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('f.parent_id = ?'), [42, 'blog-post'])
            ->willReturn(['total_sum' => 14, 'total_count' => 3]);

        $service = $this->makeService(new FeedRepository($db));

        $this->assertSame(['sum' => 14, 'count' => 3], $service->getRatingTotalsByParentAndType(42, 'blog-post', $admin));
    }

    /**
     * FeedService::countFeedsByParentAndType() - the community sidebar's
     * "Статистика" card's "Записей в сообществе" row (see
     * UsersController::buildCommunityStatsViewData()). Same admin-viewer
     * `1=1` ACL branch as testGetRatingTotalsByParentAndTypeSumsAcrossParentPosts()
     * above, keeping the expected SQL params to just [parentId, type].
     */
    public function testCountFeedsByParentAndTypeCountsAcrossParentPosts(): void
    {
        $admin = new User(id: 1, email: 'admin@example.com', role: AccessService::ROLE_ADMIN);
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('f.parent_id = ?'), [42, 'blog-post'])
            ->willReturn(['total' => 5]);

        $service = $this->makeService(new FeedRepository($db));

        $this->assertSame(5, $service->countFeedsByParentAndType(42, 'blog-post', $admin));
    }

    /**
     * FeedService::countCommentsByParentContainer() - the community
     * sidebar's "Статистика" card's "Комментариев" row (see
     * UsersController::buildCommunityStatsViewData()). Two levels deep
     * (comment -> its post -> the community), hence a subquery rather
     * than a plain parent_id match - same admin-viewer `1=1` ACL branch
     * as the other parent-scoped tests above.
     */
    public function testCountCommentsByParentContainerCountsCommentsOnContainerPosts(): void
    {
        $admin = new User(id: 1, email: 'admin@example.com', role: AccessService::ROLE_ADMIN);
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains("parent_id IN (SELECT id FROM feeds WHERE parent_id = ? AND type = 'blog-post')"), [42])
            ->willReturn(['total' => 7]);

        $service = $this->makeService(new FeedRepository($db));

        $this->assertSame(7, $service->countCommentsByParentContainer(42, $admin));
    }

    public function testAddFavoriteThrowsForbiddenExceptionForGuest(): void
    {
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('fetchOne');

        $service = $this->makeService(new FeedRepository($db), $this->makeUrlGenerator());

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage($this->trans('feed.forbidden'));

        $service->addFavorite(58, $guest);
    }

    public function testAddFavoriteThrowsValidationExceptionWhenFavoritesUnavailable(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('fetchOne');

        $service = $this->makeService(new FeedRepository($db), $this->makeUrlGenerator());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('feed.favorite_unavailable'));

        $service->addFavorite(58, $user);
    }

    public function testAddFavoriteThrowsWhenTargetFeedIsMissing(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchOne')->willReturn(null);

        $favoriteDb = $this->createMock(PdoDatabase::class);
        $favoriteDb->expects($this->never())->method('execute');

        $service = $this->makeService(
            new FeedRepository($db),
            $this->makeUrlGenerator(),
            favoriteRepository: new FeedFavoriteRepository($favoriteDb)
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('feed.favorite_target_not_found'));

        $service->addFavorite(58, $user);
    }

    public function testAddFavoriteDelegatesToFavoriteRepository(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchOne')->willReturn([
            'id' => 58,
            'parent_id' => null,
            'owner_id' => 1,
            'type' => 'publication',
            'slug' => 'feed-58',
            'title' => 'Book 58',
            'content' => null,
            'description' => null,
            'image_url' => null,
            'container_id' => null,
        ]);

        $favoriteDb = $this->createMock(PdoDatabase::class);
        $favoriteDb
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INSERT INTO'),
                    $this->stringContains('feed_favorites')
                ),
                [58, 7]
            )
            ->willReturn(1);

        $accessService = $this->createMock(AccessService::class);
        $accessService->expects($this->never())->method('canAccessFeed');

        $service = $this->makeService(
            new FeedRepository($db),
            $this->makeUrlGenerator(),
            $accessService,
            favoriteRepository: new FeedFavoriteRepository($favoriteDb)
        );

        $service->addFavorite(58, $user);
    }

    public function testRemoveFavoriteThrowsForbiddenExceptionForGuest(): void
    {
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);
        $favoriteDb = $this->createMock(PdoDatabase::class);
        $favoriteDb->expects($this->never())->method('execute');

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            favoriteRepository: new FeedFavoriteRepository($favoriteDb)
        );

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage($this->trans('feed.forbidden'));

        $service->removeFavorite(58, $guest);
    }

    public function testRemoveFavoriteDelegatesToFavoriteRepository(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $favoriteDb = $this->createMock(PdoDatabase::class);
        $favoriteDb
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('DELETE'),
                    $this->stringContains('FROM feed_favorites')
                ),
                [58, 7]
            )
            ->willReturn(1);

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            favoriteRepository: new FeedFavoriteRepository($favoriteDb)
        );

        $service->removeFavorite(58, $user);
    }

    public function testIsFeedFavoritedReturnsFalseWithoutAFavoriteRepository(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $service = $this->makeService(new FeedRepository($this->createStub(PdoDatabase::class)));

        $this->assertFalse($service->isFeedFavorited(58, $user));
    }

    public function testIsFeedFavoritedReturnsFalseForGuest(): void
    {
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);
        $favoriteDb = $this->createMock(PdoDatabase::class);
        $favoriteDb->expects($this->never())->method('fetchOne');

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            favoriteRepository: new FeedFavoriteRepository($favoriteDb)
        );

        $this->assertFalse($service->isFeedFavorited(58, $guest));
    }

    public function testIsFeedFavoritedDelegatesToFavoriteRepository(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $favoriteDb = $this->createMock(PdoDatabase::class);
        $favoriteDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('feed_favorites'), [58, 7])
            ->willReturn(['1' => 1]);

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            favoriteRepository: new FeedFavoriteRepository($favoriteDb)
        );

        $this->assertTrue($service->isFeedFavorited(58, $user));
    }

    public function testGetFavoriteFeedsReturnsEmptyArrayWithoutAFavoriteRepository(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $service = $this->makeService(new FeedRepository($this->createStub(PdoDatabase::class)));

        $this->assertSame([], $service->getFavoriteFeeds($user));
    }

    public function testGetFavoriteFeedsReturnsEmptyArrayForGuest(): void
    {
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);
        $favoriteDb = $this->createMock(PdoDatabase::class);
        $favoriteDb->expects($this->never())->method('fetchAll');

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            favoriteRepository: new FeedFavoriteRepository($favoriteDb)
        );

        $this->assertSame([], $service->getFavoriteFeeds($guest));
    }

    public function testGetFavoriteFeedsSkipsHydrationWhenUserHasNoFavorites(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $favoriteDb = $this->createMock(PdoDatabase::class);
        $favoriteDb->expects($this->once())->method('fetchAll')->willReturn([]);

        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('fetchAll');

        $service = $this->makeService(
            new FeedRepository($db),
            favoriteRepository: new FeedFavoriteRepository($favoriteDb)
        );

        $this->assertSame([], $service->getFavoriteFeeds($user));
    }

    /**
     * findFeedIdsForUser() returns ids most-recently-favorited first (60, then
     * 58), and the DB row order returned for the "IN (...)" lookup is
     * deliberately the opposite (58, then 60) - the result must still come
     * back in favorited order, proving FeedRepository::findByIds() re-sorts
     * to match rather than trusting MySQL's IN() row order.
     */
    public function testGetFavoriteFeedsReturnsBooksInMostRecentlyFavoritedOrder(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $createdAt = time() - 4000;

        $favoriteDb = $this->createMock(PdoDatabase::class);
        $favoriteDb
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'feed_favorites')
                    && str_contains($sql, 'ORDER BY created_at DESC')
                    && str_contains($sql, 'LIMIT 50 OFFSET 0')),
                [7]
            )
            ->willReturn([
                ['feed_id' => '60'],
                ['feed_id' => '58'],
            ]);

        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'f.id IN (?, ?)')
                    && str_contains($sql, 'f.type = ?')),
                [60, 58, 'publication', 7, 7]
            )
            ->willReturn([
                [
                    'id' => 58,
                    'parent_id' => null,
                    'owner_id' => 1,
                    'type' => 'publication',
                    'slug' => 'feed-58',
                    'title' => 'Book 58',
                    'content' => null,
                    'description' => null,
                    'image_url' => null,
                    'container_id' => null,
                    'visibility' => 'public',
                    'position' => 0,
                    'created_at' => $createdAt,
                    'rating_sum' => 0,
                    'rating_count' => 0,
                    'nick' => 'Author 1',
                    'avatar_url' => '/uploads/avatar-1.webp',
                ],
                [
                    'id' => 60,
                    'parent_id' => null,
                    'owner_id' => 1,
                    'type' => 'publication',
                    'slug' => 'feed-60',
                    'title' => 'Book 60',
                    'content' => null,
                    'description' => null,
                    'image_url' => null,
                    'container_id' => null,
                    'visibility' => 'public',
                    'position' => 0,
                    'created_at' => $createdAt,
                    'rating_sum' => 0,
                    'rating_count' => 0,
                    'nick' => 'Author 1',
                    'avatar_url' => '/uploads/avatar-1.webp',
                ],
            ]);

        $service = $this->makeService(
            new FeedRepository($db),
            $this->makeUrlGenerator([
                58 => $this->makeFeed(id: 58, createdAt: $createdAt, type: 'publication'),
                60 => $this->makeFeed(id: 60, createdAt: $createdAt, type: 'publication'),
            ]),
            favoriteRepository: new FeedFavoriteRepository($favoriteDb)
        );

        $result = $service->getFavoriteFeeds($user, 'publication');

        $this->assertCount(2, $result);
        $this->assertSame(60, $result[0]->id);
        $this->assertSame(58, $result[1]->id);
    }

    public function testMarkFeedAsReadIsNoopWithoutAReadRepository(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $service = $this->makeService(new FeedRepository($this->createStub(PdoDatabase::class)));

        $service->markFeedAsRead(58, $user);
        $this->addToAssertionCount(1); // No exception, no repository call to assert on
    }

    public function testMarkFeedAsReadIsNoopForGuest(): void
    {
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);
        $readDb = $this->createMock(PdoDatabase::class);
        $readDb->expects($this->never())->method('execute');

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            readRepository: new FeedReadRepository($readDb)
        );

        $service->markFeedAsRead(58, $guest);
    }

    public function testMarkFeedAsReadDelegatesToReadRepository(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $readDb = $this->createMock(PdoDatabase::class);
        $readDb
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INSERT INTO'),
                    $this->stringContains('feed_reads')
                ),
                [58, 7, null]
            )
            ->willReturn(1);

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            readRepository: new FeedReadRepository($readDb)
        );

        $service->markFeedAsRead(58, $user);
    }

    public function testGetFeedReadAtReturnsNullWithoutAReadRepository(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $service = $this->makeService(new FeedRepository($this->createStub(PdoDatabase::class)));

        $this->assertNull($service->getFeedReadAt(58, $user));
    }

    public function testGetFeedReadAtReturnsNullForGuest(): void
    {
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);
        $readDb = $this->createMock(PdoDatabase::class);
        $readDb->expects($this->never())->method('fetchOne');

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            readRepository: new FeedReadRepository($readDb)
        );

        $this->assertNull($service->getFeedReadAt(58, $guest));
    }

    public function testGetFeedReadAtDelegatesToReadRepository(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $readDb = $this->createMock(PdoDatabase::class);
        $readDb
            ->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('feed_reads'), [58, 7])
            ->willReturn(['read_at' => '1700000000']);

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            readRepository: new FeedReadRepository($readDb)
        );

        $this->assertSame(1700000000, $service->getFeedReadAt(58, $user));
    }

    public function testGetFeedReadAtMapReturnsEmptyArrayWithoutAReadRepository(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $service = $this->makeService(new FeedRepository($this->createStub(PdoDatabase::class)));

        $this->assertSame([], $service->getFeedReadAtMap([58, 60], $user));
    }

    public function testGetFeedReadAtMapReturnsEmptyArrayForGuest(): void
    {
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);
        $readDb = $this->createMock(PdoDatabase::class);
        $readDb->expects($this->never())->method('fetchAll');

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            readRepository: new FeedReadRepository($readDb)
        );

        $this->assertSame([], $service->getFeedReadAtMap([58, 60], $guest));
    }

    public function testGetFeedReadAtMapDelegatesToReadRepository(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $readDb = $this->createMock(PdoDatabase::class);
        $readDb
            ->expects($this->once())
            ->method('fetchAll')
            ->with($this->stringContains('feed_reads'), [7, 58, 60])
            ->willReturn([
                ['parent_id' => '58', 'read_at' => '1700000000'],
            ]);

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            readRepository: new FeedReadRepository($readDb)
        );

        $this->assertSame([58 => 1700000000], $service->getFeedReadAtMap([58, 60], $user));
    }

    public function testIsFeedReadReturnsFalseWhenNeverRead(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $readDb = $this->createMock(PdoDatabase::class);
        $readDb->expects($this->once())->method('fetchOne')->willReturn(null);

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            readRepository: new FeedReadRepository($readDb)
        );

        $this->assertFalse($service->isFeedRead(58, $user));
    }

    public function testIsFeedReadReturnsTrueWithoutSinceWhenEverRead(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $readDb = $this->createMock(PdoDatabase::class);
        $readDb->expects($this->once())->method('fetchOne')->willReturn(['read_at' => '1700000000']);

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            readRepository: new FeedReadRepository($readDb)
        );

        $this->assertTrue($service->isFeedRead(58, $user));
    }

    public function testIsFeedReadReturnsTrueWhenReadAtOrAfterSince(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $readDb = $this->createMock(PdoDatabase::class);
        $readDb->expects($this->once())->method('fetchOne')->willReturn(['read_at' => '1700000000']);

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            readRepository: new FeedReadRepository($readDb)
        );

        $this->assertTrue($service->isFeedRead(58, $user, 1700000000));
    }

    public function testIsFeedReadReturnsFalseWhenReadBeforeSince(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $readDb = $this->createMock(PdoDatabase::class);
        $readDb->expects($this->once())->method('fetchOne')->willReturn(['read_at' => '1700000000']);

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            readRepository: new FeedReadRepository($readDb)
        );

        $this->assertFalse($service->isFeedRead(58, $user, 1700000001));
    }

    public function testMarkFeedAsReadDelegatesToGuestReadStoreForGuest(): void
    {
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);
        $readDb = $this->createMock(PdoDatabase::class);
        $readDb->expects($this->never())->method('execute');

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            readRepository: new FeedReadRepository($readDb),
            guestReadStore: new GuestFeedReadStore(),
        );

        $service->markFeedAsRead(58, $guest);

        $this->assertNotNull((new GuestFeedReadStore())->findReadAt(58));
    }

    public function testGetFeedReadAtReadsGuestCookieViaGuestReadStore(): void
    {
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);
        $_COOKIE['feed_reads'] = json_encode(['58' => ['position' => null, 'readAt' => 1700000000]], JSON_FORCE_OBJECT);

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            guestReadStore: new GuestFeedReadStore(),
        );

        $this->assertSame(1700000000, $service->getFeedReadAt(58, $guest));
    }

    public function testGetFeedReadAtMapReadsGuestCookieViaGuestReadStore(): void
    {
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);
        $_COOKIE['feed_reads'] = json_encode(['58' => ['position' => null, 'readAt' => 1700000000]], JSON_FORCE_OBJECT);

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            guestReadStore: new GuestFeedReadStore(),
        );

        $this->assertSame([58 => 1700000000], $service->getFeedReadAtMap([58, 60], $guest));
    }

    public function testIsFeedReadWorksForGuestsWithAGuestReadStore(): void
    {
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            guestReadStore: new GuestFeedReadStore(),
        );

        $this->assertFalse($service->isFeedRead(58, $guest));

        $service->markFeedAsRead(58, $guest);

        $this->assertTrue($service->isFeedRead(58, $guest));
    }

    public function testGetUnfinishedBooksReturnsEmptyArrayWithoutAReadRepositoryForRegisteredUser(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $service = $this->makeService(new FeedRepository($this->createStub(PdoDatabase::class)));

        $this->assertSame([], $service->getUnfinishedFeeds($user, 'publication', 'chapter'));
    }

    public function testGetUnfinishedBooksReturnsEmptyArrayWithoutAGuestReadStoreForGuest(): void
    {
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);
        $service = $this->makeService(new FeedRepository($this->createStub(PdoDatabase::class)));

        $this->assertSame([], $service->getUnfinishedFeeds($guest, 'publication', 'chapter'));
    }

    /**
     * Two books have been read into: book 58 (last read page at position 1
     * of 3) and book 70 (last read page at position 2 of 2, i.e. its last
     * page - finished). Only 58 should come back.
     */
    public function testGetUnfinishedBooksExcludesBooksReadToTheirLastPage(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $createdAt = time() - 4000;

        $readDb = $this->createMock(PdoDatabase::class);
        $readDb
            ->expects($this->once())
            ->method('fetchAll')
            ->with($this->stringContains('feed_reads'), [7, 'publication'])
            ->willReturn([
                ['parent_id' => '58', 'position' => '1'],
                ['parent_id' => '70', 'position' => '2'],
            ]);

        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->exactly(4))
            ->method('fetchAll')
            ->willReturnCallback(function (string $sql, array $params) use ($createdAt) {
                if (str_contains($sql, 'MAX(position)')) {
                    $this->assertSame(['chapter', 58, 70], $params);

                    return [
                        ['parent_id' => 58, 'last_position' => 3],
                        ['parent_id' => 70, 'last_position' => 2],
                    ];
                }

                if (str_contains($sql, '(parent_id = ? AND position = ?)')) {
                    $this->assertSame(['chapter', 58, 1], $params);

                    return [
                        ['id' => 60, 'parent_id' => 58],
                    ];
                }

                if (in_array('chapter', $params, true)) {
                    $this->assertSame([60, 'chapter', 7, 7], $params);

                    return [
                        [
                            'id' => 60,
                            'parent_id' => 58,
                            'owner_id' => 1,
                            'type' => 'chapter',
                            'slug' => 'feed-60',
                            'title' => 'Page 2',
                            'content' => null,
                            'description' => null,
                            'image_url' => null,
                            'container_id' => null,
                            'visibility' => 'public',
                            'position' => 1,
                            'created_at' => $createdAt,
                            'rating_sum' => 0,
                            'rating_count' => 0,
                            'nick' => 'Author 1',
                            'avatar_url' => '/uploads/avatar-1.webp',
                        ],
                    ];
                }

                $this->assertSame([58, 'publication', 7, 7], $params);

                return [
                    [
                        'id' => 58,
                        'parent_id' => null,
                        'owner_id' => 1,
                        'type' => 'publication',
                        'slug' => 'feed-58',
                        'title' => 'Book 58',
                        'content' => null,
                        'description' => null,
                        'image_url' => null,
                        'container_id' => null,
                        'visibility' => 'public',
                        'position' => 0,
                        'created_at' => $createdAt,
                        'rating_sum' => 0,
                        'rating_count' => 0,
                        'nick' => 'Author 1',
                        'avatar_url' => '/uploads/avatar-1.webp',
                    ],
                ];
            });

        $service = $this->makeService(
            new FeedRepository($db),
            $this->makeUrlGenerator([
                58 => $this->makeFeed(id: 58, createdAt: $createdAt, type: 'publication'),
                60 => $this->makeFeed(id: 60, parentId: 58, createdAt: $createdAt, type: 'chapter'),
            ]),
            readRepository: new FeedReadRepository($readDb)
        );

        $result = $service->getUnfinishedFeeds($user, 'publication', 'chapter', 10);

        $this->assertCount(1, $result);
        $this->assertSame(58, $result[0]->id);
        // Links to where they left off (page 60), not the book's own page.
        $this->assertStringContainsString('feed-60', $result[0]->canonicalUrl);
        // Read through position 1 of 3 (0-based) = page 2 of 4 = 50%.
        $this->assertSame(50, $result[0]->readingProgress);
    }

    /**
     * Same scenario as above, but for a guest: the cookie already stores
     * {bookId: {position, readAt}} directly (one entry per book, not per
     * page), so no extra resolution step is needed before it lines up with
     * the registered-user shape.
     */
    public function testGetUnfinishedBooksResolvesGuestCookieToUnfinishedBooks(): void
    {
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);
        $createdAt = time() - 4000;

        $_COOKIE['feed_reads'] = json_encode(
            [
                '58' => ['position' => 1, 'readAt' => 1700000100],
                '70' => ['position' => 2, 'readAt' => 1700000050],
            ],
            JSON_FORCE_OBJECT
        );

        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->exactly(4))
            ->method('fetchAll')
            ->willReturnCallback(function (string $sql, array $params) use ($createdAt) {
                if (str_contains($sql, 'MAX(position)')) {
                    $this->assertSame(['chapter', 58, 70], $params);

                    return [
                        ['parent_id' => 58, 'last_position' => 3],
                        ['parent_id' => 70, 'last_position' => 2],
                    ];
                }

                if (str_contains($sql, '(parent_id = ? AND position = ?)')) {
                    $this->assertSame(['chapter', 58, 1], $params);

                    return [
                        ['id' => 60, 'parent_id' => 58],
                    ];
                }

                if (in_array('chapter', $params, true)) {
                    $this->assertSame([60, 'chapter', 0, 0], $params);

                    return [
                        [
                            'id' => 60,
                            'parent_id' => 58,
                            'owner_id' => 1,
                            'type' => 'chapter',
                            'slug' => 'feed-60',
                            'title' => 'Page 2',
                            'content' => null,
                            'description' => null,
                            'image_url' => null,
                            'container_id' => null,
                            'visibility' => 'public',
                            'position' => 1,
                            'created_at' => $createdAt,
                            'rating_sum' => 0,
                            'rating_count' => 0,
                            'nick' => 'Author 1',
                            'avatar_url' => '/uploads/avatar-1.webp',
                        ],
                    ];
                }

                return [
                    [
                        'id' => 58,
                        'parent_id' => null,
                        'owner_id' => 1,
                        'type' => 'publication',
                        'slug' => 'feed-58',
                        'title' => 'Book 58',
                        'content' => null,
                        'description' => null,
                        'image_url' => null,
                        'container_id' => null,
                        'visibility' => 'public',
                        'position' => 0,
                        'created_at' => $createdAt,
                        'rating_sum' => 0,
                        'rating_count' => 0,
                        'nick' => 'Author 1',
                        'avatar_url' => '/uploads/avatar-1.webp',
                    ],
                ];
            });

        $service = $this->makeService(
            new FeedRepository($db),
            $this->makeUrlGenerator([
                58 => $this->makeFeed(id: 58, createdAt: $createdAt, type: 'publication'),
                60 => $this->makeFeed(id: 60, parentId: 58, createdAt: $createdAt, type: 'chapter'),
            ]),
            guestReadStore: new GuestFeedReadStore()
        );

        $result = $service->getUnfinishedFeeds($guest, 'publication', 'chapter', 10);

        $this->assertCount(1, $result);
        $this->assertSame(58, $result[0]->id);
        $this->assertStringContainsString('feed-60', $result[0]->canonicalUrl);
        $this->assertSame(50, $result[0]->readingProgress);
    }

    public function testRemoveReadProgressForBookDeletesForRegisteredUser(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $readDb = $this->createMock(PdoDatabase::class);
        $readDb
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('DELETE FROM'),
                    $this->stringContains('feed_reads')
                ),
                [7, 58]
            );

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            readRepository: new FeedReadRepository($readDb)
        );

        $service->removeReadProgressForFeed(58, $user);
    }

    public function testRemoveReadProgressForBookRemovesFromGuestReadStore(): void
    {
        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);

        $_COOKIE['feed_reads'] = json_encode(
            [
                '58' => ['position' => 3, 'readAt' => 1700000000],
                '99' => ['position' => 1, 'readAt' => 1700000200],
            ],
            JSON_FORCE_OBJECT
        );

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            guestReadStore: new GuestFeedReadStore()
        );

        $service->removeReadProgressForFeed(58, $guest);

        $store = new GuestFeedReadStore();
        $this->assertNull($store->findReadAt(58));
        $this->assertNotNull($store->findReadAt(99));
    }

    private function invokeValidateFeedImage(FeedService $service, ?string $path): ?string
    {
        return (new ReflectionMethod(FeedService::class, 'validateFeedImage'))
            ->invoke($service, $path);
    }

    public function testValidateFeedImageAcceptsNullOrEmptyPath(): void
    {
        $service = $this->makeService(new FeedRepository($this->createStub(PdoDatabase::class)));

        $this->assertNull($this->invokeValidateFeedImage($service, null));
        $this->assertNull($this->invokeValidateFeedImage($service, ''));
    }

    public function testValidateFeedImageAcceptsValidPath(): void
    {
        $service = $this->makeService(new FeedRepository($this->createStub(PdoDatabase::class)));

        $this->assertSame(
            '/uploads/feed-covers/photo_1.jpg',
            $this->invokeValidateFeedImage($service, '/uploads/feed-covers/photo_1.jpg')
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidFeedImagePathProvider(): array
    {
        return [
            'too long' => [
                '/' . str_repeat('a', 255) . '.jpg',
                'feed.image_path_too_long',
            ],
            'not absolute' => [
                'uploads/photo.jpg',
                'feed.image_path_must_be_absolute',
            ],
            'path traversal' => [
                '/uploads/../../etc/passwd.jpg',
                'feed.image_path_invalid_path',
            ],
            'invalid characters' => [
                '/uploads/photo<script>.jpg',
                'feed.image_path_invalid_characters',
            ],
            'not an image extension' => [
                '/uploads/photo.exe',
                'feed.image_path_must_be_image',
            ],
        ];
    }

    #[DataProvider('invalidFeedImagePathProvider')]
    public function testValidateFeedImageRejectsInvalidPaths(string $path, string $expectedMessageKey): void
    {
        $service = $this->makeService(new FeedRepository($this->createStub(PdoDatabase::class)));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans($expectedMessageKey));

        $this->invokeValidateFeedImage($service, $path);
    }

    public function testCreateFeedInsertsSanitizedFeedAndReturnsPersistedResult(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $createdAt = time() - 50;

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeService($repository, $this->makeUrlGenerator());

        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INSERT INTO'),
                    $this->stringContains('feeds')
                ),
                $this->callback(static function (array $params): bool {
                    return $params[0] === null
                        && $params[1] === 7
                        && $params[2] === 'New Article'
                        && $params[3] === 'article'
                        && $params[4] === 'new-article'
                        && $params[5] === '<p>Hello</p>'
                        && $params[6] === 'Nice <b>desc</b>'
                        && $params[7] === '/uploads/feed-covers/photo.jpg';
                })
            );

        $db->expects($this->once())->method('lastInsertId')->willReturn(300);

        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->willReturn([
                'id' => 300,
                'parent_id' => null,
                'owner_id' => 7,
                'type' => 'article',
                'slug' => 'new-article',
                'title' => 'New Article',
                'content' => '<p>Hello</p>',
                'description' => 'Nice <b>desc</b>',
                'image_url' => '/uploads/feed-covers/photo.jpg',
                'container_id' => null,
                'visibility' => 'public',
                'position' => 1,
                'created_at' => $createdAt,
                'nick' => 'Author 7',
                'avatar_url' => '/uploads/avatar-7.webp',
            ]);

        $feed = $service->createFeed(
            title: 'New Article',
            slug: 'new-article',
            type: 'article',
            parentId: null,
            description: 'Nice <b>desc</b><script>bad()</script>',
            imageUrl: '/uploads/feed-covers/photo.jpg',
            content: '<p>Hello</p><script>alert(1)</script>',
            user: $user
        );

        $this->assertSame(300, $feed->id);
        $this->assertSame('New Article', $feed->title);
        $this->assertSame('<p>Hello</p>', $feed->content);
        $this->assertSame('Nice <b>desc</b>', $feed->description);
    }

    public function testCreateFeedThrowsValidationExceptionWhenParentFeedIsMissing(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeService($repository, $this->makeUrlGenerator());

        $db->expects($this->once())->method('fetchOne')->willReturn(null);
        $db->expects($this->never())->method('execute');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('feed.parent_not_found'));

        $service->createFeed(
            title: 'Child',
            slug: 'child',
            type: 'article',
            parentId: 999,
            description: null,
            imageUrl: null,
            content: '<p>Body</p>',
            user: $user
        );
    }

    public function testUpdateFeedUpdatesSanitizedFieldsAndReturnsRefreshedFeed(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $existingCreatedAt = time() - 5000;

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $accessService = $this->createMock(AccessService::class);

        $accessService
            ->expects($this->exactly(2))
            ->method('canAccessFeed')
            ->willReturn(true);

        $accessService
            ->expects($this->once())
            ->method('canEditFeed')
            ->willReturn(true);

        $existingRow = [
            'id' => 42,
            'parent_id' => null,
            'owner_id' => 7,
            'type' => 'article',
            'slug' => 'old-slug',
            'title' => 'Old Title',
            'content' => '<p>Old</p>',
            'description' => 'Old desc',
            'image_url' => null,
            'container_id' => null,
            'visibility' => 'public',
            'position' => 1,
            'created_at' => $existingCreatedAt,
            'nick' => 'Author 7',
            'avatar_url' => '/uploads/avatar-7.webp',
        ];

        $updatedRow = array_merge($existingRow, [
            'slug' => 'updated-slug',
            'title' => 'Updated Title',
            'content' => '<p>Updated</p>',
            'description' => 'Nice <b>desc</b>',
            'image_url' => '/uploads/feed-covers/new.jpg',
        ]);

        $db
            ->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls($existingRow, $updatedRow);

        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE feeds'),
                $this->callback(static function (array $params): bool {
                    return $params[0] === 'Updated Title'
                        && $params[1] === 'updated-slug'
                        && $params[2] === null
                        && $params[3] === 'article'
                        && $params[4] === 'Nice <b>desc</b>'
                        && $params[5] === '/uploads/feed-covers/new.jpg'
                        && $params[6] === '<p>Updated</p>'
                        && $params[7] === 42;
                })
            );

        $service = $this->makeService(
            $repository,
            $this->makeUrlGenerator([42 => $this->makeFeed(id: 42, type: 'article')]),
            $accessService
        );

        $result = $service->updateFeed(42, [
            'title' => 'Updated Title',
            'slug' => 'updated-slug',
            'parentId' => null,
            'type' => 'article',
            'description' => 'Nice <b>desc</b><script>bad()</script>',
            'imageUrl' => '/uploads/feed-covers/new.jpg',
            'content' => '<p>Updated</p><script>alert(1)</script>',
        ], $user);

        $this->assertSame(42, $result->id);
        $this->assertSame('Updated Title', $result->title);
        $this->assertSame('updated-slug', $result->slug);
        $this->assertSame('<p>Updated</p>', $result->content);
        $this->assertSame('Nice <b>desc</b>', $result->description);
        $this->assertSame('/uploads/feed-covers/new.jpg', $result->imageUrl);
    }

    public function testUpdateFeedPreservesOmittedFieldsAndClearsAnExplicitNull(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $accessService = $this->createMock(AccessService::class);

        $accessService
            ->expects($this->exactly(2))
            ->method('canAccessFeed')
            ->willReturn(true);
        $accessService
            ->expects($this->once())
            ->method('canEditFeed')
            ->willReturn(true);

        $existingRow = [
            'id' => 42,
            'parent_id' => null,
            'owner_id' => 7,
            'type' => 'publication',
            'slug' => 'old-slug',
            'title' => 'Old Title',
            'content' => '<p>Old</p>',
            'description' => 'Old description',
            'image_url' => '/uploads/old.jpg',
            'container_id' => null,
            'container_type' => null,
            'visibility' => 'private',
            'position' => 1,
            'created_at' => time() - 5000,
            'nick' => 'Author 7',
            'avatar_url' => '',
        ];
        $updatedRow = array_merge($existingRow, [
            'title' => 'Only the title changed',
            'description' => null,
        ]);

        $db
            ->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls($existingRow, $updatedRow);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('UPDATE feeds'),
                [
                    'Only the title changed',
                    'old-slug',
                    null,
                    'publication',
                    null,
                    '/uploads/old.jpg',
                    '<p>Old</p>',
                    42,
                ]
            );

        $service = $this->makeService(
            $repository,
            $this->makeUrlGenerator([42 => $this->makeFeed(id: 42, type: 'publication')]),
            $accessService
        );

        $result = $service->updateFeed(42, [
            'title' => 'Only the title changed',
            'description' => null,
        ], $user);

        $this->assertSame('Only the title changed', $result->title);
        $this->assertSame('old-slug', $result->slug);
        $this->assertSame('publication', $result->type);
        $this->assertNull($result->description);
        $this->assertSame('/uploads/old.jpg', $result->imageUrl);
        $this->assertSame('<p>Old</p>', $result->content);
    }

    public function testUpdateFeedThrowsNotFoundExceptionWhenFeedDoesNotExist(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $accessService = $this->createMock(AccessService::class);

        $db->expects($this->once())->method('fetchOne')->willReturn(null);
        $accessService->expects($this->never())->method('canAccessFeed');
        $accessService->expects($this->never())->method('canEditFeed');
        $db->expects($this->never())->method('execute');

        $service = $this->makeService($repository, $this->makeUrlGenerator(), $accessService);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage($this->trans('feed.not_found'));

        $service->updateFeed(999, [
            'title' => 'Whatever',
            'slug' => 'whatever',
            'parentId' => null,
            'type' => 'article',
            'description' => null,
            'imageUrl' => null,
            'content' => '<p>Body</p>',
        ], $user);
    }

    public function testUpdateFeedThrowsForbiddenExceptionWhenUserCannotEditFeed(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $accessService = $this->createMock(AccessService::class);

        $existingRow = [
            'id' => 42,
            'parent_id' => null,
            'owner_id' => 1,
            'type' => 'article',
            'slug' => 'old-slug',
            'title' => 'Old Title',
            'content' => '<p>Old</p>',
            'description' => 'Old desc',
            'image_url' => null,
            'container_id' => null,
            'visibility' => 'public',
            'position' => 1,
            'created_at' => time() - 5000,
            'nick' => 'Author 1',
            'avatar_url' => '/uploads/avatar-1.webp',
        ];

        $db->expects($this->once())->method('fetchOne')->willReturn($existingRow);
        $accessService->expects($this->once())->method('canAccessFeed')->willReturn(true);
        $accessService->expects($this->once())->method('canEditFeed')->willReturn(false);
        $db->expects($this->never())->method('execute');

        $service = $this->makeService(
            $repository,
            $this->makeUrlGenerator([42 => $this->makeFeed(id: 42, type: 'article')]),
            $accessService
        );

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage($this->trans('feed.forbidden'));

        $service->updateFeed(42, [
            'title' => 'Hacked Title',
            'slug' => 'old-slug',
            'parentId' => null,
            'type' => 'article',
            'description' => null,
            'imageUrl' => null,
            'content' => '<p>Old</p>',
        ], $user);
    }

    public function testDeleteFeedDeletesRowWhenOwnerCanEdit(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $accessService = $this->createMock(AccessService::class);

        $existingRow = [
            'id' => 902,
            'parent_id' => 900,
            'owner_id' => 7,
            'type' => 'blog-post',
            'slug' => 'my-post-title',
            'title' => 'My Post Title',
            'content' => '<p>Body</p>',
            'description' => null,
            'image_url' => null,
            'container_id' => null,
            'visibility' => 'public',
            'position' => 0,
            'created_at' => time(),
            'nick' => 'Author 7',
            'avatar_url' => '',
        ];

        $db->expects($this->once())->method('fetchOne')->willReturn($existingRow);
        $accessService->expects($this->once())->method('canAccessFeed')->willReturn(true);
        $accessService->expects($this->once())->method('canEditFeed')->willReturn(true);

        $db
            ->expects($this->once())
            ->method('execute')
            ->with('DELETE FROM feeds WHERE id = ?', [902]);

        // Parent 'blog' feed (900) has to be registered alongside the leaf
        // for UrlGenerator to resolve canonicalUrl cleanly - see the comment
        // on the blog/blog-post page pair in makeUrlGenerator().
        $service = $this->makeService(
            $repository,
            $this->makeUrlGenerator([
                900 => $this->makeFeed(id: 900, type: 'blog'),
                902 => $this->makeFeed(id: 902, parentId: 900, type: 'blog-post'),
            ]),
            $accessService
        );

        $service->deleteFeed(902, $user);
    }

    public function testDeleteFeedThrowsNotFoundExceptionWhenFeedDoesNotExist(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $accessService = $this->createMock(AccessService::class);

        $db->expects($this->once())->method('fetchOne')->willReturn(null);
        $accessService->expects($this->never())->method('canEditFeed');
        $db->expects($this->never())->method('execute');

        $service = $this->makeService($repository, $this->makeUrlGenerator(), $accessService);

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage($this->trans('feed.not_found'));

        $service->deleteFeed(999, $user);
    }

    public function testDeleteFeedThrowsForbiddenExceptionWhenUserCannotEditFeed(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $accessService = $this->createMock(AccessService::class);

        $existingRow = [
            'id' => 902,
            'parent_id' => 900,
            'owner_id' => 1,
            'type' => 'blog-post',
            'slug' => 'my-post-title',
            'title' => 'My Post Title',
            'content' => '<p>Body</p>',
            'description' => null,
            'image_url' => null,
            'container_id' => null,
            'visibility' => 'public',
            'position' => 0,
            'created_at' => time(),
            'nick' => 'Author 1',
            'avatar_url' => '',
        ];

        $db->expects($this->once())->method('fetchOne')->willReturn($existingRow);
        $accessService->expects($this->once())->method('canAccessFeed')->willReturn(true);
        $accessService->expects($this->once())->method('canEditFeed')->willReturn(false);
        $db->expects($this->never())->method('execute');

        $service = $this->makeService(
            $repository,
            $this->makeUrlGenerator([
                900 => $this->makeFeed(id: 900, type: 'blog'),
                902 => $this->makeFeed(id: 902, parentId: 900, type: 'blog-post'),
            ]),
            $accessService
        );

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage($this->trans('feed.forbidden'));

        $service->deleteFeed(902, $user);
    }

    /**
     * FeedService::canEditFeed() is a thin public wrapper over
     * AccessService::canEditFeed() - used by the blog-post/community-post
     * show pages (UsersController) to decide whether to render the
     * delete/edit buttons without duplicating the owner/admin/container-
     * moderator policy deleteFeed()/updateFeed() already enforce.
     */
    public function testCanEditFeedDelegatesToAccessService(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $feed = $this->makeFeed(id: 902, type: 'blog-post');

        $accessService = $this->createMock(AccessService::class);
        $accessService
            ->expects($this->once())
            ->method('canEditFeed')
            ->with($user, $feed)
            ->willReturn(true);

        $service = $this->makeService(
            new FeedRepository($this->createStub(PdoDatabase::class)),
            $this->makeUrlGenerator(),
            $accessService
        );

        $this->assertTrue($service->canEditFeed($feed, $user));
    }

    /* ===============================
       Comment editing and deletion
    =============================== */

    /**
     * A raw comment row as FeedRepository::findById() selects it.
     *
     * @return array<string, mixed>
     */
    private function commentRow(
        string $type = 'comment',
        ?int $ageSeconds = 0,
        int $ownerId = 7,
        int $id = 90
    ): array {
        return [
            'id' => $id,
            'parent_id' => 58,
            'owner_id' => $ownerId,
            'type' => $type,
            'slug' => 'comment-'.$id,
            'title' => 'Заголовок',
            'content' => '<p>Старый текст</p>',
            'description' => 'Описание',
            'image_url' => '/uploads/x.png',
            'container_id' => null,
            'visibility' => 'public',
            'position' => 0,
            'created_at' => $ageSeconds === null ? null : time() - $ageSeconds,
            'nick' => 'Author',
            'avatar_url' => '/uploads/avatar-7.webp',
        ];
    }

    /**
     * @param array<string, mixed>|null $row what findById() finds
     * @param list<array{string, array}> $writes filled in with every execute()
     */
    private function makeCommentService(
        ?array $row,
        bool $canEdit = true,
        bool $isAdmin = false,
        array &$writes = []
    ): FeedService {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn($row);
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []) use (&$writes): int {
                $writes[] = [$sql, $params];

                return 1;
            }
        );

        $accessService = $this->createStub(AccessService::class);
        $accessService->method('canEditFeed')->willReturn($canEdit);
        $accessService->method('isAdmin')->willReturn($isAdmin);

        // No seeded UrlGenerator needed, unlike the search tests: editComment()
        // finishes with decorateFeed(), which only formats timestamps and never
        // resolves a URL.
        return $this->makeService(new FeedRepository($db), null, $accessService);
    }

    private function user(int $id = 7, string $role = AccessService::ROLE_USER): User
    {
        return new User(id: $id, email: 'user@example.com', role: $role);
    }

    #[DataProvider('commentMutationProvider')]
    public function testCommentMutationsRefuseAGuest(string $method): void
    {
        $service = $this->makeCommentService($this->commentRow());

        $this->expectException(ForbiddenException::class);

        $guest = new User(id: 0, email: '', role: AccessService::ROLE_USER);

        $method === 'edit'
            ? $service->editComment(90, 'новый текст', $guest)
            : $service->deleteComment(90, $guest);
    }

    /** @return array<string, array{string}> */
    public static function commentMutationProvider(): array
    {
        return [
            'editComment' => ['edit'],
            'deleteComment' => ['delete'],
        ];
    }

    #[DataProvider('commentMutationProvider')]
    public function testCommentMutationsReportAMissingCommentAsNotFound(string $method): void
    {
        $service = $this->makeCommentService(null);

        try {
            $method === 'edit'
                ? $service->editComment(90, 'новый текст', $this->user())
                : $service->deleteComment(90, $this->user());

            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            // 404/not_found rather than a bare 400 - the API turns this into the
            // status the client branches on.
            $this->assertSame(404, $e->getHttpCode());
            $this->assertSame('not_found', $e->getCodeName());
        }
    }

    /**
     * The type gate is what stops the comment endpoint being a generic feed
     * editor: it takes a feed id, so without this an article or a blog post
     * could be rewritten through it.
     */
    #[DataProvider('nonCommentTypeProvider')]
    public function testEditCommentRefusesAnythingThatIsNotAComment(string $type): void
    {
        $writes = [];
        $service = $this->makeCommentService($this->commentRow(type: $type), writes: $writes);

        try {
            $service->editComment(90, 'новый текст', $this->user());
            $this->fail('expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(404, $e->getHttpCode());
            $this->assertSame([], $writes);
        }
    }

    /** @return array<string, array{string}> */
    public static function nonCommentTypeProvider(): array
    {
        return [
            'article' => ['article'],
            'blog-post' => ['blog-post'],
            'chapter' => ['chapter'],
        ];
    }

    #[DataProvider('editableCommentTypeProvider')]
    public function testEditCommentAcceptsCommentsAndForumPosts(string $type): void
    {
        $writes = [];
        $service = $this->makeCommentService($this->commentRow(type: $type), writes: $writes);

        $service->editComment(90, 'новый текст', $this->user());

        $this->assertTrue($this->wroteFeedUpdate($writes));
    }

    /** @return array<string, array{string}> */
    public static function editableCommentTypeProvider(): array
    {
        return [
            'comment' => ['comment'],
            'forum-post' => ['forum-post'],
        ];
    }

    /**
     * Deleting a topic's opening post would take every reply with it - `feeds`
     * has an ON DELETE CASCADE self-reference - so deleteComment() accepts only
     * 'comment', a narrower set than editComment()'s.
     */
    public function testDeleteCommentRefusesAForumPost(): void
    {
        $writes = [];
        $service = $this->makeCommentService($this->commentRow(type: 'forum-post'), writes: $writes);

        $this->expectException(ForbiddenException::class);

        try {
            $service->deleteComment(90, $this->user());
        } finally {
            $this->assertSame([], $writes);
        }
    }

    #[DataProvider('commentMutationProvider')]
    public function testCommentMutationsRefuseSomeoneWhoCannotEditTheFeed(string $method): void
    {
        $writes = [];
        $service = $this->makeCommentService($this->commentRow(), canEdit: false, writes: $writes);

        $this->expectException(ForbiddenException::class);

        try {
            $method === 'edit'
                ? $service->editComment(90, 'новый текст', $this->user(99))
                : $service->deleteComment(90, $this->user(99));
        } finally {
            $this->assertSame([], $writes);
        }
    }

    /**
     * A 24h window on top of ownership (COMMENT_EDIT_WINDOW_SECONDS), which an
     * admin is exempt from. Exactly-at-the-boundary is left out for the same
     * reason as MessageServiceTest's edit window: the row's created_at and the
     * check call time() independently.
     */
    public function testEditCommentRefusesAnOwnerPastTheEditWindow(): void
    {
        $writes = [];
        $service = $this->makeCommentService(
            $this->commentRow(ageSeconds: FeedService::COMMENT_EDIT_WINDOW_SECONDS + 60),
            writes: $writes
        );

        $this->expectException(ForbiddenException::class);

        try {
            $service->editComment(90, 'новый текст', $this->user());
        } finally {
            $this->assertSame([], $writes);
        }
    }

    public function testEditCommentLetsAnAdminPastTheEditWindow(): void
    {
        $writes = [];
        $service = $this->makeCommentService(
            $this->commentRow(ageSeconds: FeedService::COMMENT_EDIT_WINDOW_SECONDS + 60),
            isAdmin: true,
            writes: $writes
        );

        $service->editComment(90, 'новый текст', $this->user(role: AccessService::ROLE_ADMIN));

        $this->assertTrue($this->wroteFeedUpdate($writes));
    }

    public function testEditCommentAllowsACommentWithNoTimestamp(): void
    {
        // createdAt === null short-circuits the window check rather than being
        // treated as "epoch, therefore expired".
        $writes = [];
        $service = $this->makeCommentService($this->commentRow(ageSeconds: null), writes: $writes);

        $service->editComment(90, 'новый текст', $this->user());

        $this->assertTrue($this->wroteFeedUpdate($writes));
    }

    /**
     * The important one. editComment() re-sends every column update() takes, so
     * anything it got wrong would be a silent overwrite - and since the same
     * repository call backs updateFeed(), passing attacker-controlled values
     * here would turn a comment edit into a feed edit. Only content may change.
     */
    public function testEditCommentChangesOnlyTheContent(): void
    {
        $writes = [];
        $row = $this->commentRow();
        $service = $this->makeCommentService($row, writes: $writes);

        $service->editComment(90, 'новый текст', $this->user());

        [$sql, $params] = $this->feedUpdate($writes);

        $this->assertStringContainsString('UPDATE feeds', $sql);
        // Order per FeedRepository::update(): title, slug, parent_id, type,
        // description, image_url, content, id.
        $this->assertSame($row['title'], $params[0]);
        $this->assertSame($row['slug'], $params[1]);
        $this->assertSame($row['parent_id'], $params[2]);
        $this->assertSame($row['type'], $params[3]);
        $this->assertSame($row['description'], $params[4]);
        $this->assertSame($row['image_url'], $params[5]);
        $this->assertStringContainsString('новый текст', (string) $params[6]);
        $this->assertSame(90, $params[7]);

        // visibility is only appended when passed, and editComment() must not
        // pass it - eight params, not nine.
        $this->assertCount(8, $params);
    }

    #[DataProvider('invalidCommentContentProvider')]
    public function testEditCommentValidatesContent(string $content): void
    {
        $writes = [];
        $service = $this->makeCommentService($this->commentRow(), writes: $writes);

        $this->expectException(ValidationException::class);

        try {
            $service->editComment(90, $content, $this->user());
        } finally {
            $this->assertSame([], $writes);
        }
    }

    /** @return array<string, array{string}> */
    public static function invalidCommentContentProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ["  \n\t "],
            'over 5000 characters' => [str_repeat('я', 5001)],
        ];
    }

    public function testDeleteCommentDeletesTheRow(): void
    {
        $writes = [];
        $service = $this->makeCommentService($this->commentRow(), writes: $writes);

        $service->deleteComment(90, $this->user());

        $this->assertTrue(
            $this->wrote($writes, 'DELETE'),
            'the comment should have been deleted'
        );
    }

    /** @param list<array{string, array}> $writes */
    private function wrote(array $writes, string $needle): bool
    {
        foreach ($writes as [$sql, $_params]) {
            if (str_contains($sql, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{string, array}> $writes */
    private function wroteFeedUpdate(array $writes): bool
    {
        return $this->wrote($writes, 'UPDATE feeds');
    }

    /**
     * @param list<array{string, array}> $writes
     * @return array{string, array}
     */
    private function feedUpdate(array $writes): array
    {
        foreach ($writes as $write) {
            if (str_contains($write[0], 'UPDATE feeds')) {
                return $write;
            }
        }

        $this->fail('no feed update was performed');
    }

    /**
     * A raw search result row, with every column Feed::fromRow() insists on.
     *
     * @return array<string, mixed>
     */
    private function searchRow(int $id, float $relevance): array
    {
        return [
            'id' => $id,
            'parent_id' => null,
            'owner_id' => 1,
            'type' => 'article',
            'container_id' => null,
            'relevance' => $relevance,
            'search_priority' => 1,
        ];
    }

    /**
     * search() over-fetches by one to learn whether another page exists, and the
     * cursor must be the last row *kept*, not that probe row.
     *
     * It used to encode `$rows[$limit]` - the probe itself, i.e. the first row of
     * the next page - which the keyset predicate in FeedRepository::search()
     * then excluded as already-seen. So one row vanished at every page boundary,
     * on top of the duplicates the old predicate caused. This is the half of
     * that fix living in the service; the predicate half is pinned in
     * FeedRepositoryTest.
     */
    public function testSearchCursorPointsAtTheLastRowOfThePageNotTheProbeRow(): void
    {
        $admin = new User(id: 1, email: 'admin@example.com', role: AccessService::ROLE_ADMIN);
        $limit = 2;

        // Three rows for a limit of two: two to return, one to prove there is
        // more. All have the same title priority, then relevance and id descend.
        //
        // Feed::fromRow() reads id/parent_id/owner_id/type/container_id without
        // a `??`, unlike every other column - so all five have to be here. That
        // is deliberate on its side and worth leaving alone: a warning is a
        // useful signal that a SELECT forgot a column, which is exactly what a
        // silent null would hide.
        $rows = [
            $this->searchRow(30, 3.0),
            $this->searchRow(20, 2.5),
            $this->searchRow(10, 1.0),
        ];

        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchAll')->willReturn($rows);

        // Seeded so decorateFeedsWithUrls() can resolve every row it is handed -
        // UrlGenerator::feeds() error_logs the ones it can't, which PHPUnit then
        // reports as unexpected output.
        $service = $this->makeService(
            new FeedRepository($db),
            $this->makeUrlGenerator([
                30 => $this->makeFeed(id: 30),
                20 => $this->makeFeed(id: 20),
            ])
        );

        $result = $service->search('книга', $limit, null, $admin);

        $this->assertCount($limit, $result['data']);
        $this->assertTrue($result['meta']['has_more']);
        $this->assertSame([30, 20], array_map(static fn ($feed) => $feed->id, $result['data']));

        $cursor = json_decode(base64_decode($result['meta']['next_cursor']), true);

        // Row 20 - the last one shown. Row 10 is the probe and belongs to the
        // next page, so pointing at it would have skipped it.
        $this->assertSame(20, $cursor['id']);
        $this->assertSame(2.5, $cursor['rank']);
        $this->assertSame(1, $cursor['priority']);
    }

    public function testSearchRejectsOldCursorWithoutTitlePriority(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('fetchAll');
        $service = $this->makeService(new FeedRepository($db));
        $this->expectException(\StreamEngine\Core\Exceptions\ValidationException::class);
        $service->search('книга', 10, base64_encode(json_encode(['rank' => 2.5, 'id' => 20])), new User(id: 0, email: '', role: AccessService::ROLE_USER));
    }

    public function testSearchReturnsNoCursorOnTheLastPage(): void
    {
        $admin = new User(id: 1, email: 'admin@example.com', role: AccessService::ROLE_ADMIN);

        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn([$this->searchRow(30, 3.0)]);

        $service = $this->makeService(
            new FeedRepository($db),
            $this->makeUrlGenerator([30 => $this->makeFeed(id: 30)])
        );

        $result = $service->search('книга', 2, null, $admin);

        $this->assertFalse($result['meta']['has_more']);
        $this->assertNull($result['meta']['next_cursor']);
    }
}

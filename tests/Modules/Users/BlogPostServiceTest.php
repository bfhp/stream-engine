<?php

declare(strict_types=1);

namespace Tests\Modules\Users;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Users\BlogPostService;
use StreamEngine\Repository\FeedMetadataRepository;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\FeedTermRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

final class BlogPostServiceTest extends TestCase
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

    /**
     * Builds a BlogPostService wired to a real FeedService (backed by the
     * same FeedRepository/PdoDatabase mock), the same way UsersController
     * gets it in production - StreamEngine.php wires both off the same
     * FeedRepository instance so calls made directly by BlogPostService
     * (getOrCreateUserBlogFeed/uniqueBlogPostSlug) and calls it makes
     * indirectly via FeedService::createFeed() hit the same mocked db.
     */
    private function makeBlogPostService(
        FeedRepository $repository,
        ?UrlGenerator $urlGenerator = null,
        ?FeedMetadataRepository $metadataRepository = null,
        ?FeedTermRepository $termRepository = null,
        ?PageTree $pageTree = null,
    ): BlogPostService {
        // $pageTree only needs to feed $urlGenerator here - BlogPostService
        // itself no longer takes a PageTree; canonicalUrl now comes from
        // $urlGenerator->feed(), whose generic chain walker resolves the
        // right page(s) using its own internal PageTree.
        $pageTree ??= $this->makePageTree();
        $urlGenerator ??= $this->makeUrlGenerator(pageTree: $pageTree);

        $feedService = new FeedService(
            $repository,
            $urlGenerator,
            $this->createStub(AccessService::class),
            new Formatter(new TranslationManager('ru', 'en'), 'ru'),
            new TranslationManager('ru', 'en'),
            $metadataRepository,
        );

        return new BlogPostService(
            $feedService,
            $repository,
            $urlGenerator,
            new TranslationManager('ru', 'en'),
            $termRepository,
        );
    }

    /**
     * Unlike makeBlogPostService() (which hardcodes an unconfigured
     * AccessService stub - fine for createBlogPost, which never calls
     * getFeedById()/canEditFeed()), deleteBlogPost() goes through
     * FeedService::getFeedById()/deleteFeed(), both of which do consult
     * AccessService - so these tests need to control what it returns.
     */
    private function makeBlogPostServiceWithAccessService(
        FeedRepository $repository,
        AccessService $accessService,
        ?UrlGenerator $urlGenerator = null,
        ?PageTree $pageTree = null,
    ): BlogPostService {
        $pageTree ??= $this->makePageTree();
        $urlGenerator ??= $this->makeUrlGenerator(pageTree: $pageTree);

        $feedService = new FeedService(
            $repository,
            $urlGenerator,
            $accessService,
            new Formatter(new TranslationManager('ru', 'en'), 'ru'),
            new TranslationManager('ru', 'en'),
        );

        return new BlogPostService(
            $feedService,
            $repository,
            $urlGenerator,
            new TranslationManager('ru', 'en'),
        );
    }

    private function trans(string $key, array $params = []): string
    {
        return (new TranslationManager('ru', 'en'))->trans($key, $params);
    }

    private function makePageTree(): PageTree
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
            // createBlogPost()/updateBlogPost()/getFeedById()/getFeedBySlug()
            // all resolve canonicalUrl via UrlGenerator::feed(), which walks
            // this blog/blog-post pair via the generic feed-type chain
            // walker (findByFeedType('blog') matches this page directly, so
            // the owner-scoped 'user.show' fallback in UrlGenerator isn't
            // exercised by this fixture) - without this pair those calls
            // log a (harmless, caught) "Feed not found"/"No page defined"
            // warning via error_log() when the lookup fails.
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
                accessRule: AccessService::ACCESS_PUBLIC,
                action: 'user.post-show',
            ),
            // Same reasoning as the blog/blog-post pair above -
            // deleteBlogPost()'s wrong-feed-type test resolves a feed whose
            // type is deliberately something other than 'blog-post', which
            // still needs a page to resolve against.
            new Page(
                id: 9,
                parentId: 1,
                pattern: 'articles/{slug}',
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
        ];

        return new PageTree($pages);
    }

    private function makeUrlGenerator(?array $feeds = null, ?PageTree $pageTree = null): UrlGenerator
    {
        return new UrlGenerator(
            $pageTree ?? $this->makePageTree(),
            new FakeFeedRepository($feeds ?? []),
            new ArrayCache()
        );
    }

    private function makeFeed(
        int $id,
        int $ownerId = 1,
        ?int $parentId = null,
        ?int $createdAt = null,
        string $type = 'blog-post',
        ?int $containerId = null,
        ?string $containerType = null,
        string $visibility = 'public',
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
            containerId: $containerId,
            visibility: $visibility,
            position: $id,
            createdAt: $createdAt,
            relevance: null,
            canonicalUrl: null,
            authorDisplayName: 'Author '.$ownerId,
            authorAvatarUrl: '/uploads/avatar-'.$ownerId.'.webp',
            containerType: $containerType,
        );
    }

    private function makeBlogFeedRow(int $id, int $ownerId, string $title): array
    {
        return [
            'id' => $id,
            'parent_id' => null,
            'owner_id' => $ownerId,
            'type' => 'blog',
            'slug' => null,
            'title' => $title,
            'content' => '',
            'description' => null,
            'image_url' => null,
            'container_id' => null,
            'visibility' => 'public',
            'position' => 0,
            'created_at' => time(),
            'nick' => '',
            'avatar_url' => '',
        ];
    }

    private function makeBlogPostRow(
        int $id,
        int $parentId,
        int $ownerId,
        string $slug,
        string $title,
        ?int $containerId = null,
        ?string $containerType = null,
        string $visibility = 'public',
    ): array {
        return [
            'id' => $id,
            'parent_id' => $parentId,
            'owner_id' => $ownerId,
            'type' => 'blog-post',
            'slug' => $slug,
            'title' => $title,
            'content' => '<p>Body</p>',
            'description' => null,
            'image_url' => null,
            'container_id' => $containerId,
            'container_type' => $containerType,
            'visibility' => $visibility,
            'position' => 0,
            'created_at' => time(),
            'nick' => '',
            'avatar_url' => '',
        ];
    }

    public function testGetOrCreateUserBlogFeedReturnsExistingBlogFeed(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeBlogPostService($repository, $this->makeUrlGenerator());

        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('f.owner_id = ?'),
                    $this->stringContains('f.type = ?')
                ),
                [7, 'blog', 7, 7]
            )
            ->willReturn($this->makeBlogFeedRow(900, 7, '#7'));

        $db->expects($this->never())->method('execute');

        $blog = $service->getOrCreateUserBlogFeed($user);

        $this->assertSame(900, $blog->id);
        $this->assertSame('blog', $blog->type);
    }

    public function testGetOrCreateUserBlogFeedCreatesBlogFeedWhenMissing(): void
    {
        // slug matters here: 'user.show' is registered with feed_type='blog'
        // and its own pattern is {username}, not {slug} -
        // UrlGenerator::fillFeedPlaceholder() fills that placeholder from
        // this feed's own slug column, so the blog feed needs a real slug
        // (the owner's username) or every post's canonicalUrl 404s. See
        // getOrCreateUserBlogFeed()'s own docblock.
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER, username: 'nicky42');

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeBlogPostService($repository, $this->makeUrlGenerator());

        $db
            ->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(null, $this->makeBlogFeedRow(901, 7, '#7'));

        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('INSERT INTO'),
                    $this->stringContains('feeds')
                ),
                $this->callback(static function (array $params): bool {
                    return $params[0] === null        // parentId
                        && $params[1] === 7            // ownerId
                        && $params[2] === '#7'         // title (no nick set)
                        && $params[3] === 'blog'       // type
                        && $params[4] === 'nicky42'    // slug (owner's username)
                        && $params[5] === '';          // content
                })
            );

        $db->expects($this->once())->method('lastInsertId')->willReturn(901);

        $blog = $service->getOrCreateUserBlogFeed($user);

        $this->assertSame(901, $blog->id);
        $this->assertSame('blog', $blog->type);
    }

    public function testGetOrCreateUserBlogFeedCreatesBlogFeedWithNullSlugWhenUsernameBlank(): void
    {
        // Defensive edge case, not expected for a real account (every user
        // that can reach this needs a username for /users/{username}/...
        // routing to work at all already) - createFeed() takes a nullable
        // slug, so a blank username falls back to null rather than an
        // empty-string slug.
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeBlogPostService($repository, $this->makeUrlGenerator());

        $db
            ->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(null, $this->makeBlogFeedRow(901, 7, '#7'));

        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $params): bool => $params[4] === null)
            );

        $db->expects($this->once())->method('lastInsertId')->willReturn(901);

        $service->getOrCreateUserBlogFeed($user);
    }

    public function testCreateBlogPostCreatesPostUnderExistingBlog(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $blogRow = $this->makeBlogFeedRow(900, 7, '#7');

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeBlogPostService($repository, $this->makeUrlGenerator([
            900 => $this->makeFeed(id: 900, type: 'blog'),
            902 => $this->makeFeed(id: 902, parentId: 900, type: 'blog-post'),
        ]));

        $db
            ->expects($this->exactly(4))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(
                $blogRow, // getOrCreateUserBlogFeed(): findByOwnerAndType
                null,     // uniqueBlogPostSlug(): findByParentAndSlug('my-post-title') -> free
                $blogRow, // createFeed(): parent existence check
                $this->makeBlogPostRow(902, 900, 7, 'my-post-title', 'My Post Title'),
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
                    return $params[0] === 900               // parentId
                        && $params[1] === 7                  // ownerId
                        && $params[2] === 'My Post Title'    // title
                        && $params[3] === 'blog-post'        // type
                        && $params[4] === 'my-post-title';   // slug
                })
            );

        $db->expects($this->once())->method('lastInsertId')->willReturn(902);

        $post = $service->createBlogPost(
            title: 'My Post Title',
            content: '<p>Body</p>',
            description: null,
            imageUrl: null,
            user: $user,
        );

        $this->assertSame(902, $post->id);
        $this->assertSame('blog-post', $post->type);
        $this->assertSame(900, $post->parentId);
        $this->assertSame('my-post-title', $post->slug);
    }

    /**
     * @return string[][]
     */
    public static function reservedBlogPostSlugTitleProvider(): array
    {
        // Titles that slugify (Formatter::slugify() lowercases first) to one
        // of BlogPostService::RESERVED_BLOG_POST_SLUGS - each collides with
        // a static page already mounted as a sibling of user.post-show under
        // user.show (e.g. 'post' is user.post-new's own pattern), so none of
        // these must ever be handed out as an actual post slug.
        return [
            ['Post'],
            ['Friends'],
            ['Rating'],
            ['Members'],
        ];
    }

    #[DataProvider('reservedBlogPostSlugTitleProvider')]
    public function testCreateBlogPostAvoidsReservedSlug(string $title): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $blogRow = $this->makeBlogFeedRow(900, 7, '#7');
        $expectedSlug = mb_strtolower($title).'-2';

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeBlogPostService($repository, $this->makeUrlGenerator([
            900 => $this->makeFeed(id: 900, type: 'blog'),
            902 => $this->makeFeed(id: 902, parentId: 900, type: 'blog-post'),
        ]));

        $db
            ->expects($this->exactly(4))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(
                $blogRow, // getOrCreateUserBlogFeed(): findByOwnerAndType
                // uniqueBlogPostSlug() never even queries the DB for the
                // reserved base itself - in_array() short-circuits the
                // while loop's OR before findByParentAndSlug() runs - so
                // the next fetchOne is already for the suffixed candidate.
                null,     // uniqueBlogPostSlug(): "$slug-2" is free
                $blogRow, // createFeed(): parent existence check
                $this->makeBlogPostRow(902, 900, 7, $expectedSlug, $title),
            );

        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->anything(),
                $this->callback(static function (array $params) use ($expectedSlug): bool {
                    return $params[4] === $expectedSlug;
                })
            );

        $db->expects($this->once())->method('lastInsertId')->willReturn(902);

        $post = $service->createBlogPost(
            title: $title,
            content: '<p>Body</p>',
            description: null,
            imageUrl: null,
            user: $user,
        );

        $this->assertSame($expectedSlug, $post->slug);
    }

    public function testCreateBlogPostAppendsNumericSuffixOnSlugCollision(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $blogRow = $this->makeBlogFeedRow(900, 7, '#7');
        $collidingPostRow = $this->makeBlogPostRow(500, 900, 7, 'my-post-title', 'My Post Title');

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeBlogPostService($repository, $this->makeUrlGenerator([
            900 => $this->makeFeed(id: 900, type: 'blog'),
            903 => $this->makeFeed(id: 903, parentId: 900, type: 'blog-post'),
        ]));

        $db
            ->expects($this->exactly(5))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(
                $blogRow,          // getOrCreateUserBlogFeed(): findByOwnerAndType
                $collidingPostRow, // uniqueBlogPostSlug(): 'my-post-title' is taken
                null,              // uniqueBlogPostSlug(): 'my-post-title-2' is free
                $blogRow,          // createFeed(): parent existence check
                $this->makeBlogPostRow(903, 900, 7, 'my-post-title-2', 'My Post Title'),
            );

        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->anything(),
                $this->callback(static function (array $params): bool {
                    return $params[4] === 'my-post-title-2';
                })
            );

        $db->expects($this->once())->method('lastInsertId')->willReturn(903);

        $post = $service->createBlogPost(
            title: 'My Post Title',
            content: '<p>Body</p>',
            description: null,
            imageUrl: null,
            user: $user,
        );

        $this->assertSame('my-post-title-2', $post->slug);
    }

    public function testCreateBlogPostThrowsForGuestUser(): void
    {
        $user = new User(id: 0, email: '', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeBlogPostService($repository, $this->makeUrlGenerator());

        $db->expects($this->never())->method('fetchOne');
        $db->expects($this->never())->method('execute');

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage($this->trans('feed.forbidden'));

        $service->createBlogPost(
            title: 'Title',
            content: '<p>Body</p>',
            description: null,
            imageUrl: null,
            user: $user,
        );
    }

    public function testCreateBlogPostThrowsWhenTitleIsEmpty(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeBlogPostService($repository, $this->makeUrlGenerator());

        $db->expects($this->never())->method('fetchOne');
        $db->expects($this->never())->method('execute');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('feed.blog_title_required'));

        $service->createBlogPost(
            title: '   ',
            content: '<p>Body</p>',
            description: null,
            imageUrl: null,
            user: $user,
        );
    }

    public function testCreateBlogPostThrowsWhenContentIsEmpty(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeBlogPostService($repository, $this->makeUrlGenerator());

        $db->expects($this->never())->method('fetchOne');
        $db->expects($this->never())->method('execute');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('feed.blog_content_required'));

        $service->createBlogPost(
            title: 'Title',
            content: '   ',
            description: null,
            imageUrl: null,
            user: $user,
        );
    }

    public function testCreateBlogPostThrowsWhenContentIsTrixEmptyMarkup(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeBlogPostService($repository, $this->makeUrlGenerator());

        $db->expects($this->never())->method('fetchOne');
        $db->expects($this->never())->method('execute');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('feed.blog_content_required'));

        // Trix always serializes a document, even an empty one, as HTML -
        // a truly empty post looks like this rather than an empty string.
        $service->createBlogPost(
            title: 'Title',
            content: '<div><br></div>',
            description: null,
            imageUrl: null,
            user: $user,
        );
    }

    public function testCreateBlogPostAcceptsImageOnlyContentViaFigureMarkup(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $blogRow = $this->makeBlogFeedRow(900, 7, '#7');
        $figureContent = '<div><figure class="attachment attachment--preview attachment--jpg">'
            .'<img src="https://example.com/photo.jpg" alt=""></figure></div>';

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeBlogPostService($repository, $this->makeUrlGenerator([
            900 => $this->makeFeed(id: 900, type: 'blog'),
            902 => $this->makeFeed(id: 902, parentId: 900, type: 'blog-post'),
        ]));

        $db
            ->expects($this->exactly(4))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(
                $blogRow,
                null,
                $blogRow,
                $this->makeBlogPostRow(902, 900, 7, 'my-post-title', 'My Post Title'),
            );

        $db->expects($this->once())->method('execute');
        $db->expects($this->once())->method('lastInsertId')->willReturn(902);

        $post = $service->createBlogPost(
            title: 'My Post Title',
            content: $figureContent,
            description: null,
            imageUrl: null,
            user: $user,
        );

        $this->assertSame(902, $post->id);
    }

    public function testCreateBlogPostPassesVisibilityThroughToInsert(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $blogRow = $this->makeBlogFeedRow(900, 7, '#7');
        $postRow = $this->makeBlogPostRow(
            id: 902,
            parentId: 900,
            ownerId: 7,
            slug: 'my-post-title',
            title: 'My Post Title',
            containerId: 900,
            containerType: 'blog',
            visibility: 'members',
        );

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeBlogPostService($repository, $this->makeUrlGenerator([
            900 => $this->makeFeed(id: 900, type: 'blog'),
            902 => $this->makeFeed(
                id: 902,
                parentId: 900,
                type: 'blog-post',
                containerId: 900,
                containerType: 'blog',
                visibility: 'members',
            ),
        ]));

        $db
            ->expects($this->exactly(4))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls($blogRow, null, $blogRow, $postRow);

        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->anything(),
                $this->callback(static function (array $params): bool {
                    return $params[8] === 'members'
                        && $params[9] === 900
                        && $params[10] === 'blog';
                })
            );

        $db->expects($this->once())->method('lastInsertId')->willReturn(902);

        $post = $service->createBlogPost(
            title: 'My Post Title',
            content: '<p>Body</p>',
            description: null,
            imageUrl: null,
            user: $user,
            visibility: 'members',
        );

        $this->assertSame('members', $post->visibility);
        $this->assertSame(900, $post->containerId);
        $this->assertSame('blog', $post->containerType);
    }

    public function testUpdateBlogPostSetsPersonalBlogContainerForMembersVisibility(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $existingRow = $this->makeBlogPostRow(902, 900, 7, 'my-post-title', 'My Post Title');
        $updatedRow = $this->makeBlogPostRow(
            id: 902,
            parentId: 900,
            ownerId: 7,
            slug: 'my-post-title',
            title: 'Friends Only',
            containerId: 900,
            containerType: 'blog',
            visibility: 'members',
        );
        $blogRow = $this->makeBlogFeedRow(900, 7, '#7');

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $accessService = $this->createMock(AccessService::class);
        $accessService->method('canAccessFeed')->willReturn(true);
        $accessService->expects($this->once())->method('canEditFeed')->willReturn(true);

        $db
            ->expects($this->exactly(4))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(
                $existingRow,
                $existingRow,
                $blogRow,
                $updatedRow,
            );

        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'container_id = ?')
                    && str_contains($sql, 'container_type = ?')),
                $this->callback(static function (array $params): bool {
                    return $params[7] === 'members'
                        && $params[8] === 900
                        && $params[9] === 'blog'
                        && $params[10] === 902;
                })
            );

        $service = $this->makeBlogPostServiceWithAccessService(
            $repository,
            $accessService,
            $this->makeUrlGenerator([
                900 => $this->makeFeed(id: 900, type: 'blog'),
                902 => $this->makeFeed(
                    id: 902,
                    parentId: 900,
                    type: 'blog-post',
                    containerId: 900,
                    containerType: 'blog',
                    visibility: 'members',
                ),
            ]),
        );

        $post = $service->updateBlogPost(
            id: 902,
            title: 'Friends Only',
            content: '<p>Body</p>',
            description: null,
            imageUrl: null,
            user: $user,
            visibility: 'members',
        );

        $this->assertSame('members', $post->visibility);
        $this->assertSame(900, $post->containerId);
        $this->assertSame('blog', $post->containerType);
    }

    public function testUpdateBlogPostClearsPersonalBlogContainerOutsideMembersVisibility(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $existingRow = $this->makeBlogPostRow(
            id: 902,
            parentId: 900,
            ownerId: 7,
            slug: 'my-post-title',
            title: 'Friends Only',
            containerId: 900,
            containerType: 'blog',
            visibility: 'members',
        );
        $updatedRow = $this->makeBlogPostRow(902, 900, 7, 'my-post-title', 'Public Again');
        $blogRow = $this->makeBlogFeedRow(900, 7, '#7');

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $accessService = $this->createMock(AccessService::class);
        $accessService->method('canAccessFeed')->willReturn(true);
        $accessService->expects($this->once())->method('canEditFeed')->willReturn(true);

        $db
            ->expects($this->exactly(4))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(
                $existingRow,
                $existingRow,
                $blogRow,
                $updatedRow,
            );

        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->anything(),
                $this->callback(static function (array $params): bool {
                    return $params[7] === 'public'
                        && $params[8] === null
                        && $params[9] === null
                        && $params[10] === 902;
                })
            );

        $service = $this->makeBlogPostServiceWithAccessService(
            $repository,
            $accessService,
            $this->makeUrlGenerator([
                900 => $this->makeFeed(id: 900, type: 'blog'),
                902 => $this->makeFeed(id: 902, parentId: 900, type: 'blog-post'),
            ]),
        );

        $post = $service->updateBlogPost(
            id: 902,
            title: 'Public Again',
            content: '<p>Body</p>',
            description: null,
            imageUrl: null,
            user: $user,
            visibility: 'public',
        );

        $this->assertSame('public', $post->visibility);
        $this->assertNull($post->containerId);
        $this->assertNull($post->containerType);
    }

    public function testCreateBlogPostRejectsInvalidVisibility(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeBlogPostService($repository, $this->makeUrlGenerator());

        $db->expects($this->never())->method('fetchOne');
        $db->expects($this->never())->method('execute');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('feed.blog_visibility_invalid'));

        $service->createBlogPost(
            title: 'Title',
            content: '<p>Body</p>',
            description: null,
            imageUrl: null,
            user: $user,
            visibility: 'link',
        );
    }

    public function testCreateBlogPostReplacesTagsViaTermRepository(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $blogRow = $this->makeBlogFeedRow(900, 7, '#7');
        $postRow = $this->makeBlogPostRow(902, 900, 7, 'my-post-title', 'My Post Title');

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);

        $db
            ->expects($this->exactly(4))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls($blogRow, null, $blogRow, $postRow);

        $db->expects($this->once())->method('execute');
        $db->expects($this->once())->method('lastInsertId')->willReturn(902);

        // FeedTermRepository is final, so it's exercised through its own
        // mocked PdoDatabase instead of being mocked directly (same
        // reasoning as FeedRatingRepository/FeedMetadataRepository in
        // FeedServiceTest).
        $termDb = $this->createMock(PdoDatabase::class);
        $termDb->expects($this->once())->method('begin');
        $termDb->expects($this->once())->method('commit');
        $termDb->expects($this->never())->method('rollback');
        $termDb
            ->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls(['id' => 1], ['id' => 2]);

        $termExecuted = [];
        $termDb
            ->expects($this->exactly(5)) // 1 DELETE + 2 tags * (find-or-create + link insert)
            ->method('execute')
            ->willReturnCallback(static function (string $sql, array $params) use (&$termExecuted): int {
                $termExecuted[] = [$sql, $params];

                return 1;
            });

        $service = $this->makeBlogPostService(
            $repository,
            $this->makeUrlGenerator([
                900 => $this->makeFeed(id: 900, type: 'blog'),
                902 => $this->makeFeed(id: 902, parentId: 900, type: 'blog-post'),
            ]),
            termRepository: new FeedTermRepository($termDb),
        );

        $service->createBlogPost(
            title: 'My Post Title',
            content: '<p>Body</p>',
            description: null,
            imageUrl: null,
            user: $user,
            tags: ['#горы', ' музыка ', 'горы'],
        );

        $deleteCalls = array_filter($termExecuted, static fn (array $c): bool => str_contains($c[0], 'DELETE ftl'));
        $this->assertCount(1, $deleteCalls);
        $this->assertSame([902, 'tag'], array_values($deleteCalls)[0][1]);

        $linkCalls = array_filter($termExecuted, static fn (array $c): bool => str_contains($c[0], 'INSERT '.'INTO feed_term_links'));
        $this->assertCount(2, $linkCalls);
        foreach ($linkCalls as [, $params]) {
            $this->assertSame(902, $params[0]);
        }

        $termInsertNames = [];
        foreach ($termExecuted as [$sql, $params]) {
            if (str_contains($sql, 'INSERT '.'IGNORE INTO feed_terms')) {
                $termInsertNames[] = $params[1];
            }
        }
        $this->assertSame(['горы', 'музыка'], $termInsertNames);
    }

    public function testCreateBlogPostRejectsMoreThanEightTags(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $service = $this->makeBlogPostService($repository, $this->makeUrlGenerator());

        $db->expects($this->never())->method('fetchOne');
        $db->expects($this->never())->method('execute');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage($this->trans('feed.blog_tags_too_many'));

        $service->createBlogPost(
            title: 'Title',
            content: '<p>Body</p>',
            description: null,
            imageUrl: null,
            user: $user,
            tags: ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'],
        );
    }

    public function testCreateBlogPostStoresTrackUploadIdAsMetadata(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $blogRow = $this->makeBlogFeedRow(900, 7, '#7');
        $postRow = $this->makeBlogPostRow(902, 900, 7, 'my-post-title', 'My Post Title');

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);

        $db
            ->expects($this->exactly(4))
            ->method('fetchOne')
            ->willReturnOnConsecutiveCalls($blogRow, null, $blogRow, $postRow);

        $db->expects($this->once())->method('execute');
        $db->expects($this->once())->method('lastInsertId')->willReturn(902);

        // FeedMetadataRepository is final, so it's exercised through its own
        // mocked PdoDatabase instead of being mocked directly.
        $metadataDb = $this->createMock(PdoDatabase::class);
        $metadataDb->expects($this->once())->method('begin');
        $metadataDb->expects($this->once())->method('commit');
        $metadataDb->expects($this->never())->method('rollback');
        // decorateFeedsWithMetadata() reads it back after the write.
        $metadataDb->method('fetchAll')->willReturn([]);

        $metadataExecuted = [];
        $metadataDb
            ->expects($this->exactly(2)) // 1 DELETE + 1 metadata row insert
            ->method('execute')
            ->willReturnCallback(static function (string $sql, array $params) use (&$metadataExecuted): int {
                $metadataExecuted[] = [$sql, $params];

                return 1;
            });

        $service = $this->makeBlogPostService(
            $repository,
            $this->makeUrlGenerator([
                900 => $this->makeFeed(id: 900, type: 'blog'),
                902 => $this->makeFeed(id: 902, parentId: 900, type: 'blog-post'),
            ]),
            new FeedMetadataRepository($metadataDb),
        );

        $service->createBlogPost(
            title: 'My Post Title',
            content: '<p>Body</p>',
            description: null,
            imageUrl: null,
            user: $user,
            trackUploadId: 55,
        );

        $insertCalls = array_filter(
            $metadataExecuted,
            static fn (array $c): bool => str_contains($c[0], 'INSERT '.'INTO feed_metadata')
        );
        $this->assertCount(1, $insertCalls);
        $insertParams = array_values($insertCalls)[0][1];
        $this->assertSame(902, $insertParams[0]);
        $this->assertSame('track_upload_id', $insertParams[1]);
        $this->assertSame('55', $insertParams[2]);
    }

    public function testDeleteBlogPostDeletesOwnPost(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $postRow = $this->makeBlogPostRow(902, 900, 7, 'my-post-title', 'My Post Title');

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $accessService = $this->createMock(AccessService::class);

        // One getFeedById() from deleteBlogPost()'s own type check, one more
        // from inside FeedService::deleteFeed()'s existence/ownership check.
        $db->expects($this->exactly(2))->method('fetchOne')->willReturn($postRow);
        $accessService->method('canAccessFeed')->willReturn(true);
        $accessService->expects($this->once())->method('canEditFeed')->willReturn(true);

        $db
            ->expects($this->once())
            ->method('execute')
            ->with('DELETE FROM feeds WHERE id = ?', [902]);

        // Parent 'blog' feed (900) has to be registered alongside the leaf
        // for UrlGenerator to resolve canonicalUrl cleanly - see the comment
        // on the blog/blog-post page pair in makeUrlGenerator().
        $service = $this->makeBlogPostServiceWithAccessService(
            $repository,
            $accessService,
            $this->makeUrlGenerator([
                900 => $this->makeFeed(id: 900, type: 'blog'),
                902 => $this->makeFeed(id: 902, parentId: 900, type: 'blog-post'),
            ]),
        );

        $service->deleteBlogPost(902, $user);
    }

    public function testDeleteBlogPostThrowsNotFoundForNonBlogPostFeedType(): void
    {
        $user = new User(id: 7, email: 'user@example.com', role: AccessService::ROLE_USER);
        $articleRow = $this->makeBlogPostRow(902, 900, 7, 'my-post-title', 'My Post Title');
        $articleRow['type'] = 'article';
        $articleRow['parent_id'] = null;

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $accessService = $this->createMock(AccessService::class);

        // The type mismatch is caught by deleteBlogPost()'s own check, before
        // FeedService::deleteFeed() (and its own second fetch) is ever
        // reached - so only one fetchOne call happens.
        $db->expects($this->once())->method('fetchOne')->willReturn($articleRow);
        $accessService->method('canAccessFeed')->willReturn(true);
        $accessService->expects($this->never())->method('canEditFeed');
        $db->expects($this->never())->method('execute');

        $service = $this->makeBlogPostServiceWithAccessService(
            $repository,
            $accessService,
            $this->makeUrlGenerator([902 => $this->makeFeed(id: 902, type: 'article')]),
        );

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage($this->trans('feed.not_found'));

        $service->deleteBlogPost(902, $user);
    }

    public function testDeleteBlogPostThrowsForbiddenWhenNotOwner(): void
    {
        $user = new User(id: 99, email: 'other@example.com', role: AccessService::ROLE_USER);
        $postRow = $this->makeBlogPostRow(902, 900, 7, 'my-post-title', 'My Post Title');

        $db = $this->createMock(PdoDatabase::class);
        $repository = new FeedRepository($db);
        $accessService = $this->createMock(AccessService::class);

        $db->expects($this->exactly(2))->method('fetchOne')->willReturn($postRow);
        $accessService->method('canAccessFeed')->willReturn(true);
        $accessService->expects($this->once())->method('canEditFeed')->willReturn(false);
        $db->expects($this->never())->method('execute');

        $service = $this->makeBlogPostServiceWithAccessService(
            $repository,
            $accessService,
            $this->makeUrlGenerator([
                900 => $this->makeFeed(id: 900, type: 'blog'),
                902 => $this->makeFeed(id: 902, parentId: 900, type: 'blog-post'),
            ]),
        );

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionMessage($this->trans('feed.forbidden'));

        $service->deleteBlogPost(902, $user);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Modules\Forums;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use StreamEngine\Core\Config;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\Poll;
use StreamEngine\Domain\PollOption;
use StreamEngine\Domain\Upload;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Forums\ForumRepository;
use StreamEngine\Modules\Forums\ForumsController;
use StreamEngine\Repository\ConversationRepository;
use StreamEngine\Repository\FeedFavoriteRepository;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\MessageRepository;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\ParticipantRepository;
use StreamEngine\Repository\UploadRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\PollService;
use StreamEngine\Service\UploadService;
use StreamEngine\Service\UserService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;
use Tests\Support\FakePdoDatabase;
use Tests\Support\PhpInputStreamMock;

/**
 * ForumsController: its five pages (forums.list, topic-list, topic-view,
 * topic-new, topic-edit), its breadcrumbs, and all five of its JSON API
 * actions (topic create/update, mark-all-read, per-topic read, reply).
 *
 * Built the same reflection-injection way Tests\Modules\Users\
 * UsersControllerTest builds its own subject: ForumsController's collaborators
 * are constructor-promoted readonly properties, several of them `final`
 * classes that can't be doubled (ForumRepository, FeedRepository,
 * UserRepository, FeedFavoriteRepository, UrlGenerator, UserService,
 * MessageService), so those are real instances wrapped around a PdoDatabase
 * test double and the doubleable ones (FeedService, PollService,
 * UploadService) are stubs/mocks.
 *
 * Because those real repositories are the only way the page-rendering paths
 * reach any data at all, the seam most of these tests use is the PdoDatabase
 * underneath them - see makeRoutingDb() for how a query is matched to its
 * canned result, and what to watch out for when adding one.
 */
final class ForumsControllerTest extends TestCase
{
    private const int TOPIC_ID = 900;

    private const int FORUM_ID = 70;

    /* ------------------------------------------------------------------ */
    /* SQL fragments makeRoutingDb() matches queries on                   */
    /* ------------------------------------------------------------------ */

    private const string SQL_FORUM_COUNTS = 'COUNT(DISTINCT t.id) AS topics_total';

    private const string SQL_LAST_ACTIVITY = 'FROM ranked WHERE forum_rank = 1';

    private const string SQL_TOPIC_ACTIVITY = 'AS last_activity_at FROM feeds t LEFT JOIN last_comment';

    private const string SQL_TOPICS_PAGE = 'reply_counts rc ON rc.topic_id = t.id';

    private const string SQL_TOPICS_TOTAL = "COUNT(*) AS total FROM feeds WHERE type = 'forum-post' AND parent_id = ?";

    private const string SQL_REPLY_COUNT = "COUNT(*) AS total FROM feeds WHERE type = 'comment' AND parent_id = ?";

    private const string SQL_REPLY_IDS = "SELECT id FROM feeds WHERE type = 'comment' AND parent_id = ?";

    private const string SQL_TOPIC_IDS_FOR_FORUMS = "SELECT id FROM feeds WHERE type = 'forum-post' AND parent_id IN";

    private const string SQL_ALL_TOPIC_IDS = "SELECT id FROM feeds WHERE type = 'forum-post'";

    private const string SQL_PARTICIPANT_COUNT = 'COUNT(DISTINCT owner_id) AS total';

    private const string SQL_TOPIC_PARTICIPANTS = 'COUNT(*) OVER () AS total_count';

    private const string SQL_USER_POST_COUNTS = 'SELECT owner_id, COUNT(*) AS total FROM (';

    private const string SQL_POSTS_SINCE_TOPICS = "FROM feeds WHERE type = 'forum-post' AND created_at >= ?";

    private const string SQL_POSTS_SINCE_REPLIES = "FROM feeds c WHERE c.type = 'comment' AND c.created_at >= ?";

    private const string SQL_FEEDS_BY_ID = 'f.id IN (';

    private const string SQL_FEED_BY_ID = 'f.id = ?';

    private const string SQL_FEED_BY_PARENT_AND_SLUG = 'f.parent_id = ? AND f.slug = ?';

    private const string SQL_USERS_BY_ID = 'SELECT id, nick, avatar_url, created_at, signature, hide_presence FROM users WHERE id IN';

    private const string SQL_ACTIVE_USERS_BY_ID = 'u.id IN (';

    private const string SQL_PUBLIC_USER_COUNT = 'COUNT(*) AS total FROM users u';

    private const string SQL_ONLINE_MEMBER_IDS = 'AND user_id IS NOT NULL GROUP BY user_id';

    private const string SQL_ONLINE_GUEST_COUNT = 'AND user_id IS NULL AND bot_name IS NULL';

    private const string SQL_ONLINE_BOT_NAMES = 'GROUP BY bot_name';

    private const string SQL_TOPIC_FOLLOWERS = 'FROM feed_favorites WHERE feed_id = ?';

    /* ------------------------------------------------------------------ */
    /* The helpers the page tests reach only indirectly                   */
    /* ------------------------------------------------------------------ */

    /**
     * Calls a private helper. These are all pure-ish leaves the page tests
     * exercise only in passing - a topic-view test asserts `attachments` is
     * `[]`, which says nothing about what a *populated* one looks like.
     */
    private function invoke(ForumsController $module, string $method, mixed ...$args): mixed
    {
        return (new ReflectionClass(ForumsController::class))
            ->getMethod($method)
            ->invokeArgs($module, $args);
    }

    private function makeTopicWithAttachments(mixed $stored): Feed
    {
        $topic = $this->makeTopic();
        $topic->metadata = $stored === null ? [] : ['attachment_upload_ids' => $stored];

        return $topic;
    }

    /**
     * The stored ids are a JSON *string* inside a metadata row, written by
     * handleTopicCreateRequest(). Everything about that value is untrusted by
     * the time it comes back - the column is free-form text - so each way it
     * can be wrong has to end in "no attachments" rather than in a fatal on a
     * topic page.
     */
    #[DataProvider('storedAttachmentProvider')]
    public function testStoredAttachmentIdsSurviveEveryShapeTheColumnCanHold(
        mixed $stored,
        array $expected
    ): void {
        $module = $this->makeModule($this->makeRoutingDb());

        $this->assertSame(
            $expected,
            $this->invoke($module, 'storedAttachmentIds', $this->makeTopicWithAttachments($stored))
        );
    }

    /** @return array<string, array{mixed, list<int>}> */
    public static function storedAttachmentProvider(): array
    {
        return [
            'the ordinary case' => ['[41,42]', [41, 42]],
            'no metadata at all' => [null, []],
            'an empty string' => ['', []],
            'not json' => ['41,42', []],
            // json_decode gives an int, not an array - `is_array` is what
            // stops a foreach over a scalar.
            'a bare number' => ['41', []],
            'json null' => ['null', []],
            // Already decoded by something upstream: not a string, so refused.
            'an actual array' => [[41, 42], []],
            // PDO/JSON round-trips can turn ids into strings; they are cast.
            'string ids' => ['["41","42"]', [41, 42]],
            // 0 and negatives can't be upload ids, and findById(0) would be a
            // pointless query per topic view.
            'zero and negatives are dropped' => ['[0,-1,41]', [41]],
            'garbage entries become zero and are dropped' => ['["x",41]', [41]],
            // Reindexed, so the template can iterate it as a list.
            'the result is a list' => ['[0,41,42]', [41, 42]],
        ];
    }

    /**
     * An attachment whose upload row is gone - deleted by its owner, or lost
     * with a storage migration - is skipped rather than rendered as a broken
     * link. The ids live in metadata with no foreign key, so this is the
     * normal end state for a deleted upload, not an edge case.
     */
    public function testAnAttachmentWhoseUploadIsGoneIsSkipped(): void
    {
        $uploadService = $this->createStub(UploadService::class);
        $uploadService->method('findById')->willReturnCallback(
            fn (int $id): ?Upload => $id === 42
                ? new Upload(42, 7, '7/doc.pdf', 'application/pdf', 2048, 'Договор.pdf', 1_700_000_000)
                : null
        );

        $module = $this->makeModule($this->makeRoutingDb(), uploadService: $uploadService);

        $rows = $this->invoke($module, 'buildAttachmentRows', $this->makeTopicWithAttachments('[41,42,43]'));

        $this->assertCount(1, $rows);
        $this->assertSame(42, $rows[0]['id']);
    }

    public function testAnAttachmentRowCarriesWhatTheTemplateRenders(): void
    {
        $uploadService = $this->createStub(UploadService::class);
        $uploadService->method('findById')->willReturn(
            new Upload(42, 7, '7/doc.pdf', 'application/pdf', 2048, 'Договор.pdf', 1_700_000_000)
        );

        $module = $this->makeModule($this->makeRoutingDb(), uploadService: $uploadService);

        $rows = $this->invoke($module, 'buildAttachmentRows', $this->makeTopicWithAttachments('[42]'));

        $this->assertSame(
            [
                'id' => 42,
                // The stored path is relative; the public prefix is added here.
                'url' => '/uploads/7/doc.pdf',
                'name' => 'Договор.pdf',
                'sizeLabel' => '2 КБ',
                'icon' => 'bi-file-earmark-pdf',
            ],
            $rows[0]
        );
    }

    /**
     * The icon is chosen by mime prefix, not by extension - the same
     * content-over-filename rule the upload gate itself applies.
     */
    #[DataProvider('attachmentIconProvider')]
    public function testTheAttachmentIconFollowsTheStoredMimeType(string $mime, string $icon): void
    {
        $uploadService = $this->createStub(UploadService::class);
        $uploadService->method('findById')->willReturn(
            new Upload(42, 7, '7/file', $mime, 100, 'файл', 1_700_000_000)
        );

        $module = $this->makeModule($this->makeRoutingDb(), uploadService: $uploadService);

        $rows = $this->invoke($module, 'buildAttachmentRows', $this->makeTopicWithAttachments('[42]'));

        $this->assertSame($icon, $rows[0]['icon']);
    }

    /** @return array<string, array{string, string}> */
    public static function attachmentIconProvider(): array
    {
        return [
            'jpeg' => ['image/jpeg', 'bi-image'],
            'png' => ['image/png', 'bi-image'],
            'webp' => ['image/webp', 'bi-image'],
            'pdf' => ['application/pdf', 'bi-file-earmark-pdf'],
            // Only `image/` is special-cased, so everything else - today only
            // pdf can be uploaded - gets the document icon.
            'anything else' => ['audio/mpeg', 'bi-file-earmark-pdf'],
        ];
    }

    /**
     * Deliberately kept in step with forums.ts's own bytesToLabel(), so the
     * label a file gets while it is being uploaded matches the one it keeps
     * afterwards. The boundaries are what would drift first.
     */
    #[DataProvider('fileSizeProvider')]
    public function testFileSizesUseTheSameThreeTiersAsTheClient(int $bytes, string $expected): void
    {
        $this->assertSame(
            $expected,
            $this->invoke($this->makeModule($this->makeRoutingDb()), 'formatFileSize', $bytes)
        );
    }

    /** @return array<string, array{int, string}> */
    public static function fileSizeProvider(): array
    {
        return [
            'zero' => [0, '0 Б'],
            'bytes' => [512, '512 Б'],
            'the last byte before КБ' => [1023, '1023 Б'],
            'exactly one КБ' => [1024, '1 КБ'],
            // Rounded, not truncated - 1.5 КБ reads as 2 КБ.
            'rounded to whole КБ' => [1536, '2 КБ'],
            'the last КБ before МБ' => [1024 * 1024 - 1, '1024 КБ'],
            // One decimal from here on, because "1 МБ" for anything between
            // 1.0 and 1.9 would be useless on an attachment list.
            'exactly one МБ' => [1024 * 1024, '1.0 МБ'],
            'a decimal МБ' => [1024 * 1024 * 3 + 512 * 1024, '3.5 МБ'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* buildParticipants                                                  */
    /* ------------------------------------------------------------------ */

    public function testParticipantsKeepTheOrderTheyWereAskedFor(): void
    {
        $module = $this->makeModule($this->makeRoutingDb([
            self::SQL_USERS_BY_ID => [
                ['id' => 9, 'nick' => 'Девятый', 'avatar_url' => '/uploads/9.webp'],
                ['id' => 7, 'nick' => 'Седьмой', 'avatar_url' => ''],
            ],
        ]));

        // The repository returns an id-keyed map in whatever order the database
        // felt like; the caller's order is the meaningful one (most recent
        // poster first), so it is the loop over $ownerIds that decides.
        $participants = $this->invoke($module, 'buildParticipants', [7, 9]);

        $this->assertSame([7, 9], array_column($participants, 'id'));
        $this->assertSame(['Седьмой', 'Девятый'], array_column($participants, 'displayName'));
    }

    /**
     * A participant whose user row is gone - a deleted account still owning
     * posts - renders as `#id` rather than as a blank name, and still gets an
     * avatar. The alternative is a hole in the participant strip.
     */
    public function testAParticipantWithNoUserRowStillRenders(): void
    {
        $module = $this->makeModule($this->makeRoutingDb([self::SQL_USERS_BY_ID => []]));

        $participants = $this->invoke($module, 'buildParticipants', [7]);

        $this->assertSame('#7', $participants[0]['displayName']);
        $this->assertNotSame('', $participants[0]['avatarUrl']);
    }

    public function testAnEmptyParticipantListAsksTheDatabaseNothing(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('fetchAll');

        $this->assertSame([], $this->invoke($this->makeModule($db), 'buildParticipants', []));
    }

    /**
     * An empty avatar column becomes the default image here, not in the
     * template - `Feed::fromRow()` deliberately leaves it raw, and this is the
     * presentation layer that resolves it.
     */
    public function testAParticipantWithoutAnAvatarGetsTheDefault(): void
    {
        $module = $this->makeModule($this->makeRoutingDb([
            self::SQL_USERS_BY_ID => [['id' => 7, 'nick' => 'Седьмой', 'avatar_url' => '']],
        ]));

        $participants = $this->invoke($module, 'buildParticipants', [7]);

        $this->assertSame(
            UserService::resolveAvatarUrl(''),
            $participants[0]['avatarUrl']
        );
        $this->assertNotSame('', $participants[0]['avatarUrl']);
    }

    /* ------------------------------------------------------------------ */
    /* canEditTopic / buildTopicEditUrl                                   */
    /* ------------------------------------------------------------------ */

    /**
     * The "Редактировать" button's visibility is derived from the very check
     * the edit page and its PATCH endpoint enforce, so the button can never be
     * shown for a topic those would then refuse. This is that equivalence.
     */
    #[DataProvider('editPermissionProvider')]
    public function testTheEditButtonMirrorsTheEnforcementExactly(
        int $userId,
        bool $canEdit,
        bool $withinWindow,
        bool $expected
    ): void {
        $module = $this->makeModule($this->makeRoutingDb());
        $this->setContext($module, new User(id: $userId, email: 'a@b.c'));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('canEditFeed')->willReturn($canEdit);
        $feedService->method('isWithinEditWindow')->willReturn($withinWindow);
        $this->setProperty($module, 'feedService', $feedService);

        $topic = $this->makeTopic();

        $this->assertSame($expected, $this->invoke($module, 'canEditTopic', $topic));

        // And the boolean really is the assertion, caught: the same inputs
        // either throw or don't.
        $threw = false;

        try {
            $this->invoke($module, 'assertTopicEditable', $topic, $this->contextUser($module));
        } catch (ForbiddenException) {
            $threw = true;
        }

        $this->assertSame($expected, ! $threw);
    }

    /** @return array<string, array{int, bool, bool, bool}> */
    public static function editPermissionProvider(): array
    {
        return [
            'the author inside the window' => [1, true, true, true],
            // A guest is refused before either service is consulted - they
            // cannot own a topic, so `canEditFeed` returning true would be a
            // bug elsewhere and must not be enough on its own.
            'a guest' => [0, true, true, false],
            'somebody else' => [1, false, true, false],
            'the author too late' => [1, true, false, false],
        ];
    }

    private function contextUser(ForumsController $module): User
    {
        return (new ReflectionClass(ForumsController::class))
            ->getParentClass()
            ->getProperty('context')
            ->getValue($module)
            ->user;
    }

    /**
     * The edit URL is the topic's own canonical plus `/edit/` rather than a
     * rebuild through UrlGenerator: several ancestor pages here share the
     * `{slug}` placeholder *name*, so one flat params array would fill the
     * section's and the topic's from the same value.
     */
    #[DataProvider('editUrlProvider')]
    public function testTheEditUrlIsTheCanonicalPlusASuffix(?string $canonical, ?string $expected): void
    {
        $topic = $this->makeTopic();
        $topic->canonicalUrl = $canonical;

        $this->assertSame(
            $expected,
            $this->invoke($this->makeModule($this->makeRoutingDb()), 'buildTopicEditUrl', $topic)
        );
    }

    /** @return array<string, array{?string, ?string}> */
    public static function editUrlProvider(): array
    {
        return [
            'with a trailing slash' => ['/forums/magiya/svecha/', '/forums/magiya/svecha/edit/'],
            // Normalised, so the suffix never produces a double slash.
            'without one' => ['/forums/magiya/svecha', '/forums/magiya/svecha/edit/'],
            // A topic whose URL cannot be resolved gets no button at all,
            // rather than a link to '/edit/'.
            'no canonical url' => [null, null],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* The API surface                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * The two parent routes exist only so their children can hang off them -
     * `/api/v1/forums/` and `/api/v1/forums/{topicId}/` are never meant to be
     * requested directly. They are declared with a real action rather than
     * null so a bare GET lands in callApi()'s default arm and 404s, instead of
     * dispatching on a null action.
     */
    #[DataProvider('placeholderActionProvider')]
    public function testTheParentRoutesAnswerWithANotFoundRatherThanDispatching(string $action): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        try {
            $module->callApi($this->makePage(id: 90, parentId: 89, pattern: 'x', action: $action), []);
            $this->fail($action.' should not have dispatched');
        } catch (ValidationException $e) {
            $this->assertSame('Unknown API action', $e->getMessage());
            // 404, not the default 400: the URL names nothing, which is a
            // different answer from "your request was malformed".
            $this->assertSame(404, $e->getHttpCode());
        }
    }

    /** @return array<string, array{string}> */
    public static function placeholderActionProvider(): array
    {
        return [
            'the forums parent' => ['forums.api-parent'],
            'the topic-id parent' => ['forums.topic-api-parent'],
            'an action from another module' => ['users.friend'],
            'nothing at all' => ['forums.invented'],
        ];
    }

    /**
     * Both parent routes are GET-only. Worth pinning because they are the
     * shape `CsrfCoverageTest` reports as a mutating endpoint with no CSRF
     * check if they ever declare POST - which is exactly what happened to
     * `feed.poll-api-parent` and took a pass over the audit to explain.
     */
    public function testTheParentRoutesDeclareNoMutatingMethods(): void
    {
        $tree = new PageTree([]);
        ForumsController::registerApi(10, $tree);

        foreach (['forums.api-parent', 'forums.topic-api-parent'] as $action) {
            $page = $tree->findByAction($action);

            $this->assertNotNull($page, $action.' is not registered');
            $this->assertSame(['GET'], $page->requestMethods, $action.' declares a mutating method');
        }
    }

    /**
     * Every action registerApi() declares has an arm in callApi(), and every
     * arm has a route. A mismatch either way is invisible until someone hits
     * the URL: a declared action with no arm 404s despite existing, and an arm
     * with no route is dead code that reads as coverage.
     */
    public function testTheRoutesAndTheDispatcherDeclareTheSameActions(): void
    {
        $tree = new PageTree([]);
        ForumsController::registerApi(10, $tree);

        $registered = [];
        foreach ($tree->all() as $page) {
            if ($page->action !== null) {
                $registered[] = $page->action;
            }
        }

        sort($registered);

        $expected = [
            'forums.api-parent',
            'forums.read-all',
            'forums.reply',
            'forums.topic-api-parent',
            'forums.topic-create',
            'forums.topic-item',
            'forums.topic-read',
        ];
        sort($expected);

        $this->assertSame($expected, $registered);

        // Distinct ids, or PageTree::add() would have overwritten a route.
        $ids = array_map(static fn (Page $page): int => $page->id, $tree->all());
        $this->assertCount(count($ids), array_unique($ids));
    }

    #[DataProvider('mutatingRouteProvider')]
    public function testEveryMutatingRouteDeclaresItsOwnMethod(string $action, array $methods): void
    {
        $tree = new PageTree([]);
        ForumsController::registerApi(10, $tree);

        $this->assertSame($methods, $tree->findByAction($action)->requestMethods);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function mutatingRouteProvider(): array
    {
        return [
            'mark all read' => ['forums.read-all', ['POST']],
            'create a topic' => ['forums.topic-create', ['POST']],
            // PATCH, not POST: an edit is a partial update of the item the
            // create endpoint made.
            'edit a topic' => ['forums.topic-item', ['PATCH']],
            'reply' => ['forums.reply', ['POST']],
            'mark one topic read' => ['forums.topic-read', ['POST']],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* forums.topic-edit (GET)                                            */
    /* ------------------------------------------------------------------ */

    public function testShowTopicEditPageRendersPrefilledFormForAuthor(): void
    {
        $page = $this->makeTopicEditPage();
        $module = $this->makeModule($this->makeDbReturningForumRow());

        $topic = $this->makeTopic();

        $feedService = $this->createStub(FeedService::class);
        $this->stubTopicResolution($feedService, $topic);
        $feedService->method('canEditFeed')->willReturn(true);
        $feedService->method('isWithinEditWindow')->willReturn(true);
        $this->setProperty($module, 'feedService', $feedService);

        $pollService = $this->createStub(PollService::class);
        $pollService->method('getPollForFeed')->willReturn(null);
        $this->setProperty($module, 'pollService', $pollService);

        $view = $module->show($page, ['slug' => 'svecha-gasnet']);

        $this->assertSame('modules/forums/forums.topic-form.twig', $view->template);
        // The same template create mode renders - the only difference is what
        // the view model puts in it (see buildTopicFormViewModel()).
        $this->assertSame($topic, $view->data['topic']);
        $this->assertSame('/api/v1/forums/topics/'.self::TOPIC_ID, $view->data['apiUrl']);
        $this->assertSame('PATCH', $view->data['apiMethod']);
        // Canonical is the topic's own URL + '/edit/', cancel is the topic
        // itself (you came here *from* the topic) - not the section, which is
        // what create mode uses.
        $this->assertSame('/forums/magiya/svecha-gasnet/edit/', $view->data['canonical']);
        $this->assertSame('/forums/magiya/svecha-gasnet/', $view->data['cancelUrl']);
        $this->assertNull($view->data['poll']);
        $this->assertFalse($view->data['pollLocked']);
    }

    public function testShowTopicEditPageThrowsForbiddenWhenCanEditFeedDenies(): void
    {
        $page = $this->makeTopicEditPage();
        $module = $this->makeModule($this->makeDbReturningForumRow());

        $feedService = $this->createStub(FeedService::class);
        $this->stubTopicResolution($feedService, $this->makeTopic());
        $feedService->method('canEditFeed')->willReturn(false);
        $feedService->method('isWithinEditWindow')->willReturn(true);
        $this->setProperty($module, 'feedService', $feedService);

        $this->expectException(ForbiddenException::class);

        $module->show($page, ['slug' => 'svecha-gasnet']);
    }

    /**
     * The window is the same COMMENT_EDIT_WINDOW_SECONDS an ordinary reply
     * gets (FeedService::isWithinEditWindow(), which owns the admin bypass) -
     * being the author isn't enough once it's passed.
     */
    public function testShowTopicEditPageThrowsForbiddenOutsideEditWindow(): void
    {
        $page = $this->makeTopicEditPage();
        $module = $this->makeModule($this->makeDbReturningForumRow());

        $feedService = $this->createStub(FeedService::class);
        $this->stubTopicResolution($feedService, $this->makeTopic());
        $feedService->method('canEditFeed')->willReturn(true);
        $feedService->method('isWithinEditWindow')->willReturn(false);
        $this->setProperty($module, 'feedService', $feedService);

        $this->expectException(ForbiddenException::class);

        $module->show($page, ['slug' => 'svecha-gasnet']);
    }

    public function testShowTopicEditPageThrowsNotFoundForNonTopicFeed(): void
    {
        $page = $this->makeTopicEditPage();
        $module = $this->makeModule($this->makeDbReturningForumRow());

        // A 'forum' section, not a 'forum-post' topic - the edit page must
        // refuse rather than render a topic editor for a section.
        $section = new Feed(
            id: self::FORUM_ID,
            parentId: null,
            ownerId: 7,
            type: 'forum',
            slug: 'magiya',
            title: 'Магия',
            description: null,
            imageUrl: null,
            content: null,
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: time(),
            relevance: null,
            canonicalUrl: '/forums/magiya/',
        );

        $feedService = $this->createStub(FeedService::class);
        $this->stubTopicResolution($feedService, $section);
        $this->setProperty($module, 'feedService', $feedService);

        $this->expectException(NotFoundException::class);

        $module->show($page, ['slug' => 'magiya']);
    }

    /**
     * An already-voted-on poll comes back to the form as read-only rather
     * than editable or hidden - buildTopicFormViewModel()'s own pollLocked,
     * the view-layer half of the rule replaceTopicPoll()/
     * PollService::deletePoll() enforce server-side.
     */
    public function testShowTopicEditPageLocksPollThatAlreadyHasVotes(): void
    {
        $page = $this->makeTopicEditPage();
        $module = $this->makeModule($this->makeDbReturningForumRow());

        $feedService = $this->createStub(FeedService::class);
        $this->stubTopicResolution($feedService, $this->makeTopic());
        $feedService->method('canEditFeed')->willReturn(true);
        $feedService->method('isWithinEditWindow')->willReturn(true);
        $this->setProperty($module, 'feedService', $feedService);

        $pollService = $this->createStub(PollService::class);
        $pollService->method('getPollForFeed')->willReturn($this->makePoll(votersCount: 4));
        $this->setProperty($module, 'pollService', $pollService);

        $view = $module->show($page, ['slug' => 'svecha-gasnet']);

        $this->assertTrue($view->data['pollLocked']);
        $this->assertSame('Какой ритуал?', $view->data['poll']['question']);
        $this->assertSame(['Первый', 'Второй'], $view->data['poll']['options']);
    }

    /**
     * durationDays is recovered relative to the poll's own createdAt, not to
     * "now" - a 7-day poll created four days ago is still a 7-day poll, and
     * measuring from now would silently downgrade it to 3 in the form.
     */
    public function testShowTopicEditPageRecoversPollDurationFromCreationTime(): void
    {
        $page = $this->makeTopicEditPage();
        $module = $this->makeModule($this->makeDbReturningForumRow());

        $feedService = $this->createStub(FeedService::class);
        $this->stubTopicResolution($feedService, $this->makeTopic());
        $feedService->method('canEditFeed')->willReturn(true);
        $feedService->method('isWithinEditWindow')->willReturn(true);
        $this->setProperty($module, 'feedService', $feedService);

        $createdAt = time() - 4 * 86400;

        $pollService = $this->createStub(PollService::class);
        $pollService->method('getPollForFeed')->willReturn($this->makePoll(
            votersCount: 0,
            createdAt: $createdAt,
            closesAt: $createdAt + 7 * 86400,
        ));
        $this->setProperty($module, 'pollService', $pollService);

        $view = $module->show($page, ['slug' => 'svecha-gasnet']);

        $this->assertFalse($view->data['pollLocked']);
        $this->assertSame(7, $view->data['poll']['durationDays']);
    }

    /* ------------------------------------------------------------------ */
    /* PATCH /api/v1/forums/topics/{id}                                   */
    /* ------------------------------------------------------------------ */

    /**
     * The headline rule for this endpoint: a new title never re-slugs the
     * topic. Its URL is what gets pasted into other topics and notification
     * messages and nothing writes redirects for a moved feed, so updateFeed()
     * has to receive the *existing* slug verbatim.
     */
    public function testTopicUpdateKeepsExistingSlugWhenTitleChanges(): void
    {
        $module = $this->makeUpdatableModule($feedService, $pollService, mockFeedService: true);

        $feedService->expects($this->once())
            ->method('updateFeed')
            ->with(
                self::TOPIC_ID,
                $this->callback(function (array $data): bool {
                    $this->assertSame('svecha-gasnet', $data['slug']);
                    $this->assertSame('Совершенно новый заголовок', $data['title']);
                    $this->assertSame('forum-post', $data['type']);
                    $this->assertSame(self::FORUM_ID, $data['parentId']);

                    return true;
                }),
                $this->anything(),
            )
            ->willReturn($this->makeTopic(title: 'Совершенно новый заголовок'));

        $output = $this->callUpdateApi($module, [
            'title' => 'Совершенно новый заголовок',
            'content' => '<div>Обновлённый текст</div>',
        ]);

        $decoded = json_decode($output, true);
        $this->assertSame(self::TOPIC_ID, $decoded['id']);
        $this->assertSame('svecha-gasnet', $decoded['slug']);
        $this->assertSame('/forums/magiya/svecha-gasnet/', $decoded['canonicalUrl']);
    }

    /**
     * `attachments` is the complete new set, not a list of additions - so a
     * save that sends none has to actively clear the stored ids rather than
     * leave the old ones in place, which means metadata is always part of the
     * updateFeed() payload (updateFeed() only rewrites metadata when the key
     * is present at all).
     */
    public function testTopicUpdateClearsAttachmentMetadataWhenNoneSent(): void
    {
        $module = $this->makeUpdatableModule($feedService, $pollService, mockFeedService: true);

        $feedService->expects($this->once())
            ->method('updateFeed')
            ->with(
                self::TOPIC_ID,
                $this->callback(function (array $data): bool {
                    $this->assertArrayHasKey('metadata', $data);
                    $this->assertNull($data['metadata']['attachment_upload_ids']);

                    return true;
                }),
                $this->anything(),
            )
            ->willReturn($this->makeTopic());

        $this->callUpdateApi($module, [
            'title' => 'Заголовок',
            'content' => '<div>Текст</div>',
            'attachments' => [],
        ]);
    }

    /**
     * Editing a poll is expressed as delete-then-recreate (option *identity*
     * is what votes point at, so there's no safe in-place update) - which is
     * only reachable while nobody has voted.
     */
    public function testTopicUpdateReplacesPollThatHasNoVotes(): void
    {
        $module = $this->makeUpdatableModule($feedService, $pollService, mockPollService: true);
        $feedService->method('updateFeed')->willReturn($this->makeTopic());

        $pollService->method('getPollForFeed')->willReturn($this->makePoll(votersCount: 0));
        $pollService->expects($this->once())->method('deletePoll')->with(555, $this->anything());
        $pollService->expects($this->once())
            ->method('createPoll')
            ->with(
                self::TOPIC_ID,
                'Новый вопрос?',
                ['А', 'Б', 'В'],
                1,
                false,
                Poll::VISIBILITY_ALWAYS,
                null,
                $this->anything(),
            )
            ->willReturn($this->makePoll(votersCount: 0));

        $this->callUpdateApi($module, [
            'title' => 'Заголовок',
            'content' => '<div>Текст</div>',
            'poll' => [
                'question' => 'Новый вопрос?',
                'options' => ['А', 'Б', 'В'],
                'multiple' => false,
                'durationDays' => 0,
            ],
        ]);
    }

    /**
     * A save that leaves the duration select alone must carry the *original*
     * end date through the delete/recreate, not recompute it from
     * durationDays: doing the latter would push a 7-day poll's deadline out by
     * another 7 days on every unrelated edit, so a title fix would silently
     * extend the vote.
     */
    public function testTopicUpdatePreservesPollEndDateWhenDurationUnchanged(): void
    {
        $module = $this->makeUpdatableModule($feedService, $pollService, mockPollService: true);
        $feedService->method('updateFeed')->willReturn($this->makeTopic());

        $createdAt = time() - 4 * 86400;
        $closesAt = $createdAt + 7 * 86400;

        $pollService->method('getPollForFeed')->willReturn($this->makePoll(
            votersCount: 0,
            createdAt: $createdAt,
            closesAt: $closesAt,
        ));
        $pollService->expects($this->once())->method('deletePoll');
        $pollService->expects($this->once())
            ->method('createPoll')
            ->with(
                self::TOPIC_ID,
                'Тот же вопрос?',
                ['А', 'Б'],
                1,
                false,
                Poll::VISIBILITY_ALWAYS,
                // The original timestamp, not time() + 7 days.
                $closesAt,
                $this->anything(),
            )
            ->willReturn($this->makePoll(votersCount: 0));

        $this->callUpdateApi($module, [
            'title' => 'Новый заголовок',
            'content' => '<div>Текст</div>',
            'poll' => [
                'question' => 'Тот же вопрос?',
                'options' => ['А', 'Б'],
                'multiple' => false,
                // Exactly what buildPollFormState() rendered into the form for
                // this poll - i.e. the author didn't touch the select.
                'durationDays' => 7,
            ],
        ]);
    }

    /**
     * The data-loss guard: a poll payload the server can't turn into a real
     * poll (blank question here; duplicate options and over-length text behave
     * the same) must leave the existing poll alone. Deleting first and only
     * then failing to build the replacement would destroy a real poll and
     * still report a successful save.
     */
    public function testTopicUpdateKeepsExistingPollWhenPayloadIsUnusable(): void
    {
        $module = $this->makeUpdatableModule($feedService, $pollService, mockPollService: true);
        $feedService->method('updateFeed')->willReturn($this->makeTopic());

        $pollService->method('getPollForFeed')->willReturn($this->makePoll(votersCount: 0));
        $pollService->expects($this->never())->method('deletePoll');
        $pollService->expects($this->never())->method('createPoll');

        $log = $this->captureErrorLog(fn () => $this->callUpdateApi($module, [
            'title' => 'Заголовок',
            'content' => '<div>Текст</div>',
            'poll' => [
                'question' => '   ',
                'options' => ['А', 'Б'],
                'multiple' => false,
                'durationDays' => 0,
            ],
        ]));

        // The response is an ordinary 200 either way, so the log line is the
        // only outward sign this branch (rather than "remove the poll") ran.
        $this->assertStringContainsString('existing poll kept', $log);
    }

    /**
     * Same guard, for the case that actually reaches it in practice: nothing
     * used to check for duplicate options client-side, and
     * PollService::createPoll() rejects them.
     */
    public function testTopicUpdateKeepsExistingPollWhenOptionsAreDuplicated(): void
    {
        $module = $this->makeUpdatableModule($feedService, $pollService, mockPollService: true);
        $feedService->method('updateFeed')->willReturn($this->makeTopic());

        $pollService->method('getPollForFeed')->willReturn($this->makePoll(votersCount: 0));
        $pollService->expects($this->never())->method('deletePoll');
        $pollService->expects($this->never())->method('createPoll');

        $log = $this->captureErrorLog(fn () => $this->callUpdateApi($module, [
            'title' => 'Заголовок',
            'content' => '<div>Текст</div>',
            'poll' => [
                'question' => 'Вопрос?',
                'options' => ['Да', 'Да'],
                'multiple' => false,
                'durationDays' => 0,
            ],
        ]));

        $this->assertStringContainsString('existing poll kept', $log);
    }

    /**
     * An admin or moderator editing someone else's topic doesn't own its
     * uploads, and findOwnedUpload() has no admin bypass - so the ids the form
     * faithfully sends back have to resolve through the unscoped findById()
     * instead, or every attachment silently detaches on save.
     */
    public function testTopicUpdateKeepsAttachmentsWhenEditorIsNotTheUploader(): void
    {
        // The trusted path: already-attached ids go through findById(), never
        // findOwnedUpload() (which would reject them - they belong to user 7).
        $uploadService = $this->createMock(UploadService::class);
        $uploadService->expects($this->never())->method('findOwnedUpload');
        $uploadService->method('findById')->willReturnCallback(
            fn (int $id): Upload => $this->makeUpload($id)
        );

        // Built directly rather than via makeUpdatableModule(): this test needs
        // both its own UploadService *and* a getFeedById() returning a topic
        // that carries attachment metadata, and every one of those properties
        // is readonly - a second reflection setValue() would throw.
        $module = $this->makeModule($this->createStub(PdoDatabase::class), $uploadService);

        // A moderator, not the topic's author.
        $this->setContext($module, new User(id: 99, email: 'mod@example.com', role: AccessService::ROLE_MODERATOR));

        $topic = $this->makeTopic();
        $topic->metadata = ['attachment_upload_ids' => json_encode([41, 42])];

        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedById')->willReturn($topic);
        $feedService->method('canEditFeed')->willReturn(true);
        $feedService->method('isWithinEditWindow')->willReturn(true);
        $this->setProperty($module, 'feedService', $feedService);

        $pollService = $this->createStub(PollService::class);
        $pollService->method('getPollForFeed')->willReturn(null);
        $this->setProperty($module, 'pollService', $pollService);

        $feedService->expects($this->once())
            ->method('updateFeed')
            ->with(
                self::TOPIC_ID,
                $this->callback(function (array $data): bool {
                    $this->assertSame('[41,42]', $data['metadata']['attachment_upload_ids']);

                    return true;
                }),
                $this->anything(),
            )
            ->willReturn($this->makeTopic());

        $this->callUpdateApi($module, [
            'title' => 'Заголовок',
            'content' => '<div>Текст</div>',
            'attachments' => [41, 42],
        ]);
    }

    public function testTopicUpdateIgnoresPollThatAlreadyHasVotes(): void
    {
        $module = $this->makeUpdatableModule($feedService, $pollService, mockPollService: true);
        $feedService->method('updateFeed')->willReturn($this->makeTopic());

        $pollService->method('getPollForFeed')->willReturn($this->makePoll(votersCount: 12));
        $pollService->expects($this->never())->method('deletePoll');
        $pollService->expects($this->never())->method('createPoll');

        $this->callUpdateApi($module, [
            'title' => 'Заголовок',
            'content' => '<div>Текст</div>',
            // A client that ignores the disabled poll card entirely - the
            // server still has to refuse to touch the poll.
            'poll' => [
                'question' => 'Подменённый вопрос?',
                'options' => ['А', 'Б'],
                'multiple' => true,
                'durationDays' => 30,
            ],
        ]);
    }

    /**
     * A save with the poll card collapsed removes the poll - the only way the
     * UI offers to take one off a topic. `poll` absent, existing poll unvoted
     * -> deleted and not recreated.
     */
    public function testTopicUpdateRemovesPollWhenNoneSent(): void
    {
        $module = $this->makeUpdatableModule($feedService, $pollService, mockPollService: true);
        $feedService->method('updateFeed')->willReturn($this->makeTopic());

        $pollService->method('getPollForFeed')->willReturn($this->makePoll(votersCount: 0));
        $pollService->expects($this->once())->method('deletePoll');
        $pollService->expects($this->never())->method('createPoll');

        $this->callUpdateApi($module, [
            'title' => 'Заголовок',
            'content' => '<div>Текст</div>',
        ]);
    }

    public function testTopicUpdateRejectsEmptyTitle(): void
    {
        $module = $this->makeUpdatableModule($feedService, $pollService, mockFeedService: true);
        $feedService->expects($this->never())->method('updateFeed');

        $this->expectException(ValidationException::class);

        $this->callUpdateApi($module, ['title' => '   ', 'content' => '<div>Текст</div>']);
    }

    /**
     * Trix always serializes *something* for an empty document ("<div><br>
     * </div>"), so an empty check has to look at the stripped text rather
     * than the raw string. Same rule the create endpoint applies, via the
     * shared validateTopicInput().
     */
    public function testTopicUpdateRejectsEmptyTrixDocument(): void
    {
        $module = $this->makeUpdatableModule($feedService, $pollService, mockFeedService: true);
        $feedService->expects($this->never())->method('updateFeed');

        $this->expectException(ValidationException::class);

        $this->callUpdateApi($module, ['title' => 'Заголовок', 'content' => '<div><br></div>']);
    }

    /**
     * The other half of that rule: a post whose only content is an image is
     * still real content, even though strip_tags() leaves nothing behind.
     */
    public function testTopicUpdateAllowsFigureOnlyContent(): void
    {
        $module = $this->makeUpdatableModule($feedService, $pollService, mockFeedService: true);
        $feedService->expects($this->once())->method('updateFeed')->willReturn($this->makeTopic());
        $pollService->method('getPollForFeed')->willReturn(null);

        $this->callUpdateApi($module, [
            'title' => 'Заголовок',
            'content' => '<figure data-trix-attachment="{}"></figure>',
        ]);
    }

    public function testTopicUpdateRejectsNonPatchMethod(): void
    {
        $module = $this->makeUpdatableModule($feedService, $pollService);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Method not allowed');

        $this->callUpdateApi($module, ['title' => 'T', 'content' => '<p>x</p>'], method: 'DELETE');
    }

    public function testTopicUpdateThrowsForbiddenOutsideEditWindow(): void
    {
        $module = $this->makeUpdatableModule(
            $feedService,
            $pollService,
            withinEditWindow: false,
            mockFeedService: true,
        );
        $feedService->expects($this->never())->method('updateFeed');

        $this->expectException(ForbiddenException::class);

        $this->callUpdateApi($module, ['title' => 'Заголовок', 'content' => '<div>Текст</div>']);
    }

    /* ------------------------------------------------------------------ */
    /* forums.list (GET)                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * getFeedsByType() hands back one flat list of every 'forum' feed, in no
     * particular order - turning that into "groups, each with its own
     * subforums, both sorted by position" is the page's own job, so the
     * fixture below is deliberately shuffled against both orderings.
     */
    public function testForumsListGroupsSubforumsUnderTheirParentSortedByPosition(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $lateGroup = $this->makeForum(id: 10, slug: 'obshchee', position: 1);
        $earlyGroup = $this->makeForum(id: 11, slug: 'praktika', position: 0);
        $lateSub = $this->makeForum(id: 20, slug: 'tarot', position: 5, parentId: 10);
        $earlySub = $this->makeForum(id: 21, slug: 'runy', position: 1, parentId: 10);

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedsByType')->willReturn([$lateSub, $lateGroup, $earlySub, $earlyGroup]);
        $feedService->method('getFeedReadAtMap')->willReturn([]);
        $this->setProperty($module, 'feedService', $feedService);

        $view = $module->show($this->makeForumsListPage());

        // Only top-level forums are groups; the ones with a parentId are
        // nested under theirs rather than listed alongside them.
        $this->assertSame([11, 10], array_keys($view->data['forums']));
        $this->assertSame([21, 20], array_map(
            static fn (Feed $child): int => $child->id,
            $view->data['forums'][10]->children
        ));
        $this->assertSame([], $view->data['forums'][11]->children);
    }

    /**
     * A topic counts as unread when the viewer has no read watermark for it
     * at all, or one older than its last activity. A group's own badge is the
     * sum of its children's counts - top-level forums hold no topics
     * themselves.
     */
    public function testForumsListCountsUnreadTopicsAndRollsThemUpToTheGroupBadge(): void
    {
        $module = $this->makeModule($this->makeRoutingDb([
            self::SQL_TOPIC_ACTIVITY => [
                ['topic_id' => 900, 'forum_id' => 20, 'last_activity_at' => 1_000],
                ['topic_id' => 901, 'forum_id' => 20, 'last_activity_at' => 2_000],
                ['topic_id' => 902, 'forum_id' => 21, 'last_activity_at' => 3_000],
            ],
        ]));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedsByType')->willReturn([
            $this->makeForum(id: 10, slug: 'obshchee'),
            $this->makeForum(id: 20, slug: 'tarot', parentId: 10),
            $this->makeForum(id: 21, slug: 'runy', parentId: 10),
            $this->makeForum(id: 22, slug: 'astrologiya', parentId: 10),
        ]);
        $feedService->method('getFeedReadAtMap')->willReturn([
            // Read after its last activity - the only one that counts as read.
            900 => 1_500,
            // Read, but before the reply that bumped it.
            901 => 1_000,
            // 902 is absent entirely: never opened.
        ]);
        $this->setProperty($module, 'feedService', $feedService);

        $view = $module->show($this->makeForumsListPage());

        $this->assertSame(1, $view->data['forumUnreadCounts'][20]);
        $this->assertSame(1, $view->data['forumUnreadCounts'][21]);
        // Present as an explicit 0 rather than absent, so the template can
        // index it without a |default filter.
        $this->assertSame(0, $view->data['forumUnreadCounts'][22]);
        $this->assertSame(2, $view->data['forumGroupUnreadCounts'][10]);
    }

    /**
     * The "last post" preview is built from two separate queries (the
     * activity row, then the topic itself), so a topic that vanished or fell
     * out of ACL between them has to be dropped rather than rendered half
     * empty. An author whose user row is gone degrades to "#<id>" instead.
     */
    public function testForumsListSkipsLastPostPreviewWhenTheTopicNoLongerResolves(): void
    {
        $bumpedAt = time() - 600;

        $module = $this->makeModule($this->makeRoutingDb([
            self::SQL_LAST_ACTIVITY => [
                ['topic_id' => 900, 'forum_id' => 20, 'last_owner_id' => 5, 'last_activity_at' => $bumpedAt],
                ['topic_id' => 999, 'forum_id' => 21, 'last_owner_id' => 6, 'last_activity_at' => $bumpedAt],
            ],
            // Only topic 900 comes back - 999 is the one that disappeared.
            self::SQL_FEEDS_BY_ID => [$this->feedRow(900, 'forum-post', parentId: 20)],
        ]));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedsByType')->willReturn([
            $this->makeForum(id: 10, slug: 'obshchee'),
            $this->makeForum(id: 20, slug: 'tarot', parentId: 10),
            $this->makeForum(id: 21, slug: 'runy', parentId: 10),
        ]);
        $feedService->method('getFeedReadAtMap')->willReturn([]);
        $this->setProperty($module, 'feedService', $feedService);

        $view = $module->show($this->makeForumsListPage());

        $this->assertSame([20], array_keys($view->data['forumLastPost']));

        $preview = $view->data['forumLastPost'][20];
        $this->assertSame('#5', $preview['authorName']);
        // No user row means no avatar of their own, but the strip still gets
        // the site's default silhouette rather than an empty src.
        $this->assertNotSame('', $preview['authorAvatarUrl']);
        $this->assertNotNull($preview['topicUrl']);
        $this->assertTrue($preview['unread']);
    }

    /**
     * "Сообщений" is topics + replies (a topic's own opening message counts as
     * a post), the stats card's totals are the sum over every subforum on the
     * page, and a subforum with no topics at all still gets a zeroed entry.
     */
    public function testForumsListSumsTopicAndPostTotalsAcrossSubforums(): void
    {
        $module = $this->makeModule($this->makeRoutingDb(
            [
                self::SQL_FORUM_COUNTS => [
                    ['forum_id' => 20, 'topics_total' => 3, 'replies_total' => 7],
                    ['forum_id' => 21, 'topics_total' => 1, 'replies_total' => 0],
                ],
            ],
            [
                self::SQL_PUBLIC_USER_COUNT => ['total' => 42],
                self::SQL_POSTS_SINCE_REPLIES => ['total' => 3],
                self::SQL_POSTS_SINCE_TOPICS => ['total' => 2],
            ]
        ));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedsByType')->willReturn([
            $this->makeForum(id: 10, slug: 'obshchee'),
            $this->makeForum(id: 20, slug: 'tarot', parentId: 10),
            $this->makeForum(id: 21, slug: 'runy', parentId: 10),
            $this->makeForum(id: 22, slug: 'astrologiya', parentId: 10),
        ]);
        $feedService->method('getFeedReadAtMap')->willReturn([]);
        $this->setProperty($module, 'feedService', $feedService);

        $view = $module->show($this->makeForumsListPage());

        $this->assertSame(['topics' => 3, 'posts' => 10], $view->data['forumStats'][20]);
        $this->assertSame(['topics' => 1, 'posts' => 1], $view->data['forumStats'][21]);
        $this->assertSame(['topics' => 0, 'posts' => 0], $view->data['forumStats'][22]);

        $this->assertSame(4, $view->data['forumTotals']['topics']);
        $this->assertSame(11, $view->data['forumTotals']['posts']);
        $this->assertSame(42, $view->data['forumTotals']['members']);
        // Topics created today plus replies posted today, from the two halves
        // of countPostsSince()'s own pair of queries.
        $this->assertSame(5, $view->data['forumTotals']['postsToday']);
    }

    /**
     * Members, guests and crawlers stay three separate figures ("12 онлайн"
     * that turns out to be one member and eleven scrapers reads as flattery),
     * the reserved system account never counts as present, and a member with
     * no username set still appears - just without a profile link.
     */
    public function testForumsListSeparatesOnlineMembersFromGuestsAndBots(): void
    {
        $module = $this->makeModule($this->makeRoutingDb(
            [
                self::SQL_ONLINE_MEMBER_IDS => [
                    ['user_id' => 5],
                    // The reserved system account - filtered out, never shown.
                    ['user_id' => User::SYSTEM_USER_ID],
                    ['user_id' => 6],
                ],
                self::SQL_ACTIVE_USERS_BY_ID => [
                    $this->activeUserRow(5, 'Аня', 'anya'),
                    $this->activeUserRow(6, 'Гость форума', ''),
                ],
                self::SQL_ONLINE_BOT_NAMES => [['bot_name' => 'Googlebot']],
            ],
            [
                self::SQL_ONLINE_GUEST_COUNT => ['total' => 4],
            ]
        ));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedsByType')->willReturn([]);
        $feedService->method('getFeedReadAtMap')->willReturn([]);
        $this->setProperty($module, 'feedService', $feedService);

        $online = $module->show($this->makeForumsListPage())->data['onlineNow'];

        $this->assertSame(2, $online['total']);
        $this->assertSame(0, $online['hidden']);
        $this->assertSame(['Аня', 'Гость форума'], array_column($online['members'], 'displayName'));
        $this->assertSame('/users/anya/', $online['members'][0]['url']);
        // No username, so there's no profile page to link to - the name still
        // shows, as plain text.
        $this->assertNull($online['members'][1]['url']);
        $this->assertSame(4, $online['guests']);
        $this->assertSame(['Googlebot'], $online['bots']);
    }

    /* ------------------------------------------------------------------ */
    /* forums.topic-list (GET)                                            */
    /* ------------------------------------------------------------------ */

    public function testTopicListRefusesAFeedThatIsNotAForum(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createMock(FeedService::class);
        // Defensive guard: the typed repository contract should return only
        // forums, but the controller still refuses a malformed result.
        $feedService->expects($this->once())
            ->method('getFeedByTypeAndSlug')
            ->with('forum', 'svecha-gasnet', $this->anything())
            ->willReturn($this->makeTopic());
        $this->setProperty($module, 'feedService', $feedService);

        $this->expectException(ForbiddenException::class);

        $module->show($this->makeTopicListPage(), ['slug' => 'svecha-gasnet']);
    }

    /**
     * A "base" section - one with subforums of its own - holds no topics
     * directly, so it lists none and its stats card rolls up its children's
     * numbers instead of showing its own always-empty direct counts.
     */
    public function testTopicListForABaseSectionRollsUpSubforumStatsAndListsNoTopics(): void
    {
        $module = $this->makeModule($this->makeRoutingDb(
            [
                self::SQL_FORUM_COUNTS => [
                    ['forum_id' => 80, 'topics_total' => 2, 'replies_total' => 3],
                    ['forum_id' => 81, 'topics_total' => 3, 'replies_total' => 5],
                ],
            ],
            [
                // The parent forum, for the eyebrow above the title.
                self::SQL_FEED_BY_ID => $this->feedRow(self::FORUM_ID, 'forum'),
                self::SQL_PARTICIPANT_COUNT => ['total' => 9],
                self::SQL_POSTS_SINCE_REPLIES => ['total' => 1],
                self::SQL_POSTS_SINCE_TOPICS => ['total' => 1],
            ]
        ));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedByTypeAndSlug')->willReturn(
            $this->makeForum(id: 71, slug: 'tarot', parentId: self::FORUM_ID)
        );
        $feedService->method('getFeedsByParentAndType')->willReturn([
            $this->makeForum(id: 80, slug: 'tarot-raspisaniya', parentId: 71),
            $this->makeForum(id: 81, slug: 'tarot-shkola', parentId: 71),
        ]);
        $feedService->method('getFeedReadAtMap')->willReturn([]);
        $this->setProperty($module, 'feedService', $feedService);

        $view = $module->show($this->makeTopicListPage(), ['slug' => 'tarot']);

        $this->assertSame([], $view->data['topics']);
        $this->assertSame(['current' => 1, 'total' => 1], [
            'current' => $view->data['pagination']['current'],
            'total' => $view->data['pagination']['total'],
        ]);

        // 2 + 3 topics, (2 + 3) + (3 + 5) posts - summed from the children's
        // own counts, not queried for the section itself.
        $this->assertSame(5, $view->data['forumTotals']['topics']);
        $this->assertSame(13, $view->data['forumTotals']['posts']);
        // Deduped across every subforum in one query, not summed per-child.
        $this->assertSame(9, $view->data['forumTotals']['participants']);
        $this->assertSame(2, $view->data['forumTotals']['postsToday']);

        $this->assertSame(self::FORUM_ID, $view->data['parentForum']->id);
        $this->assertNotNull($view->data['parentForum']->canonicalUrl);
    }

    /**
     * A leaf section's own topic page: total pages is ceil(total / page size)
     * regardless of how few rows this page holds, each row carries its reply
     * count and "last bumped by" preview, and "unread" compares the viewer's
     * watermark against that bump rather than the topic's creation.
     */
    public function testTopicListPaginatesAndDecoratesTopicRows(): void
    {
        $bumpedAt = time() - 300;

        $module = $this->makeModule($this->makeRoutingDb(
            [
                self::SQL_TOPICS_PAGE => [
                    [
                        'topic_id' => 900,
                        'reply_count' => 4,
                        'last_owner_id' => 5,
                        'last_activity_at' => $bumpedAt,
                    ],
                    [
                        'topic_id' => 901,
                        'reply_count' => 0,
                        'last_owner_id' => 5,
                        'last_activity_at' => $bumpedAt,
                    ],
                ],
                self::SQL_FEEDS_BY_ID => [
                    $this->feedRow(900, 'forum-post', parentId: self::FORUM_ID),
                    $this->feedRow(901, 'forum-post', parentId: self::FORUM_ID),
                ],
                self::SQL_USERS_BY_ID => [
                    ['id' => 5, 'nick' => 'Аня', 'avatar_url' => '/uploads/anya.png', 'created_at' => 1_700_000_000],
                ],
                self::SQL_FORUM_COUNTS => [
                    ['forum_id' => self::FORUM_ID, 'topics_total' => 45, 'replies_total' => 60],
                ],
            ],
            [
                self::SQL_TOPICS_TOTAL => ['total' => 45],
                self::SQL_PARTICIPANT_COUNT => ['total' => 12],
            ]
        ));

        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->once())
            ->method('getFeedByTypeAndSlug')
            ->with('forum', 'magiya', $this->anything())
            ->willReturn($this->makeForumFeed());
        $feedService->method('getFeedsByParentAndType')->willReturn([]);
        $feedService->method('getFeedReadAtMap')->willReturn([
            // Read after the bump; 901 has no watermark at all.
            900 => $bumpedAt + 10,
        ]);
        $this->setProperty($module, 'feedService', $feedService);

        $view = $module->show($this->makeTopicListPage(), ['slug' => 'magiya']);

        // 45 topics at 20 per page - three pages, even though this page only
        // returned two rows.
        $this->assertSame(3, $view->data['pagination']['total']);
        $this->assertSame(1, $view->data['pagination']['current']);

        $this->assertCount(2, $view->data['topics']);

        [$first, $second] = $view->data['topics'];

        $this->assertSame(4, $first['replies']);
        $this->assertSame('Аня', $first['lastAuthorName']);
        $this->assertSame('/uploads/anya.png', $first['lastAuthorAvatarUrl']);
        $this->assertFalse($first['unread']);
        // Decorated here rather than by FeedService, since these Feeds are
        // built outside its own decorated getters.
        $this->assertNotNull($first['feed']->canonicalUrl);
        $this->assertNotNull($first['feed']->createdAtLabel);
        $this->assertNotNull($first['feed']->createdAtTitle);

        $this->assertTrue($second['unread']);

        $this->assertSame(45, $view->data['forumTotals']['topics']);
        $this->assertSame(105, $view->data['forumTotals']['posts']);
    }

    /**
     * An empty section still reports one page - findTopicsPage() short-circuits
     * on a zero count, and "0 pages" would make the pagination widget render
     * nothing at all.
     */
    public function testTopicListReportsASinglePageForAnEmptySection(): void
    {
        $module = $this->makeModule($this->makeRoutingDb([], [self::SQL_TOPICS_TOTAL => ['total' => 0]]));

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedByTypeAndSlug')->willReturn($this->makeForumFeed());
        $feedService->method('getFeedsByParentAndType')->willReturn([]);
        $feedService->method('getFeedReadAtMap')->willReturn([]);
        $this->setProperty($module, 'feedService', $feedService);

        $view = $module->show($this->makeTopicListPage(), ['slug' => 'magiya']);

        $this->assertSame([], $view->data['topics']);
        $this->assertSame(1, $view->data['pagination']['total']);
        $this->assertSame(['topics' => 0, 'posts' => 0], [
            'topics' => $view->data['forumTotals']['topics'],
            'posts' => $view->data['forumTotals']['posts'],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* forums.topic-view (GET)                                            */
    /* ------------------------------------------------------------------ */

    public function testTopicViewRefusesAFeedThatIsNotATopic(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createStub(FeedService::class);
        $this->stubTopicResolution($feedService, $this->makeForumFeed());
        $this->setProperty($module, 'feedService', $feedService);

        $this->expectException(ForbiddenException::class);

        $module->show($this->makeTopicViewPage(), ['slug' => 'magiya']);
    }

    /**
     * Classic forum numbering: the topic's own opening message is post #1 of
     * page 1, replies follow. Per-post gates differ on purpose - "Изменить" is
     * self-service and window-bound, "Удалить" is self-service but never
     * offered for the opening post (deleting that is a different, not-yet-built
     * action).
     */
    public function testTopicViewNumbersTheOpeningPostFirstAndGatesPerPostActions(): void
    {
        $editedAt = time() - 60;

        $module = $this->makeModule($this->makeTopicViewDb(
            replyCount: 2,
            replyRows: [
                // Own reply, edited after posting.
                $this->feedRow(910, 'comment', parentId: self::TOPIC_ID, ownerId: 7, extra: [
                    'created_at' => time() - 1800,
                    'updated_at' => $editedAt,
                ]),
                // Someone else's reply.
                $this->feedRow(911, 'comment', parentId: self::TOPIC_ID, ownerId: 8),
            ],
            userRows: [
                [
                    'id' => 7,
                    'nick' => 'Автор',
                    'avatar_url' => '/uploads/author.png',
                    'created_at' => 1_700_000_000,
                    // Already-purified HTML, i.e. the shape users.signature
                    // actually holds - UserService::sanitizeSignature() runs on
                    // every write path into that column, so this page never
                    // sees raw markup. What sanitizing accepts and rejects is
                    // UserServiceTest's job; here it only matters that the
                    // stored value reaches the post intact plus line breaks.
                    'signature' => "Читайте <a href=\"https://example.com\" rel=\"nofollow\" target=\"_blank\">мой блог</a>\nвторая строка",
                ],
            ],
            postCountRows: [['owner_id' => 7, 'total' => 12]],
            participantRows: [
                ['owner_id' => 7, 'total_count' => 7],
                ['owner_id' => 8, 'total_count' => 7],
            ],
        ));

        $feedService = $this->createStub(FeedService::class);
        $this->stubTopicResolution($feedService, $this->makeTopic());
        $feedService->method('isWithinEditWindow')->willReturn(true);
        $feedService->method('canEditFeed')->willReturn(true);
        $feedService->method('getUserRatingValues')->willReturn([910 => 4]);
        $feedService->method('isFeedFavorited')->willReturn(true);
        $this->setProperty($module, 'feedService', $feedService);

        $pollService = $this->createStub(PollService::class);
        $pollService->method('getPollForFeed')->willReturn(null);
        $this->setProperty($module, 'pollService', $pollService);

        $view = $module->show($this->makeTopicViewPage(), ['slug' => 'svecha-gasnet']);

        $posts = $view->data['posts'];
        $this->assertCount(3, $posts);
        $this->assertSame([1, 2, 3], array_column($posts, 'number'));

        [$opening, $ownReply, $otherReply] = $posts;

        $this->assertTrue($opening['isOpeningPost']);
        $this->assertTrue($opening['isTopicAuthor']);
        $this->assertTrue($opening['isCurrentUser']);
        $this->assertTrue($opening['canEdit']);
        $this->assertFalse($opening['canDelete']);
        $this->assertFalse($opening['wasEdited']);
        $this->assertSame(12, $opening['authorPostCount']);
        $this->assertSame('/uploads/author.png', $opening['authorAvatarUrl']);
        $this->assertNotNull($opening['authorMemberSince']);

        // The stored (already purified) signature reaches the post as-is, with
        // only the author's own newline turned into a <br>.
        $this->assertStringContainsString('href="https://example.com"', $opening['authorSignature']);
        $this->assertStringContainsString('<br', $opening['authorSignature']);
        // Repeated on every post by that author, forum-style - not just the
        // opening one.
        $this->assertSame($opening['authorSignature'], $ownReply['authorSignature']);
        // Author 8 has no user row at all, so no signature either - which is
        // also what the template's single truthiness check keys off.
        $this->assertSame('', $otherReply['authorSignature']);

        $this->assertFalse($ownReply['isOpeningPost']);
        $this->assertTrue($ownReply['canEdit']);
        $this->assertTrue($ownReply['canDelete']);
        $this->assertNotNull($ownReply['editableContent']);
        // updatedAt moved past createdAt, so this was a real edit rather than
        // a freshly inserted row (insert() stamps both the same).
        $this->assertTrue($ownReply['wasEdited']);
        $this->assertNotNull($ownReply['editedAtLabel']);
        $this->assertNotNull($ownReply['editedAtTitle']);
        $this->assertSame(4, $ownReply['userRating']);

        $this->assertFalse($otherReply['isCurrentUser']);
        $this->assertFalse($otherReply['isTopicAuthor']);
        $this->assertFalse($otherReply['canEdit']);
        $this->assertFalse($otherReply['canDelete']);
        $this->assertNull($otherReply['editableContent']);
        // Computed for every post regardless of canEdit - quoting someone
        // else's post needs their plain text too.
        $this->assertNotSame('', $otherReply['quoteText']);
        // No user row for author 8, and no forum-post count either.
        $this->assertSame(0, $otherReply['authorPostCount']);
        $this->assertNull($otherReply['userRating']);
        $this->assertNull($otherReply['authorMemberSince']);

        // Only the opening post can carry attachments.
        $this->assertSame([[], [], []], array_column($posts, 'attachments'));
    }

    /**
     * The header stats, and the two side effects of rendering a topic: the
     * view counter (a plain increment, reflected locally rather than
     * re-fetched) and - because this render lands on the topic's last page -
     * the read watermark.
     */
    public function testTopicViewCountsTheViewAndMarksTheTopicReadOnItsLastPage(): void
    {
        $module = $this->makeModule($this->makeTopicViewDb(
            replyCount: 2,
            replyRows: [
                $this->feedRow(910, 'comment', parentId: self::TOPIC_ID, ownerId: 7),
                $this->feedRow(911, 'comment', parentId: self::TOPIC_ID, ownerId: 8),
            ],
            participantRows: [
                ['owner_id' => 7, 'total_count' => 7],
                ['owner_id' => 8, 'total_count' => 7],
            ],
        ));

        $feedService = $this->createMock(FeedService::class);
        $this->stubTopicResolution($feedService, $this->makeTopic(views: 17));
        $feedService->expects($this->once())->method('recordView')->with(self::TOPIC_ID);
        $feedService->expects($this->once())->method('markFeedAsRead')->with(self::TOPIC_ID, $this->anything());
        $this->setProperty($module, 'feedService', $feedService);

        $pollService = $this->createStub(PollService::class);
        $pollService->method('getPollForFeed')->willReturn(null);
        $this->setProperty($module, 'pollService', $pollService);

        $view = $module->show($this->makeTopicViewPage(), ['slug' => 'svecha-gasnet']);

        // Opening post + 2 replies.
        $this->assertSame(3, $view->data['topicTotals']['posts']);
        $this->assertSame(7, $view->data['topicTotals']['participants']);
        // The row was read before recordView() bumped it, so the page adds the
        // increment locally instead of re-querying.
        $this->assertSame(18, $view->data['topicTotals']['views']);
        // Five more posters than the avatar strip has room for.
        $this->assertSame(5, $view->data['participantsOverflow']);
        $this->assertCount(2, $view->data['participants']);
    }

    /**
     * A topic longer than one page must *not* be marked read from page 1:
     * read state is "seen since last activity", with no per-page position, so
     * doing it here would hide the unread badge for someone who never got to
     * the newest replies.
     */
    public function testTopicViewDoesNotMarkTheTopicReadBeforeItsLastPage(): void
    {
        $module = $this->makeModule($this->makeTopicViewDb(
            // 41 replies + the opening post = 42 posts = three pages of 20.
            replyCount: 41,
            replyRows: [$this->feedRow(910, 'comment', parentId: self::TOPIC_ID, ownerId: 7)],
        ));

        $feedService = $this->createMock(FeedService::class);
        $this->stubTopicResolution($feedService, $this->makeTopic());
        $feedService->expects($this->once())->method('recordView');
        $feedService->expects($this->never())->method('markFeedAsRead');
        $this->setProperty($module, 'feedService', $feedService);

        $pollService = $this->createStub(PollService::class);
        $pollService->method('getPollForFeed')->willReturn(null);
        $this->setProperty($module, 'pollService', $pollService);

        $view = $module->show($this->makeTopicViewPage(), ['slug' => 'svecha-gasnet']);

        $this->assertSame(3, $view->data['pagination']['total']);
        $this->assertSame(1, $view->data['pagination']['current']);
        $this->assertSame(42, $view->data['topicTotals']['posts']);
    }

    /**
     * "Цитировать"/"Изменить" both need something close to what the author
     * originally typed, so the stored HTML is walked back: a leading quote
     * block becomes "> ..." lines again (the exact shape
     * FeedService::extractLeadingQuotes() expects on the way back in, so an
     * already-quoted post round-trips instead of doubling up its markup),
     * <br> becomes a newline, and entities are decoded.
     */
    public function testTopicViewTurnsStoredQuoteMarkupBackIntoQuotedPlainText(): void
    {
        $stored = '<blockquote class="comment-quote mb-3 px-3 py-2 rounded-2">'
            .'<div class="text-body-secondary mb-1 comment-quote-author">'
            .'<i class="bi bi-quote me-1"></i>Аня писал(а):</div>'
            .'<div class="text-body-secondary comment-quote-text">Первая строка<br>'."\n"
            .'Вторая &amp; строка</div>'
            .'</blockquote>Мой ответ &amp; вывод';

        $module = $this->makeModule($this->makeTopicViewDb());

        $feedService = $this->createStub(FeedService::class);
        $this->stubTopicResolution($feedService, $this->makeTopic(content: $stored));
        $feedService->method('isWithinEditWindow')->willReturn(true);
        $this->setProperty($module, 'feedService', $feedService);

        $pollService = $this->createStub(PollService::class);
        $pollService->method('getPollForFeed')->willReturn(null);
        $this->setProperty($module, 'pollService', $pollService);

        $opening = $module->show($this->makeTopicViewPage(), ['slug' => 'svecha-gasnet'])->data['posts'][0];

        $expected = "> Аня писал(а):\n> Первая строка\n> Вторая & строка\n\nМой ответ & вывод";

        $this->assertSame($expected, $opening['quoteText']);
        // The edit textarea is prefilled from the same computed string rather
        // than transforming twice.
        $this->assertSame($expected, $opening['editableContent']);
    }

    /**
     * A member who can still vote gets the form and *only* the form - even
     * under the default "results always visible" setting, the results card
     * isn't glued underneath the ballot they're still filling in.
     */
    public function testTopicViewPollShowsOnlyTheVoteFormToAMemberWhoHasNotVoted(): void
    {
        $poll = $this->buildPollView(
            $this->makePoll(votersCount: 0),
            userVotes: [],
            canSeeResults: true,
        );

        $this->assertTrue($poll['showVoteForm']);
        $this->assertFalse($poll['showResults']);
        $this->assertFalse($poll['showHiddenNote']);
        $this->assertFalse($poll['hasVoted']);
        $this->assertFalse($poll['canChangeVote']);
        $this->assertFalse($poll['isClosed']);
        // Nothing selected yet, so the submit button starts disabled.
        $this->assertTrue($poll['submitDisabled']);
        $this->assertFalse($poll['pollGuestPrompt']);
    }

    /**
     * A single-choice poll's percentages are "share of all votes cast" - each
     * voter picked exactly one option, so that denominator equals the voter
     * count too.
     */
    public function testTopicViewPollScalesSingleChoicePercentagesByTotalVotes(): void
    {
        $poll = $this->buildPollView(
            $this->makePoll(votersCount: 4, optionVotes: [3, 1]),
            userVotes: [1],
            canSeeResults: true,
        );

        $this->assertFalse($poll['showVoteForm']);
        $this->assertTrue($poll['showResults']);
        $this->assertTrue($poll['hasVoted']);
        $this->assertFalse($poll['isMultiple']);

        $this->assertSame([75, 25], array_column($poll['options'], 'pct'));
        $this->assertSame([true, false], array_column($poll['options'], 'isLeading'));
        $this->assertSame([true, false], array_column($poll['options'], 'checked'));
        $this->assertSame('1', $poll['currentVotesAttr']);
    }

    /**
     * A multiple-choice poll's percentages are "share of respondents who
     * picked this option" instead - one voter can push several counts up at
     * once, so using the total vote count would make the bars sum to less than
     * 100% and under-represent popular options.
     */
    public function testTopicViewPollScalesMultipleChoicePercentagesByVoterCount(): void
    {
        $poll = $this->buildPollView(
            $this->makePoll(votersCount: 4, maxChoices: 2, optionVotes: [3, 3]),
            userVotes: [1, 2],
            canSeeResults: true,
        );

        $this->assertTrue($poll['isMultiple']);
        // 3 of 4 respondents each, not 3 of 6 votes.
        $this->assertSame([75, 75], array_column($poll['options'], 'pct'));
        $this->assertSame([true, true], array_column($poll['options'], 'isLeading'));
        $this->assertSame('1,2', $poll['currentVotesAttr']);
    }

    /**
     * An "after vote" poll that closed without the viewer ever voting stays
     * hidden forever - the app's own Poll::canSeeResults() rule, which the
     * original mockup's "closed always reveals results" shortcut would have
     * gotten wrong.
     */
    public function testTopicViewPollHidesResultsOfAClosedPollTheViewerNeverVotedIn(): void
    {
        $poll = $this->buildPollView(
            $this->makePoll(
                votersCount: 3,
                closesAt: time() - 3600,
                resultsVisibility: Poll::VISIBILITY_AFTER_VOTE,
                optionVotes: [2, 1],
            ),
            userVotes: [],
            canSeeResults: false,
        );

        $this->assertTrue($poll['isClosed']);
        $this->assertFalse($poll['showVoteForm']);
        $this->assertFalse($poll['showResults']);
        $this->assertTrue($poll['showHiddenNote']);
        $this->assertNotNull($poll['deadlineText']);
        // Voting is over for everyone, so there's nothing to prompt a guest
        // to sign in for either.
        $this->assertFalse($poll['pollGuestPrompt']);
    }

    /**
     * A guest can never vote, so results are shown right away (when the poll's
     * own visibility allows it) alongside a prompt to sign in.
     */
    public function testTopicViewPollShowsResultsToAGuestWithNoVoteForm(): void
    {
        $poll = $this->buildPollView(
            $this->makePoll(votersCount: 4, optionVotes: [3, 1]),
            userVotes: [],
            canSeeResults: true,
            user: new User(id: 0, email: '', role: AccessService::ROLE_USER),
        );

        $this->assertFalse($poll['showVoteForm']);
        $this->assertTrue($poll['showResults']);
        $this->assertTrue($poll['pollGuestPrompt']);
    }

    /* ------------------------------------------------------------------ */
    /* forums.topic-new (GET)                                             */
    /* ------------------------------------------------------------------ */

    /**
     * The create half of the same form the edit tests above exercise: no
     * topic, nothing prefilled, and a canonical built by filling the
     * *ancestor* topic-list page's {slug} - this page's own pattern is the
     * literal 'new'.
     */
    public function testTopicNewPageBuildsCreateModeForm(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedByTypeAndSlug')->willReturn($this->makeForumFeed());
        $this->setProperty($module, 'feedService', $feedService);

        $view = $module->show($this->makeTopicNewPage(), ['slug' => 'magiya']);

        $this->assertSame('modules/forums/forums.topic-form.twig', $view->template);
        $this->assertNull($view->data['topic']);
        $this->assertSame('/api/v1/forums/topics', $view->data['apiUrl']);
        $this->assertSame('POST', $view->data['apiMethod']);
        $this->assertSame([], $view->data['attachments']);
        $this->assertNull($view->data['poll']);
        $this->assertFalse($view->data['pollLocked']);
        $this->assertSame('/forums/magiya/new/', $view->data['canonical']);
        // Cancel goes back to the section - unlike edit mode, which returns to
        // the topic the author came from.
        $this->assertSame('/forums/magiya/', $view->data['cancelUrl']);
    }

    public function testTopicNewPageRefusesAFeedThatIsNotAForum(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedByTypeAndSlug')->willReturn($this->makeTopic());
        $this->setProperty($module, 'feedService', $feedService);

        $this->expectException(ForbiddenException::class);

        $module->show($this->makeTopicNewPage(), ['slug' => 'svecha-gasnet']);
    }

    /* ------------------------------------------------------------------ */
    /* getBreadcrumb()                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * A forum's and a topic's crumbs both swap the generic pattern-based one
     * for the real feed's own title/slug.
     */
    public function testBreadcrumbUsesTheResolvedFeedTitle(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->once())
            ->method('getFeedByTypeAndSlug')
            ->with('forum', 'magiya', $this->anything())
            ->willReturn($this->makeForumFeed());
        $feedService->expects($this->once())
            ->method('getFeedByParentAndSlug')
            ->with(self::FORUM_ID, 'svecha-gasnet', $this->anything(), 'forum-post')
            ->willReturn($this->makeTopic(title: 'Свеча гаснет'));
        $this->setProperty($module, 'feedService', $feedService);

        $page = $this->makeTopicViewPage();
        $page->params = ['slug' => 'svecha-gasnet'];

        $crumb = $module->getBreadcrumb($page);

        $this->assertSame('Свеча гаснет', $crumb->title);
        $this->assertSame('svecha-gasnet', $crumb->slug);
    }

    public function testBreadcrumbThrowsForbiddenWhenTheFeedDoesNotResolve(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedByTypeAndSlug')->willReturn(null);
        $this->setProperty($module, 'feedService', $feedService);

        $page = $this->makeTopicListPage();
        $page->params = ['slug' => 'magiya'];

        $this->expectException(ForbiddenException::class);

        $module->getBreadcrumb($page);
    }

    /**
     * Pages with no feed behind them (forums.list) fall through to the base
     * controller's pattern-based crumb - no feed lookup at all.
     */
    public function testBreadcrumbFallsBackToThePatternForAFeedlessPage(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->never())->method('getFeedBySlug');
        $feedService->expects($this->never())->method('getFeedByTypeAndSlug');
        $this->setProperty($module, 'feedService', $feedService);

        $crumb = $module->getBreadcrumb($this->makeForumsListPage());

        $this->assertSame('Форумы', $crumb->title);
        $this->assertSame('forums', $crumb->slug);
    }

    /* ------------------------------------------------------------------ */
    /* POST /api/v1/forums/topics                                         */
    /* ------------------------------------------------------------------ */

    public function testTopicCreateStoresAPublicForumPostAndReturnsItsUrl(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeForumFeed());
        $feedService->expects($this->once())
            ->method('createFeed')
            ->with(
                'Свеча гаснет',
                'svecha-gasnet',
                'forum-post',
                self::FORUM_ID,
                null,
                null,
                '<div>Текст</div>',
                $this->anything(),
                // No attachments in this payload, so no metadata to write.
                null,
            )
            ->willReturn($this->makeTopic());
        $this->setProperty($module, 'feedService', $feedService);

        $output = $this->callCreateApi($module, [
            'forumId' => self::FORUM_ID,
            'title' => 'Свеча гаснет',
            'content' => '<div>Текст</div>',
        ]);

        $decoded = json_decode($output, true);
        $this->assertSame(self::TOPIC_ID, $decoded['id']);
        $this->assertSame('svecha-gasnet', $decoded['slug']);
        $this->assertSame('/forums/magiya/svecha-gasnet/', $decoded['canonicalUrl']);
    }

    /**
     * 'new' is forums.topic-new's own pattern, mounted as a static sibling of
     * every topic's page - and Router matches static routes first - so a topic
     * that landed on that slug would be permanently unreachable at its own URL.
     */
    public function testTopicCreateNeverLetsATopicTakeTheReservedNewSlug(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeForumFeed());
        $feedService->expects($this->once())
            ->method('createFeed')
            ->with('New', 'new-2', $this->anything(), $this->anything())
            ->willReturn($this->makeTopic());
        $this->setProperty($module, 'feedService', $feedService);

        $this->callCreateApi($module, [
            'forumId' => self::FORUM_ID,
            'title' => 'New',
            'content' => '<div>Текст</div>',
        ]);
    }

    /** Topic slugs are unique within their own forum, not across all forums. */
    public function testTopicCreateAppendsASuffixWhenTheSlugIsAlreadyTaken(): void
    {
        $module = $this->makeModule($this->makeRoutingDb([], [
            // Only the first candidate is taken; 'test-2' comes back free.
            self::SQL_FEED_BY_PARENT_AND_SLUG => fn (array $params): ?array => $params[1] === 'test'
                ? $this->feedRow(500, 'forum-post', parentId: self::FORUM_ID)
                : null,
        ]));

        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeForumFeed());
        $feedService->expects($this->once())
            ->method('createFeed')
            ->with('Test', 'test-2', $this->anything(), $this->anything())
            ->willReturn($this->makeTopic());
        $this->setProperty($module, 'feedService', $feedService);

        $this->callCreateApi($module, [
            'forumId' => self::FORUM_ID,
            'title' => 'Test',
            'content' => '<div>Текст</div>',
        ]);
    }

    public function testTopicCreateRejectsGuests(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());
        $this->setContext($module, new User(id: 0, email: '', role: AccessService::ROLE_USER));

        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->never())->method('createFeed');
        $this->setProperty($module, 'feedService', $feedService);

        $this->expectException(ForbiddenException::class);

        $this->callCreateApi($module, [
            'forumId' => self::FORUM_ID,
            'title' => 'Заголовок',
            'content' => '<div>Текст</div>',
        ]);
    }

    public function testTopicCreateRejectsAParentThatIsNotAForum(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createMock(FeedService::class);
        // A topic, not a section - you can't post a topic inside a topic.
        $feedService->method('getFeedById')->willReturn($this->makeTopic());
        $feedService->expects($this->never())->method('createFeed');
        $this->setProperty($module, 'feedService', $feedService);

        $this->expectException(ValidationException::class);

        $this->callCreateApi($module, [
            'forumId' => self::TOPIC_ID,
            'title' => 'Заголовок',
            'content' => '<div>Текст</div>',
        ]);
    }

    public function testTopicCreateRejectsANonPostMethod(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Method not allowed');

        $this->callCreateApi($module, ['title' => 'T', 'content' => '<p>x</p>'], method: 'GET');
    }

    /**
     * "Подписаться на тему" is the create form's own switch (checked by
     * default), wired to the same generic favorites the "Следить" button uses -
     * which is what makes the author a follower for reply notifications.
     */
    public function testTopicCreateSubscribesTheAuthorWhenAsked(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeForumFeed());
        $feedService->method('createFeed')->willReturn($this->makeTopic());
        $feedService->expects($this->once())->method('addFavorite')->with(self::TOPIC_ID, $this->anything());
        $this->setProperty($module, 'feedService', $feedService);

        $this->callCreateApi($module, [
            'forumId' => self::FORUM_ID,
            'title' => 'Заголовок',
            'content' => '<div>Текст</div>',
            'subscribe' => true,
        ]);
    }

    /**
     * The topic itself is already written by the time the subscribe step runs,
     * so a failure there must not turn a successful create into a 500 the
     * author would retry - and duplicate.
     */
    public function testTopicCreateStillSucceedsWhenSubscribingFails(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeForumFeed());
        $feedService->method('createFeed')->willReturn($this->makeTopic());
        $feedService->method('addFavorite')->willThrowException(new RuntimeException('favorites are down'));
        $this->setProperty($module, 'feedService', $feedService);

        $output = '';
        $log = $this->captureErrorLog(function () use ($module, &$output): void {
            $output = $this->callCreateApi($module, [
                'forumId' => self::FORUM_ID,
                'title' => 'Заголовок',
                'content' => '<div>Текст</div>',
                'subscribe' => true,
            ]);
        });

        $this->assertSame(self::TOPIC_ID, json_decode($output, true)['id']);
        $this->assertStringContainsString('favorites are down', $log);
    }

    /**
     * Server-side enforcement of the form's own "не больше 5 файлов": a client
     * that bypasses its own cap still can't store more than the form claims to
     * allow, and repeated or bogus ids are dropped rather than failing the
     * whole submission.
     */
    public function testTopicCreateDeduplicatesAndCapsAttachments(): void
    {
        $uploadService = $this->createStub(UploadService::class);
        $uploadService->method('findOwnedUpload')->willReturnCallback(
            fn (int $id): Upload => $this->makeUpload($id)
        );

        $module = $this->makeModule($this->makeRoutingDb(), $uploadService);

        $captured = null;
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeForumFeed());
        $feedService->method('createFeed')->willReturnCallback(
            function (...$args) use (&$captured): Feed {
                $captured = $args;

                return $this->makeTopic();
            }
        );
        $this->setProperty($module, 'feedService', $feedService);

        $this->callCreateApi($module, [
            'forumId' => self::FORUM_ID,
            'title' => 'Заголовок',
            'content' => '<div>Текст</div>',
            'attachments' => [41, 41, 42, 43, 44, 45, 46, 0, -3],
        ]);

        // feed_metadata has no array column, so the list is stored as one
        // JSON-encoded value.
        $this->assertSame('[41,42,43,44,45]', $captured[8]['attachment_upload_ids']);
    }

    /**
     * Every id in a create payload has to be one of the author's own uploads -
     * findOwnedUpload() is the same ownership-checked lookup a blog post's
     * track goes through. A foreign id is dropped, not fatal.
     */
    public function testTopicCreateDropsAttachmentsTheAuthorDoesNotOwn(): void
    {
        $uploadService = $this->createStub(UploadService::class);
        $uploadService->method('findOwnedUpload')->willReturnCallback(
            fn (int $id): ?Upload => $id === 41 ? $this->makeUpload($id) : null
        );

        $module = $this->makeModule($this->makeRoutingDb(), $uploadService);

        $captured = null;
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeForumFeed());
        $feedService->method('createFeed')->willReturnCallback(
            function (...$args) use (&$captured): Feed {
                $captured = $args;

                return $this->makeTopic();
            }
        );
        $this->setProperty($module, 'feedService', $feedService);

        $this->callCreateApi($module, [
            'forumId' => self::FORUM_ID,
            'title' => 'Заголовок',
            'content' => '<div>Текст</div>',
            'attachments' => [41, 42],
        ]);

        $this->assertSame('[41]', $captured[8]['attachment_upload_ids']);
    }

    /**
     * The poll card maps onto the generic PollService: "можно выбрать
     * несколько" means maxChoices = every option (no real cap beyond "all of
     * them"), and the form exposes no revote/visibility controls, so those stay
     * at their defaults.
     */
    public function testTopicCreateAttachesAMultipleChoicePollCappedAtItsOptionCount(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeForumFeed());
        $feedService->method('createFeed')->willReturn($this->makeTopic());
        $this->setProperty($module, 'feedService', $feedService);

        $pollService = $this->createMock(PollService::class);
        $pollService->expects($this->once())
            ->method('createPoll')
            ->with(
                self::TOPIC_ID,
                'Какой ритуал?',
                ['А', 'Б', 'В'],
                3,
                false,
                Poll::VISIBILITY_ALWAYS,
                // "Без ограничения".
                null,
                $this->anything(),
            )
            ->willReturn($this->makePoll(votersCount: 0));
        $this->setProperty($module, 'pollService', $pollService);

        $this->callCreateApi($module, [
            'forumId' => self::FORUM_ID,
            'title' => 'Заголовок',
            'content' => '<div>Текст</div>',
            'poll' => [
                'question' => 'Какой ритуал?',
                'options' => ['А', 'Б', 'В'],
                'multiple' => true,
                'durationDays' => 0,
            ],
        ]);
    }

    /**
     * durationDays is clamped to the three values the select actually offers -
     * both so an arbitrary number can't overflow the `$days * 86400` arithmetic
     * into a float (a TypeError *after* the topic itself has been written), and
     * so the edit form's "did the author change this?" comparison compares like
     * with like.
     */
    public function testTopicCreateClampsAPollDurationTheFormNeverOffers(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeForumFeed());
        $feedService->method('createFeed')->willReturn($this->makeTopic());
        $this->setProperty($module, 'feedService', $feedService);

        $pollService = $this->createMock(PollService::class);
        $pollService->expects($this->once())
            ->method('createPoll')
            ->with(
                self::TOPIC_ID,
                $this->anything(),
                $this->anything(),
                1,
                false,
                Poll::VISIBILITY_ALWAYS,
                // Anything outside POLL_DURATION_DAYS collapses to
                // "open-ended" rather than being honoured.
                null,
                $this->anything(),
            )
            ->willReturn($this->makePoll(votersCount: 0));
        $this->setProperty($module, 'pollService', $pollService);

        $this->callCreateApi($module, [
            'forumId' => self::FORUM_ID,
            'title' => 'Заголовок',
            'content' => '<div>Текст</div>',
            'poll' => [
                'question' => 'Вопрос?',
                'options' => ['А', 'Б'],
                'durationDays' => 99999999999,
            ],
        ]);
    }

    public function testTopicCreateMeasuresAnOfferedPollDurationFromNow(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeForumFeed());
        $feedService->method('createFeed')->willReturn($this->makeTopic());
        $this->setProperty($module, 'feedService', $feedService);

        $pollService = $this->createMock(PollService::class);
        $pollService->expects($this->once())
            ->method('createPoll')
            ->with(
                self::TOPIC_ID,
                $this->anything(),
                $this->anything(),
                1,
                false,
                Poll::VISIBILITY_ALWAYS,
                $this->callback(
                    static fn (?int $closesAt): bool => $closesAt !== null
                        && abs($closesAt - (time() + 7 * 86400)) <= 5
                ),
                $this->anything(),
            )
            ->willReturn($this->makePoll(votersCount: 0));
        $this->setProperty($module, 'pollService', $pollService);

        $this->callCreateApi($module, [
            'forumId' => self::FORUM_ID,
            'title' => 'Заголовок',
            'content' => '<div>Текст</div>',
            'poll' => [
                'question' => 'Вопрос?',
                'options' => ['А', 'Б'],
                'durationDays' => 7,
            ],
        ]);
    }

    /**
     * Same reasoning as the subscribe step: the author ends up with a topic and
     * no poll rather than a failed submission they'd retry.
     */
    public function testTopicCreateStillSucceedsWhenThePollCannotBeCreated(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeForumFeed());
        $feedService->method('createFeed')->willReturn($this->makeTopic());
        $this->setProperty($module, 'feedService', $feedService);

        $pollService = $this->createStub(PollService::class);
        $pollService->method('createPoll')->willThrowException(new RuntimeException('poll storage is down'));
        $this->setProperty($module, 'pollService', $pollService);

        $output = '';
        $log = $this->captureErrorLog(function () use ($module, &$output): void {
            $output = $this->callCreateApi($module, [
                'forumId' => self::FORUM_ID,
                'title' => 'Заголовок',
                'content' => '<div>Текст</div>',
                'poll' => ['question' => 'Вопрос?', 'options' => ['А', 'Б']],
            ]);
        });

        $this->assertSame(self::TOPIC_ID, json_decode($output, true)['id']);
        $this->assertStringContainsString('poll storage is down', $log);
    }

    /* ------------------------------------------------------------------ */
    /* POST /api/v1/forums/read-all                                       */
    /* ------------------------------------------------------------------ */

    /**
     * markFeedsAsRead() no-ops for guests anyway, so this rejects them rather
     * than doing all the topic-id query work for nothing.
     */
    public function testMarkAllReadRejectsGuests(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());
        $this->setContext($module, new User(id: 0, email: '', role: AccessService::ROLE_USER));

        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->never())->method('markFeedsAsRead');
        $this->setProperty($module, 'feedService', $feedService);

        $this->expectException(ForbiddenException::class);

        $this->callReadAllApi($module, []);
    }

    /**
     * A section's own topics *plus* its direct subforums' - matching how the
     * topic-list page already renders a top-level forum's subforums inline
     * rather than as a separate drill-down.
     */
    public function testMarkAllReadForOneSectionCoversItsSubforumsToo(): void
    {
        $module = $this->makeModule($this->makeRoutingDb([
            self::SQL_TOPIC_IDS_FOR_FORUMS => [['id' => 900], ['id' => 901]],
        ]));

        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeForumFeed());
        $feedService->method('getFeedsByParentAndType')->willReturn([
            $this->makeForum(id: 71, slug: 'tarot', parentId: self::FORUM_ID),
        ]);
        $feedService->expects($this->once())
            ->method('markFeedsAsRead')
            ->with([900, 901], $this->anything());
        $this->setProperty($module, 'feedService', $feedService);

        $this->callReadAllApi($module, ['forumId' => self::FORUM_ID]);
    }

    public function testMarkAllReadWithoutAForumIdCoversEveryTopic(): void
    {
        $module = $this->makeModule($this->makeRoutingDb([
            self::SQL_ALL_TOPIC_IDS => [['id' => 900], ['id' => 901], ['id' => 902]],
        ]));

        $feedService = $this->createMock(FeedService::class);
        // Nothing to resolve - the site-wide button sends no scope at all.
        $feedService->expects($this->never())->method('getFeedById');
        $feedService->expects($this->once())
            ->method('markFeedsAsRead')
            ->with([900, 901, 902], $this->anything());
        $this->setProperty($module, 'feedService', $feedService);

        $this->callReadAllApi($module, []);
    }

    /* ------------------------------------------------------------------ */
    /* POST /api/v1/forums/{topicId}/read                                 */
    /* ------------------------------------------------------------------ */

    public function testTopicReadMarksASingleTopicRead(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeTopic());
        $feedService->expects($this->once())
            ->method('markFeedAsRead')
            ->with(self::TOPIC_ID, $this->anything());
        $this->setProperty($module, 'feedService', $feedService);

        $this->callTopicReadApi($module);
    }

    /**
     * Guests are deliberately *not* rejected here (unlike the bulk endpoint):
     * markFeedAsRead() has its own guest branch, the cookie-backed store, which
     * is the same one the automatic call site relies on.
     */
    public function testTopicReadWorksForGuestsThroughTheirOwnReadStore(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());
        $this->setContext($module, new User(id: 0, email: '', role: AccessService::ROLE_USER));

        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeTopic());
        $feedService->expects($this->once())->method('markFeedAsRead');
        $this->setProperty($module, 'feedService', $feedService);

        $this->callTopicReadApi($module);
    }

    public function testTopicReadRejectsAnIdThatIsNotATopic(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createMock(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeForumFeed());
        $feedService->expects($this->never())->method('markFeedAsRead');
        $this->setProperty($module, 'feedService', $feedService);

        $this->expectException(ValidationException::class);

        $this->callTopicReadApi($module);
    }

    /* ------------------------------------------------------------------ */
    /* POST /api/v1/forums/{topicId}/reply                                */
    /* ------------------------------------------------------------------ */

    public function testTopicReplyCreatesACommentFromTrimmedContent(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->once())
            ->method('createComment')
            ->with(self::TOPIC_ID, 'Ответ по теме', $this->anything())
            ->willReturn($this->makeReply(910));
        $this->setProperty($module, 'feedService', $feedService);

        $output = $this->callReplyApi($module, ['content' => "  Ответ по теме\n"]);

        $this->assertSame(910, json_decode($output, true)['id']);
    }

    public function testTopicReplyRejectsGuestsInTheController(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());
        $this->setContext($module, new User(id: 0, email: '', role: AccessService::ROLE_USER));

        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->never())->method('createComment');
        $this->setProperty($module, 'feedService', $feedService);

        $this->expectException(ForbiddenException::class);

        $this->callReplyApi($module, ['content' => 'Ответ']);
    }

    /**
     * The whole reason a reply has its own endpoint instead of posting to the
     * generic comments action: whoever favorited the topic gets a queued
     * notification - except the author, who shouldn't be notified about
     * their own reply.
     *
     * MessageService is `final readonly`, so it can't be doubled - this builds
     * a real one over its own FakePdoDatabase and reads the queued deliveries
     * back out afterwards. That's a second database instance alongside the
     * controller's own routing stub, which is fine: the two never share a
     * table here (followers come from feed_favorites, messages go to
     * conversations/messages).
     */
    public function testTopicReplyNotifiesEveryFollowerButTheAuthor(): void
    {
        $messageDb = new FakePdoDatabase();
        $messageService = new MessageService(
            $messageDb,
            new MessageRepository($messageDb),
            new ParticipantRepository($messageDb),
            new ConversationRepository($messageDb),
            new UserRepository($messageDb),
            new UserSessionRepository($messageDb),
            new UploadRepository($messageDb),
        );

        $module = $this->makeModule(
            $this->makeRoutingDb(
                [
                    // User 7 is the replying author themselves.
                    self::SQL_TOPIC_FOLLOWERS => [['user_id' => 7], ['user_id' => 8], ['user_id' => 9]],
                ],
                [
                    self::SQL_FEED_BY_ID => $this->feedRow(self::TOPIC_ID, 'forum-post', parentId: self::FORUM_ID),
                ]
            ),
            messageService: $messageService,
            notificationDb: $messageDb,
        );

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('createComment')->willReturn($this->makeReply(910));
        $this->setProperty($module, 'feedService', $feedService);

        $this->callReplyApi($module, ['content' => 'Ответ']);

        $this->assertCount(2, $messageDb->notificationDeliveries);

        $recipients = array_column($messageDb->notificationDeliveries, 'recipient_user_id');
        sort($recipients);

        $this->assertSame([8, 9], $recipients);
        $this->assertStringContainsString(
            'Feed '.self::TOPIC_ID,
            $messageDb->notificationDeliveries[0]['message_text'],
        );
        $this->assertSame([], $messageDb->messages);
    }

    public function testTopicReplySendsNothingWhenNobodyFollowsTheTopic(): void
    {
        $messageDb = new FakePdoDatabase();
        $messageService = new MessageService(
            $messageDb,
            new MessageRepository($messageDb),
            new ParticipantRepository($messageDb),
            new ConversationRepository($messageDb),
            new UserRepository($messageDb),
            new UserSessionRepository($messageDb),
            new UploadRepository($messageDb),
        );

        $module = $this->makeModule(
            $this->makeRoutingDb(),
            messageService: $messageService,
            notificationDb: $messageDb,
        );

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('createComment')->willReturn($this->makeReply(910));
        $this->setProperty($module, 'feedService', $feedService);

        $this->callReplyApi($module, ['content' => 'Ответ']);

        $this->assertSame([], $messageDb->messages);
    }

    /* ------------------------------------------------------------------ */
    /* callApi() dispatch                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * '/api/v1/forums' and '/api/v1/forums/{topicId}' exist only to hang their
     * children off - a bare request to either has to 400 here rather than
     * reach a null action.
     */
    public function testCallApiRejectsAnActionItDoesNotOwn(): void
    {
        $module = $this->makeModule($this->makeRoutingDb());

        $page = $this->makePage(
            id: 400,
            parentId: 399,
            pattern: 'forums',
            action: 'forums.api-parent',
            responseType: 'json',
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Unknown API action');

        $this->callJsonApi($module, $page, method: 'GET');
    }

    /* ------------------------------------------------------------------ */
    /* fixtures / harness                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * A ForumsController wired for the PATCH tests, with the two collaborators
     * those tests configure handed back by reference (a mock rather than a
     * stub for both: several of these assert on *whether* a method is called,
     * not just its return).
     */
    private function makeUpdatableModule(
        &$feedService,
        &$pollService,
        bool $withinEditWindow = true,
        bool $mockFeedService = false,
        bool $mockPollService = false,
    ): ForumsController {
        $module = $this->makeModule($this->createStub(PdoDatabase::class));

        // A collaborator is only built as a createMock() when the caller
        // actually asserts on its calls; everything else is a createStub().
        // PHPUnit 12 emits a notice for a mock object with no configured
        // expectations ("consider a test stub instead"), and these tests split
        // cleanly down the middle - the content/validation ones assert on
        // FeedService::updateFeed(), the poll ones on PollService's
        // deletePoll()/createPoll(), and none assert on both.
        //
        // isWithinEditWindow is a constructor-style argument rather than
        // something a test overrides afterwards: re-stubbing the same method
        // appends a second matcher instead of replacing the first, so the
        // original `true` would keep winning and the "window expired" test
        // would pass for the wrong reason.
        $feedService = $mockFeedService
            ? $this->createMock(FeedService::class)
            : $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($this->makeTopic());
        $feedService->method('canEditFeed')->willReturn(true);
        $feedService->method('isWithinEditWindow')->willReturn($withinEditWindow);
        $this->setProperty($module, 'feedService', $feedService);

        $pollService = $mockPollService
            ? $this->createMock(PollService::class)
            : $this->createStub(PollService::class);
        $this->setProperty($module, 'pollService', $pollService);

        return $module;
    }

    /**
     * Runs $fn with error_log() redirected to a temp file and returns whatever
     * landed there.
     *
     * Two things at once: it keeps ForumsController's deliberate "logged and
     * swallowed" diagnostics (see replaceTopicPoll()'s docblock) out of the
     * test run's output - PHPUnit captures error_log() per test and *prints*
     * anything a test didn't declare, which reads exactly like a real
     * production error to anything watching a CI log - and it turns the log
     * line itself into something assertable, which is the only externally
     * visible evidence that the unusable-payload branch was the one taken
     * (the response body is a normal 200 either way).
     *
     * PHPUnit's own expectErrorLog() covers the first half only ("something
     * was logged", no access to what) - enough where the message doesn't
     * matter, as in Tests\Core\CronRunnerTest, but not here.
     *
     * Nests correctly inside that per-test capture: $previous is PHPUnit's own
     * temp target, restored on the way out.
     */
    private function captureErrorLog(callable $fn): string
    {
        $logFile = tempnam(sys_get_temp_dir(), 'forums-error-log-');
        $previous = ini_get('error_log');

        ini_set('error_log', $logFile);

        try {
            $fn();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $contents = (string) file_get_contents($logFile);
        unlink($logFile);

        return $contents;
    }

    /**
     * Drives one JSON API action end to end: request method, a valid CSRF
     * pair, a php://input body, and whatever the handler echoed back.
     *
     * @param array<string, string> $args
     * @param array<string, mixed> $body
     */
    private function callJsonApi(
        ForumsController $module,
        Page $page,
        array $args = [],
        array $body = [],
        string $method = 'POST',
    ): string {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_COOKIE['csrfToken'] = 'token';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'token';

        PhpInputStreamMock::register(json_encode($body));

        // ob_get_clean() lives in the finally, not after the call: several of
        // these tests expect callApi() to throw, and an ob_start() left
        // unclosed would leak the buffer into whatever test runs next.
        ob_start();

        try {
            $module->callApi($page, $args);
        } finally {
            $output = (string) ob_get_clean();
            PhpInputStreamMock::restore();
            unset($_SERVER['REQUEST_METHOD'], $_SERVER['HTTP_X_CSRF_TOKEN'], $_COOKIE['csrfToken']);
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function callUpdateApi(ForumsController $module, array $body, string $method = 'PATCH'): string
    {
        return $this->callJsonApi(
            $module,
            $this->makeTopicItemApiPage(),
            ['id' => (string) self::TOPIC_ID],
            $body,
            $method,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function callCreateApi(ForumsController $module, array $body, string $method = 'POST'): string
    {
        return $this->callJsonApi(
            $module,
            $this->makeApiPage('topics', 'forums.topic-create'),
            [],
            $body,
            $method,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function callReadAllApi(ForumsController $module, array $body): string
    {
        return $this->callJsonApi($module, $this->makeApiPage('read-all', 'forums.read-all'), [], $body);
    }

    private function callTopicReadApi(ForumsController $module): string
    {
        return $this->callJsonApi(
            $module,
            $this->makeApiPage('read', 'forums.topic-read'),
            ['topicId' => (string) self::TOPIC_ID],
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function callReplyApi(ForumsController $module, array $body): string
    {
        return $this->callJsonApi(
            $module,
            $this->makeApiPage('reply', 'forums.reply'),
            ['topicId' => (string) self::TOPIC_ID],
            $body,
        );
    }

    private function makeApiPage(string $pattern, string $action): Page
    {
        return $this->makePage(
            id: 310,
            parentId: 299,
            pattern: $pattern,
            action: $action,
            requestMethods: ['POST'],
            responseType: 'json',
        );
    }

    #[DataProvider('paginationUrls')]
    public function testPaginationCanonicalAndInvalidPages(string $kind, mixed $number, ?string $suffix): void
    {
        $previousQuery = $_GET;
        $_GET = ['page' => $number, 'utm_source' => 'ignored'];
        try {
            $db = $kind === 'topic'
                ? $this->makeTopicViewDb(replyCount: 41)
                : $this->makeRoutingDb([], [self::SQL_TOPICS_TOTAL => ['total' => 45]]);
            $module = $this->makeModule($db);
            $feed = $kind === 'topic' ? $this->makeTopic() : $this->makeForumFeed();
            $feedService = $this->createStub(FeedService::class);
            if ($kind === 'topic') {
                $this->stubTopicResolution($feedService, $feed);
            } else {
                $feedService->method('getFeedByTypeAndSlug')->willReturn($feed);
            }
            $feedService->method('getFeedsByParentAndType')->willReturn($kind === 'base' ? [$this->makeForum(id: 80, slug: 'child', parentId: self::FORUM_ID)] : []);
            $this->setProperty($module, 'feedService', $feedService);
            $this->setProperty($module, 'pollService', $this->createStub(PollService::class));
            if ($suffix === null) {
                $this->expectException(NotFoundException::class);
            }
            $view = $kind === 'topic'
                ? $module->showTopicViewPage($this->makeTopicViewPage(), $feed->slug)
                : $module->showTopicListPage($this->makeTopicListPage(), $feed->slug);
            $this->assertSame($feed->canonicalUrl.$suffix, $view->data['canonical']);
            $this->assertSame($feed->canonicalUrl, $view->data['pagination']['baseUrl']);
        } finally {
            $_GET = $previousQuery;
        }
    }

    public static function paginationUrls(): iterable
    {
        foreach (['topic', 'list', 'base'] as $kind) {
            foreach (['1' => '', '2' => '?page=2', '3' => '?page=3', '4' => null,
                '0' => null, '-1' => null, 'abc' => null, '1.5' => null,
                (string) PHP_INT_MAX => null] as $number => $suffix) {
                yield $kind.' '.$number => [$kind, (string) $number, $kind === 'base' && $number > 1 ? null : $suffix];
            }
            yield $kind.' array' => [$kind, ['2'], null];
        }
    }

    private function stubTopicResolution(FeedService $feedService, Feed $topic): void
    {
        $feedService->method('getFeedByTypeAndSlug')->willReturn($this->makeForumFeed());
        $feedService->method('getFeedByParentAndSlug')->willReturn($topic);
    }

    /**
     * ForumsController's collaborators are constructor-promoted readonly
     * properties, so the subject is built without a constructor and each one
     * is injected directly - same approach as Tests\Modules\Users\
     * UsersControllerTest. Everything a test doesn't override gets a real (for
     * `final` classes) or stubbed default here.
     */
    private function makeModule(
        PdoDatabase $db,
        ?UploadService $uploadService = null,
        ?MessageService $messageService = null,
        ?PdoDatabase $notificationDb = null,
    ): ForumsController {
        $reflection = new ReflectionClass(ForumsController::class);
        $module = $reflection->newInstanceWithoutConstructor();

        $reflection->getParentClass()->getProperty('db')->setValue($module, $db);

        $this->setContext($module, new User(id: 7, email: 'author@example.com', role: AccessService::ROLE_USER));

        $tm = new TranslationManager('ru', 'en');
        $this->setProperty($module, 'tm', $tm);
        $this->setProperty($module, 'formatter', new Formatter($tm, 'ru'));

        // UserService/MailService/MessageService are all `final` - built as
        // real instances (the last two via reflection, since nothing here
        // reaches a path that calls them), same reasoning ModuleTest gives.
        $this->setProperty(
            $module,
            'userService',
            new UserService(
                new UserRepository($db),
                $db,
                (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
                $tm,
                new NotificationService(
                    (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
                    new NotificationDeliveryRepository($db),
                    new NotificationPreferenceRepository($db),
                    new UserRepository($db),
                    (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
                    translationManager: new \StreamEngine\Core\TranslationManager('ru', 'en'),
                    config: new \StreamEngine\Core\Config(['SITE_URL' => 'https://example.test']),
                ),
                new UserSessionRepository($db),
                new Config([]),
            )
        );

        // ForumRepository/FeedRepository/UserRepository/FeedFavoriteRepository
        // are `final` too, and are built privately by the real constructor
        // rather than injected - real instances over the same stub
        // PdoDatabase, whose unconfigured fetchAll() returns [] ("this
        // section has no topics yet").
        $this->setProperty($module, 'forumRepository', new ForumRepository($db));
        $this->setProperty($module, 'feedRepository', new FeedRepository($db));
        $this->setProperty($module, 'userRepository', new UserRepository($db));
        $this->setProperty($module, 'favoriteRepository', new FeedFavoriteRepository($db));

        // NotificationService wraps the final MessageService, so the one path that asserts on
        // it (the reply endpoint's follower notifications) passes a real
        // instance built over a FakePdoDatabase it can inspect afterwards;
        // everything else gets a constructor-less shell it never calls.
        $this->setProperty(
            $module,
            'notifications',
            new NotificationService(
                $messageService ?? (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
                new NotificationDeliveryRepository($notificationDb ?? $db),
                new NotificationPreferenceRepository($notificationDb ?? $db),
                new UserRepository($notificationDb ?? $db),
                (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
                translationManager: new \StreamEngine\Core\TranslationManager('ru', 'en'),
                config: new \StreamEngine\Core\Config(['SITE_URL' => 'https://example.test']),
            )
        );

        $this->setProperty($module, 'uploadService', $uploadService ?? $this->createStub(UploadService::class));

        // feedService/pollService are deliberately NOT defaulted here: they're
        // readonly promoted properties, and a second reflection setValue() on
        // an already-initialized readonly property throws - so every test
        // installs its own (the same constraint UsersControllerTest documents
        // for its own feedService). Tests that never reach a code path using
        // one simply leave it uninitialized.

        // Real matched PageTree over the same forums page shape
        // UrlGeneratorTest uses: section {slug} then topic {slug}. Keeping
        // each segment in its own Page::params mirrors Router::resolve() and
        // lets ForumsController resolve a topic through its forum parent.
        $topicListPage = $this->makePage(
            id: 3,
            parentId: 2,
            pattern: '{slug}',
            action: 'forums.topic-list',
            feedType: 'forum'
        );
        $topicListPage->params = ['slug' => 'magiya'];

        $topicViewPage = $this->makePage(
            id: 4,
            parentId: 3,
            pattern: '{slug}',
            action: 'forums.topic-view',
            feedType: 'forum-post'
        );
        $topicViewPage->params = ['slug' => 'svecha-gasnet'];

        $pageTree = new PageTree([
            $this->makePage(id: 1, parentId: null, pattern: '', action: null),
            $this->makePage(id: 2, parentId: 1, pattern: 'forums', action: 'forums.list'),
            $topicListPage,
            $topicViewPage,
            // Not a Forums page at all - Modules\Users owns it - but
            // buildOnlineNow() links member names through it, so the
            // "member without a username gets no link" branch is only
            // meaningfully testable with the page present.
            $this->makePage(id: 6, parentId: 1, pattern: 'users/{username}', action: 'user.show'),
        ]);
        $this->setProperty($module, 'pageTree', $pageTree);

        $this->setProperty($module, 'urlGenerator', new UrlGenerator(
            $pageTree,
            new FakeFeedRepository([
                self::FORUM_ID => $this->makeForumFeed(),
                71 => $this->makeForum(id: 71, slug: 'tarot', parentId: self::FORUM_ID),
                self::TOPIC_ID => $this->makeTopic(),
                901 => $this->makeTopic(title: 'Вторая тема', id: 901, slug: 'vtoraya-tema'),
                902 => $this->makeTopic(title: 'Третья тема', id: 902, slug: 'tretya-tema'),
            ]),
            new ArrayCache(),
        ));

        return $module;
    }

    private function setContext(ForumsController $module, User $user): void
    {
        (new ReflectionClass(ForumsController::class))
            ->getParentClass()
            ->getProperty('context')
            ->setValue(
                $module,
                new RequestContext($user, new \DateTimeZone('UTC'), QueryParams::fromGlobals())
            );
    }

    private function setProperty(ForumsController $module, string $name, mixed $value): void
    {
        (new ReflectionClass(ForumsController::class))->getProperty($name)->setValue($module, $value);
    }

    /**
     * A PdoDatabase stub that answers each query with a canned result, picked
     * by matching a distinctive fragment of its SQL (whitespace-normalized
     * first, so the fragments below can be written as one readable line
     * regardless of how the real query is indented).
     *
     * This exists because the page-rendering paths run through *real*
     * repositories - ForumRepository/FeedRepository/UserRepository/
     * UserSessionRepository are all `final`, and the first two are built by
     * ForumsController's own constructor rather than injected - so the only
     * seam left for a test is the PdoDatabase underneath them. Matching SQL
     * text is admittedly coarse (same trade-off Tests\Support\
     * FakePdoDatabase already documents), but it keeps each test's fixture
     * next to its assertions instead of in a growing general-purpose fake.
     *
     * Two things to know when adding routes:
     * - they're matched in insertion order, which matters when one fragment is
     *   a prefix of another (SQL_ALL_TOPIC_IDS is one of
     *   SQL_TOPIC_IDS_FOR_FORUMS): register the more specific one first;
     * - a value may be a Closure, called with the query's own bound params -
     *   needed when the same query is issued repeatedly with different
     *   arguments and has to answer differently each time (see the
     *   slug-collision test).
     *
     * @param array<string, mixed> $fetchAll
     * @param array<string, mixed> $fetchOne
     */
    private function makeRoutingDb(array $fetchAll = [], array $fetchOne = []): PdoDatabase
    {
        $db = $this->createStub(PdoDatabase::class);

        $db->method('fetchAll')->willReturnCallback(
            fn (string $sql, array $params = []): array => (array) $this->routeQuery($fetchAll, $sql, $params, [])
        );

        $db->method('fetchOne')->willReturnCallback(
            fn (string $sql, array $params = []): ?array => $this->routeQuery($fetchOne, $sql, $params, null)
        );

        return $db;
    }

    /**
     * @param array<string, mixed> $routes
     * @param list<mixed> $params
     */
    private function routeQuery(array $routes, string $sql, array $params, mixed $default): mixed
    {
        $normalized = (string) preg_replace('/\s+/', ' ', $sql);

        foreach ($routes as $fragment => $result) {
            if (str_contains($normalized, $fragment)) {
                return $result instanceof Closure ? $result($params) : $result;
            }
        }

        return $default;
    }

    /**
     * The queries forums.topic-view always issues, in one place: the parent
     * forum row (for the eyebrow), the reply count (which drives the whole
     * pagination), the page's own reply ids and rows, and the per-post author
     * stats. $replyRows doubles as both the id list findTopicPostsPage()
     * returns and the rows findByIds() then resolves them to, so a test only
     * has to describe each reply once.
     *
     * @param list<array<string, mixed>> $replyRows
     * @param list<array<string, mixed>> $userRows
     * @param list<array<string, mixed>> $postCountRows
     * @param list<array<string, mixed>> $participantRows
     */
    private function makeTopicViewDb(
        int $replyCount = 0,
        array $replyRows = [],
        array $userRows = [],
        array $postCountRows = [],
        array $participantRows = [],
    ): PdoDatabase {
        return $this->makeRoutingDb(
            [
                self::SQL_REPLY_IDS => array_map(
                    static fn (array $row): array => ['id' => $row['id']],
                    $replyRows
                ),
                self::SQL_FEEDS_BY_ID => $replyRows,
                self::SQL_USERS_BY_ID => $userRows,
                self::SQL_USER_POST_COUNTS => $postCountRows,
                self::SQL_TOPIC_PARTICIPANTS => $participantRows,
            ],
            [
                self::SQL_FEED_BY_ID => $this->feedRow(self::FORUM_ID, 'forum'),
                self::SQL_REPLY_COUNT => ['total' => $replyCount],
            ]
        );
    }

    /**
     * Renders forums.topic-view around a given poll and returns just that
     * page's poll view model - the five poll tests differ only in the poll
     * itself, the viewer's own votes and whether PollService lets them see
     * results, so everything else stays here.
     *
     * @param int[] $userVotes
     * @return array<string, mixed>
     */
    private function buildPollView(
        Poll $poll,
        array $userVotes,
        bool $canSeeResults,
        ?User $user = null,
    ): array {
        $module = $this->makeModule($this->makeTopicViewDb());

        if ($user !== null) {
            $this->setContext($module, $user);
        }

        $feedService = $this->createStub(FeedService::class);
        $this->stubTopicResolution($feedService, $this->makeTopic());
        $this->setProperty($module, 'feedService', $feedService);

        $pollService = $this->createStub(PollService::class);
        $pollService->method('getPollForFeed')->willReturn($poll);
        $pollService->method('getUserVotes')->willReturn($userVotes);
        $pollService->method('canSeeResults')->willReturn($canSeeResults);
        $this->setProperty($module, 'pollService', $pollService);

        return $module->show($this->makeTopicViewPage(), ['slug' => 'svecha-gasnet'])->data['poll'];
    }

    /**
     * A `feeds` row as FeedRepository's own SELECT hands it back, i.e. what
     * Feed::fromRow() expects - every key it reads has to be present, even
     * the null ones.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function feedRow(int $id, string $type, ?int $parentId = null, int $ownerId = 7, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'parent_id' => $parentId,
            'owner_id' => $ownerId,
            'type' => $type,
            'slug' => 'feed-'.$id,
            'title' => 'Feed '.$id,
            'description' => null,
            'image_url' => null,
            'content' => '<div>Сообщение '.$id.'</div>',
            'container_id' => null,
            'visibility' => 'public',
            'position' => 0,
            'created_at' => time() - 3600,
        ], $extra);
    }

    /**
     * A `users` row in UserRepository::findActiveByIds()' own (full public
     * profile) shape - the one presence goes through.
     *
     * @return array<string, mixed>
     */
    private function activeUserRow(int $id, string $nick, string $username): array
    {
        return [
            'id' => $id,
            'email' => 'user'.$id.'@example.com',
            'role' => 'user',
            'nick' => $nick,
            'username' => $username,
            'bio' => '',
            'signature' => '',
            'homepage' => '',
            'gender' => '',
            'birth_date' => null,
            'avatar_url' => '',
            'created_at' => 1_700_000_000,
        ];
    }

    /**
     * A PdoDatabase whose fetchOne() yields the parent forum's row -
     * showTopicEditPage() resolves the section through the real
     * FeedRepository::findById(), and treats a missing one as fatal.
     */
    private function makeDbReturningForumRow(): PdoDatabase
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn([
            'id' => self::FORUM_ID,
            'parent_id' => null,
            'owner_id' => 7,
            'type' => 'forum',
            'slug' => 'magiya',
            'title' => 'Магия',
            'container_id' => null,
        ]);
        $db->method('fetchAll')->willReturn([]);

        return $db;
    }

    private function makeTopic(
        string $title = 'Свеча гаснет',
        int $id = self::TOPIC_ID,
        string $slug = 'svecha-gasnet',
        int $ownerId = 7,
        ?string $content = null,
        int $views = 0,
    ): Feed {
        return new Feed(
            id: $id,
            parentId: self::FORUM_ID,
            ownerId: $ownerId,
            type: 'forum-post',
            slug: $slug,
            title: $title,
            description: null,
            imageUrl: null,
            content: $content ?? '<div>Текст первого сообщения</div>',
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: time() - 3600,
            relevance: null,
            canonicalUrl: '/forums/magiya/'.$slug.'/',
            views: $views,
        );
    }

    /**
     * A reply, i.e. the plain 'comment' feed FeedService::createComment()
     * returns - what the reply endpoint echoes back to the quick-reply form.
     */
    private function makeReply(int $id): Feed
    {
        return new Feed(
            id: $id,
            parentId: self::TOPIC_ID,
            ownerId: 7,
            type: 'comment',
            slug: null,
            title: null,
            description: null,
            imageUrl: null,
            content: '<div>Ответ по теме</div>',
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: time(),
            relevance: null,
            canonicalUrl: null,
        );
    }

    private function makeUpload(int $id): Upload
    {
        return new Upload(
            id: $id,
            userId: 7,
            path: '2026/08/file-'.$id.'.png',
            mime: 'image/png',
            size: 2048,
            originalName: 'file-'.$id.'.png',
            createdAt: time(),
        );
    }

    private function makeForum(int $id, string $slug, int $position = 0, ?int $parentId = null): Feed
    {
        return new Feed(
            id: $id,
            parentId: $parentId,
            ownerId: 7,
            type: 'forum',
            slug: $slug,
            title: 'Раздел '.$id,
            description: null,
            imageUrl: null,
            content: null,
            containerId: null,
            visibility: 'public',
            position: $position,
            createdAt: time() - 86400,
            relevance: null,
            canonicalUrl: '/forums/'.$slug.'/',
        );
    }

    private function makeForumFeed(): Feed
    {
        return new Feed(
            id: self::FORUM_ID,
            parentId: null,
            ownerId: 7,
            type: 'forum',
            slug: 'magiya',
            title: 'Магия',
            description: null,
            imageUrl: null,
            content: null,
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: time() - 86400,
            relevance: null,
            canonicalUrl: '/forums/magiya/',
        );
    }

    /**
     * @param int[] $optionVotes one entry per option, its own vote count
     */
    private function makePoll(
        int $votersCount,
        ?int $createdAt = null,
        ?int $closesAt = null,
        int $maxChoices = 1,
        string $resultsVisibility = Poll::VISIBILITY_ALWAYS,
        array $optionVotes = [0, 0],
    ): Poll {
        $createdAt ??= time() - 3600;

        $texts = ['Первый', 'Второй', 'Третий', 'Четвёртый'];

        $options = [];
        foreach (array_values($optionVotes) as $index => $votes) {
            $options[] = new PollOption(
                id: $index + 1,
                pollId: 555,
                text: $texts[$index] ?? ('Вариант '.($index + 1)),
                position: $index,
                votesCount: $votes,
            );
        }

        return new Poll(
            id: 555,
            feedId: self::TOPIC_ID,
            question: 'Какой ритуал?',
            maxChoices: $maxChoices,
            allowRevote: false,
            resultsVisibility: $resultsVisibility,
            closesAt: $closesAt,
            votersCount: $votersCount,
            createdAt: $createdAt,
            updatedAt: $createdAt,
            options: $options,
        );
    }

    private function makeForumsListPage(): Page
    {
        return $this->makePage(
            id: 2,
            parentId: 1,
            pattern: 'forums',
            action: 'forums.list',
            pageName: 'Форумы',
        );
    }

    private function makeTopicListPage(): Page
    {
        return $this->makePage(
            id: 3,
            parentId: 2,
            pattern: '{slug}',
            action: 'forums.topic-list',
            feedType: 'forum',
        );
    }

    private function makeTopicViewPage(): Page
    {
        return $this->makePage(
            id: 4,
            parentId: 3,
            pattern: '{slug}',
            action: 'forums.topic-view',
            feedType: 'forum-post',
        );
    }

    private function makeTopicNewPage(): Page
    {
        return $this->makePage(
            id: 7,
            parentId: 3,
            pattern: 'new',
            action: 'forums.topic-new',
            accessRule: AccessService::ACCESS_AUTHENTICATED,
        );
    }

    private function makeTopicEditPage(): Page
    {
        return $this->makePage(
            id: 5,
            parentId: 4,
            pattern: 'edit',
            action: 'forums.topic-edit',
            accessRule: AccessService::ACCESS_AUTHENTICATED,
        );
    }

    private function makeTopicItemApiPage(): Page
    {
        return $this->makePage(
            id: 300,
            parentId: 299,
            pattern: '{id}',
            action: 'forums.topic-item',
            requestMethods: ['PATCH'],
            responseType: 'json',
        );
    }

    private function makePage(
        int $id,
        ?int $parentId,
        string $pattern,
        ?string $action,
        ?string $feedType = null,
        string $accessRule = AccessService::ACCESS_PUBLIC,
        array $requestMethods = ['GET'],
        string $responseType = 'html',
        ?string $pageName = null,
    ): Page {
        return new Page(
            id: $id,
            parentId: $parentId,
            pattern: $pattern,
            pageName: $pageName,
            settings: null,
            feedType: $feedType,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: $requestMethods,
            responseType: $responseType,
            accessRule: $accessRule,
            action: $action,
        );
    }
}

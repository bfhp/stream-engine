<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Forums;

use DateTimeImmutable;
use RuntimeException;
use StreamEngine\Core\AbstractController;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\Security;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Notification;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\Poll;
use StreamEngine\Domain\PollOption;
use StreamEngine\Domain\Upload;
use StreamEngine\Domain\User;
use StreamEngine\Repository\FeedFavoriteRepository;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\PollService;
use StreamEngine\Service\UploadService;
use StreamEngine\Service\UserService;
use StreamEngine\View\Breadcrumb;
use StreamEngine\View\ViewModel;
use Throwable;

class ForumsController extends AbstractController
{
    public static function feedTypes(): array
    {
        return [
            'forum' => 'Форум',
            'forum-post' => 'Тема форума',
        ];
    }

    // forums.topic-list's page size. No page->settings-driven override yet
    // (see Page->settings docblock in Domain\Page) - not worth the extra
    // indirection until a second caller needs a different number.
    private const int TOPICS_PER_PAGE = 20;

    // forums.topic-view's page size, in "posts" units - the topic's own
    // opening post counts as post #1 of page 1 (classic forum numbering),
    // so page 1 holds this many posts total (opening post + first
    // POSTS_PER_PAGE - 1 replies) and every later page holds this many
    // replies. See buildPostRows()'s own docblock.
    private const int POSTS_PER_PAGE = 20;

    // How many distinct posters get their own avatar bubble in "Members
    // topic" before the rest collapse into a single "+N" overflow badge.
    private const int TOPIC_PARTICIPANT_AVATARS = 5;

    // How many names the forum stats card's "Online now" line spells
    // out before the rest collapse into "and N more". The count next to it
    // stays exact regardless - see buildOnlineNow() and
    // UserService::onlineMembers(), which return the total separately
    // from the capped list for exactly that reason.
    private const int ONLINE_NAMES_LIMIT = 30;

    // forums.topic-new's own "Attachments" card cap ("no more than 5 files" -
    // see forums.topic-form.twig) - enforced again server-side in
    // resolveTopicAttachments() so a client bypassing its own limit can't
    // store more than the form claims to allow.
    private const int MAX_TOPIC_ATTACHMENTS = 5;

    // Mirrors of Service\PollService's own private MAX_QUESTION_LENGTH /
    // MAX_OPTIONS / MAX_OPTION_TEXT_LENGTH. Duplicated rather than exposed from
    // there because these serve a different purpose: PollService throws on
    // them, whereas normalizePollInput() has to *predict* that throw so
    // replaceTopicPoll() can decline to delete an existing poll it wouldn't be
    // able to replace (see that method's own docblock). Keep in sync if
    // PollService's change - a mirror that's too generous only costs a
    // swallowed createPoll() failure on a payload no real client sends.
    private const int MAX_POLL_QUESTION_LENGTH = 255;

    private const int MAX_POLL_OPTIONS = 20;

    private const int MAX_POLL_OPTION_LENGTH = 255;

    /**
     * The "Poll duration" select's own options (0 = "No limit",
     * see forums.topic-form.twig) - the only durations a poll created through
     * this form can have, which is what lets buildPollFormState() recover the
     * author's original choice from a stored closes_at.
     *
     * @var int[]
     */
    private const array POLL_DURATION_DAYS = [7, 14, 30];

    /**
     * Slugs a forum topic is never allowed to land on - 'new' is
     * forums.topic-new's own pattern, mounted (see registerApi()'s own
     * 'topics' sibling and showTopicNewPage()'s docblock) as a static
     * child of forums.topic-list's {slug} page, exactly the same shape as
     * a topic's own forums.topic-view page (also a dynamic child of that
     * same {slug} page). Router::resolve()
     * matches static routes before dynamic ones (see
     * Modules\Users\BlogPostService::RESERVED_BLOG_POST_SLUGS's identical
     * reasoning), so a topic whose slug happened to be "new" would
     * silently become unreachable at its own URL - the request would
     * resolve to this "create a topic" page instead.
     *
     * forums.topic-edit's own 'edit' pattern deliberately isn't in here:
     * unlike 'new' it's mounted one level deeper, as a static child of
     * forums.topic-view's {slug} page rather than a sibling of it (see
     * showTopicEditPage()'s own docblock), so it's only ever reached *under*
     * an already-resolved topic and no topic slug can shadow or be shadowed
     * by it.
     *
     * @var string[]
     */
    private const array RESERVED_FORUM_TOPIC_SLUGS = ['new'];

    private readonly ForumRepository $forumRepository;

    private readonly FeedRepository $feedRepository;

    private readonly UserRepository $userRepository;

    private readonly FeedFavoriteRepository $favoriteRepository;

    public static function pageActions(): array
    {
        return [
            'forums.list' => 'List forums',
            'forums.topic-list' => [
                'label' => 'List topics of a forum section',
                'fields' => [
                    'feedType' => ['status' => 'required', 'values' => ['forum']],
                ],
            ],
            'forums.topic-view' => [
                'label' => 'View a forum topic and its replies',
                'fields' => [
                    'feedType' => ['status' => 'required', 'values' => ['forum-post']],
                ],
            ],
            'forums.topic-new' => 'Create a new topic in a forum section',
            'forums.topic-edit' => 'Edit an existing forum topic',
        ];
    }

    public function __construct(
        PdoDatabase                         $db,
        RequestContext                      $context,
        private readonly FeedService        $feedService,
        private readonly UserService        $userService,
        private readonly UrlGenerator       $urlGenerator,
        private readonly Formatter          $formatter,
        private readonly TranslationManager $tm,
        // Shared notification orchestrator. The module describes what
        // happened; the service owns the choice of delivery transport.
        private readonly NotificationService $notifications,
        // Generic feed-attached polls (Service\PollService, passed to
        // ControllerFactory the same unconditional way other shared platform
        // services are) - forums.topic-new is
        // this service's first real caller (see attachTopicPoll()'s own
        // docblock and migrations/20260912000000_initial.sql's).
        private readonly PollService        $pollService,
        // Generic upload storage (Service\UploadService, same Core service
        // Modules\Users\UsersController already injects for cover images/
        // tracks) - forums.topic-new's own "Attachments" card resolves and
        // stores its file ids through this (see resolveTopicAttachments()),
        // and forums.topic-view reads them back through it too (see
        // buildAttachmentRows()).
        private readonly UploadService      $uploadService,
        private readonly PageTree           $pageTree,
    ) {
        parent::__construct($db, $context);

        // Module-owned, stateless wrappers over PdoDatabase - built here
        // directly (not wired through ControllerFactory), same as
        // Modules\Users\BlogPostService/FriendService. FeedRepository/
        // UserRepository/FeedFavoriteRepository are Core classes (allowed
        // dependencies); building fresh instances here instead of sharing
        // StreamEngine's own is the same call BlogPostService already makes.
        $this->forumRepository = new ForumRepository($db);
        $this->feedRepository = new FeedRepository($db);
        $this->userRepository = new UserRepository($db);
        $this->favoriteRepository = new FeedFavoriteRepository($db);
    }

    /**
     * @throws ForbiddenException
     */
    public function getBreadcrumb(Page $page): ?Breadcrumb
    {
        if ($page->action === 'forums.topic-list' && key_exists('slug', $page->params)) {
            // Same idiom as ArticleController::getBreadcrumb() - swap the
            // generic pattern-based crumb for the actual forum's own
            // title/slug. Doesn't walk the forum's own parent chain (a
            // subforum's crumb won't show its ancestor forum) - forums.list
            // is a single flat listing today, same simplification Article
            // makes for its own list pages.
            $forumFeed = $this->feedService->getFeedByTypeAndSlug(
                'forum',
                $page->params['slug'],
                $this->context->user
            );

            if (!$forumFeed) {
                throw new ForbiddenException('Forum not found');
            }

            return new Breadcrumb($forumFeed->title, $forumFeed->slug);
        }

        if ($page->action === 'forums.topic-view' && key_exists('slug', $page->params)) {
            // Same idiom as the forums.topic-list branch above, one level
            // deeper: swap the generic crumb for the topic's own title.
            $topicFeed = $this->resolveTopicForPage($page, $page->params['slug']);

            if (!$topicFeed) {
                throw new ForbiddenException('Topic not found');
            }

            return new Breadcrumb($topicFeed->title, $topicFeed->slug);
        }

        return parent::getBreadcrumb($page);
    }

    /**
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    public function show(Page $page, array $args = []): ?ViewModel
    {
        return match ($page->action) {
            'forums.list' => $this->showForumsListPage($page),
            'forums.topic-list' => $this->showTopicListPage($page, (string) $args['slug']),
            'forums.topic-view' => $this->showTopicViewPage($page, (string) $args['slug']),
            'forums.topic-new' => $this->showTopicNewPage($page, (string) $args['slug']),
            'forums.topic-edit' => $this->showTopicEditPage($page, (string) $args['slug']),
            default => throw new RuntimeException('Unknown action'),
        };
    }
    public function showForumsListPage(Page $page): ?ViewModel
    {
        $forumsList = [];
        $subForumsList = [];

        $forumFeeds = $this->feedService->getFeedsByType('forum', $this->context->user, 99999);

        foreach ($forumFeeds as $feed) {
            if ($feed->parentId) {
                $subForumsList[$feed->parentId][] = $feed;
            } else {
                $forumsList[$feed->id] = $feed;
            }
        }

        $byPosition = static fn (Feed $a, Feed $b): int => ($a->position ?? 0) <=> ($b->position ?? 0);

        uasort($forumsList, $byPosition);

        foreach ($forumsList as $id => $forum) {
            $children = $subForumsList[$id] ?? [];
            usort($children, $byPosition);
            $forum->children = $children;
        }

        $subForumIds = array_map(
            static fn (Feed $forum): int => $forum->id,
            array_merge([], ...array_values($subForumsList))
        );
        $counts = $this->forumRepository->countTopicsAndPosts($subForumIds);

        // Kept as a separate id => counts map rather than stamped onto the
        // Feed objects themselves - topics/posts counts are a forums.list
        // presentation concern, not something a shared Domain\Feed should
        // carry (see docs/MODULE_CONTRACT.md).
        $forumStats = [];
        foreach ($subForumIds as $id) {
            $forumStats[$id] = [
                'topics' => $counts[$id]['topics'] ?? 0,
                'posts' => $counts[$id]['posts'] ?? 0,
            ];
        }

        $forumLastPost = $this->buildLastPostPreviews($subForumIds);
        $forumTotals = $this->buildForumTotals($forumStats);
        $forumUnreadCounts = $this->buildUnreadCounts($subForumIds);

        // Top-level forum groups have no topics of their own (only their
        // subforums do - see $subForumIds above), so their card-header badge
        // is just the sum of their children's own counts, not a separate
        // query.
        $forumGroupUnreadCounts = [];
        foreach ($forumsList as $id => $forum) {
            $sum = 0;
            foreach ($forum->children as $child) {
                $sum += $forumUnreadCounts[$child->id] ?? 0;
            }
            $forumGroupUnreadCounts[$id] = $sum;
        }

        return ViewModel::fromPage(
            $page,
            'modules/forums/forums.list.twig',
            [
                'description' => $page->pageName,
                'canonical' => $this->urlGenerator->action('forums.list'),
                'head_ext' => [
                    '<script type="module" src="/assets/js/forums.js" defer></script>',
                ],
                'forums' => $forumsList,
                'forumStats' => $forumStats,
                'forumLastPost' => $forumLastPost,
                'forumTotals' => $forumTotals,
                'onlineNow' => $this->buildOnlineNow(),
                'forumUnreadCounts' => $forumUnreadCounts,
                'forumGroupUnreadCounts' => $forumGroupUnreadCounts,
            ]
        );
    }

    /**
     * forums.list's "last post" preview per forum: whoever posted most
     * recently (a new topic or a reply to one), what topic it was on, when,
     * and whether the current user has already seen it.
     *
     * "unread" is approximated from the one topic each forum's preview is
     * already built from: has the viewer read *that* topic since its last
     * activity (its own creation, or its newest reply)? It's not "every
     * topic in the forum has been read" (that would mean batch-checking
     * read state for every topic, not just the latest one) - but for a
     * "does this forum have something new" icon, "is the newest thing here
     * read" is the same signal every reader actually cares about, and it's
     * free: FeedService::getFeedReadAtMap() is the same generic per-feed
     * read tracking already used for feed comments, just batched over
     * the topic ids buildLastPostPreviews() already fetched.
     *
     * @param int[] $forumIds
     * @return array<int, array{topicTitle: string, topicUrl: ?string, authorName: string, authorAvatarUrl: string, when: string, whenTitle: string, unread: bool}>
     */
    private function buildLastPostPreviews(array $forumIds): array
    {
        $lastActivity = $this->forumRepository->findLastActivity($forumIds);

        if ($lastActivity === []) {
            return [];
        }

        $topicIds = array_map(
            static fn (array $activity): int => $activity['topicId'],
            $lastActivity
        );
        $topics = $this->feedRepository->findByIds($topicIds, $this->context->user, 'forum-post');

        $topicsById = [];
        foreach ($topics as $topic) {
            $topicsById[$topic->id] = $topic;
        }

        $topicUrls = $this->urlGenerator->feeds($topics);
        $readAtByTopicId = $this->feedService->getFeedReadAtMap($topicIds, $this->context->user);

        $ownerIds = array_map(
            static fn (array $activity): int => $activity['ownerId'],
            $lastActivity
        );
        $owners = $this->userRepository->findByIds($ownerIds);

        $forumLastPost = [];

        foreach ($lastActivity as $forumId => $activity) {
            $topic = $topicsById[$activity['topicId']] ?? null;

            if ($topic === null) {
                // Topic vanished or fell out of ACL between the two
                // queries - skip rather than show a broken preview.
                continue;
            }

            $createdAt = new DateTimeImmutable('@'.$activity['createdAt']);
            $owner = $owners[$activity['ownerId']] ?? null;
            $readAt = $readAtByTopicId[$activity['topicId']] ?? null;

            $forumLastPost[$forumId] = [
                'topicTitle' => (string) $topic->title,
                'topicUrl' => $topicUrls[$topic->id] ?? null,
                'authorName' => $owner['displayName'] ?? ('#'.$activity['ownerId']),
                'authorAvatarUrl' => $this->userService->resolveAvatarUrl($owner['avatarUrl'] ?? ''),
                'when' => $this->formatter->relative($createdAt),
                'whenTitle' => $this->formatter->datetime($createdAt),
                'unread' => $readAt === null || $readAt < $activity['createdAt'],
            ];
        }

        return $forumLastPost;
    }

    /**
     * Real per-forum/subforum unread topic counts - supersedes the old
     * "is just the newest topic unread" approximation buildLastPostPreviews()
     * builds for its own icon-only purpose. Counts every topic in each
     * forum whose last activity (own creation, or newest reply, whichever
     * is newer - ForumRepository::findTopicActivity()'s own rule) is newer
     * than the viewer's read watermark for that topic.
     *
     * Deliberately reuses FeedService::getFeedReadAtMap() rather than
     * joining feed_reads directly in SQL - that's the same call
     * buildLastPostPreviews()/buildTopicRows() already make, and it's the
     * only way to see a guest's read state at all (guests are tracked via
     * their GuestFeedReadStore cookie, not a feed_reads row, so a raw SQL
     * join would silently show every topic as unread for them).
     *
     * @param int[] $forumIds
     * @return array<int, int> forum id => unread topic count; every
     *     requested forum id is present (0 if none of its topics are
     *     unread), so templates can index it directly without |default(0)
     */
    private function buildUnreadCounts(array $forumIds): array
    {
        $counts = array_fill_keys($forumIds, 0);

        $activity = $this->forumRepository->findTopicActivity($forumIds);

        if ($activity === []) {
            return $counts;
        }

        $topicIds = array_map(static fn (array $row): int => $row['topicId'], $activity);
        $readAtByTopicId = $this->feedService->getFeedReadAtMap($topicIds, $this->context->user);

        foreach ($activity as $row) {
            $readAt = $readAtByTopicId[$row['topicId']] ?? null;

            if ($readAt === null || $readAt < $row['lastActivityAt']) {
                $counts[$row['forumId']] = ($counts[$row['forumId']] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * forums.list's "Forum statistics" card. Topics/posts are just a sum
     * of $forumStats (already computed for every subforum on the page, see
     * ForumRepository::countTopicsAndPosts()'s own docblock for what
     * each counts) and total members is UserRepository::countPublicUsers()
     * - both already-generic Core lookups, nothing new needed for those
     * two. "Posts in the last 24h" is the one genuinely new (tiny) query,
     * kept in ForumRepository alongside the rest of this page's
     * Forums-only counting.
     *
     * Deliberately excludes "who's online": that one isn't a forum fact at
     * all (the same number would hold on any page that asked), so it comes
     * from Core's own UserService (see buildOnlineNow() below) instead of
     * being computed here alongside the forum's own counts.
     *
     * @param array<int, array{topics: int, posts: int}> $forumStats
     * @return array{topics: int, posts: int, members: int, postsToday: int}
     */
    private function buildForumTotals(array $forumStats): array
    {
        $topics = 0;
        $posts = 0;

        foreach ($forumStats as $stats) {
            $topics += $stats['topics'];
            $posts += $stats['posts'];
        }

        return [
            'topics' => $topics,
            'posts' => $posts,
            'members' => $this->userRepository->countPublicUsers(),
            'postsToday' => $this->forumRepository->countPostsSince(time() - 86400),
        ];
    }

    /**
     * forums.list's "Online now" line: how many members are online
     * and (up to ONLINE_NAMES_LIMIT of) who they are, most recently seen
     * first.
     *
     * All this does is decorate UserService::onlineMembers()' answer for
     * the view - no presence logic of its own, deliberately: what counts as
     * online, and who may be shown at all, is that Core service's call to
     * make, not this module's. "Online
     * now" isn't a forum fact either - the same number would hold on any
     * other page that asked - which is why it isn't computed here next to
     * the topic/post counts that genuinely are forum-specific. Deleting the
     * Forums folder takes this rendering with it and leaves presence
     * itself untouched, which is the point.
     *
     * Note the honest wording this feeds: "on the forum" would be a lie -
     * presence is site-wide, so activity on other site pages counts too.
     * The template says "on the site" for that reason.
     *
     * Members whose profile can't be linked (no username set) still appear,
     * just as plain text - same graceful degradation as the community
     * member cards in Modules\Users.
     *
     * Guests are a count and nothing else - an anonymous visitor has no name
     * to show - and crawlers are listed by name (there are only ever a
     * handful). All three stay separate figures rather than one sum: "12
     * online" that turns out to be one member and eleven scrapers is the
     * kind of number that reads as flattery.
     *
     * `totalLabel`/`guestLabel` are the pluralized nouns for their counts,
     * built here rather than in the template because Twig has no plural
     * filter in this project - Russian pluralization lives in
     * Core\Formatter::plural(), same as the poll results' own vote counts
     * a few hundred lines up.
     *
     * @return array{
     *     total: int,
     *     totalLabel: string,
     *     hidden: int,
     *     members: list<array{displayName: string, url: ?string}>,
     *     guests: int,
     *     guestLabel: string,
     *     bots: list<string>
     * }
     */
    private function buildOnlineNow(): array
    {
        $presence = $this->userService->onlineMembers(self::ONLINE_NAMES_LIMIT);
        $guests = $this->userService->onlineGuestCount();

        $members = array_map(
            function (User $user): array {
                return [
                    'displayName' => $user->getDisplayName(),
                    // action() already returns null when the `user.show`
                    // page doesn't exist, so the only case left to guard
                    // here is a member without a username to put in it.
                    'url' => $user->username !== ''
                        ? $this->urlGenerator->action('user.show', ['username' => $user->username])
                        : null,
                ];
            },
            $presence['users']
        );

        $participantForms = $this->tm->getAll()['forums.participant'];
        $guestForms = $this->tm->getAll()['forums.guest'];

        return [
            'total' => $presence['total'],
            'totalLabel' => $this->formatter->plural(
                $presence['total'],
                ...$participantForms
            ),
            'hidden' => $presence['hidden'],
            'members' => $members,
            'guests' => $guests,
            'guestLabel' => $this->formatter->plural($guests, ...$guestForms),
            'bots' => $this->userService->onlineBotNames(),
        ];
    }

    /**
     * forums.topic-list: one forum section - its own subforums (if any) and
     * its paginated topic list, ordered by last activity.
     *
     * MVP scope only (see conversation with the requester): no pinned/
     * closed topics and no view counts - the `feeds` table has no columns
     * for any of those today, and adding them is a schema decision left for
     * a follow-up rather than smuggled into this page. Pagination is
     * query-string based (?page=N), matching every other paginated
     * controller in the codebase (Users/API modules) rather than inventing
     * a new /slug/N/ path-segment route, which would need Router/PageTree
     * changes of its own.
     *
     * @throws ForbiddenException
     */
    public function showTopicListPage(Page $page, string $slug): ?ViewModel
    {
        $forumFeed = $this->feedService->getFeedByTypeAndSlug('forum', $slug, $this->context->user);

        if (!$forumFeed || $forumFeed->type !== 'forum') {
            throw new ForbiddenException('Forum not found');
        }

        // Eyebrow above the title ("Section · <parent forum>") for a
        // subforum. Plain FeedRepository::findById() (not FeedService's
        // getFeedById(), which throws on a miss) since a parent forum not
        // resolving shouldn't break the page - it just means no eyebrow.
        // canonicalUrl comes straight from UrlGenerator::feed(), same call
        // FeedService's own decorateFeedsWithUrls() makes internally -
        // no hand-built path.
        $parentForum = null;
        if ($forumFeed->parentId) {
            $parentForum = $this->feedRepository->findById($forumFeed->parentId, $this->context->user);
            if ($parentForum) {
                $parentForum->canonicalUrl = $this->urlGenerator->feed($parentForum);
            }
        }

        $subForums = $this->feedService->getFeedsByParentAndType(
            $forumFeed->id,
            'forum',
            $this->context->user
        );

        $subForumIds = array_map(static fn (Feed $forum): int => $forum->id, $subForums);
        $subForumCounts = $this->forumRepository->countTopicsAndPosts($subForumIds);

        $subForumStats = [];
        foreach ($subForumIds as $id) {
            $subForumStats[$id] = [
                'topics' => $subForumCounts[$id]['topics'] ?? 0,
                'posts' => $subForumCounts[$id]['posts'] ?? 0,
            ];
        }

        $subForumLastPost = $this->buildLastPostPreviews($subForumIds);
        $subForumUnreadCounts = $this->buildUnreadCounts($subForumIds);

        // A "base"/category section - one that has subforums of its own -
        // holds no topics directly (see forums.list.twig's own top-level-vs-
        // subforum split: only subforums ever get topics posted to them), so
        // there's nothing for findTopicsPage()/buildTopicRows() to fetch and
        // no "New topic" action to offer here - both belong to its
        // subforums instead. Its own "Section statistics" card rolls up
        // every subforum's numbers (via the multi-forum overloads
        // countParticipants()/countPostsSince() already support, and a plain
        // array_sum() of $subForumStats already fetched above) rather than
        // showing its own always-empty direct counts.
        if ($subForums !== []) {
            $topics = [];
            $currentPage = $this->urlGenerator->pageNumber($this->context->query);
            $totalPages = 1;

            $forumTotals = [
                'topics' => array_sum(array_column($subForumStats, 'topics')),
                'posts' => array_sum(array_column($subForumStats, 'posts')),
            ];
            $forumTotals['participants'] = $this->forumRepository->countParticipants($subForumIds);
            $forumTotals['postsToday'] = $this->forumRepository->countPostsSince(time() - 86400, $subForumIds);
        } else {
            $currentPage = $this->urlGenerator->pageNumber($this->context->query);
            if ($currentPage > intdiv(PHP_INT_MAX, self::TOPICS_PER_PAGE)) {
                throw new NotFoundException('Page not found');
            }
            $offset = ($currentPage - 1) * self::TOPICS_PER_PAGE;

            $topicsPage = $this->forumRepository->findTopicsPage($forumFeed->id, self::TOPICS_PER_PAGE, $offset);
            $topics = $this->buildTopicRows($topicsPage['topicIds'], $topicsPage['stats']);

            $totalPages = $topicsPage['total'] > 0
                ? (int) ceil($topicsPage['total'] / self::TOPICS_PER_PAGE)
                : 1;

            // Reuses countTopicsAndPosts() (already built for the subforums
            // above) for this forum's own "N topics, M posts" header line -
            // no new query needed, same method just called with a single id.
            // 'participants'/'postsToday' are added on top for the
            // "Section statistics" card - the per-forum siblings of
            // forums.list's own site-wide stats card.
            $forumTotals = $this->forumRepository->countTopicsAndPosts([$forumFeed->id])[$forumFeed->id]
                ?? ['topics' => 0, 'posts' => 0];
            $forumTotals['participants'] = $this->forumRepository->countParticipants($forumFeed->id);
            $forumTotals['postsToday'] = $this->forumRepository->countPostsSince(time() - 86400, $forumFeed->id);
        }

        if ($currentPage > $totalPages) {
            throw new NotFoundException('Page not found');
        }

        return ViewModel::fromPage(
            $page,
            'modules/forums/forums.topic-list.twig',
            [
                'title' => $forumFeed->title,
                'description' => $forumFeed->description,
                'canonical' => $this->urlGenerator->listing($forumFeed->canonicalUrl, $currentPage),
                'head_ext' => [
                    '<script type="module" src="/assets/js/forums.js" defer></script>',
                ],
                'feed' => $forumFeed,
                'parentForum' => $parentForum,
                'forumsListUrl' => $this->urlGenerator->action('forums.list'),
                'subForums' => $subForums,
                'subForumStats' => $subForumStats,
                'subForumLastPost' => $subForumLastPost,
                'subForumUnreadCounts' => $subForumUnreadCounts,
                'topics' => $topics,
                'forumTotals' => $forumTotals,
                'pagination' => [
                    'current' => min($currentPage, $totalPages),
                    'total' => $totalPages,
                    'baseUrl' => $forumFeed->canonicalUrl,
                ],
            ]
        );
    }

    /**
     * forums.topic-new: the "create a topic in this section" form. Mounted
     * (see registerApi()'s own 'topics' sibling route and
     * RESERVED_FORUM_TOPIC_SLUGS's own docblock) as a static 'new' child of
     * forums.topic-list's own {slug} page, so - exactly like
     * Modules\Users\UsersController::resolveCommunityFeed() reads
     * community.post-new's {slug} from its own parent page's placeholder -
     * $slug here is inherited from forums.topic-list's page, not from any
     * placeholder of forums.topic-new's own (its pattern is the literal
     * 'new').
     *
     * Guests never reach this page for real: forums.topic-list.twig's own
     * "New topic" button opens the login modal
     * for a guest instead of linking here, and this page's own
     * access_rule keeps AccessService::canAccessPage() from calling
     * show() for one who navigates here directly anyway.
     *
     * @throws ForbiddenException
     */
    public function showTopicNewPage(Page $page, string $slug): ?ViewModel
    {
        $forumFeed = $this->feedService->getFeedByTypeAndSlug('forum', $slug, $this->context->user);

        if (!$forumFeed || $forumFeed->type !== 'forum') {
            throw new ForbiddenException('Forum not found');
        }

        // Same call as UsersController::showBlogPostFormPage()'s own
        // canonical - the page itself has no placeholder ('new' is
        // literal), but urlGenerator->page() fills the *ancestor*
        // forums.topic-list page's {slug} from $params here regardless
        // (see UrlGenerator::page()'s own loop over buildPageAncestors()),
        // producing '/{section slug}/new/'.
        $canonical = $this->urlGenerator->page($page, ['slug' => $slug]);

        return $this->buildTopicFormViewModel(
            page: $page,
            forumFeed: $forumFeed,
            topic: null,
            canonical: $canonical,
            cancelUrl: $forumFeed->canonicalUrl,
        );
    }

    /**
     * forums.topic-edit: the same form as forums.topic-new, pointed at an
     * existing topic. Mounted as a static 'edit' child of
     * forums.topic-view's own {slug} page - the identical shape
     * Modules\Users\UsersController's user.post-edit/community.post-edit use
     * for a blog post (URL '/{section}/{topic}/edit/'), and the reason
     * RESERVED_FORUM_TOPIC_SLUGS needs no 'edit' entry alongside its 'new'
     * (see that constant's own docblock): 'new' is a *sibling* of every
     * topic's own page and so competes with topic slugs, whereas 'edit'
     * lives one level deeper, under a single already-resolved topic, where
     * nothing dynamic can collide with it. $slug is therefore the topic's
     * own, inherited from that ancestor page's placeholder exactly the way
     * showTopicNewPage() inherits its forum's.
     *
     * Forums pages are pattern-driven rather than pinned by feed_id, so the
     * matched forums.topic-list ancestor supplies the forum slug and the
     * matched forums.topic-view ancestor supplies this topic slug.
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    public function showTopicEditPage(Page $page, string $slug): ?ViewModel
    {
        $topic = $this->resolveTopicForPage($page, $slug);

        if (! $topic || $topic->type !== 'forum-post') {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }

        $this->assertTopicEditable($topic, $this->context->user);

        // Plain FeedRepository::findById() rather than FeedService's throwing
        // getFeedById(), same as showTopicViewPage()'s own parent lookup - but
        // unlike that page, a missing/wrong-typed parent is fatal here: the
        // form's own "N topics in this section" card, its cancel link and the eyebrow
        // above the title all describe the section, and there's no sensible
        // way to render an editor for a topic that isn't in a forum.
        $forumFeed = $topic->parentId !== null
            ? $this->feedRepository->findById($topic->parentId, $this->context->user)
            : null;

        if (! $forumFeed || $forumFeed->type !== 'forum') {
            throw new NotFoundException($this->tm->trans('feed.parent_not_found'));
        }

        $forumFeed->canonicalUrl = $this->urlGenerator->feed($forumFeed);

        return $this->buildTopicFormViewModel(
            page: $page,
            forumFeed: $forumFeed,
            topic: $topic,
            canonical: $this->buildTopicEditUrl($topic),
            // Cancel goes back to the topic itself, not the section - the
            // reader came *from* the topic to edit it (see
            // forums.topic-view.twig's own "Edit" button), same
            // reasoning as buildPostEditViewModel()'s own cancelUrl in
            // Modules\Users.
            cancelUrl: $topic->canonicalUrl,
        );
    }

    /**
     * The one forums.topic-form.twig view model, shared by
     * showTopicNewPage() and showTopicEditPage() - $topic null means "create"
     * and everything edit-specific below collapses to its empty default, so
     * the template branches on a single `topic` variable rather than on a
     * pile of separate mode flags.
     *
     * The three prefilled blocks are the whole reason a shared builder earns
     * its keep:
     * - `topic.content` is fed straight back into the hidden #topicBody input
     *   the <trix-editor> initializes from, i.e. the already-purified stored
     *   HTML - *not* contentToEditableText()'s reverse transform, which
     *   exists for the plain-text quick-reply textarea and would actively
     *   corrupt rich text (it decodes entities and unwraps <br>).
     * - `attachments` are buildAttachmentRows()'s same rows the topic itself
     *   renders, plus each row's own upload id so the form can send the
     *   unchanged ones straight back as `attachments` (see
     *   handleTopicUpdateRequest()'s own resolveTopicAttachments() call - a
     *   PATCH that omits a previously-attached id detaches it, so the client
     *   has to know the current set to preserve it).
     * - `poll` mirrors forums.topic-form.twig's own poll-card controls rather
     *   than the Poll domain object: question/options/multiple/durationDays,
     *   the exact four values collectPollPayload() sends back. `pollLocked`
     *   is the votes-cast freeze (see replaceTopicPoll() and
     *   PollService::deletePoll() for why a poll people have answered can't
     *   be reworded) - the template renders that card read-only instead of
     *   hiding it, so an author sees *why* they can't touch it.
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    private function buildTopicFormViewModel(
        Page $page,
        Feed $forumFeed,
        ?Feed $topic,
        ?string $canonical,
        ?string $cancelUrl,
    ): ViewModel {
        $isEdit = $topic !== null;

        $forumTotals = $this->forumRepository->countTopicsAndPosts([$forumFeed->id])[$forumFeed->id]
            ?? ['topics' => 0, 'posts' => 0];

        $poll = $isEdit ? $this->pollService->getPollForFeed($topic->id, $this->context->user) : null;

        $defaultTitle = $this->tm->trans($isEdit ? 'forums.topic_edit' : 'forums.topic_new');

        return ViewModel::fromPage(
            $page,
            'modules/forums/forums.topic-form.twig',
            [
                'title' => $page->pageName ?: $defaultTitle,
                'description' => $this->tm->trans(
                    $isEdit ? 'forums.topic_edit_title' : 'forums.topic_new_in',
                    ['title' => $isEdit ? $topic->title : $forumFeed->title],
                ),
                'canonical' => $canonical,
                'head_ext' => [
                    '<link rel="stylesheet" href="/assets/css/trix.css">',
                    '<script type="module" src="/assets/js/forums.js" defer></script>',
                ],
                'feed' => $forumFeed,
                'topic' => $topic,
                'forumTotals' => $forumTotals,
                'apiUrl' => $isEdit ? '/api/v1/forums/topics/'.$topic->id : '/api/v1/forums/topics',
                'apiMethod' => $isEdit ? 'PATCH' : 'POST',
                'uploadsApiUrl' => '/api/v1/uploads',
                'cancelUrl' => $cancelUrl,
                'attachments' => $isEdit ? $this->buildAttachmentRows($topic) : [],
                'poll' => $poll !== null ? $this->buildPollFormState($poll) : null,
                'pollLocked' => $poll !== null && $poll->votersCount > 0,
                'authorAvatarUrl' => $this->userService->resolveAvatarUrl($this->context->user->avatarUrl),
            ]
        );
    }

    /**
     * Both gates on editing a whole topic, in one place so the GET
     * (showTopicEditPage()) and the PATCH (handleTopicUpdateRequest()) can
     * never disagree - a form you were allowed to open must still be a form
     * you're allowed to save, and vice versa:
     * - FeedService::canEditFeed() - owner, admin, or container moderator.
     *   Deliberately the fuller rule rather than buildPostRows()'s own
     *   author-only canEdit (see its docblock): this mirrors
     *   Modules\Users\UsersController::showCommunityPostEditPage()'s choice
     *   over showBlogPostEditPage()'s, for the same reason - a moderator who
     *   may already delete a topic outright should be able to fix its title
     *   instead, and it's the identical rule FeedService::updateFeed() will
     *   re-check server-side anyway.
     * - FeedService::isWithinEditWindow() - the same 24h
     *   COMMENT_EDIT_WINDOW_SECONDS an ordinary reply gets, admins excepted.
     *   Deliberately not a second, topic-specific number: the opening post is
     *   a post, and editing it through the per-post "Edit" button (which
     *   goes to FeedService::editComment()) already had exactly this limit -
     *   a longer one here would just mean two ways to edit the same text with
     *   two different deadlines.
     *
     * Order matters: permission first, window second, so someone else's
     * topic reads as "forbidden" rather than leaking "this would have been
     * editable 20 hours ago".
     *
     * @throws ForbiddenException
     */
    private function assertTopicEditable(Feed $topic, User $user): void
    {
        if ($user->isGuest() || ! $this->feedService->canEditFeed($topic, $user)) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        if (! $this->feedService->isWithinEditWindow($topic, $user)) {
            throw new ForbiddenException($this->tm->trans('feed.comment_edit_window_expired'));
        }
    }

    /**
     * assertTopicEditable() as a plain boolean, for the view layer - same
     * thin-wrapper-over-the-real-enforcement idiom FeedService::canEditFeed()
     * itself is (see its docblock): forums.topic-view.twig needs to decide
     * whether to render the "Edit" button, and deriving that from
     * the very method the edit page and its PATCH endpoint call means the
     * button can never be shown for a topic those would then refuse.
     */
    private function canEditTopic(Feed $topic): bool
    {
        try {
            $this->assertTopicEditable($topic, $this->context->user);
        } catch (ForbiddenException) {
            return false;
        }

        return true;
    }

    /**
     * forums.topic-view.twig's "Edit" button href, and
     * forums.topic-edit's own canonical - just the topic's canonicalUrl with
     * a literal '/edit/' suffix, exactly like
     * Modules\Users\UsersController::buildPostEditUrl() (see its docblock for
     * why appending to the feed's own URL beats rebuilding via
     * UrlGenerator::page(): several ancestor pages here share the {slug}
     * placeholder *name*, so one flat params array would fill the section's
     * and the topic's from the same value).
     */
    private function buildTopicEditUrl(Feed $topic): ?string
    {
        return $topic->canonicalUrl !== null ? rtrim($topic->canonicalUrl, '/').'/edit/' : null;
    }

    /**
     * A Poll flattened back into the four controls forums.topic-form.twig's
     * poll card actually has - the exact inverse of attachTopicPoll()'s own
     * mapping, so a poll round-trips through the edit form unchanged.
     *
     * durationDays is recovered from closesAt relative to the poll's own
     * createdAt, not to now: "open for 7 days" is the choice the author made at
     * creation time, and measuring from now would silently downgrade it to
     * "3 days" just because four days have passed. Anything that doesn't land
     * on one of the card's own offered values (POLL_DURATION_DAYS) falls back
     * to 0, "No limit" - resolvePollClosesAt() only ever writes those three
     * multiples, so in practice that branch is for polls created outside this
     * form (nothing does that today) rather than a lossy case for ones
     * created in it.
     *
     * @return array{question: string, options: string[], multiple: bool, durationDays: int}
     */
    private function buildPollFormState(Poll $poll): array
    {
        $durationDays = 0;
        if ($poll->closesAt !== null) {
            $days = (int) round(($poll->closesAt - $poll->createdAt) / 86400);
            $durationDays = in_array($days, self::POLL_DURATION_DAYS, true) ? $days : 0;
        }

        return [
            'question' => $poll->question,
            'options' => array_map(static fn (PollOption $option): string => $option->text, $poll->options),
            'multiple' => $poll->isMultipleChoice(),
            'durationDays' => $durationDays,
        ];
    }

    /**
     * Turns findTopicsPage()'s bare id/stat rows into what the topic-list
     * template actually renders: the topic's own Feed (decorated with a
     * canonicalUrl and createdAtLabel/Title exactly like FeedService's own
     * private decorateFeed() would - duplicated here rather than exposed
     * from FeedService, since forums.topic-list is the only caller that
     * builds topic Feeds outside FeedService's own decorated getters) plus
     * its reply count and "last bumped by" preview (same shape as
     * buildLastPostPreviews()'s per-forum version, just per-topic and fed
     * by findTopicsPage() instead of findLastActivity()).
     *
     * @param int[] $topicIds
     * @param array<int, array{replyCount: int, lastOwnerId: int, lastActivityAt: int}> $stats
     * @return array<int, array{feed: Feed, replies: int, lastAuthorName: string, lastAuthorAvatarUrl: string, lastWhen: string, lastWhenTitle: string, unread: bool}>
     */
    private function buildTopicRows(array $topicIds, array $stats): array
    {
        if ($topicIds === []) {
            return [];
        }

        $topics = $this->feedRepository->findByIds($topicIds, $this->context->user, 'forum-post');
        $topicUrls = $this->urlGenerator->feeds($topics);

        $ownerIds = array_map(static fn (array $s): int => $s['lastOwnerId'], $stats);
        $owners = $this->userRepository->findByIds($ownerIds);

        $readAtByTopicId = $this->feedService->getFeedReadAtMap($topicIds, $this->context->user);

        $rows = [];

        foreach ($topics as $topic) {
            $topicStats = $stats[$topic->id] ?? [
                'replyCount' => 0,
                'lastOwnerId' => $topic->ownerId,
                'lastActivityAt' => $topic->createdAt ?? 0,
            ];

            $topic->canonicalUrl = $topicUrls[$topic->id] ?? null;

            if ($topic->createdAt !== null) {
                $createdAt = new DateTimeImmutable('@'.$topic->createdAt);
                $topic->createdAtLabel = $this->formatter->relative($createdAt);
                $topic->createdAtTitle = $this->formatter->datetime($createdAt);
            }

            $lastActivityAt = new DateTimeImmutable('@'.$topicStats['lastActivityAt']);
            $owner = $owners[$topicStats['lastOwnerId']] ?? null;
            $readAt = $readAtByTopicId[$topic->id] ?? null;

            $rows[] = [
                'feed' => $topic,
                'replies' => $topicStats['replyCount'],
                'lastAuthorName' => $owner['displayName'] ?? ('#'.$topicStats['lastOwnerId']),
                'lastAuthorAvatarUrl' => $this->userService->resolveAvatarUrl($owner['avatarUrl'] ?? ''),
                'lastWhen' => $this->formatter->relative($lastActivityAt),
                'lastWhenTitle' => $this->formatter->datetime($lastActivityAt),
                'unread' => $readAt === null || $readAt < $topicStats['lastActivityAt'],
            ];
        }

        return $rows;
    }

    /**
     * forums.topic-view: one topic, its opening post + a page of replies,
     * ordered oldest-first (classic forum numbering: opening post is #1).
     *
     * MVP scope only, same posture as showTopicListPage()'s own docblock:
     * this renders the topic and lets a member post a reply, submitted to
     * this module's own POST /api/v1/forums/{topicId}/reply action (see
     * handleTopicReplyRequest()) rather than the generic
     * /api/v1/comments/{parentId} every other comment form uses - needed so
     * a new reply can also notify the topic's "Follow" followers (see
     * notifyTopicFollowers()). Also records a view
     * (FeedService::recordView() - see its own docblock for why it's a
     * plain, un-deduped counter), and marks the topic read for the current
     * viewer once they're on its last page (FeedService::markFeedAsRead() -
     * automatic, not a manual "Mark as read" button; see the call
     * site's own comment for why it's gated to the last page, not any page).
     * Everything else the mockup shows as an *interactive*
     * feature beyond that - per-post rating, editing/deleting your own
     * post, quoting into a reply, "Preview" - is left as a
     * disabled "Coming soon" stub in forums.topic-view.twig (mirroring the
     * unread-tracking stubs forums.topic-list.twig already has) or omitted
     * outright where even a disabled control would be misleading (e.g. no
     * fabricated participant/post counts - same reasoning showTopicListPage()
     * gives for not inventing pinned columns). See docs/TODO.md "Forums:
     * topic-view interactive features" for the deferred list.
     *
     * @throws ForbiddenException
     */
    public function showTopicViewPage(Page $page, string $slug): ?ViewModel
    {
        $topicFeed = $this->resolveTopicForPage($page, $slug);

        if (!$topicFeed || $topicFeed->type !== 'forum-post') {
            throw new ForbiddenException('Topic not found');
        }

        // Counts this page render as a view - see recordView()'s own
        // docblock for why it's a plain, un-deduped increment (no "already
        // viewed" tracking, unlike the separate read/unread mechanism).
        // $topicFeed->views still holds the pre-increment count in memory
        // (it was fetched above), so the view model below adds 1 locally
        // rather than re-fetching the row just to read the new value back.
        $this->feedService->recordView($topicFeed->id);

        // Eyebrow above the title ("Section · <forum>") - same
        // plain FeedRepository::findById() (not FeedService's getFeedById(),
        // which throws on a miss) as showTopicListPage()'s own parent-forum
        // lookup: a parent forum not resolving shouldn't break the page.
        $parentForum = null;
        if ($topicFeed->parentId) {
            $parentForum = $this->feedRepository->findById($topicFeed->parentId, $this->context->user);
            if ($parentForum) {
                $parentForum->canonicalUrl = $this->urlGenerator->feed($parentForum);
            }
        }

        if ($topicFeed->createdAt !== null) {
            $createdAt = new DateTimeImmutable('@'.$topicFeed->createdAt);
            $topicFeed->createdAtLabel = $this->formatter->relative($createdAt);
            $topicFeed->createdAtTitle = $this->formatter->datetime($createdAt);
        }

        $replyCount = $this->forumRepository->countReplies($topicFeed->id);
        $totalPosts = 1 + $replyCount;
        $totalPages = max(1, (int) ceil($totalPosts / self::POSTS_PER_PAGE));

        $currentPage = $this->urlGenerator->pageNumber($this->context->query);
        if ($currentPage > $totalPages) {
            throw new NotFoundException('Page not found');
        }
        $offset = ($currentPage - 1) * self::POSTS_PER_PAGE;

        // Only marks the topic read once the viewer is actually on its last
        // page - topics track no per-page position (see markFeedAsRead()'s
        // own docblock: "seen since last activity", not which reply), so
        // marking read on *any* page would wrongly hide the unread badge for
        // someone who opened page 1 of a 10-page topic and never got to the
        // newest replies on page 10. $totalPages/$currentPage are computed
        // fresh above from the current reply count, so a reply landing
        // between requests correctly bumps the "last page" target rather
        // than leaving a stale one.
        if ($currentPage === $totalPages) {
            $this->feedService->markFeedAsRead($topicFeed->id, $this->context->user);
        }

        $posts = $this->buildPostRows($topicFeed, $offset);

        $participantsPage = $this->forumRepository->findTopicParticipants(
            $topicFeed->id,
            self::TOPIC_PARTICIPANT_AVATARS
        );
        $participants = $this->buildParticipants($participantsPage['ids']);

        // "Follow" - the generic feed_favorites/feed.rating-style widget
        // (data-favorite-feed, CMS.initFavorite() already global via
        // main.ts) doubles as "follow this topic": favoriting a feed is
        // what notifyTopicFollowers() (called from handleTopicReplyRequest()
        // below, this controller's own Forums-owned reply endpoint) checks
        // to decide who gets notified about a new reply.
        $isFollowing = $this->feedService->isFeedFavorited($topicFeed->id, $this->context->user);

        // Generic feed-attached poll (Service\PollService, see
        // attachTopicPoll()'s own docblock for how a topic gets one at
        // creation time) - a topic has at most one (feed_polls.feed_id is
        // unique), so this is a single ?Poll, not a list. Re-checks feed
        // access internally (PollService::getPollForFeed() calls
        // FeedService::getFeedById()) - redundant with $topicFeed already
        // having been resolved above, but harmless, and keeps PollService
        // self-contained rather than trusting a caller's own earlier check.
        $poll = $this->pollService->getPollForFeed($topicFeed->id, $this->context->user);
        $pollView = $poll !== null ? $this->buildPollViewModel($poll) : null;

        return ViewModel::fromPage(
            $page,
            'modules/forums/forums.topic-view.twig',
            [
                'title' => $topicFeed->title,
                'description' => $topicFeed->description,
                'canonical' => $this->urlGenerator->listing($topicFeed->canonicalUrl, $currentPage),
                'head_ext' => [
                    '<script type="module" src="/assets/js/forums.js" defer></script>',
                ],
                'feed' => $topicFeed,
                'isFollowing' => $isFollowing,
                'poll' => $pollView,
                'canEditTopic' => $this->canEditTopic($topicFeed),
                'topicEditUrl' => $this->buildTopicEditUrl($topicFeed),
                'parentForum' => $parentForum,
                'forumsListUrl' => $this->urlGenerator->action('forums.list'),
                'posts' => $posts,
                'participants' => $participants,
                'participantsOverflow' => max(0, $participantsPage['total'] - count($participants)),
                'topicTotals' => [
                    'posts' => $totalPosts,
                    'participants' => $participantsPage['total'],
                    'views' => $topicFeed->views + 1,
                ],
                'pagination' => [
                    'current' => $currentPage,
                    'total' => $totalPages,
                    'baseUrl' => $topicFeed->canonicalUrl,
                    'perPage' => self::POSTS_PER_PAGE,
                ],
            ]
        );
    }

    /**
     * Turns a Poll into everything forums.topic-view.twig's #topic-poll card
     * actually renders - both the vote-form and results markup are always
     * present in the DOM (forums.ts's initPoll() toggles which is visible,
     * same both-blocks-rendered pattern the comment edit/view and
     * quick-reply edit/preview toggles already use elsewhere on this page),
     * so this computes the *initial* state for both rather than picking
     * just one.
     *
     * Deliberately doesn't copy the original design mockup's own ad-hoc
     * booleans (closed-always-reveals-results, a separate
     * hideResultsBeforeVote flag) - this app's Poll/PollService already
     * model "when do results show" precisely via resultsVisibility
     * (Poll::canSeeResults()), including a case the mockup's shortcut would
     * have gotten wrong (an AFTER_VOTE poll that's closed but the viewer
     * never voted should stay hidden forever, not reveal just because it
     * closed) - so this defers to that real rule as the single source of
     * truth instead.
     *
     * Vote counts/percentages are only ever computed when
     * PollService::canSeeResults() allows it - same "never fabricate a 0
     * vs. actually-zero" posture APIController::pollToArray() already
     * documents for the vote API's own JSON shape, which this mirrors so a
     * post-vote client-side re-render (forums.ts's applyPollResponse())
     * produces the same numbers either way.
     */
    private function buildPollViewModel(Poll $poll): array
    {
        $user = $this->context->user;
        $guest = $user->isGuest();
        $closed = $poll->isClosed();
        $isMultiple = $poll->isMultipleChoice();

        $userVotes = $this->pollService->getUserVotes($poll->id, $user);
        $hasVoted = $userVotes !== [];
        $canSeeResults = $this->pollService->canSeeResults($poll, $user);

        $showVoteForm = !$closed && !$guest && !$hasVoted;
        // Mutually exclusive with the vote form on purpose, even when
        // canSeeResults is already true (e.g. the default
        // Poll::VISIBILITY_ALWAYS every poll created via forums.topic-new
        // gets today) - a member who still can (and should) vote shouldn't
        // see the results card glued on right underneath the form they're
        // filling out. Results become visible once there's nothing left to
        // vote on: they've voted, the poll's closed, or they're a guest who
        // never could vote in the first place.
        $showResults = $canSeeResults && !$showVoteForm;
        $showHiddenNote = !$showVoteForm && !$showResults;
        $canChangeVote = $hasVoted && !$closed && $poll->allowRevote;

        $totalVotes = array_sum(array_map(
            static fn (PollOption $option): int => $option->votesCount,
            $poll->options
        ));

        $leaderVotes = $poll->options === [] ? 0 : max(array_map(
            static fn (PollOption $option): int => $option->votesCount,
            $poll->options
        ));
        // Matches the vote API's own percentage base (APIController's
        // pollToArray() feeds the client the raw counts, not a precomputed
        // percentage, but this is the same denominator rule): a
        // single-choice poll's percentages are "share of all votes cast"
        // (denom = totalVotes, and since each voter picks exactly one
        // option, that equals the voter count too); a multiple-choice
        // poll's are "share of respondents who picked this option" (denom =
        // votersCount), since one voter can push several options' counts up
        // at once - using totalVotes there would make percentages sum to
        // less than 100% and under-represent popular options.
        $denom = $isMultiple ? $poll->votersCount : $totalVotes;
        $voteForms = $this->tm->getAll()['forums.vote'];
        $participantForms = $this->tm->getAll()['forums.participant'];
        $optionForms = $this->tm->getAll()['forums.option'];

        $options = array_map(
            function (PollOption $option) use ($userVotes, $denom, $leaderVotes, $voteForms): array {
                $chosen = in_array($option->id, $userVotes, true);
                $pct = $denom > 0 ? (int) round($option->votesCount / $denom * 100) : 0;

                return [
                    'id' => $option->id,
                    'text' => $option->text,
                    'checked' => $chosen,
                    'pct' => $pct,
                    'isLeading' => $leaderVotes > 0 && $option->votesCount === $leaderVotes,
                    'statText' => $pct.'% · '.$option->votesCount.' '
                        .$this->formatter->plural($option->votesCount, ...$voteForms),
                ];
            },
            $poll->options
        );

        $deadlineText = null;
        if ($poll->closesAt !== null) {
            $deadlineAt = new DateTimeImmutable('@'.$poll->closesAt);
            $deadlineText = $this->tm->trans(
                $closed ? 'forums.poll.deadline_closed' : 'forums.poll.deadline_open',
                ['date' => $this->formatter->datetime($deadlineAt)],
            );
        }

        $hint = $isMultiple
            ? $this->tm->trans('forums.poll.multiple_hint', [
                'count' => $poll->maxChoices,
                'unit' => $this->formatter->plural($poll->maxChoices, ...$optionForms),
            ])
            : $this->tm->trans('forums.poll.single_hint');
        if ($poll->allowRevote && !$closed) {
            $hint .= $this->tm->trans('forums.poll.revote_hint');
        }

        $hiddenNoteText = match ($poll->resultsVisibility) {
            Poll::VISIBILITY_AFTER_CLOSE => $this->tm->trans('forums.poll.hidden_until_close'),
            Poll::VISIBILITY_AFTER_VOTE => $this->tm->trans('forums.poll.hidden_until_vote'),
            default => $this->tm->trans('forums.poll.hidden'),
        };

        $selectionHint = $isMultiple
            ? $this->tm->trans('forums.poll.selection_multiple', [
                'count' => count($userVotes),
                'max' => $poll->maxChoices,
            ])
            : $this->tm->trans($hasVoted ? 'forums.poll.selection_voted' : 'forums.poll.selection_empty');

        return [
            'id' => $poll->id,
            'question' => $poll->question,
            'isMultiple' => $isMultiple,
            'maxChoices' => $poll->maxChoices,
            'isClosed' => $closed,
            'deadlineText' => $deadlineText,
            'hint' => $hint,
            'showVoteForm' => $showVoteForm,
            'showResults' => $showResults,
            'showHiddenNote' => $showHiddenNote,
            'hiddenNoteText' => $hiddenNoteText,
            'pollGuestPrompt' => $guest && !$closed,
            'hasVoted' => $hasVoted,
            'canChangeVote' => $canChangeVote,
            'submitDisabled' => $userVotes === [],
            'selectionHint' => $selectionHint,
            'currentVotesAttr' => implode(',', $userVotes),
            'options' => $options,
            'totalVotesText' => $totalVotes.' '.$this->formatter->plural($totalVotes, ...$voteForms),
            'votersText' => $poll->votersCount.' '
                .$this->formatter->plural($poll->votersCount, ...$participantForms),
        ];
    }

    /**
     * Turns one page's worth of ids into forums.topic-view's actual post
     * rows: the topic's own opening post (only ever on page 1, i.e. when
     * $offset is 0 - findTopicPostsPage() is asked for one fewer reply that
     * page to make room for it) plus a page of replies from
     * ForumRepository::findTopicPostsPage(), each decorated with its
     * author's site-wide forum post count and "member since" month/year -
     * the per-post sidebar stats the mockup shows - and its position badge
     * (opening post / topic author / "this is you").
     *
     * Also gates "Edit"/"Delete" for each post: canEdit is
     * self-service only (isCurrentUser, not FeedService::canEditFeed()'s
     * fuller owner/admin/moderator rule - Forums has no moderator concept
     * today, and an admin's own ability to edit someone else's post still
     * works if called directly, there's just no button for it yet) plus
     * FeedService::isWithinEditWindow() (the same 24h
     * COMMENT_EDIT_WINDOW_SECONDS rule, including its admin bypass, that
     * FeedService::editComment() enforces server-side on the resulting save -
     * asked of that one method rather than re-compared here so the button and
     * the enforcement can't drift; see also assertTopicEditable(), which
     * gates the whole-topic editor on the identical pair of checks);
     * canDelete is the same
     * self-service check minus the window (matches the mockup - its delete
     * button carries no time-limit hint) and excludes the topic's own
     * opening post entirely (see FeedService::deleteComment()'s own
     * docblock for why deleting that is a different, not-yet-built
     * action). editableContent is only computed when canEdit is true, and
     * wasEdited/editedAtLabel/editedAtTitle reflect updatedAt having moved
     * past createdAt (i.e. an actual edit happened, not just the row being
     * freshly inserted, since insert() sets both to the same timestamp).
     * userRating is the viewer's own vote for that post, if any - feeds its
     * generic rating widget's initial star state (see
     * FeedService::getUserRatingValues()); ratingSum/ratingCount/
     * ratingAverage() are already on $feed itself, no extra field needed.
     * quoteText is contentToEditableText()'s same reverse-transform, but
     * computed for every post regardless of canEdit - "Quote" needs
     * the quoted author's plain text too, not just your own; editableContent
     * reuses the same computed string rather than transforming twice.
     *
     * authorSignature is the author's forum signature (users.signature, edited
     * in Modules\Profile's "Forum" tab) as display-ready HTML, repeated on
     * every one of their posts the way forums traditionally show it, or '' if
     * they haven't set one. Already purified when stored (see
     * UserService::sanitizeSignature()), which is what makes the template's
     * `|raw` safe.
     *
     * @return array<int, array{
     *     feed: Feed,
     *     number: int,
     *     isOpeningPost: bool,
     *     isTopicAuthor: bool,
     *     isCurrentUser: bool,
     *     authorPostCount: int,
     *     authorMemberSince: ?string,
     *     authorAvatarUrl: string,
     *     authorSignature: string,
     *     canEdit: bool,
     *     canDelete: bool,
     *     editableContent: ?string,
     *     quoteText: string,
     *     wasEdited: bool,
     *     editedAtLabel: ?string,
     *     editedAtTitle: ?string,
     *     userRating: ?int,
     *     attachments: array<int, array{id: int, url: string, name: string, sizeLabel: string, icon: string}>
     * }>
     */
    private function buildPostRows(Feed $topicFeed, int $offset): array
    {
        $includeOpeningPost = $offset === 0;
        $replyLimit = self::POSTS_PER_PAGE - ($includeOpeningPost ? 1 : 0);
        $replyOffset = $includeOpeningPost ? 0 : $offset - 1;

        $replyIds = $replyLimit > 0
            ? $this->forumRepository->findTopicPostsPage($topicFeed->id, $replyLimit, $replyOffset)
            : [];

        // findByIds() preserves $replyIds's own order (see its docblock),
        // which is already findTopicPostsPage()'s created_at ASC page order
        // - no re-sorting needed here.
        $replies = $replyIds !== []
            ? $this->feedRepository->findByIds($replyIds, $this->context->user, 'comment')
            : [];

        $posts = $includeOpeningPost ? [$topicFeed] : [];
        $posts = array_merge($posts, $replies);

        if ($posts === []) {
            return [];
        }

        $authorIds = array_map(static fn (Feed $post): int => $post->ownerId, $posts);
        $authors = $this->userRepository->findByIds($authorIds);
        $authorPostCounts = $this->forumRepository->countUserForumPosts($authorIds);

        // Once per distinct author, not per post - the same author usually
        // holds several posts on a page.
        $authorSignatures = [];
        foreach ($authors as $authorId => $authorRow) {
            $authorSignatures[$authorId] = UserService::renderSignature($authorRow['signature'] ?? '');
        }

        $currentUserId = $this->context->user->isGuest() ? null : $this->context->user->id;

        // Generic feed rating - the same FeedService::rateFeed()/
        // getUserRatingValue() other feed types already use via
        // the `feed.rating` API action, nothing forum-specific here.
        // ratingSum/ratingCount/ratingAverage() are already on each $post
        // (every Feed row carries them), only the current user's own vote
        // needs its own lookup, batched over the whole page at once.
        $postIds = array_map(static fn (Feed $post): int => $post->id, $posts);
        $userRatings = $currentUserId !== null
            ? $this->feedService->getUserRatingValues($postIds, $this->context->user)
            : [];

        $rows = [];
        $number = $offset + 1;

        foreach ($posts as $post) {
            if ($post->createdAt !== null) {
                $createdAt = new DateTimeImmutable('@'.$post->createdAt);
                $post->createdAtLabel = $this->formatter->relative($createdAt);
                $post->createdAtTitle = $this->formatter->datetime($createdAt);
            }

            $author = $authors[$post->ownerId] ?? null;
            $memberSince = $author && $author['createdAt'] > 0
                ? $this->formatter->monthYear(new DateTimeImmutable('@'.$author['createdAt']))
                : null;
            $authorAvatarUrl = $this->userService->resolveAvatarUrl($author['avatarUrl'] ?? '');

            $isOpeningPost = $post->id === $topicFeed->id;
            $isCurrentUser = $currentUserId !== null && $post->ownerId === $currentUserId;

            $canEdit = $isCurrentUser && $this->feedService->isWithinEditWindow($post, $this->context->user);
            $canDelete = $isCurrentUser && ! $isOpeningPost;

            $wasEdited = $post->updatedAt !== null
                && $post->createdAt !== null
                && $post->updatedAt > $post->createdAt;

            $editedAtLabel = null;
            $editedAtTitle = null;
            if ($wasEdited) {
                $editedAt = new DateTimeImmutable('@'.$post->updatedAt);
                $editedAtLabel = $this->formatter->relative($editedAt);
                $editedAtTitle = $this->formatter->datetime($editedAt);
            }

            // Same reverse-transform contentToEditableText() already does
            // for the "Edit" textarea, but computed for every post (not
            // just canEdit ones) - quoting into a reply needs the quoted
            // author's plain text too, not just your own.
            $plainContent = $this->contentToEditableText((string) $post->content);

            $rows[] = [
                'feed' => $post,
                'number' => $number,
                'isOpeningPost' => $isOpeningPost,
                'isTopicAuthor' => $post->ownerId === $topicFeed->ownerId,
                'isCurrentUser' => $isCurrentUser,
                'authorPostCount' => $authorPostCounts[$post->ownerId] ?? 0,
                'authorMemberSince' => $memberSince,
                'authorAvatarUrl' => $authorAvatarUrl,
                'authorSignature' => $authorSignatures[$post->ownerId] ?? '',
                'canEdit' => $canEdit,
                'canDelete' => $canDelete,
                'editableContent' => $canEdit ? $plainContent : null,
                'quoteText' => $plainContent,
                'wasEdited' => $wasEdited,
                'editedAtLabel' => $editedAtLabel,
                'editedAtTitle' => $editedAtTitle,
                'userRating' => $userRatings[$post->id] ?? null,
                // Only the opening post ever has one - replies have no
                // attachments UI at all (see buildAttachmentRows()'s own
                // docblock), so this is always [] for every other row.
                'attachments' => $isOpeningPost ? $this->buildAttachmentRows($topicFeed) : [],
            ];

            $number++;
        }

        return $rows;
    }

    /**
     * Reverses FeedService::normalizeCommentContent()'s
     * htmlspecialchars+nl2br+purify transform (plus, since quoting shipped,
     * FeedService::renderQuoteBlock()'s <blockquote> markup) well enough to
     * prefill the "Edit" textarea - or feed "Quote" quoting
     * someone else's post - with something close to what the author
     * originally typed: any quote block(s) at the very start become
     * "> Author wrote: / > ..." lines again (the exact shape
     * FeedService::extractLeadingQuotes() expects on the way back in, so
     * editing or re-quoting an already-quoted post round-trips instead of
     * doubling up the markup), then <br> variants become newlines and
     * entities are decoded back (&amp; -> &, etc). Not a byte-perfect
     * inverse (the purifier could in principle rewrite something on the way
     * in), but the quick-reply form is plain-text input to begin with - no
     * rich-text editor exists yet (see docs/TODO.md) - so round-tripping
     * through this is enough for an edit/quote box, not a rich-text
     * re-render. Both <br> replacements below also eat the one literal "\n"
     * PHP's nl2br() always leaves right after the tag it inserts (it
     * inserts <br> *before* the newline, not instead of it) - matching only
     * the tag itself would leave a doubled blank line per original line
     * break once it's converted back.
     */
    private function contentToEditableText(string $content): string
    {
        $quoteAuthorMarker = '__QUOTE_AUTHOR__';
        $quoteHeaderPattern = preg_quote(
            $this->tm->trans('feed.quote_header', ['author' => $quoteAuthorMarker]),
            '~',
        );
        $quoteHeaderPattern = str_replace(preg_quote($quoteAuthorMarker, '~'), '(.*?)', $quoteHeaderPattern);

        $content = preg_replace_callback(
            '~<blockquote class="comment-quote mb-3 px-3 py-2 rounded-2">'
            .'<div class="text-body-secondary mb-1 comment-quote-author">'
            .'<i class="bi bi-quote me-1"></i>'.$quoteHeaderPattern.'</div>'
            .'<div class="text-body-secondary comment-quote-text">(.*?)</div>'
            .'</blockquote>~s',
            function (array $matches): string {
                $author = html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
                $quotedText = preg_replace('~<br\s*/?>\r?\n?~i', "\n", $matches[2]) ?? $matches[2];
                $quotedText = html_entity_decode($quotedText, ENT_QUOTES, 'UTF-8');
                $quotedLines = array_map(
                    static fn (string $line): string => '> '.$line,
                    explode("\n", $quotedText)
                );

                return '> '.$this->tm->trans('feed.quote_header', ['author' => $author])."\n"
                    .implode("\n", $quotedLines)."\n\n";
            },
            $content
        ) ?? $content;

        $withNewlines = preg_replace('~<br\s*/?>\r?\n?~i', "\n", $content) ?? $content;

        return html_entity_decode($withNewlines, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Turns findTopicParticipants()'s bare owner ids into display rows for
     * the "Topic participants" avatar strip - display name plus a real avatar
     * image (UserService::resolveAvatarUrl() falls back to the site's
     * default silhouette when a user has no upload, same as every other
     * avatar on the site - see UsersController's own calls), kept as its
     * own small structure rather than reusing UserRepository's raw array
     * directly so the template doesn't reach into a repository-shaped
     * array.
     *
     * @param int[] $ownerIds
     * @return array<int, array{id: int, displayName: string, avatarUrl: string}>
     */
    private function buildParticipants(array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        $users = $this->userRepository->findByIds($ownerIds);

        $participants = [];
        foreach ($ownerIds as $id) {
            $participants[] = [
                'id' => $id,
                'displayName' => $users[$id]['displayName'] ?? ('#'.$id),
                'avatarUrl' => $this->userService->resolveAvatarUrl($users[$id]['avatarUrl'] ?? ''),
            ];
        }

        return $participants;
    }

    /**
     * POST /api/v1/forums/read-all - the "Mark all as read" buttons
     * on forums.list.twig (no body, or `{"forumId": null}` - the whole forum
     * system) and forums.topic-list.twig (body `{"forumId": <section id>}`) -
     * see registerApi()'s own note on why this is a Forums-owned action
     * rather than something generic on FeedService/APIController: which
     * topics belong to "this section" or "the whole forum" is Forums
     * vocabulary, same reasoning ForumRepository itself exists instead of
     * that logic living in FeedRepository (see docs/MODULE_CONTRACT.md).
     * The actual bulk write (FeedService::markFeedsAsRead()) is the generic,
     * Core-owned primitive underneath - this endpoint's only job is
     * resolving *which* topic ids that scope means.
     *
     * A single topic being marked read (the third of the three cases this
     * feature covers) already happens automatically as a side effect of
     * viewing (showTopicViewPage()'s own markFeedAsRead() call, gated to the
     * topic's last page) - forums.topic-view's own "Mark as read"
     * button (POST /api/v1/forums/{topicId}/read, see
     * handleTopicReadRequest()) is the same write triggered on demand
     * instead, for a reader who wants a topic off their unread list without
     * paging all the way to the end.
     *
     * POST /api/v1/forums/{topicId}/reply - forums.ts's quick-reply form. A
     * Forums-owned action, not the generic /api/v1/comments/{parentId}
     * every other comment-form on the site posts to - the only reason a
     * reply to a topic needs its own endpoint at all is
     * notifyTopicFollowers() below: "Follow" (favoriting a topic) is
     * generic, Core-owned machinery (FeedService::isFeedFavorited()'s same
     * feed_favorites table), but "a new forum reply should message that
     * topic's followers" is a Forums product decision, not something every
     * comment everywhere should trigger - see docs/MODULE_CONTRACT.md's
     * module-vs-Core test. Editing/deleting a reply doesn't notify anyone,
     * so PATCH/DELETE stay on the generic /api/v1/comments/{topicId}/
     * {commentId} action (APIController::handleCommentItemRequest()) - only
     * creating one gets its own action.
     *
     * POST /api/v1/forums/topics - forums.topic-new's submit button (see
     * handleTopicCreateRequest()'s own docblock).
     *
     * PATCH /api/v1/forums/topics/{id} - forums.topic-edit's save button (see
     * handleTopicUpdateRequest()'s own docblock). No DELETE alongside it, one
     * of only two places this resource deviates from Modules\Users's
     * blog-posts/{id} shape: deleting a topic (and with it every reply under
     * it) is a moderation action nothing in the UI offers yet - see
     * FeedService::deleteComment()'s own note on why even the opening post
     * isn't individually deletable - so registering the verb would advertise
     * a capability with no product decision behind it.
     *
     * @throws ValidationException
     * @throws ForbiddenException
     */
    public function callApi(Page $page, array $args = []): void
    {
        header('Content-Type: application/json');

        match ($page->action) {
            'forums.read-all' => $this->handleMarkAllReadRequest(),
            'forums.reply' => $this->handleTopicReplyRequest((int) ($args['topicId'] ?? 0)),
            'forums.topic-read' => $this->handleTopicReadRequest((int) ($args['topicId'] ?? 0)),
            'forums.topic-create' => $this->handleTopicCreateRequest(),
            'forums.topic-item' => $this->handleTopicItemRequest((int) ($args['id'] ?? 0)),
            default => throw new NotFoundException('Unknown API action'),
        };
    }

    /**
     * Registers this module's own /api/v1/forums/read-all,
     * /api/v1/forums/topics (+ its /{id} item, see that route's own comment
     * below) and /api/v1/forums/{topicId}/reply actions, the
     * same way Modules\Users\UsersController registers its own
     * /api/v1/users/{username}/friend action - a module owning a mutation's
     * full behaviour (including a step, like the reply endpoint's
     * follower-notify, or the topics endpoint's subscribe/poll steps, that
     * only makes sense for that module) without APIController needing a
     * per-module special case.
     *
     * 'topics' takes the target forum's id from its own request body
     * (handleTopicCreateRequest()'s own $input['forumId']), same idiom as
     * 'read-all' above, rather than adding a second '{forumId}' dynamic
     * branch alongside '{topicId}' just for this one write action.
     *
     * 'forums' and '{topicId}' are never meant to be requested on their own
     * (only their 'read-all'/'topics'/'reply' children are) - same
     * real-but-unused-action placeholder reasoning UsersController's own
     * registerApi() uses for 'user.posts-parent' et al., so a bare GET to
     * either 400s via callApi()'s "Unknown action" instead of 500ing on a
     * null action.
     */
    public static function registerApi(int $apiPageId, PageTree $pageTree): void
    {
        $forumsPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $forumsPageId,
                parentId: $apiPageId,
                pattern: 'forums',
                requestMethods: ['GET'],
                action: 'forums.api-parent',
            )
        );

        $pageTree->add(
            Page::api(
                id: $pageTree->getMaxPageId(),
                parentId: $forumsPageId,
                pattern: 'read-all',
                requestMethods: ['POST'],
                action: 'forums.read-all',
            )
        );

        $topicsPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $topicsPageId,
                parentId: $forumsPageId,
                pattern: 'topics',
                requestMethods: ['POST'],
                action: 'forums.topic-create',
            )
        );

        // 'topics/{id}' - forums.topic-edit's save. Note this is a *second*
        // dynamic topic-id route alongside '{topicId}' below, which the
        // reply/read endpoints hang off: those two predate this one and take
        // their id directly under /api/v1/forums/ ('/api/v1/forums/12/reply'),
        // whereas an edit is naturally a PATCH of the same collection item
        // POST /api/v1/forums/topics created - the shape
        // Modules\Users\UsersController's own blog-posts/{id} already uses,
        // and the one that keeps this action's own dispatch a plain
        // handleTopicItemRequest() rather than a third verb bolted onto the
        // '{topicId}' branch. Consolidating the older pair onto this
        // collection would be a URL break for no functional gain, so they're
        // left where they are.
        $pageTree->add(
            Page::api(
                id: $pageTree->getMaxPageId(),
                parentId: $topicsPageId,
                pattern: '{id}',
                requestMethods: ['PATCH'],
                action: 'forums.topic-item',
            )
        );

        $topicIdPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $topicIdPageId,
                parentId: $forumsPageId,
                pattern: '{topicId}',
                requestMethods: ['GET'],
                action: 'forums.topic-api-parent',
            )
        );

        $pageTree->add(
            Page::api(
                id: $pageTree->getMaxPageId(),
                parentId: $topicIdPageId,
                pattern: 'reply',
                requestMethods: ['POST'],
                action: 'forums.reply',
            )
        );

        $pageTree->add(
            Page::api(
                id: $pageTree->getMaxPageId(),
                parentId: $topicIdPageId,
                pattern: 'read',
                requestMethods: ['POST'],
                action: 'forums.topic-read',
            )
        );
    }

    /**
     * Resolves the "mark all read" scope from the request body and marks
     * every topic in it read for the current user:
     * - `forumId` given: that forum/subforum's own topics, plus its direct
     *   subforums' topics (a top-level forum's subforums count as part of
     *   "this section" - matches how forums.topic-list.twig already renders
     *   a top-level forum's subforums inline on the same page as its own
     *   topics, not as a separate drill-down).
     * - `forumId` omitted or null: every topic across every forum -
     *   forums.list.twig's site-wide button.
     *
     * Guests never reach FeedService::markFeedsAsRead() with anything to do
     * (it no-ops for guests - see its own docblock on why), so this rejects
     * them outright instead of doing the query work for nothing.
     *
     * @throws ValidationException
     * @throws ForbiddenException
     */
    private function handleMarkAllReadRequest(): void
    {
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        if ($this->context->user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];
        $forumId = isset($input['forumId']) ? (int) $input['forumId'] : null;

        if ($forumId !== null) {
            // Throws NotFoundException/ForbiddenException on a bogus or
            // inaccessible id - both are caught by StreamEngine's own
            // callApi() dispatch and turned into a clean JSON error
            // response, same as ValidationException.
            $forum = $this->feedService->getFeedById($forumId, $this->context->user);

            $subForums = $this->feedService->getFeedsByParentAndType($forum->id, 'forum', $this->context->user);
            $subForumIds = array_map(static fn (Feed $f): int => $f->id, $subForums);

            $topicIds = $this->forumRepository->findTopicIdsForForums([$forum->id, ...$subForumIds]);
        } else {
            $topicIds = $this->forumRepository->findAllTopicIds();
        }

        $this->feedService->markFeedsAsRead($topicIds, $this->context->user);

        http_response_code(204);
    }

    /**
     * @throws ValidationException
     * @throws ForbiddenException
     */
    private function handleTopicReplyRequest(int $topicId): void
    {
        $this->requireAuthenticatedUser();
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $input = json_decode(file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];

        $comment = $this->feedService->createComment(
            parentId: $topicId,
            content: trim((string) ($input['content'] ?? '')),
            user: $this->context->user
        );

        $this->notifyTopicFollowers($topicId, $this->context->user);

        http_response_code(201);
        echo Formatter::json($comment);
    }

    /**
     * POST /api/v1/forums/{topicId}/read - forums.topic-view's manual
     * "Mark as read" button. Same write showTopicViewPage() already
     * performs automatically once a viewer reaches a topic's last page (see
     * that method's own docblock for why it's gated to the last page
     * there) - this is that same call triggered on demand instead, from
     * any page of the topic.
     *
     * No guest rejection, unlike handleMarkAllReadRequest(): that bulk
     * write is meaningless for a guest (rejected outright there - see its
     * own docblock), but a single FeedService::markFeedAsRead() call
     * already has its own guest branch (GuestFeedReadStore cookie) - the
     * exact same one the automatic call site relies on - so there's
     * nothing to special-case here.
     *
     * @throws ValidationException
     * @throws ForbiddenException
     */
    private function handleTopicReadRequest(int $topicId): void
    {
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        // Confirms the id is a real, visible topic (throws Not Found/
        // Forbidden otherwise) before marking anything read - same
        // reasoning handleMarkAllReadRequest() gives for its own
        // getFeedById() call on a caller-supplied id.
        $topic = $this->feedService->getFeedById($topicId, $this->context->user);
        if ($topic->type !== 'forum-post') {
            throw new NotFoundException('Topic not found');
        }

        $this->feedService->markFeedAsRead($topic->id, $this->context->user);

        http_response_code(204);
    }

    /**
     * POST /api/v1/forums/topics - forums.topic-new's submit button. Creates
     * the topic itself (a plain 'forum-post' feed, via the same generic
     * FeedService::createFeed() every other feed type goes through) plus two
     * Forums-specific follow-up steps a generic feed-create endpoint has no
     * business doing on its own:
     * - `subscribe` (truthy): favorites the new topic for its own author,
     *   via the same generic FeedService::addFavorite() forums.topic-view's
     *   "Follow" button uses - so the author gets notifyTopicFollowers()'s
     *   own reply notifications on their own topic without a separate click
     *   after creating it, matching forums.topic-form.twig's own
     *   "Subscribe to topic" switch (checked by default).
     * - `poll` (optional): see attachTopicPoll()'s own docblock.
     *
     * No draft/visibility control here unlike Modules\Users\BlogPostService::
     * createBlogPost() - ForumRepository::findTopicsPage() has no visibility
     * filter at all yet (every topic in a section is listed to everyone
     * regardless of its `visibility` column), so a 'private' "draft" topic
     * would still show up in the public topic list - offering one from this
     * form would be actively misleading until that gap is closed, so every
     * topic created here is 'public' (createFeed()'s own default), full
     * stop.
     *
     * Slug collisions/reservations are uniqueTopicSlug()'s own job (see its
     * docblock, and RESERVED_FORUM_TOPIC_SLUGS's, for exactly why "new" is
     * one of them) - this method just calls it once and passes the result
     * straight through to createFeed().
     *
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleTopicCreateRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new ValidationException('Method not allowed', 405);
        }

        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $user = $this->context->user;
        if ($user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        $input = $this->readJsonBody();

        // getFeedById() throws NotFoundException/ForbiddenException on a
        // bogus or inaccessible id - both are caught by StreamEngine's own
        // callApi() dispatch and turned into a clean JSON error response,
        // same as ValidationException below.
        $forumFeed = $this->feedService->getFeedById((int) ($input['forumId'] ?? 0), $user);
        if ($forumFeed->type !== 'forum') {
            throw new ValidationException($this->tm->trans('feed.parent_not_found'));
        }

        ['title' => $title, 'content' => $content] = $this->validateTopicInput($input);

        $slug = $this->uniqueTopicSlug($forumFeed->id, $title, $user);

        // See resolveTopicAttachments()'s own docblock: each id is an
        // already-uploaded file (via the shared /api/v1/uploads endpoint,
        // same as a blog post's cover image/track) that forums.topic-new.
        // twig's own "Attachments" card collected before submit - stored as one
        // JSON-encoded feed_metadata value (feed_metadata has no native
        // array column - see FeedService::normalizeMetadataInput()'s own
        // scalar-only contract), same idiom BlogPostService::createBlogPost()
        // uses for its own single 'track_upload_id', just a list instead of
        // one value.
        $attachments = $this->resolveTopicAttachments($input['attachments'] ?? null, $user);
        $metadata = $attachments !== []
            ? ['attachment_upload_ids' => json_encode(array_map(static fn (Upload $upload): int => $upload->id, $attachments))]
            : null;

        $topic = $this->feedService->createFeed(
            title: $title,
            slug: $slug,
            type: 'forum-post',
            parentId: $forumFeed->id,
            description: null,
            imageUrl: null,
            content: $content,
            user: $user,
            metadata: $metadata,
        );

        if (! empty($input['subscribe'])) {
            try {
                $this->feedService->addFavorite($topic->id, $user);
            } catch (Throwable $e) {
                error_log($e->getMessage());
            }
        }

        $this->attachTopicPoll($topic->id, $input['poll'] ?? null, $user);

        $topic->canonicalUrl = $this->urlGenerator->feed($topic);

        http_response_code(201);
        echo Formatter::json([
            'id' => $topic->id,
            'slug' => $topic->slug,
            'title' => $topic->title,
            'canonicalUrl' => $topic->canonicalUrl,
        ]);
    }

    /**
     * Single dispatcher for the topics/{id} resource. Only PATCH today (see
     * callApi()'s own note on the deliberately absent DELETE), but kept as
     * its own match() rather than calling handleTopicUpdateRequest()
     * directly, same shape as Modules\Users\UsersController::
     * handleBlogPostItemRequest(): the page row itself declares which verbs
     * Router lets through, and this is the layer that turns anything else
     * into a clean 405 instead of silently treating it as a save.
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleTopicItemRequest(int $id): void
    {
        match ($_SERVER['REQUEST_METHOD']) {
            'PATCH' => $this->handleTopicUpdateRequest($id),
            default => throw new ValidationException('Method not allowed', 405),
        };
    }

    /**
     * PATCH /api/v1/forums/topics/{id} - forums.topic-edit's save button.
     * The write side of showTopicEditPage(); both gate on the same
     * assertTopicEditable() (owner/admin/moderator, inside the 24h window),
     * and the title/content rules are the same validateTopicInput() the
     * create endpoint uses, so an edit can't slip past a rule creation
     * enforces.
     *
     * Three things a topic's edit deliberately does *not* touch:
     *
     * - **Its slug.** The existing one is passed straight back to
     *   updateFeed(), never re-derived from the (possibly new) title -
     *   editing a topic never changes its URL, same rule
     *   Modules\Users\BlogPostService::updateBlogPost() already follows for a
     *   blog post. A forum topic has a stronger claim to it than a blog post
     *   does, too: its URL is what gets pasted into other topics and
     *   notification messages, and nothing in this app writes redirects for a
     *   moved feed, so re-slugging would silently 404 every existing link.
     *
     * - **Its section.** `forumId` in the body is ignored (the create
     *   endpoint's own reading of it is what makes it meaningful there);
     *   moving a topic between forums is a moderation action with its own
     *   consequences - unread watermarks, the source and destination
     *   sections' cached topic/post counts - and no UI asks for it.
     *
     * - **Who follows it.** `subscribe` is create-only for the same reason:
     *   by edit time the author already has forums.topic-view's own "Follow"
     *   toggle, and having a save button silently re-subscribe them to a
     *   topic they'd deliberately unfollowed would be a surprise.
     *
     * `attachments` *is* the complete new set, not a list of additions - an
     * id the client omits is detached (the metadata value is rewritten
     * wholesale), which is why buildTopicFormViewModel() hands the form every
     * currently-attached id to send back. An edit that drops an attachment
     * leaves the underlying upload row alone; nothing in this app garbage-
     * collects orphaned uploads yet.
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleTopicUpdateRequest(int $id): void
    {
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $user = $this->context->user;
        if ($user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        if ($id <= 0) {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }

        $topic = $this->feedService->getFeedById($id, $user);
        if (! $topic || $topic->type !== 'forum-post') {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }

        $this->assertTopicEditable($topic, $user);

        $input = $this->readJsonBody();
        ['title' => $title, 'content' => $content] = $this->validateTopicInput($input);

        // The topic's currently-stored ids are passed as already-owned: the
        // form sends them straight back to keep them, and an admin/moderator
        // editing someone else's topic doesn't own them (see
        // resolveTopicAttachments()'s own docblock).
        $attachments = $this->resolveTopicAttachments(
            $input['attachments'] ?? null,
            $user,
            $this->storedAttachmentIds($topic),
        );

        $updated = $this->feedService->updateFeed(
            $id,
            [
                'title' => $title,
                // The existing slug, verbatim - see this method's own docblock
                // on why an edit never re-derives one.
                'slug' => $topic->slug,
                'parentId' => $topic->parentId,
                'type' => 'forum-post',
                'description' => $topic->description,
                'imageUrl' => $topic->imageUrl,
                'content' => $content,
                // Always sent, even when it's an empty list: updateFeed() only
                // rewrites metadata when the key is present (see its own
                // array_key_exists() check), so omitting it on a "remove the
                // last attachment" save would leave the old ids in place. A
                // null value is how "no attachments at all" is expressed -
                // FeedMetadataRepository::replaceForFeed() skips null entries
                // while still having wiped the previous rows, so it leaves no
                // empty placeholder row behind. Note that method replaces a
                // feed's metadata *wholesale*: safe here only because
                // attachment_upload_ids is the single key a topic ever carries
                // (see handleTopicCreateRequest()'s own write) - anything else
                // added later has to be round-tripped through this array too.
                'metadata' => $attachments !== []
                    ? ['attachment_upload_ids' => json_encode(array_map(static fn (Upload $upload): int => $upload->id, $attachments))]
                    : ['attachment_upload_ids' => null],
            ],
            $user
        );

        $this->replaceTopicPoll($id, $input['poll'] ?? null, $user);

        $updated->canonicalUrl = $this->urlGenerator->feed($updated);

        echo Formatter::json([
            'id' => $updated->id,
            'slug' => $updated->slug,
            'title' => $updated->title,
            'canonicalUrl' => $updated->canonicalUrl,
        ]);
    }

    /**
     * The title/content rules for a topic, shared by
     * handleTopicCreateRequest() and handleTopicUpdateRequest() so the two
     * can't drift - creating a topic the edit form would then reject (or the
     * reverse) is exactly the kind of asymmetry a second inline copy of these
     * four checks invites.
     *
     * Doesn't cover `forumId`, `subscribe`, `poll` or `attachments`: each of
     * those means something different (or nothing at all) depending on the
     * verb - see handleTopicUpdateRequest()'s own docblock for which ones an
     * edit deliberately ignores - so folding them in here would just move the
     * per-endpoint branching one level down.
     *
     * @param array<string, mixed> $input
     * @return array{title: string, content: string}
     *
     * @throws ValidationException
     */
    private function validateTopicInput(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new ValidationException($this->tm->trans('forums.topic_title_required'));
        }
        if (mb_strlen($title) > 200) {
            throw new ValidationException($this->tm->trans('forums.topic_title_too_long'));
        }

        $content = trim((string) ($input['content'] ?? ''));

        // Same Trix-always-serializes-something check as
        // BlogPostService::createBlogPost() - Trix's (possibly empty)
        // document still comes through as e.g. "<div><br></div>", so a plain
        // empty-string check no longer works; a figure-only (image) post is
        // still real content.
        if (trim(strip_tags($content)) === '' && ! str_contains($content, '<figure')) {
            throw new ValidationException($this->tm->trans('forums.topic_content_required'));
        }

        return ['title' => $title, 'content' => $content];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonBody(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);

        return is_array($input) ? $input : [];
    }

    /**
     * Applies forums.topic-edit's poll card to a topic that may already have
     * a poll. There's no "update a poll in place" primitive (PollService has
     * createPoll()/deletePoll(), no updatePoll()) and deliberately so: option
     * *identity* is what feed_poll_votes rows point at, so an in-place edit
     * would have to answer "is this reworded option still the one 12 people
     * chose?" - a question with no good answer. Delete-and-recreate sidesteps
     * it entirely, and PollService::deletePoll()'s own votersCount guard is
     * what makes that safe: this is only ever reachable while nobody has
     * voted.
     *
     * So, by case:
     * - no poll yet: plain attachTopicPoll(), same as creating a topic.
     * - poll exists with votes: the whole `poll` field is ignored, no matter
     *   what the client sent. The form already renders that card read-only
     *   (buildTopicFormViewModel()'s own pollLocked), so this is the
     *   server-side half of the same rule rather than the primary surface -
     *   but it's the half that actually holds, for a client that ignores the
     *   disabled inputs.
     * - poll exists without votes, `poll` absent from the payload: removed.
     *   That's a save with the poll card collapsed, and it's the only way the
     *   UI offers to take a poll off a topic.
     * - poll exists without votes, `poll` present and usable: dropped and
     *   recreated.
     * - poll exists without votes, `poll` present but *unusable* (blank
     *   question, fewer than 2 distinct options): left alone. Deleting first
     *   and only then discovering the replacement can't be built would destroy
     *   a real poll and still report a successful save - which is exactly why
     *   normalizePollInput() is a separate, side-effect-free step run before
     *   the delete rather than folded into the recreate.
     *
     * Two settings survive the delete/recreate round trip because they have no
     * control in the form and so can't be re-derived from the payload:
     * allowRevote and resultsVisibility are copied off the existing poll
     * (nothing but this form creates polls today, so in practice they're always
     * false/VISIBILITY_ALWAYS - but reading them beats silently resetting a
     * poll made some other way).
     *
     * closesAt is preserved too, whenever the author didn't touch the
     * "Poll duration" select: recomputing it from durationDays would
     * quietly push a 7-day poll's deadline out by another 7 days on every
     * unrelated save (a title fix would extend the vote). A changed select -
     * or an end date that has already lapsed, which createPoll() would reject
     * outright - does get a fresh window measured from now.
     *
     * Failures are logged and swallowed, same posture as attachTopicPoll()
     * itself (see its docblock): the topic's own title/content write has
     * already committed by the time this runs, so throwing here would report
     * a failed save that actually half-succeeded.
     */
    private function replaceTopicPoll(int $topicId, mixed $pollInput, User $user): void
    {
        try {
            $existing = $this->pollService->getPollForFeed($topicId, $user);
        } catch (Throwable $e) {
            error_log($e->getMessage());

            return;
        }

        if ($existing === null) {
            $this->attachTopicPoll($topicId, $pollInput, $user);

            return;
        }

        if ($existing->votersCount > 0) {
            return;
        }

        $normalized = $this->normalizePollInput($pollInput);

        // A poll was sent but can't be built into one - keep what's already
        // there rather than trading it for nothing.
        if ($normalized === null && $pollInput !== null) {
            error_log('Ignoring unusable poll payload for topic '.$topicId.' - existing poll kept');

            return;
        }

        $closesAt = $this->resolvePollClosesAtForEdit($existing, $normalized['durationDays'] ?? 0);

        try {
            $this->pollService->deletePoll($existing->id, $user);
        } catch (Throwable $e) {
            error_log($e->getMessage());

            return;
        }

        // Intentional removal - the poll card was collapsed on save.
        if ($normalized === null) {
            return;
        }

        $this->createTopicPoll(
            topicId: $topicId,
            poll: $normalized,
            user: $user,
            allowRevote: $existing->allowRevote,
            resultsVisibility: $existing->resultsVisibility,
            closesAt: $closesAt,
        );
    }

    /**
     * The end date a recreated poll should carry: the original one when the
     * author left the duration select alone, a fresh window from now when they
     * changed it (or when the original has already lapsed, which
     * PollService::createPoll() rejects as 'poll.closes_at_in_past').
     *
     * "Left it alone" is decided by comparing against buildPollFormState()'s
     * own recovered value - the same number the form was rendered with - rather
     * than by any explicit "unchanged" flag from the client, so it holds even
     * for a client that just echoes back whatever it was given.
     */
    private function resolvePollClosesAtForEdit(Poll $existing, int $submittedDurationDays): ?int
    {
        $renderedDurationDays = $this->buildPollFormState($existing)['durationDays'];

        if ($submittedDurationDays !== $renderedDurationDays) {
            return $this->resolvePollClosesAt($submittedDurationDays);
        }

        if ($existing->closesAt !== null && $existing->closesAt <= time()) {
            return $this->resolvePollClosesAt($submittedDurationDays);
        }

        return $existing->closesAt;
    }

    /**
     * Slugifies $title and appends a numeric suffix until the result is
     * free among the selected forum's own topics. Topic URLs include the
     * forum slug, and resolveTopicForPage() resolves that forum first, so the
     * same topic slug is valid in two different forums.
     *
     * RESERVED_FORUM_TOPIC_SLUGS is folded into the same loop for the same
     * reason BlogPostService::RESERVED_BLOG_POST_SLUGS is (see that
     * constant's own docblock and this class's copy of it): 'new' is
     * forums.topic-new's own pattern, a static sibling - under the same
     * forums.topic-list {slug} parent - of every forum's own dynamic
     * topic-view page, and Router::resolve() matches static routes before
     * dynamic ones.
     */
    private function uniqueTopicSlug(int $forumId, string $title, User $user): string
    {
        $maxLength = FeedService::MAX_SLUG_LENGTH;
        $base = Formatter::slugify($title, '-');

        if ($base === '') {
            $base = 'topic';
        }

        $base = mb_substr($base, 0, $maxLength);

        $candidate = $base;
        $suffix = 1;

        while (
            in_array($candidate, self::RESERVED_FORUM_TOPIC_SLUGS, true)
            || $this->feedRepository->findByParentAndSlug($forumId, $candidate, $user, 'forum-post') !== null
        ) {
            $suffix++;
            $suffixPart = '-'.$suffix;
            $candidate = mb_substr($base, 0, $maxLength - mb_strlen($suffixPart)).$suffixPart;
        }

        return $candidate;
    }

    /**
     * Resolves a topic through the forum segment matched by its ancestor
     * forums.topic-list page. Router stores each dynamic segment in that
     * page's own params, so repeated {slug} placeholders do not lose the
     * forum slug here.
     */
    private function resolveTopicForPage(Page $page, string $topicSlug): ?Feed
    {
        $ancestors = $this->pageTree->ancestors($page);
        array_pop($ancestors);
        foreach (array_reverse($ancestors) as $current) {
            if ($current->action !== 'forums.topic-list') {
                continue;
            }

            $forumSlug = trim((string) ($current->params['slug'] ?? ''));
            if ($forumSlug === '') {
                return null;
            }

            $forum = $this->feedService->getFeedByTypeAndSlug('forum', $forumSlug, $this->context->user);
            if ($forum === null) {
                return null;
            }

            return $this->feedService->getFeedByParentAndSlug(
                $forum->id,
                $topicSlug,
                $this->context->user,
                'forum-post'
            );
        }

        return null;
    }

    /**
     * Attaches a real poll to a brand-new topic via the generic
     * Service\PollService::createPoll() - the same already-built,
     * previously-uncalled machinery in migrations/20260912000000_initial.sql
     * .sql's own docblock anticipated ("forum topics are the first caller").
     * $pollInput is forums.topic-form.twig's own poll card, sent as a plain
     * sub-object of the topic-create payload (or omitted/null if the author
     * never opened the poll card - see forums.ts's own initTopicForm()).
     *
     * Only maps the two settings the form actually exposes: `multiple`
     * (the "Allow multiple choices" switch - maxChoices becomes
     * every option, i.e. no real cap beyond "all of them", when checked,
     * else the classic single-choice 1) and `durationDays` (the poll-duration
     * select - 0/absent means no end date). allowRevote stays
     * false and resultsVisibility stays Poll::VISIBILITY_ALWAYS - neither
     * has a control in the form. A third switch ("Show individual votes")
     * was in the mockup but was removed rather than left as a
     * disabled stub: PollService/feed_polls has no per-voter-identity
     * visibility concept at all today, only resultsVisibility (*when*
     * results show, not *whose* vote is whose), so the switch had no real
     * setting to eventually wire up.
     *
     * A poll problem (too few real options, question left blank after
     * trimming, etc.) never fails the whole request - by this point the
     * topic itself already exists, so (same "don't turn an already-
     * successful write into a 500" posture as notifyTopicFollowers()'s own
     * try/catch) this logs and silently skips rather than throws: the
     * author ends up with a topic and no poll, not a failed submission that
     * risks a second, duplicate topic on retry. forums.ts's own
     * initTopicForm() is expected to validate the poll card client-side
     * before submitting at all (question filled in, at least 2 non-empty
     * options) - this is a defensive backstop, not the primary validation
     * surface.
     */
    private function attachTopicPoll(int $topicId, mixed $pollInput, User $user): void
    {
        $normalized = $this->normalizePollInput($pollInput);

        if ($normalized === null) {
            return;
        }

        $this->createTopicPoll(
            topicId: $topicId,
            poll: $normalized,
            user: $user,
            allowRevote: false,
            resultsVisibility: Poll::VISIBILITY_ALWAYS,
            closesAt: $this->resolvePollClosesAt($normalized['durationDays']),
        );
    }

    /**
     * The poll card's raw payload, checked and reshaped into createPoll()'s
     * own vocabulary - or null for "there's no usable poll here", which every
     * caller treats as "don't create one".
     *
     * Extracted out of attachTopicPoll() specifically so replaceTopicPoll()
     * can tell "no poll wanted" from "a poll was sent but it's unusable"
     * *before* deleting the existing one. Getting that order wrong was a real
     * data-loss bug: delete-then-fail-to-recreate silently destroys a poll and
     * still reports a successful save.
     *
     * The checks mirror the ones PollService::createPoll() would throw on, for
     * the cases forums.ts can plausibly send: question present, at least 2
     * non-empty options, and no duplicates among them (createPoll()'s
     * 'poll.duplicate_options' - nothing validates that client-side, so it's
     * the most likely way a well-meaning submission gets rejected). Anything
     * createPoll() still rejects beyond these is a backstop, not an expected
     * path - see createTopicPoll()'s own note on why it can't be allowed to
     * take the existing poll down with it either.
     *
     * @return array{question: string, options: string[], maxChoices: int, durationDays: int}|null
     */
    private function normalizePollInput(mixed $pollInput): ?array
    {
        if (! is_array($pollInput)) {
            return null;
        }

        $question = trim((string) ($pollInput['question'] ?? ''));
        $rawOptions = is_array($pollInput['options'] ?? null) ? $pollInput['options'] : [];

        $options = array_values(array_filter(
            array_map(static fn ($option): string => trim((string) $option), $rawOptions),
            static fn (string $option): bool => $option !== ''
        ));

        if ($question === '' || count($options) < 2) {
            return null;
        }

        if (count(array_unique($options)) !== count($options)) {
            return null;
        }

        // The remaining three rules createPoll() enforces. The form's own
        // maxlength/MAX_POLL_OPTIONS already keep a well-behaved client inside
        // them, but "well-behaved client" is exactly the assumption this method
        // exists not to make: anything createPoll() would throw on has to read
        // as "unusable" *here*, or replaceTopicPoll() deletes a real poll and
        // then discovers it can't build the replacement.
        if (mb_strlen($question) > self::MAX_POLL_QUESTION_LENGTH) {
            return null;
        }

        if (count($options) > self::MAX_POLL_OPTIONS) {
            return null;
        }

        foreach ($options as $option) {
            if (mb_strlen($option) > self::MAX_POLL_OPTION_LENGTH) {
                return null;
            }
        }

        $days = is_numeric($pollInput['durationDays'] ?? null) ? (int) $pollInput['durationDays'] : 0;

        return [
            'question' => $question,
            'options' => $options,
            'maxChoices' => ! empty($pollInput['multiple']) ? count($options) : 1,
            // Clamped to the values the form actually offers, the same set
            // buildPollFormState() reads back - both so an arbitrary number
            // can't reach resolvePollClosesAt()'s own `$days * 86400`
            // (which overflows to float for a large enough int, violating its
            // `: ?int` return type under strict_types and throwing a TypeError
            // *after* the topic's own write has already committed), and so the
            // "did the author change the duration?" comparison in
            // resolvePollClosesAtForEdit() compares like with like.
            'durationDays' => in_array($days, self::POLL_DURATION_DAYS, true) ? $days : 0,
        ];
    }

    /**
     * The one PollService::createPoll() call site, shared by the create and
     * edit paths. Logs and swallows rather than throwing, for the reason
     * attachTopicPoll()'s docblock gives (the topic itself is already written
     * by this point).
     *
     * That swallowing is also why replaceTopicPoll() must never rely on this
     * succeeding to preserve anything: by the time it's reached, the old poll
     * is already gone.
     *
     * @param array{question: string, options: string[], maxChoices: int, durationDays: int} $poll
     */
    private function createTopicPoll(
        int $topicId,
        array $poll,
        User $user,
        bool $allowRevote,
        string $resultsVisibility,
        ?int $closesAt,
    ): void {
        // VISIBILITY_AFTER_CLOSE is meaningless (and rejected by createPoll())
        // without an end date - only reachable when preserving the settings of
        // an existing poll whose own closes_at has since lapsed, and cheaper to
        // downgrade here than to let createPoll() throw and lose the poll.
        if ($resultsVisibility === Poll::VISIBILITY_AFTER_CLOSE && $closesAt === null) {
            $resultsVisibility = Poll::VISIBILITY_ALWAYS;
        }

        try {
            $this->pollService->createPoll(
                feedId: $topicId,
                question: $poll['question'],
                options: $poll['options'],
                maxChoices: $poll['maxChoices'],
                allowRevote: $allowRevote,
                resultsVisibility: $resultsVisibility,
                closesAt: $closesAt,
                user: $user,
            );
        } catch (Throwable $e) {
            error_log($e->getMessage());
        }
    }

    /**
     * `durationDays` -> `closesAt`: a positive day count becomes
     * time() + that many days, anything else (0, absent, non-numeric -
     * forums.topic-form.twig's own "No limit" option) means an
     * open-ended poll, same as PollService::createPoll() already treats a
     * null $closesAt.
     */
    private function resolvePollClosesAt(mixed $durationDays): ?int
    {
        $days = is_numeric($durationDays) ? (int) $durationDays : 0;

        return $days > 0 ? time() + $days * 86400 : null;
    }

    /**
     * Validates forums.topic-new's own "Attachments" card into a list of real,
     * owned uploads - each id must already be a file the current user
     * uploaded through the generic /api/v1/uploads endpoint (forums.ts's own
     * initTopicForm(), same call blog-post-form.js's cover-image/track
     * widgets make), verified the same ownership-checked way
     * BlogPostService::createBlogPost() verifies its own single
     * $trackUploadId: UploadService::findOwnedUpload(). No mime-prefix
     * filter here (unlike that audio-only check) - forums.topic-new allows
     * images *and* PDFs, so the prefix check would have to accept two
     * different prefixes; UploadService::ALLOWED_MIME already rejected
     * anything else at upload time, so re-checking mime here would just be
     * re-deriving what upload time already enforced.
     *
     * A bogus, foreign, or duplicate id is silently dropped rather than
     * failing the whole submission - same "a secondary field's problem
     * shouldn't block the primary write" posture as attachTopicPoll(), and
     * the count is capped at MAX_TOPIC_ATTACHMENTS regardless of how many
     * ids the client sent, so a request that bypasses forums.ts's own
     * client-side "no more than 5 files" cap can't store more than the form
     * claims to allow either.
     *
     * $alreadyAttachedIds is the edit path's exception to the ownership check
     * (handleTopicUpdateRequest() passes the topic's currently-stored ids):
     * those resolve through the *unscoped* UploadService::findById() instead,
     * for the same reason buildAttachmentRows() reads them that way - they
     * were ownership-checked once at upload time, and they belong to the
     * topic's author, not necessarily to whoever is editing. Without this an
     * admin or moderator saving someone else's topic would silently detach
     * every one of its files, since findOwnedUpload() has no admin bypass:
     * the form faithfully sends the existing ids back, and every one of them
     * would fail the "is this yours?" test. Ids *not* in this list are new in
     * this request and still have to be the editor's own uploads.
     *
     * @param int[] $alreadyAttachedIds
     * @return Upload[]
     */
    private function resolveTopicAttachments(mixed $rawIds, User $user, array $alreadyAttachedIds = []): array
    {
        if (! is_array($rawIds)) {
            return [];
        }

        $trusted = array_fill_keys($alreadyAttachedIds, true);

        $uploads = [];
        $seenIds = [];

        foreach ($rawIds as $rawId) {
            if (count($uploads) >= self::MAX_TOPIC_ATTACHMENTS) {
                break;
            }

            $id = (int) $rawId;
            if ($id <= 0 || isset($seenIds[$id])) {
                continue;
            }
            $seenIds[$id] = true;

            $upload = isset($trusted[$id])
                ? $this->uploadService->findById($id)
                : $this->uploadService->findOwnedUpload($id, $user);

            if ($upload !== null) {
                $uploads[] = $upload;
            }
        }

        return $uploads;
    }

    /**
     * The upload ids currently stored on a topic - the read side of
     * handleTopicCreateRequest()/handleTopicUpdateRequest()'s own
     * 'attachment_upload_ids' metadata write, as plain ints. Shared by
     * buildAttachmentRows() (which resolves them to display rows) and
     * handleTopicUpdateRequest() (which passes them to
     * resolveTopicAttachments() as the already-owned set).
     *
     * @return int[]
     */
    private function storedAttachmentIds(Feed $feed): array
    {
        $raw = $feed->metadata['attachment_upload_ids'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $ids = json_decode($raw, true);
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($id): int => (int) $id, $ids),
            static fn (int $id): bool => $id > 0
        ));
    }

    /**
     * Turns a topic's own stored attachment ids (see handleTopicCreateRequest()'s
     * own metadata: ['attachment_upload_ids' => ...] write) back into rows
     * forums.topic-view.twig can render - one per file, with a real URL,
     * its original filename, a human-readable size, and an icon picked from
     * its mime (image vs. everything else, since PDF is the only non-image
     * type forums.topic-new's own upload card allows).
     *
     * Resolves each id through UploadService::findById() - the *unscoped*
     * lookup, not findOwnedUpload() - deliberately: any reader of a public
     * topic needs to see its attachments, not just the topic's own author.
     * The ids themselves were already ownership-checked once, at upload
     * time (resolveTopicAttachments()); this is just resolving them back to
     * a row to render. A since-deleted upload row (shouldn't happen today -
     * nothing deletes an upload once created - but findById() returning
     * null costs nothing to guard against) is silently skipped rather than
     * breaking the whole topic's render.
     *
     * Each row carries its own upload id as well as its display fields:
     * forums.topic-view ignores it, but forums.topic-edit's own form needs it
     * to send the still-wanted attachments back on save (see
     * buildTopicFormViewModel()'s own note, and handleTopicUpdateRequest()'s
     * "a PATCH's `attachments` is the complete new set" contract).
     *
     * @return array<int, array{id: int, url: string, name: string, sizeLabel: string, icon: string}>
     */
    private function buildAttachmentRows(Feed $feed): array
    {
        $rows = [];

        foreach ($this->storedAttachmentIds($feed) as $id) {
            $upload = $this->uploadService->findById($id);
            if ($upload === null) {
                continue;
            }

            $rows[] = [
                'id' => $upload->id,
                'url' => '/uploads/'.$upload->path,
                'name' => $upload->originalName,
                'sizeLabel' => $this->formatFileSize($upload->size),
                'icon' => str_starts_with($upload->mime, 'image/') ? 'bi-image' : 'bi-file-earmark-pdf',
            ];
        }

        return $rows;
    }

    /**
     * Same three-tier file-size formatting as forums.ts's bytesToLabel()
     * - kept in sync manually since one's PHP and the other's TS, same
     * trade-off blog-post-form.js's own bytesToLabel() docblock already
     * accepts for its file-size labels.
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1).$this->tm->trans('unit.megabyte_short');
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024).$this->tm->trans('unit.kilobyte_short');
        }

        return $bytes.$this->tm->trans('unit.byte_short');
    }

    /**
     * "Follow"'s other half: whoever favorited $topicId gets a system
     * message about the new reply, through the shared NotificationService -
     * the same orchestrator Modules\Users\FriendService/UserService use for
     * their own system notifications. Silently does
     * nothing if nobody favorited the topic (the common case) or if the
     * topic itself can't be resolved (shouldn't happen -
     * handleTopicReplyRequest() just created a comment under this exact id
     * via FeedService, so the parent necessarily exists - but this method
     * takes just the id, not the Feed FeedService already had in hand, so it
     * re-resolves defensively rather than assume).
     *
     * Deliberately swallows a failure while queueing any one follower. No
     * messenger or SMTP I/O happens here anymore, but a database failure for
     * one recipient still must not turn an already-created reply into a 500
     * or prevent the remaining followers from being queued.
     */
    private function notifyTopicFollowers(int $topicId, User $author): void
    {
        $followerIds = $this->favoriteRepository->findUserIdsForFeed($topicId);
        if ($followerIds === []) {
            return;
        }

        $topic = $this->feedRepository->findById($topicId, $author);
        if (!$topic) {
            return;
        }

        $topicUrl = $this->urlGenerator->feed($topic);
        $title = (string) $topic->title;

        $message = $this->tm->trans('forums.new_reply_notification', [
            'name' => $author->getDisplayName(),
            'title' => $title,
        ]);

        if ($topicUrl !== null) {
            $message .= ' <a href="'.htmlspecialchars($topicUrl, ENT_QUOTES).'">'
                .htmlspecialchars($title, ENT_QUOTES).'</a>';
        }

        foreach ($followerIds as $followerId) {
            if ($followerId === $author->id) {
                // Replying to your own followed topic shouldn't notify
                // yourself.
                continue;
            }

            try {
                $this->notifications->notify(new Notification(
                    recipientUserId: $followerId,
                    type: 'forum.reply',
                    messengerText: $message,
                    payload: [
                        'topicId' => $topicId,
                        'topicTitle' => $title,
                        'topicUrl' => $topicUrl,
                        'actorUserId' => $author->id,
                        'actorName' => $author->getDisplayName(),
                    ],
                ));
            } catch (RuntimeException $e) {
                error_log($e->getMessage());
            }
        }
    }
}

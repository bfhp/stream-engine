<?php

namespace StreamEngine\Modules\Users;

use DateTimeImmutable;
use Exception;
use StreamEngine\Core\AbstractController;
use StreamEngine\Core\Cron\CronRegistry;
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
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Repository\FeedMetadataRepository;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\FeedTermRepository;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Repository\UploadRepository;
use StreamEngine\Service\AuthService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\UploadService;
use StreamEngine\Service\UserService;
use StreamEngine\View\Breadcrumb;
use StreamEngine\View\ViewModel;
use Throwable;

class UsersController extends AbstractController
{
    public static function feedTypes(): array
    {
        return [
            'blog' => 'Блог',
            'blog-post' => 'Запись',
            'community' => 'Сообщество',
        ];
    }

    /**
     * How many blog posts the profile page's own feed shows per "page" -
     * both the page's own first-render batch (buildBlogFeedViewData()) and
     * each "Load more" click (handleUserPostsRequest()) load this many
     * at a time.
     */
    private const int BLOG_POSTS_PER_PAGE = 4;

    /**
     * Average adult reading speed (words/minute), used to turn a blog
     * post's word count into the feed card's "N min" estimate.
     */
    private const int WORDS_PER_MINUTE = 150;

    /**
     * How many mutual friends the profile page's own friends list shows
     * per "page" - both the page's own first-render batch
     * (buildFriendsViewData()) and each "Load more" click
     * (handleUserFriendsRequest()) load this many at a time. Same pattern
     * as BLOG_POSTS_PER_PAGE, just a smaller number since friend cards are
     * a compact avatar+name row rather than a full post card.
     */
    private const int FRIENDS_PER_PAGE = 12;

    /**
     * How many blog posts the community page's site-wide feed shows per
     * "page" - both the page's own first-render batch
     * (buildCommunityFeedViewData()) and each "Load more" click
     * (handleCommunityPostsRequest()) load this many at a time. Same
     * AJAX-append pattern as BLOG_POSTS_PER_PAGE on the profile page.
     */
    private const int COMMUNITY_POSTS_PER_PAGE = 10;

    /**
     * How many tags the community page's tag cloud sidebar widget shows,
     * ranked by how many blog posts carry them (see buildCommunityTagCloud()).
     */
    private const int COMMUNITY_TAG_CLOUD_LIMIT = 30;

    /**
     * Font-size range (rem) buildCommunityTagCloud() maps a tag's post
     * count onto - the least-used tag among the fetched batch renders at
     * MIN, the most-used at MAX, everything else scaled linearly between
     * (classic weighted tag-cloud look, no count number shown next to the
     * word - see that method's own docblock).
     */
    private const float COMMUNITY_TAG_CLOUD_MIN_FONT_REM = 0.85;

    private const float COMMUNITY_TAG_CLOUD_MAX_FONT_REM = 1.9;

    /**
     * How many members a single community page's own "Members" sidebar
     * widget shows per "page" - both its first-render batch
     * (buildCommunityMembersViewData()) and each "Load more" click
     * (handleCommunityMembersRequest()) load this many at a time. Same
     * AJAX-append pattern as FRIENDS_PER_PAGE.
     */
    private const int COMMUNITY_MEMBERS_PER_PAGE = 12;

    /**
     * How many communities each of the community page's own "New
     * communities"/"Popular communities" sidebar widgets shows - see
     * buildNewCommunitiesWidget()/buildPopularCommunitiesWidget(). No
     * "Load more" here (unlike COMMUNITY_MEMBERS_PER_PAGE etc.) - these
     * are small teaser lists, not paginated feeds.
     */
    private const int COMMUNITY_WIDGET_LIST_LIMIT = 5;

    /**
     * First cut for music playlists: enough for a useful page-level player
     * without making the API shape depend on offset pagination before the
     * frontend needs it.
     */
    private const int PLAYLIST_POST_LIMIT = 100;

    /**
     * Short "My communities" sidebar widget on the site-wide community feed.
     * The full, searchable list lives in Profile's "Subscriptions" tab; here the
     * goal is just quick access to the user's most relevant communities.
     */
    private const int MY_COMMUNITIES_WIDGET_LIMIT = 5;

    /**
     * How many rows the community.manage page's own "Members" tab shows
     * per list (members and, separately, pending subscribers) - no
     * "Load more" here yet (see showCommunityManagePage()'s own
     * docblock), just a single generous cap.
     */
    private const int COMMUNITY_MANAGE_LIST_LIMIT = 200;

    /**
     * BlogPostService/FriendService are only ever used from inside this
     * module (see their own docblocks), so - per docs/MODULE_CONTRACT.md -
     * they're built right below in the constructor body rather than
     * declared as constructor-injected types, which would force
     * StreamEngine's global bootstrap (or its ControllerFactory) to know
     * about a module-private class. They stay plain properties, not
     * promoted params.
     */
    private readonly BlogPostService $blogPostService;

    private readonly FriendService $friendService;

    private readonly CommunityService $communityService;

    private readonly MembershipRepository $memberships;

    public function __construct(
        PdoDatabase $db,
        RequestContext $context,
        private readonly AuthService $authService,
        private readonly UserService $userService,
        private readonly TranslationManager $tm,
        private readonly UrlGenerator $urlGenerator,
        private readonly UploadService $uploadService,
        private readonly FeedService $feedService,
        private readonly PageTree $pageTree,
        private readonly Formatter $formatter,
        private readonly NotificationService $notificationService,
    ) {
        parent::__construct($db, $context);

        // FeedRepository/MembershipRepository/FeedTermRepository are
        // stateless query wrappers over PdoDatabase (see Repository/* -
        // no per-instance caching), so building fresh ones here rather
        // than sharing StreamEngine's own instances is equivalent.
        // NotificationService is a shared platform service (Core registers
        // it because UserService needs it too), so it's autowired here and
        // shared by friendship and blog-publication notifications.
        $feedRepository = new FeedRepository($db);
        $this->memberships = new MembershipRepository($db);

        $this->blogPostService = new BlogPostService(
            $this->feedService,
            $feedRepository,
            $this->urlGenerator,
            $this->tm,
            new FeedTermRepository($db),
        );

        $this->friendService = new FriendService(
            $this->blogPostService,
            $feedRepository,
            $this->memberships,
            $this->notificationService,
            $this->tm,
            $this->pageTree,
            $this->urlGenerator,
        );

        $this->communityService = new CommunityService(
            $this->feedService,
            $feedRepository,
            $this->memberships,
            $this->tm,
            $this->pageTree,
            $this->urlGenerator,
            $this->notificationService,
        );
    }

    public static function pageActions(): array
    {
        return [
            'users.list' => 'Users list page',
            'user.show' => 'User profile page',
            'user.register' => 'User registration page',
            'user.retrieve' => 'Password retrieval page',
            'user.post-new' => 'New blog post page',
            'user.post-show' => 'Blog post page',
            'user.post-edit' => 'Blog post edit page',
            'community.main' => 'Community page',
            'community.create' => 'Create community page',
            'community.show' => 'Single community page',
            'community.post-new' => 'New community post page',
            'community.post-show' => 'Community post page',
            'community.post-edit' => 'Community post edit page',
            'community.manage' => 'Community manage page',
        ];
    }

    public function getBreadcrumb(Page $page): ?Breadcrumb
    {
        if ($page->action === 'user.show') {
            if (key_exists('username', $page->params)) {
                $profileUser = $this->userService->findPublicUserByUsername($page->params['username']);
                return new Breadcrumb($profileUser->nick, $profileUser->username);
            }
        }

        // Same idea as 'user.post-show' below - community.show's own
        // pageName ("Community page") isn't fit to show in the trail,
        // the crumb should read as the community's own title.
        if ($page->action === 'community.show' && key_exists('slug', $page->params)) {
            $community = $this->feedService->getFeedByParentAndSlug(
                null,
                $page->params['slug'],
                $this->context->user,
                'community'
            );

            if ($community && $community->type === 'community') {
                return new Breadcrumb($community->title, $community->slug);
            }
        }

        // Same idea as ArticleController::getBreadcrumb(): the page row's
        // own pageName/pattern ("Blog post page"/"{slug}") aren't
        // fit to show in the trail - the crumb should read as the post's own
        // title, and its slug segment needs to be real so
        // BreadcrumbsService::finalize() builds a working path.
        if ($page->action === 'user.post-show' && key_exists('slug', $page->params)) {
            $post = $this->resolveBlogPostByRoute($page, $page->params['slug'], $this->context->user);

            if ($post && $post->type === 'blog-post') {
                return new Breadcrumb($post->title, $post->slug);
            }
        }

        // Same idea as 'user.post-show' above, for a post viewed at its
        // community.post-show URL instead. $page->params here is this one
        // page's own local regex match (see Router::resolve() - each
        // breadcrumb Page keeps its own $pageParams, not the route's
        // aggregate $params), so it's the post's own slug even though
        // community.show's own {slug} segment (the community's slug,
        // matched one level up) uses the exact same placeholder name.
        if ($page->action === 'community.post-show' && key_exists('slug', $page->params)) {
            $post = $this->resolveBlogPostByRoute($page, $page->params['slug'], $this->context->user);

            if ($post && $post->type === 'blog-post') {
                return new Breadcrumb($post->title, $post->slug);
            }
        }

        return parent::getBreadcrumb($page);
    }


    /**
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function show(Page $page, array $args = []): ?ViewModel
    {
        return match ($page->action) {
            'users.list' => $this->showUsersListPage($page),
            'user.show' => $this->showUserPage($page, $args),
            'user.register' => $this->showRegisterPage($page),
            'user.retrieve' => $this->showRetrievePage($page),
            'user.post-new' => $this->showBlogPostFormPage($page, $args),
            'user.post-show' => $this->showBlogPostPage($page, $args),
            'user.post-edit' => $this->showBlogPostEditPage($page, $args),
            'community.main' => $this->showCommunityPage($page),
            'community.create' => $this->showCommunityCreatePage($page),
            'community.show' => $this->showCommunityShowPage($page, $args),
            'community.post-new' => $this->showCommunityPostFormPage($page, $args),
            'community.post-show' => $this->showCommunityPostPage($page, $args),
            'community.post-edit' => $this->showCommunityPostEditPage($page, $args),
            'community.manage' => $this->showCommunityManagePage($page, $args),
            default => throw new ForbiddenException('Unknown page action'),
        };
    }

    private function showUsersListPage(Page $page): ViewModel
    {
        $filters = $this->userService->normalizePublicUsersListFilters($this->context->query->all());
        $canonicalUrl = $this->urlGenerator->page($page);
        $currentPage = $this->urlGenerator->pageNumber($this->context->query);
        if ($currentPage > 1 && $currentPage > $this->userService->getPublicUsersTotalPages($filters['q'])) {
            throw new NotFoundException('Page not found');
        }
        $canonicalFilters = array_diff_assoc($filters, ['q' => '', 'sort' => 'registered', 'direction' => 'desc']);

        return ViewModel::fromPage(
            $page,
            'modules/users/list.twig',
            [
                'title' => $page->pageName ?: $this->tm->trans('user.list_title'),
                'description' => $page->pageName ?: $this->tm->trans('user.list_title'),
                'canonical' => $this->urlGenerator->listing($canonicalUrl, $currentPage, $canonicalFilters),
                'listUrl' => $canonicalUrl,
                'head_ext' => [
                    '<script type="module" src="/assets/js/users.js" defer></script>',
                ],
                'apiUrl' => '/api/v1/users',
                'filters' => $filters,
            ]
        );
    }

    private function getUsersListPayload(): array
    {
        $payload = $this->userService->getPublicUsersListPayload($this->context->query->all());
        $payload['data'] = $this->buildPublicUserCards($payload['data']);

        return $payload;
    }

    /**
     * Adds a profile `url` to each row of the public users list, same
     * pageTree/urlGenerator lookup as buildFriendCards() - kept as its own
     * method (rather than folded into UserService) since page routing is a
     * controller-layer concern, not a data-layer one.
     *
     * @param list<array{id:int, displayName:string, username:?string, avatarUrl:string, createdAt:int}> $users
     * @return list<array{id:int, displayName:string, username:?string, avatarUrl:string, createdAt:int, url:?string}>
     */
    private function buildPublicUserCards(array $users): array
    {
        if ($users === []) {
            return [];
        }

        $showPage = $this->pageTree->findByAction('user.show');

        return array_map(function (array $user) use ($showPage): array {
            $user['url'] = $showPage !== null && $user['username']
                ? $this->urlGenerator->page($showPage, ['username' => $user['username']])
                : null;

            return $user;
        }, $users);
    }

    /**
     * @throws NotFoundException
     */
    private function showUserPage(Page $page, array $args): ViewModel
    {
        $username = trim((string) ($args['username'] ?? ''));
        $profileUser = $username !== '' ? $this->userService->findPublicUserByUsername($username) : null;

        if (! $profileUser) {
            throw new NotFoundException($this->tm->trans('user.not_found'));
        }

        $viewer = $this->context->user;
        $isOwnProfile = ! $viewer->isGuest() && $viewer->id === $profileUser->id;
        $friendsData = $this->buildFriendsViewData($profileUser, $viewer, $isOwnProfile);
        $privacyData = $this->buildProfilePrivacyViewData(
            $profileUser,
            $isOwnProfile,
            $friendsData['relationshipStatus']
        );

        $isOnline = $privacyData['canViewProfilePresence']
            ? $this->userService->isOnline($profileUser->id)
            : false;

        $canonicalUrl = $this->urlGenerator->page($page, ['username' => $username]);
        $blogFeed = $this->resolvePersonalBlogFeed($profileUser, $viewer);
        $blogFeedData = $this->buildBlogFeedViewData($profileUser, $viewer, $isOwnProfile, $blogFeed);
        $audioPlaylistData = $this->buildUserAudioPlaylistViewData($blogFeed, $viewer);
        $profileUserAvatarUrl = $this->userService->resolveAvatarUrl($profileUser->avatarUrl);

        return ViewModel::fromPage(
            $page,
            'modules/users/show.twig',
            [
                'title' => $profileUser->getDisplayName(),
                'description' => $profileUser->bio !== '' ? $profileUser->bio : $profileUser->getDisplayName(),
                'image' => $profileUserAvatarUrl,
                'canonical' => $canonicalUrl,
                'profileUser' => $profileUser,
                'profileUserAvatarUrl' => $profileUserAvatarUrl,
                'profileBirthDateLabel' => $this->formatProfileBirthDate($profileUser),
                'isOwnProfile' => $isOwnProfile,
                'isOnline' => $isOnline,
                'head_ext' => [
                    '<script type="module" src="/assets/js/users.js" defer></script>',
                ],
                ...$blogFeedData,
                ...$audioPlaylistData,
                ...$this->buildProfileStatsViewData($profileUser, $viewer, $blogFeed),
                ...$friendsData,
                ...$privacyData,
            ]
        );
    }

    private function resolvePersonalBlogFeed(User $profileUser, User $viewer): ?Feed
    {
        return $this->feedService->getFeedByOwnerAndType($profileUser->id, 'blog', $viewer);
    }

    /**
     * Builds the profile page's blog feed: the profile owner's own first
     * page of blog-post cards, an API URL + next offset for the
     * "Load more" button to fetch more from client-side (see
     * handleUserPostsRequest() and users.ts's initBlogFeedLoadMore() -
     * appending cards to the DOM this way, rather than a full page
     * reload, needs the initial batch and every subsequent one to share
     * the exact same card shape, which is why buildPostCards() is a
     * separate method both this and handleUserPostsRequest() call), plus
     * the "write a post" entry points (own profile only).
     *
     * @return array{
     *     posts: list<array{
     *         title: ?string, url: ?string, imageUrl: ?string, dateLabel: ?string,
     *         readTimeLabel: string, excerpt: string, tags: string[],
     *         commentCount: int, ratingAverage: float, ratingCount: int
     *     }>,
     *     hasPosts: bool, isEmpty: bool, postsApiUrl: string,
     *     nextOffset: ?int, newPostUrl: ?string
     * }
     */
    private function buildBlogFeedViewData(User $profileUser, User $viewer, bool $isOwnProfile, ?Feed $blogFeed): array
    {
        $perPage = self::BLOG_POSTS_PER_PAGE;

        $postsPage = $blogFeed !== null
            ? $this->feedService->getFeedsByParentAndTypePage($blogFeed->id, 'blog-post', $viewer, $perPage)
            : ['items' => [], 'total' => 0];
        $postCards = $this->buildPostCards($postsPage['items']);
        $total = $postsPage['total'];

        $nextOffset = $total > count($postCards) ? count($postCards) : null;

        $newPostUrl = null;
        if ($isOwnProfile) {
            $newPostPage = $this->pageTree->findByAction('user.post-new');
            if ($newPostPage !== null) {
                $newPostUrl = $this->urlGenerator->page($newPostPage, ['username' => $profileUser->username]);
            }
        }

        return [
            'posts' => $postCards,
            'hasPosts' => $postCards !== [],
            'isEmpty' => $postCards === [],
            'postsApiUrl' => '/api/v1/users/'.rawurlencode($profileUser->username).'/blog-posts',
            'nextOffset' => $nextOffset,
            'newPostUrl' => $newPostUrl,
        ];
    }

    /**
     * First sidebar player pass: render from the same playlist shape the API
     * returns, but without making the browser fetch it yet. Once the player
     * becomes global/sticky, this can swap to the API endpoint without
     * changing the client-side item contract.
     *
     * @return array{audioPlaylist:list<array<string,mixed>>}
     */
    private function buildUserAudioPlaylistViewData(?Feed $blogFeed, User $viewer): array
    {
        $postsPage = $blogFeed !== null
            ? $this->feedService->getFeedsByParentAndTypePage(
                $blogFeed->id,
                'blog-post',
                $viewer,
                self::PLAYLIST_POST_LIMIT,
            )
            : ['items' => [], 'total' => 0];

        return [
            'audioPlaylist' => $this->buildPlaylistItems($postsPage['items']),
        ];
    }

    /**
     * @return array{audioPlaylist:list<array<string,mixed>>}
     */
    private function buildCommunityAudioPlaylistViewData(Feed $community, User $viewer): array
    {
        return [
            'audioPlaylist' => $this->buildPlaylistItems($this->feedService->getFeedsByParentAndType(
                $community->id,
                'blog-post',
                $viewer,
                self::PLAYLIST_POST_LIMIT,
            )),
        ];
    }

    /**
     * Decorates a page of blog-post Feeds into the plain card arrays both
     * the profile page's first render (buildBlogFeedViewData()) and its
     * "Load more" API endpoint (handleUserPostsRequest()) need -
     * kept in one place so a later change to a card's shape can't
     * accidentally drift between the two.
     *
     * The profile feed is parent-scoped to the user's personal blog feed, not
     * owner-scoped: posts the same user writes inside communities belong on
     * community.show only.
     *
     * @param Feed[] $posts
     * @return list<array{
     *     title: ?string, url: ?string, imageUrl: ?string, dateLabel: ?string,
     *     readTimeLabel: string, excerpt: string, tags: string[],
     *     commentCount: int, ratingAverage: float, ratingCount: int,
     *     audioTrackId: ?string
     * }>
     */
    private function buildPostCards(array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        $postIds = array_map(static fn (Feed $post): int => $post->id, $posts);

        $termRepository = new FeedTermRepository($this->db);
        $tagsByFeed = $termRepository->findByFeedIdsAndVocabulary($postIds, 'tag');

        $commentCounts = $this->feedService->countCommentsForFeeds($postIds);
        $playlistItemsByPostId = $this->buildPlaylistItemsByPostId($posts);

        return array_map(
            function (Feed $post) use ($tagsByFeed, $commentCounts, $playlistItemsByPostId): array {
                return [
                    'title' => $post->title,
                    'url' => $post->canonicalUrl,
                    'imageUrl' => $post->imageUrl,
                    'dateLabel' => $post->createdAt !== null
                        ? $this->formatter->date(new DateTimeImmutable('@'.$post->createdAt))
                        : null,
                    'readTimeLabel' => $this->estimateReadingTime((string) $post->content),
                    'excerpt' => $this->buildExcerpt($post->description !== '' && $post->description !== null ? $post->description : (string) $post->content),
                    'tags' => array_map(static fn ($term): string => $term->name, $tagsByFeed[$post->id] ?? []),
                    'commentCount' => $commentCounts[$post->id] ?? 0,
                    'ratingAverage' => $post->ratingAverage(),
                    'ratingCount' => $post->ratingCount,
                    'audioTrackId' => $playlistItemsByPostId[$post->id]['id'] ?? null,
                ];
            },
            $posts
        );
    }

    /**
     * GET /api/v1/users/{username}/blog-posts?offset= - the profile
     * page's "Load more" button. Same card shape (buildPostCards()) and
     * ACL scoping (FeedService::getFeedsByParentAndTypePage() on the
     * user's personal blog feed) as the page's own first-render batch,
     * just offset further into the same ordered list.
     *
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleUserPostsRequest(string $username): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            throw new ValidationException('Method not allowed', 405);
        }

        $username = trim($username);
        $profileUser = $username !== '' ? $this->userService->findPublicUserByUsername($username) : null;

        if (! $profileUser) {
            throw new NotFoundException($this->tm->trans('user.not_found'));
        }

        $offset = max(0, $this->context->query->int('offset'));
        $limit = self::BLOG_POSTS_PER_PAGE;

        $blogFeed = $this->resolvePersonalBlogFeed($profileUser, $this->context->user);
        $postsPage = $blogFeed !== null
            ? $this->feedService->getFeedsByParentAndTypePage($blogFeed->id, 'blog-post', $this->context->user, $limit, $offset)
            : ['items' => [], 'total' => 0];
        $items = $this->buildPostCards($postsPage['items']);

        $nextOffset = $postsPage['total'] > $offset + count($items) ? $offset + count($items) : null;

        echo Formatter::json([
            'items' => $items,
            'meta' => [
                'total' => $postsPage['total'],
                'nextOffset' => $nextOffset,
            ],
        ]);
    }

    /**
     * GET /api/v1/users/{username}/playlist - audio tracks attached to the
     * same blog-post feed the public profile page shows for that user.
     *
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleUserPlaylistRequest(string $username): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            throw new ValidationException('Method not allowed', 405);
        }

        $username = trim($username);
        $profileUser = $username !== '' ? $this->userService->findPublicUserByUsername($username) : null;

        if (! $profileUser) {
            throw new NotFoundException($this->tm->trans('user.not_found'));
        }

        $blogFeed = $this->resolvePersonalBlogFeed($profileUser, $this->context->user);
        $postsPage = $blogFeed !== null
            ? $this->feedService->getFeedsByParentAndTypePage(
                $blogFeed->id,
                'blog-post',
                $this->context->user,
                self::PLAYLIST_POST_LIMIT,
            )
            : ['items' => [], 'total' => 0];
        $items = $this->buildPlaylistItems($postsPage['items']);

        echo Formatter::json([
            'items' => $items,
            'meta' => [
                'scope' => 'user',
                'ownerId' => $profileUser->id,
                'username' => $profileUser->username,
                'postLimit' => self::PLAYLIST_POST_LIMIT,
                'postTotal' => $postsPage['total'],
                'trackCount' => count($items),
            ],
        ]);
    }

    /**
     * GET /api/v1/community/blog-posts?offset=&tag= - the community page's
     * "Load more" button (action=community.main). Same card shape
     * (buildCommunityPostCards()) and ACL scoping
     * (FeedService::getFeedsByTypePage()) as the page's own first-render
     * batch, just offset further into the same ordered (optionally
     * tag-filtered) feed - see handleUserPostsRequest() for the profile
     * page's equivalent.
     *
     * @throws ValidationException
     */
    private function handleCommunityPostsRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            throw new ValidationException('Method not allowed', 405);
        }

        $tagSlug = $this->context->query->trimmed('tag');
        $tagSlug = $tagSlug !== '' ? $tagSlug : null;

        $offset = max(0, $this->context->query->int('offset'));
        $limit = self::COMMUNITY_POSTS_PER_PAGE;

        $postsPage = $this->feedService->getFeedsByTypePage('blog-post', $this->context->user, $limit, $offset, $tagSlug);
        $items = $this->buildCommunityPostCards($postsPage['items']);

        $nextOffset = $postsPage['total'] > $offset + count($items) ? $offset + count($items) : null;

        echo Formatter::json([
            'items' => $items,
            'meta' => [
                'total' => $postsPage['total'],
                'nextOffset' => $nextOffset,
            ],
        ]);
    }

    /**
     * GET /api/v1/communities/{id}/blog-posts?offset= - a single community
     * page's own "Load more" button. Same card shape as the site-wide
     * community feed, but scoped to posts parented directly under this
     * community.
     *
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleCommunityScopedPostsRequest(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            throw new ValidationException('Method not allowed', 405);
        }

        $community = $id > 0 ? $this->feedService->getFeedById($id, $this->context->user) : null;

        if (! $community || $community->type !== 'community') {
            throw new NotFoundException($this->tm->trans('community.not_found'));
        }

        $offset = max(0, $this->context->query->int('offset'));
        $limit = self::COMMUNITY_POSTS_PER_PAGE;

        $postsPage = $this->feedService->getFeedsByParentAndTypePage($community->id, 'blog-post', $this->context->user, $limit, $offset);
        $items = $this->buildCommunityPostCards($postsPage['items']);

        $nextOffset = $postsPage['total'] > $offset + count($items) ? $offset + count($items) : null;

        echo Formatter::json([
            'items' => $items,
            'meta' => [
                'total' => $postsPage['total'],
                'nextOffset' => $nextOffset,
            ],
        ]);
    }

    /**
     * GET /api/v1/communities/{id}/playlist - audio tracks attached to posts
     * parented directly under one community.
     *
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleCommunityPlaylistRequest(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            throw new ValidationException('Method not allowed', 405);
        }

        $viewer = $this->context->user;
        $community = $id > 0 ? $this->feedService->getFeedById($id, $viewer) : null;

        if (! $community || $community->type !== 'community') {
            throw new NotFoundException($this->tm->trans('community.not_found'));
        }

        $posts = $this->feedService->getFeedsByParentAndType(
            $community->id,
            'blog-post',
            $viewer,
            self::PLAYLIST_POST_LIMIT,
        );
        $items = $this->buildPlaylistItems($posts);

        echo Formatter::json([
            'items' => $items,
            'meta' => [
                'scope' => 'community',
                'communityId' => $community->id,
                'postLimit' => self::PLAYLIST_POST_LIMIT,
                'postTotal' => count($posts),
                'trackCount' => count($items),
            ],
        ]);
    }

    /**
     * @param Feed[] $posts
     * @return list<array{
     *     id:string,
     *     postId:int,
     *     postTitle:?string,
     *     postUrl:?string,
     *     trackUploadId:int,
     *     title:string,
     *     url:string,
     *     mime:string,
     *     size:int
     * }>
     */
    private function buildPlaylistItems(array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        $postIds = array_map(static fn (Feed $post): int => $post->id, $posts);
        $metadataByFeed = (new FeedMetadataRepository($this->db))->findByFeedIds($postIds);

        $trackUploadIdsByPostId = [];
        foreach ($posts as $post) {
            $trackUploadId = (int) ($metadataByFeed[$post->id]['track_upload_id'] ?? 0);
            if ($trackUploadId > 0) {
                $trackUploadIdsByPostId[$post->id] = $trackUploadId;
            }
        }

        if ($trackUploadIdsByPostId === []) {
            return [];
        }

        $uploadsById = (new UploadRepository($this->db))->findByIds(array_values($trackUploadIdsByPostId));

        $items = [];
        foreach ($posts as $post) {
            $trackUploadId = $trackUploadIdsByPostId[$post->id] ?? null;
            if ($trackUploadId === null) {
                continue;
            }

            $upload = $uploadsById[$trackUploadId] ?? null;
            if ($upload === null || ! str_starts_with($upload->mime, 'audio/')) {
                continue;
            }

            $items[] = [
                'id' => 'post-'.$post->id.'-track-'.$upload->id,
                'postId' => $post->id,
                'postTitle' => $post->title,
                'postUrl' => $post->canonicalUrl,
                'trackUploadId' => $upload->id,
                'title' => $upload->originalName !== '' ? $upload->originalName : (string) $post->title,
                'url' => '/uploads/'.$upload->path,
                'mime' => $upload->mime,
                'size' => $upload->size,
            ];
        }

        return $items;
    }

    /**
     * @param Feed[] $posts
     * @return array<int,array<string,mixed>>
     */
    private function buildPlaylistItemsByPostId(array $posts): array
    {
        $itemsByPostId = [];
        foreach ($this->buildPlaylistItems($posts) as $item) {
            $itemsByPostId[(int) $item['postId']] = $item;
        }

        return $itemsByPostId;
    }

    /**
     * @param list<array<string,mixed>> $audioPlaylist
     */
    private function findAudioTrackIdForPost(array $audioPlaylist, int $postId): ?string
    {
        foreach ($audioPlaylist as $item) {
            if ((int) ($item['postId'] ?? 0) === $postId && isset($item['id'])) {
                return (string) $item['id'];
            }
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $audioPlaylist
     * @return list<array<string,mixed>>
     */
    private function ensurePostInAudioPlaylist(array $audioPlaylist, Feed $post): array
    {
        if ($this->findAudioTrackIdForPost($audioPlaylist, $post->id) !== null) {
            return $audioPlaylist;
        }

        return [
            ...$this->buildPlaylistItems([$post]),
            ...$audioPlaylist,
        ];
    }

    /**
     * POST /api/v1/communities - the community.create form's submit
     * button.
     *
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleCommunityCreateRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new ValidationException('Method not allowed', 405);
        }

        $this->requireAuthenticatedUser();
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $input = json_decode(file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];

        $imageUrl = trim((string) ($input['imageUrl'] ?? ''));
        $description = array_key_exists('description', $input) ? (string) $input['description'] : null;

        $community = $this->communityService->createCommunity(
            user: $this->context->user,
            name: trim((string) ($input['name'] ?? '')),
            description: $description,
            imageUrl: $imageUrl !== '' ? $imageUrl : null,
            membershipType: (string) ($input['membershipType'] ?? CommunityService::MEMBERSHIP_TYPE_OPEN),
        );

        // CommunityService::createCommunity() already resolved the new
        // community's own canonical URL (community.show, via its real
        // slug) - the form redirects straight there. Falls back to the
        // site-wide community feed page only if community.show somehow
        // isn't registered (shouldn't happen in production, but keeps this
        // safe if it ever isn't - same defensive fallback pattern as
        // FriendService::buildProfileUrl()).
        $redirectUrl = $community->canonicalUrl;
        if ($redirectUrl === null) {
            $communityPage = $this->pageTree->findByAction('community.main');
            $redirectUrl = $communityPage !== null ? $this->urlGenerator->page($communityPage) : null;
        }

        http_response_code(201);
        echo Formatter::json([
            'id' => $community->id,
            'title' => $community->title,
            'membershipType' => $community->metadata['membership_type'] ?? null,
            'redirectUrl' => $redirectUrl,
        ]);
    }

    /**
     * GET /api/v1/communities/{id}/members?offset= - a single community
     * page's "Members" widget "Load more" button (see
     * buildCommunityMembersViewData()). Same paginated shape/reasoning as
     * handleUserFriendsRequest(), just over CommunityService::getMembers()
     * instead of FriendService::getFriends().
     *
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleCommunityMembersRequest(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            throw new ValidationException('Method not allowed', 405);
        }

        $community = $id > 0 ? $this->feedService->getFeedById($id, $this->context->user) : null;

        if (! $community || $community->type !== 'community') {
            throw new NotFoundException($this->tm->trans('community.not_found'));
        }

        $offset = max(0, $this->context->query->int('offset'));
        $limit = self::COMMUNITY_MEMBERS_PER_PAGE;

        $membersPage = $this->communityService->getMembers($community, $limit, $offset);
        $items = $this->buildCommunityMemberCards($membersPage['items']);

        $nextOffset = $membersPage['total'] > $offset + count($items) ? $offset + count($items) : null;

        echo Formatter::json([
            'items' => $items,
            'meta' => [
                'total' => $membersPage['total'],
                'nextOffset' => $nextOffset,
            ],
        ]);
    }

    /**
     * POST /api/v1/communities/{id}/membership - a community page's
     * "Join" button (CommunityService::join()): joins an open
     * community outright, or files a pending request for an approval-type
     * one. DELETE - "Leave community"/"Cancel request"
     * (CommunityService::leave()), whichever applies. Both branches
     * respond with the viewer's resulting relationship status, so the
     * button's JS can update its own label/action without a second round
     * trip - same contract as handleUserFriendRequest().
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleCommunityMembershipRequest(int $id): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method !== 'POST' && $method !== 'DELETE') {
            throw new ValidationException('Method not allowed', 405);
        }

        $user = $this->context->user;

        $this->requireAuthenticatedUser();

        $community = $id > 0 ? $this->feedService->getFeedById($id, $user) : null;

        if (! $community || $community->type !== 'community') {
            throw new NotFoundException($this->tm->trans('community.not_found'));
        }

        if ($method === 'POST') {
            $this->communityService->join($user, $community);
        } else {
            $this->communityService->leave($user, $community);
        }

        echo Formatter::json([
            'status' => $this->communityService->getRelationshipStatus($user, $community),
        ]);
    }

    /**
     * GET lists one community's own posts for AJAX pagination; POST creates
     * a post in that community. They intentionally share the REST collection
     * URL, so the route action dispatches by verb here.
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleCommunityScopedBlogPostsRequest(int $id): void
    {
        $method = $_SERVER['REQUEST_METHOD'];

        if ($method === 'GET') {
            $this->handleCommunityScopedPostsRequest($id);
            return;
        }

        if ($method === 'POST') {
            $this->handleCommunityPostCreateRequest($id);
            return;
        }

        throw new ValidationException('Method not allowed', 405);
    }

    /**
     * POST /api/v1/communities/{id}/blog-posts - the community.post-new
     * form's submit button (see showCommunityPostFormPage()). Only a real
     * member/moderator/owner may post (CommunityService::canPost()) - a
     * subscriber (pending join request) or a stranger gets a
     * ForbiddenException, same as the page itself would if it re-checked
     * membership (see showCommunityPostFormPage()'s own docblock on why the
     * page's access_rule alone can't express that rule). Everything
     * past that point is identical to handleBlogPostCreateRequest() - same
     * request-shape validation, same BlogPostService::createBlogPost() call,
     * just with $community passed as the post's parent instead of the
     * author's own personal blog.
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleCommunityPostCreateRequest(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new ValidationException('Method not allowed', 405);
        }

        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $user = $this->context->user;

        $community = $id > 0 ? $this->feedService->getFeedById($id, $user) : null;

        if (! $community || $community->type !== 'community') {
            throw new NotFoundException($this->tm->trans('community.not_found'));
        }

        if (! $this->communityService->canPost($user, $community)) {
            throw new ForbiddenException($this->tm->trans('community.post_forbidden'));
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];

        $trackUploadId = null;
        if (! empty($input['trackUploadId'])) {
            $trackUploadId = (int) $input['trackUploadId'];

            if ($this->uploadService->findOwnedUpload($trackUploadId, $user, 'audio/') === null) {
                throw new ValidationException($this->tm->trans('feed.blog_track_invalid'));
            }
        }

        $tags = array_key_exists('tags', $input) && is_array($input['tags']) ? $input['tags'] : null;
        $imageUrl = trim((string) ($input['imageUrl'] ?? ''));

        $post = $this->blogPostService->createBlogPost(
            title: trim((string) ($input['title'] ?? '')),
            content: (string) ($input['content'] ?? ''),
            description: null,
            imageUrl: $imageUrl !== '' ? $imageUrl : null,
            user: $user,
            visibility: (string) ($input['visibility'] ?? 'public'),
            tags: $tags,
            trackUploadId: $trackUploadId,
            parent: $community,
        );

        $this->notifyCommunityMembersAboutPost($post, $community, $user);

        http_response_code(201);
        echo Formatter::json([
            'id' => $post->id,
            'slug' => $post->slug,
            'title' => $post->title,
            'visibility' => $post->visibility,
            'canonicalUrl' => $post->canonicalUrl,
        ]);
    }

    /**
     * PATCH /api/v1/communities/{id}/manage - the community.manage
     * "Settings" tab's save button (CommunityService::updateSettings()).
     *
     * When switching membership_type from "approval" to "open" while
     * subscribers are still pending, this responds with
     * {needsConfirmation:true, pendingCount:N} and saves nothing - the
     * settings form's own JS shows the owner a confirmation dialog and
     * resubmits with confirmPromoteSubscribers=true once they agree (see
     * CommunityService::updateSettings()'s own docblock for the full
     * contract).
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleCommunityManageSettingsRequest(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
            throw new ValidationException('Method not allowed', 405);
        }

        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $user = $this->context->user;
        $community = $id > 0 ? $this->feedService->getFeedById($id, $user) : null;

        if (! $community || $community->type !== 'community') {
            throw new NotFoundException($this->tm->trans('community.not_found'));
        }

        if ($user->isGuest() || $user->id !== $community->ownerId) {
            throw new ForbiddenException($this->tm->trans('community.manage_forbidden'));
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];

        $imageUrl = trim((string) ($input['imageUrl'] ?? ''));
        $description = array_key_exists('description', $input) ? (string) $input['description'] : null;

        $result = $this->communityService->updateSettings(
            viewer: $user,
            community: $community,
            name: trim((string) ($input['name'] ?? '')),
            description: $description,
            imageUrl: $imageUrl !== '' ? $imageUrl : null,
            membershipType: (string) ($input['membershipType'] ?? CommunityService::MEMBERSHIP_TYPE_OPEN),
            confirmPromoteSubscribers: filter_var($input['confirmPromoteSubscribers'] ?? false, FILTER_VALIDATE_BOOLEAN),
        );

        if ($result['needsConfirmation']) {
            echo Formatter::json([
                'needsConfirmation' => true,
                'pendingCount' => $result['pendingCount'],
            ]);

            return;
        }

        echo Formatter::json([
            'needsConfirmation' => false,
            'promotedCount' => $result['promotedCount'],
            'membershipType' => $result['feed']->metadata['membership_type'] ?? null,
        ]);
    }

    /**
     * PATCH /api/v1/communities/{id}/manage/members/{userId} - the
     * community.manage "Members" tab's "Accept" button
     * (CommunityService::approveSubscriber()).
     * DELETE - its "Remove" button (CommunityService::removeMember(),
     * a demotion back to subscriber, not a hard delete - see that
     * method's own docblock on why).
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleCommunityManageMemberRequest(int $id, int $userId): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method !== 'PATCH' && $method !== 'DELETE') {
            throw new ValidationException('Method not allowed', 405);
        }

        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $user = $this->context->user;
        $community = $id > 0 ? $this->feedService->getFeedById($id, $user) : null;

        if (! $community || $community->type !== 'community') {
            throw new NotFoundException($this->tm->trans('community.not_found'));
        }

        if ($user->isGuest() || $user->id !== $community->ownerId) {
            throw new ForbiddenException($this->tm->trans('community.manage_forbidden'));
        }

        if ($userId <= 0) {
            throw new NotFoundException($this->tm->trans('user.not_found'));
        }

        if ($method === 'PATCH') {
            $this->communityService->approveSubscriber($user, $community, $userId);
        } else {
            $this->communityService->removeMember($user, $community, $userId);
        }

        echo Formatter::json(['success' => true]);
    }

    /**
     * GET /api/v1/users/{username}/friends?offset= - the profile page's
     * friends list "Load more" button. Same shape and reasoning as
     * handleUserPostsRequest(), just over FriendService::getFriends()
     * instead of the blog feed.
     *
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleUserFriendsRequest(string $username): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            throw new ValidationException('Method not allowed', 405);
        }

        $username = trim($username);
        $profileUser = $username !== '' ? $this->userService->findPublicUserByUsername($username) : null;

        if (! $profileUser) {
            throw new NotFoundException($this->tm->trans('user.not_found'));
        }

        $offset = max(0, $this->context->query->int('offset'));
        $limit = self::FRIENDS_PER_PAGE;

        $friendsPage = $this->friendService->getFriends($profileUser, $limit, $offset);
        $items = $this->buildFriendCards($friendsPage['items']);

        $nextOffset = $friendsPage['total'] > $offset + count($items) ? $offset + count($items) : null;

        echo Formatter::json([
            'items' => $items,
            'meta' => [
                'total' => $friendsPage['total'],
                'nextOffset' => $nextOffset,
            ],
        ]);
    }

    /**
     * POST /api/v1/users/{username}/friend - the profile-sidebar "Add
     * friend" button (FriendService::sendRequest(): a fresh one-
     * directional subscription, or - if $username had already subscribed
     * to the current viewer - the action that completes a mutual
     * friendship; either way it's the exact same call, see
     * FriendService's own docblock for why).
     * DELETE /api/v1/users/{username}/friend - "Unsubscribe"/"Remove
     * friend" (FriendService::removeFriend()).
     *
     * Both branches respond with the viewer's resulting relationship
     * status, so the button's JS can update its own label/action without a
     * second round trip.
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleUserFriendRequest(string $username): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method !== 'POST' && $method !== 'DELETE') {
            throw new ValidationException('Method not allowed', 405);
        }

        $this->requireAuthenticatedUser();
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $username = trim($username);
        $target = $username !== '' ? $this->userService->findPublicUserByUsername($username) : null;

        if (! $target) {
            throw new NotFoundException($this->tm->trans('user.not_found'));
        }

        $actor = $this->context->user;

        if ($method === 'POST') {
            $this->friendService->sendRequest($actor, $target);
        } else {
            $this->friendService->removeFriend($actor, $target);
        }

        echo Formatter::json([
            'status' => $this->friendService->getRelationshipStatus($actor, $target),
        ]);
    }

    /**
     * Builds the profile page sidebar's "Statistics" numbers and the
     * shared profile-sidebar.twig rating card's real values (posts,
     * comments authored, registration date, aggregate blog-post rating).
     * "Books favorited" was removed from this stat block for now (along
     * with the favorites-count query behind it) - not part of the profile
     * page's scope until product decides otherwise. All ACL-scoped the
     * same way as everything else on the profile page: a viewer only ever
     * sees counts that reflect what they themselves can access.
     *
     * @return array{
     *     postCount: int, commentCount: int,
     *     registeredAtLabel: ?string,
     *     authorRatingCount: int, authorRatingAverage: ?float,
     *     authorRatingFullStars: int, authorRatingHasHalfStar: bool,
     *     authorRatingHint: ?string
     * }
     */
    private function buildProfileStatsViewData(User $profileUser, User $viewer, ?Feed $blogFeed): array
    {
        $postCount = $blogFeed !== null
            ? $this->feedService->countFeedsByParentAndType($blogFeed->id, 'blog-post', $viewer)
            : 0;
        $commentCount = $this->feedService->countFeedsByOwnerAndType($profileUser->id, 'comment', $viewer);

        $registeredAtLabel = $profileUser->createdAt !== null
            ? $this->formatter->monthYear(new DateTimeImmutable('@'.$profileUser->createdAt))
            : null;

        $ratingTotals = $blogFeed !== null
            ? $this->feedService->getRatingTotalsByParentAndType($blogFeed->id, 'blog-post', $viewer)
            : ['sum' => 0, 'count' => 0];
        $ratingCount = $ratingTotals['count'];
        $ratingAverage = $ratingCount > 0 ? round($ratingTotals['sum'] / $ratingCount, 1) : null;
        $ratingFullStars = $ratingAverage !== null ? (int) floor($ratingAverage) : 0;
        $ratingHasHalfStar = $ratingAverage !== null && ($ratingAverage - $ratingFullStars) >= 0.5;
        $ratingUnits = $this->tm->getAll()['rating.unit'];

        $ratingHint = $ratingCount > 0
            ? $this->tm->trans('user.rating_hint', [
                'count' => $ratingCount,
                'unit' => $this->formatter->plural($ratingCount, ...$ratingUnits),
            ])
            : null;

        return [
            'postCount' => $postCount,
            'commentCount' => $commentCount,
            'registeredAtLabel' => $registeredAtLabel,
            'authorRatingCount' => $ratingCount,
            'authorRatingAverage' => $ratingAverage,
            'authorRatingFullStars' => $ratingFullStars,
            'authorRatingHasHalfStar' => $ratingHasHalfStar,
            'authorRatingHint' => $ratingHint,
        ];
    }

    private function formatProfileBirthDate(?User $profileUser): ?string
    {
        if ($profileUser === null || $profileUser->birthDate === null) {
            return null;
        }

        $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $profileUser->birthDate);

        return $birthDate !== false ? $this->formatter->date($birthDate, true) : null;
    }

    /**
     * Builds the profile page's friends list (mutual only - see
     * FriendService's own docblock for what "mutual" means here) the same
     * first-batch-plus-"Load more" shape as buildBlogFeedViewData()/
     * handleUserPostsRequest(), plus the viewer's own relationship to
     * $profileUser for profile-sidebar.twig's friend-request button.
     * relationshipStatus is null on your own profile - there's no
     * "add yourself as a friend" state, and the sidebar button is a
     * different one (edit profile) there anyway.
     *
     * @return array{
     *     friends: list<array{displayName: string, avatarUrl: string, url: ?string}>,
     *     hasFriends: bool, friendsTotal: int, friendsApiUrl: string,
     *     friendsNextOffset: ?int, friendActionUrl: string,
     *     relationshipStatus: ?string
     * }
     */
    private function buildFriendsViewData(User $profileUser, User $viewer, bool $isOwnProfile): array
    {
        $friendsPage = $this->friendService->getFriends($profileUser, self::FRIENDS_PER_PAGE);
        $friendCards = $this->buildFriendCards($friendsPage['items']);
        $total = $friendsPage['total'];

        $nextOffset = $total > count($friendCards) ? count($friendCards) : null;

        return [
            'friends' => $friendCards,
            'hasFriends' => $friendCards !== [],
            'friendsTotal' => $total,
            'friendsApiUrl' => '/api/v1/users/'.rawurlencode($profileUser->username).'/friends',
            'friendsNextOffset' => $nextOffset,
            'friendActionUrl' => '/api/v1/users/'.rawurlencode($profileUser->username).'/friend',
            'relationshipStatus' => $isOwnProfile ? null : $this->friendService->getRelationshipStatus($viewer, $profileUser),
        ];
    }

    /**
     * @return array{
     *     canViewProfileGender: bool, canViewProfileBirthDate: bool,
     *     canViewProfileHomepage: bool, canViewProfilePresence: bool
     * }
     */
    private function buildProfilePrivacyViewData(
        User $profileUser,
        bool $isOwnProfile,
        ?string $relationshipStatus
    ): array {
        $friendException = $profileUser->showHiddenProfileToFriends && $relationshipStatus === 'friends';
        $canSeeHidden = $isOwnProfile || $friendException;

        return [
            'canViewProfileGender' => $profileUser->showGenderPublicly || $canSeeHidden,
            'canViewProfileBirthDate' => $profileUser->showBirthDatePublicly || $canSeeHidden,
            'canViewProfileHomepage' => $profileUser->showHomepagePublicly || $canSeeHidden,
            'canViewProfilePresence' => ! $profileUser->hidePresence || $canSeeHidden,
        ];
    }

    /**
     * Decorates MembershipRepository::findMutualFriends()'s plain rows into
     * the shape both the page's first render (buildFriendsViewData()) and
     * its "Load more" API endpoint (handleUserFriendsRequest()) need -
     * same one-place-to-avoid-drift reasoning as buildPostCards().
     *
     * @param list<array{id:int, nick:string, username:?string, avatarUrl:string}> $friends
     * @return list<array{displayName: string, avatarUrl: string, url: ?string}>
     */
    private function buildFriendCards(array $friends): array
    {
        if ($friends === []) {
            return [];
        }

        $showPage = $this->pageTree->findByAction('user.show');

        return array_map(function (array $friend) use ($showPage): array {
            $url = $showPage !== null && $friend['username']
                ? $this->urlGenerator->page($showPage, ['username' => $friend['username']])
                : null;

            return [
                'displayName' => $friend['nick'] !== '' ? $friend['nick'] : '#'.$friend['id'],
                'avatarUrl' => $this->resolveAvatarUrl($friend['avatarUrl']),
                'url' => $url,
            ];
        }, $friends);
    }

    /**
     * Builds a single community page's own first post batch: posts parented
     * directly under that community plus the API URL and next offset for
     * users.ts's load-more wiring.
     *
     * @return array{
     *     posts: list<array{
     *         title: ?string, url: ?string, imageUrl: ?string, dateLabel: ?string,
     *         readTimeLabel: string, excerpt: string, tags: string[],
     *         commentCount: int, ratingAverage: float, ratingCount: int,
     *         authorName: string, authorAvatarUrl: string, authorUrl: ?string
     *     }>,
     *     hasPosts: bool, isEmpty: bool, postsApiUrl: string, nextOffset: ?int
     * }
     */
    private function buildCommunityShowFeedViewData(Feed $community, User $viewer): array
    {
        $perPage = self::COMMUNITY_POSTS_PER_PAGE;

        $postsPage = $this->feedService->getFeedsByParentAndTypePage($community->id, 'blog-post', $viewer, $perPage);
        $postCards = $this->buildCommunityPostCards($postsPage['items']);
        $total = $postsPage['total'];

        $nextOffset = $total > count($postCards) ? count($postCards) : null;

        return [
            'posts' => $postCards,
            'hasPosts' => $postCards !== [],
            'isEmpty' => $postCards === [],
            'postsApiUrl' => '/api/v1/communities/'.$community->id.'/blog-posts',
            'nextOffset' => $nextOffset,
        ];
    }

    /**
     * action=community.main: the site-wide feed of every blog-post feed the
     * viewer can see (ACL-filtered by FeedRepository::findByTypePage(), same
     * as every other listing here), optionally narrowed to one tag via
     * ?tag=slug.
     *
     * @throws ValidationException
     */
    private function showCommunityPage(Page $page): ViewModel
    {
        $viewer = $this->context->user;

        $tagSlug = $this->context->query->trimmed('tag');
        $tagSlug = $tagSlug !== '' ? $tagSlug : null;

        $newPostUrl = null;
        $createCommunityUrl = null;
        if (! $viewer->isGuest()) {
            $newPostPage = $this->pageTree->findByAction('user.post-new');
            if ($newPostPage !== null) {
                $newPostUrl = $this->urlGenerator->page($newPostPage, ['username' => $viewer->username]);
            }

            // "Create community" sidebar button - now that
            // community.create actually exists (see
            // showCommunityCreatePage()), community.twig no longer needs to
            // fall back to its disabled stub.
            $createCommunityPage = $this->pageTree->findByAction('community.create');
            if ($createCommunityPage !== null) {
                $createCommunityUrl = $this->urlGenerator->page($createCommunityPage);
            }
        }

        $canonicalUrl = $this->urlGenerator->page($page);

        return ViewModel::fromPage(
            $page,
            'modules/users/community.twig',
            [
                'title' => $page->pageName ?: $this->tm->trans('community.page_title'),
                'description' => $this->tm->trans('community.page_description'),
                'canonical' => $this->urlGenerator->listing($canonicalUrl, 1, $tagSlug !== null ? ['tag' => $tagSlug] : []),
                'head_ext' => [
                    '<script type="module" src="/assets/js/users.js" defer></script>',
                ],
                'communityUrl' => $canonicalUrl,
                'activeTag' => $tagSlug,
                'isGuest' => $viewer->isGuest(),
                'newPostUrl' => $newPostUrl,
                'createCommunityUrl' => $createCommunityUrl,
                'tagCloud' => $this->buildCommunityTagCloud($canonicalUrl, $viewer),
                'myCommunities' => $this->buildMyCommunitiesWidget($viewer),
                'newCommunities' => $this->buildNewCommunitiesWidget($viewer),
                'popularCommunities' => $this->buildPopularCommunitiesWidget(),
                ...$this->buildCommunityFeedViewData($viewer, $tagSlug),
            ]
        );
    }

    /**
     * action=community.create: the "create a community" form. Mounted
     * as a child of the community feed page (community.main) and gated to
     * logged-in users only via the page row's own access_rule (see
     * the initial page configuration and
     * AccessService::canAccessPage()) - guests never reach this method at
     * all, same mechanism as user.post-new.
     */
    private function showCommunityCreatePage(Page $page): ViewModel
    {
        $canonical = $this->urlGenerator->page($page);
        // Same "ancestor pattern doesn't fill in" guard
        // showBlogPostFormPage() uses - low stakes either way since this
        // page is access_rule-gated and changefreq='noindex'.
        $canonical = str_contains($canonical, '{') ? null : $canonical;

        $cancelUrl = null;
        $communityPage = $this->pageTree->findByAction('community.main');
        if ($communityPage !== null) {
            $cancelUrl = $this->urlGenerator->page($communityPage);
        }

        $headExt = [
            // initCommunityCreateForm() now lives in users.ts/users.js -
            // this page no longer has its own dedicated JS entry (see
            // vite.config.ts), same bundle every other users.* page loads.
            '<script type="module" src="/assets/js/users.js" defer></script>',
        ];

        return ViewModel::fromPage(
            $page,
            'modules/users/community-create.twig',
            [
                'title' => $page->pageName ?: $this->tm->trans('community.create_title'),
                'description' => $this->tm->trans('community.create_description'),
                'canonical' => $canonical,
                'head_ext' => $headExt,
                'apiUrl' => '/api/v1/communities',
                'uploadsApiUrl' => '/api/v1/uploads',
                'cancelUrl' => $cancelUrl,
                'membershipTypeOpen' => CommunityService::MEMBERSHIP_TYPE_OPEN,
                'membershipTypeApproval' => CommunityService::MEMBERSHIP_TYPE_APPROVAL,
            ]
        );
    }

    /**
     * action=community.show: a single community's own page - mounted at
     * pages.action 'community.show' ({slug} under community.main, feed_type
     * 'community'). A literal feedId pinned on the page row wins; otherwise
     * the slug is resolved explicitly in the top-level community namespace.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function showCommunityShowPage(Page $page, array $args): ViewModel
    {
        $viewer = $this->context->user;
        $community = $this->resolveCommunityFeed($page, $args, $viewer);

        $owner = $this->userService->findPublicUserById($community->ownerId);

        $ownerUrl = null;
        if ($owner !== null && $owner->username) {
            $userShowPage = $this->pageTree->findByAction('user.show');
            if ($userShowPage !== null) {
                $ownerUrl = $this->urlGenerator->page($userShowPage, ['username' => $owner->username]);
            }
        }

        $canonicalUrl = $community->slug !== null
            ? $this->urlGenerator->page($page, ['slug' => $community->slug])
            : $community->canonicalUrl;

        // A community itself is never rated directly - only its own posts
        // are (see components/users/community-sidebar.twig's own note) -
        // so the sidebar's "Rating" card shows the aggregate across this
        // community's blog-post children instead of a per-viewer vote on
        // the community feed. Possibly revisited later.
        $ratingViewData = $this->buildCommunityRatingViewData($community, $viewer);

        $audioPlaylistData = $this->buildCommunityAudioPlaylistViewData($community, $viewer);

        // "Post to community" sidebar entry point - only for a real
        // member/moderator/owner (CommunityService::canPost()), same
        // membership gate handleCommunityPostCreateRequest() itself
        // enforces on the actual write.
        $newPostUrl = null;
        if ($this->communityService->canPost($viewer, $community)) {
            $newPostPage = $this->pageTree->findByAction('community.post-new');
            if ($newPostPage !== null && $community->slug !== null) {
                $newPostUrl = $this->urlGenerator->page($newPostPage, ['slug' => $community->slug]);
            }
        }

        // The sidebar's "Manage" action (community-sidebar.twig's own
        // community_manage_stub block) - owner-only, same restriction
        // showCommunityManagePage() itself enforces, so a moderator (who
        // used to see this stub too, back when it was just a disabled
        // placeholder) no longer gets a button that would 403 if clicked.
        $manageUrl = null;
        if (! $viewer->isGuest() && $viewer->id === $community->ownerId) {
            $managePage = $this->pageTree->findByAction('community.manage');
            if ($managePage !== null && $community->slug !== null) {
                $manageUrl = $this->urlGenerator->page($managePage, ['slug' => $community->slug]);
            }
        }

        return ViewModel::fromPage(
            $page,
            'modules/users/community-show.twig',
            [
                'title' => $community->title,
                'description' => $community->description !== '' && $community->description !== null
                    ? $community->description
                    : $community->title,
                'image' => $community->imageUrl,
                'canonical' => $canonicalUrl,
                'community' => $community,
                'communityImageUrl' => $this->communityService->resolveImageUrl($community),
                'owner' => $owner,
                'ownerUrl' => $ownerUrl,
                'isOwner' => ! $viewer->isGuest() && $viewer->id === $community->ownerId,
                'relationshipStatus' => $this->communityService->getRelationshipStatus($viewer, $community),
                'membershipActionUrl' => '/api/v1/communities/'.$community->id.'/membership',
                'manageUrl' => $manageUrl,
                ...$audioPlaylistData,
                'newPostUrl' => $newPostUrl,
                ...$this->buildCommunityShowFeedViewData($community, $viewer),
                ...$ratingViewData,
                ...$this->buildCommunityStatsViewData($community, $viewer),
                ...$this->buildCommunityMembersViewData($community),
                'head_ext' => [
                    '<script type="module" src="/assets/js/users.js" defer></script>',
                ],
            ]
        );
    }

    /**
     * @throws NotFoundException
     */
    private function resolveCommunityFeed(Page $page, array $args, User $viewer): Feed
    {
        if ($page->feedId) {
            $community = $this->feedService->getFeedById($page->feedId, $viewer);
        } else {
            $slug = trim((string) ($args['slug'] ?? ''));
            if ($slug === '') {
                throw new NotFoundException($this->tm->trans('community.not_found'));
            }

            $community = $this->feedService->getFeedByParentAndSlug(null, $slug, $viewer, 'community');
        }

        if (! $community || $community->type !== 'community') {
            throw new NotFoundException($this->tm->trans('community.not_found'));
        }

        return $community;
    }

    /**
     * action=community.manage: the community owner's own settings +
     * member-moderation page - mounted as a child of a single community's
     * own page (action 'community.show', {slug}), same {slug}-inherited-
     * from-parent nesting as community.post-new (see
     * resolveCommunityFeed()'s own use above). Owner-only: the page row's
     * own access_rule only ever gates "must be logged in" (see
     * AccessService::canAccessPage()'s own docblock, quoted on this
     * class's other community.* pages too), so the actual "must be *this*
     * community's owner" rule is this explicit id check - a moderator
     * (who canPost()/canEditFeed() would otherwise treat almost like an
     * owner elsewhere in this module) is deliberately NOT let in here, per
     * this feature's own spec ("Owner access only").
     *
     * No pagination on either list yet (same known first-cut trade-off
     * showCommunityShowPage()'s own post list already accepts) - a
     * community with a very large membership/queue would need a "Load
     * more" action here too eventually.
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    private function showCommunityManagePage(Page $page, array $args): ViewModel
    {
        $viewer = $this->context->user;
        $community = $this->resolveCommunityFeed($page, $args, $viewer);

        if ($viewer->isGuest() || $viewer->id !== $community->ownerId) {
            throw new ForbiddenException($this->tm->trans('community.manage_forbidden'));
        }

        $communityShowPage = $this->pageTree->findByAction('community.show');
        $cancelUrl = $communityShowPage !== null && $community->slug !== null
            ? $this->urlGenerator->page($communityShowPage, ['slug' => $community->slug])
            : null;

        $membersPage = $this->communityService->getMembers($community, self::COMMUNITY_MANAGE_LIST_LIMIT);
        $subscribersPage = $this->communityService->getSubscribers($community, self::COMMUNITY_MANAGE_LIST_LIMIT);

        $tabs = [
            ['id' => 'settings', 'label' => $this->tm->trans('community.manage_settings'), 'template' => 'components/users/community-manage/settings.twig'],
            ['id' => 'members', 'label' => $this->tm->trans('community.manage_members'), 'template' => 'components/users/community-manage/members.twig'],
        ];

        return ViewModel::fromPage(
            $page,
            'modules/users/community-manage.twig',
            [
                'title' => $this->tm->trans('community.manage_title', ['title' => $community->title]),
                'description' => $this->tm->trans('community.manage_description', ['title' => $community->title]),
                'community' => $community,
                'communityImageUrl' => $this->communityService->resolveImageUrl($community),
                'cancelUrl' => $cancelUrl,
                'tabs' => $tabs,
                'settingsApiUrl' => '/api/v1/communities/'.$community->id.'/manage',
                'uploadsApiUrl' => '/api/v1/uploads',
                'membershipTypeOpen' => CommunityService::MEMBERSHIP_TYPE_OPEN,
                'membershipTypeApproval' => CommunityService::MEMBERSHIP_TYPE_APPROVAL,
                'membershipType' => (string) ($community->metadata['membership_type'] ?? CommunityService::MEMBERSHIP_TYPE_OPEN),
                'members' => $this->buildCommunityManageMemberCards($membersPage['items']),
                'membersTotal' => $membersPage['total'],
                'subscribers' => $this->buildCommunityManageMemberCards($subscribersPage['items']),
                'subscribersTotal' => $subscribersPage['total'],
                'memberActionUrlBase' => '/api/v1/communities/'.$community->id.'/manage/members/',
                'head_ext' => [
                    '<script type="module" src="/assets/js/users.js" defer></script>',
                ],
            ]
        );
    }

    /**
     * action=community.post-new: the "write a post into this community"
     * editor - mounted as a child of a single community's own page
     * (action 'community.show', {slug}), same nesting community.post-new's
     * own pattern ('post') mirrors user.post-new's under user.show
     * ({username}). Reuses showBlogPostFormPage()'s exact view/template
     * (modules/users/blog-post-form.twig) - only the API target and cancel
     * URL differ, the form itself has no community-specific fields.
     *
     * Only a real member/moderator/owner may post - a subscriber (pending
     * join request on an approval-type community) may not, same
     * CommunityService::canPost() gate handleCommunityPostCreateRequest()
     * repeats on the actual write, since the page's own access_rule only
     * ever gates "must be logged in", not "must be a member of this
     * particular community" (see AccessService::canAccessPage()'s own
     * docblock).
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    private function showCommunityPostFormPage(Page $page, array $args): ViewModel
    {
        $viewer = $this->context->user;
        $community = $this->resolveCommunityFeed($page, $args, $viewer);

        if (! $this->communityService->canPost($viewer, $community)) {
            throw new ForbiddenException($this->tm->trans('community.post_forbidden'));
        }

        $canonical = $community->slug !== null
            ? $this->urlGenerator->page($page, ['slug' => $community->slug])
            : null;

        $cancelUrl = null;
        $communityShowPage = $this->pageTree->findByAction('community.show');
        if ($communityShowPage !== null && $community->slug !== null) {
            $cancelUrl = $this->urlGenerator->page($communityShowPage, ['slug' => $community->slug]);
        }

        // Same Trix bundle (and its cover-image drop-zone styling) as
        // showBlogPostFormPage()/showCommunityCreatePage() - the form
        // itself (blog-post-form.twig) and its JS (blog-post-form.js) are
        // entirely generic over where the post ends up, so nothing new is
        // needed here beyond pointing apiUrl at this community.
        //
        // trix.css: see showBlogPostFormPage()'s own note on why this has to
        // be linked explicitly (Vite splits it into its own shared chunk
        // now that Modules\Forums's forums.js also imports it).
        $headExt = [
            '<link rel="stylesheet" href="/assets/css/trix.css">',
            '<script type="module" src="/assets/js/blog-post-form.js" defer></script>',
        ];

        return ViewModel::fromPage(
            $page,
            'modules/users/blog-post-form.twig',
            [
                'title' => $page->pageName ?: $this->tm->trans('community.new_post'),
                'description' => $this->tm->trans('community.new_post_title', ['title' => $community->title]),
                'canonical' => $canonical,
                'head_ext' => $headExt,
                'apiUrl' => '/api/v1/communities/'.$community->id.'/blog-posts',
                'uploadsApiUrl' => '/api/v1/uploads',
                'cancelUrl' => $cancelUrl,
                ...$this->buildUploadStorageViewData($viewer),
                // blog-post-form.twig/js's only community-aware bit - swaps
                // the 'members' visibility option's label/note from "Friends
                // only" to "Members only" (see BlogPostService::
                // createBlogPost()'s $parent/$containerId wiring, which is
                // what actually makes that restriction work for a community
                // post instead of degrading to owner/admin-only).
                'isCommunityPost' => true,
            ]
        );
    }

    /**
     * Builds the community sidebar's "Rating" card: the aggregate rating
     * across this community's own posts (type=blog-post, parent_id=
     * $community->id), NOT a vote on the community feed itself - a
     * community/blog container is never something a viewer rates
     * directly (product decision, possibly revisited later; see
     * components/users/community-sidebar.twig's own note). Same full/
     * half-star math as buildProfileStatsViewData()'s author rating card,
     * just parent-scoped instead of owner-scoped since a community's
     * posts come from many different owners.
     *
     * @return array{
     *     ratingCount: int, ratingAverage: ?float,
     *     ratingFullStars: int, ratingHasHalfStar: bool, ratingHint: ?string
     * }
     */
    private function buildCommunityRatingViewData(Feed $community, User $viewer): array
    {
        $ratingTotals = $this->feedService->getRatingTotalsByParentAndType($community->id, 'blog-post', $viewer);
        $ratingCount = $ratingTotals['count'];
        $ratingAverage = $ratingCount > 0 ? round($ratingTotals['sum'] / $ratingCount, 1) : null;
        $ratingFullStars = $ratingAverage !== null ? (int) floor($ratingAverage) : 0;
        $ratingHasHalfStar = $ratingAverage !== null && ($ratingAverage - $ratingFullStars) >= 0.5;
        $ratingUnits = $this->tm->getAll()['rating.unit'];

        $ratingHint = $ratingCount > 0
            ? $this->tm->trans('community.rating_hint', [
                'count' => $ratingCount,
                'unit' => $this->formatter->plural($ratingCount, ...$ratingUnits),
            ])
            : null;

        return [
            'ratingCount' => $ratingCount,
            'ratingAverage' => $ratingAverage,
            'ratingFullStars' => $ratingFullStars,
            'ratingHasHalfStar' => $ratingHasHalfStar,
            'ratingHint' => $ratingHint,
        ];
    }

    /**
     * Builds a community page's "Members" sidebar widget: its first page
     * of real members (owner/moderators/members - not pending subscribers,
     * see MembershipRepository::findMembers()'s own docblock) plus an API
     * URL + next offset for "Load more" - same first-batch-plus-AJAX-
     * append shape as buildFriendsViewData() on the profile page.
     *
     * @return array{
     *     members: list<array{displayName: string, avatarUrl: string, url: ?string, roleLabel: ?string}>,
     *     hasMembers: bool, membersTotal: int, membersApiUrl: string,
     *     membersNextOffset: ?int
     * }
     */
    private function buildCommunityMembersViewData(Feed $community): array
    {
        $perPage = self::COMMUNITY_MEMBERS_PER_PAGE;

        $membersPage = $this->communityService->getMembers($community, $perPage);
        $memberCards = $this->buildCommunityMemberCards($membersPage['items']);
        $total = $membersPage['total'];

        $nextOffset = $total > count($memberCards) ? count($memberCards) : null;

        return [
            'members' => $memberCards,
            'hasMembers' => $memberCards !== [],
            'membersTotal' => $total,
            'membersApiUrl' => '/api/v1/communities/'.$community->id.'/members',
            'membersNextOffset' => $nextOffset,
        ];
    }

    /**
     * Builds the community sidebar's "Statistics" card: post count
     * parented under this community, comment count on those posts, and
     * the community's creation date - same row set/formatting as
     * buildProfileStatsViewData() (postCount/commentCount/
     * registeredAtLabel), just container-scoped. membersTotal isn't
     * included here - buildCommunityMembersViewData() already computed
     * it (and the members list below already displays it), and product
     * decided the comment count is the more useful "activity" number for
     * this card instead (see components/users/community-sidebar.twig's
     * community_stats block).
     *
     * @return array{postCount: int, commentCount: int, createdAtLabel: ?string}
     */
    private function buildCommunityStatsViewData(Feed $community, User $viewer): array
    {
        $postCount = $this->feedService->countFeedsByParentAndType($community->id, 'blog-post', $viewer);
        $commentCount = $this->feedService->countCommentsByParentContainer($community->id, $viewer);

        $createdAtLabel = $community->createdAt !== null
            ? $this->formatter->monthYear(new DateTimeImmutable('@'.$community->createdAt))
            : null;

        return [
            'postCount' => $postCount,
            'commentCount' => $commentCount,
            'createdAtLabel' => $createdAtLabel,
        ];
    }

    /**
     * Decorates MembershipRepository::findMembers()'s plain rows into the
     * shape both the page's first render (buildCommunityMembersViewData())
     * and its "Load more" API endpoint (handleCommunityMembersRequest())
     * need - same one-place-to-avoid-drift reasoning as buildFriendCards().
     *
     * @param list<array{id:int, nick:string, username:?string, avatarUrl:string, roleLevel:int}> $members
     * @return list<array{displayName: string, avatarUrl: string, url: ?string, roleLabel: ?string}>
     */
    private function buildCommunityMemberCards(array $members): array
    {
        if ($members === []) {
            return [];
        }

        $showPage = $this->pageTree->findByAction('user.show');

        return array_map(function (array $member) use ($showPage): array {
            $url = $showPage !== null && $member['username']
                ? $this->urlGenerator->page($showPage, ['username' => $member['username']])
                : null;

            return [
                'displayName' => $member['nick'] !== '' ? $member['nick'] : '#'.$member['id'],
                'avatarUrl' => $this->resolveAvatarUrl($member['avatarUrl']),
                'url' => $url,
                'roleLabel' => match ($member['roleLevel']) {
                    3 => $this->tm->trans('community.role.owner'),
                    2 => $this->tm->trans('community.role.moderator'),
                    default => null,
                },
            ];
        }, $members);
    }

    /**
     * Decorates MembershipRepository::findMembers()/findSubscribers()'s
     * plain rows for the community.manage "Members" tab - same shape as
     * buildCommunityMemberCards() above, but keeping the raw user id (so
     * each row's "Accept"/"Remove" button can target it) and covering
     * every role level with its own label instead of leaving a plain
     * member/subscriber unlabeled, since here (unlike the public members
     * list) telling a pending subscriber apart from a real member is the
     * whole point of the tab.
     *
     * @param list<array{id:int, nick:string, username:?string, avatarUrl:string, roleLevel:int}> $rows
     * @return list<array{id:int, displayName:string, avatarUrl:string, url:?string, roleLabel:string}>
     */
    private function buildCommunityManageMemberCards(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $showPage = $this->pageTree->findByAction('user.show');

        return array_map(function (array $row) use ($showPage): array {
            $url = $showPage !== null && $row['username']
                ? $this->urlGenerator->page($showPage, ['username' => $row['username']])
                : null;

            return [
                'id' => $row['id'],
                'displayName' => $row['nick'] !== '' ? $row['nick'] : '#'.$row['id'],
                'avatarUrl' => $this->resolveAvatarUrl($row['avatarUrl']),
                'url' => $url,
                'roleLabel' => match ($row['roleLevel']) {
                    3 => $this->tm->trans('community.role.owner'),
                    2 => $this->tm->trans('community.role.moderator'),
                    1 => $this->tm->trans('community.role.member'),
                    default => $this->tm->trans('community.role.subscriber'),
                },
            ];
        }, $rows);
    }

    /**
     * A user avatar URL for display - delegates to UserService::
     * resolveAvatarUrl(), kept as a thin wrapper so buildFriendCards()/
     * buildCommunityMemberCards()/buildCommunityManageMemberCards() (each
     * working from a plain MembershipRepository row, not a User object)
     * don't each repeat the call, and so no view or client-side script
     * needs a hardcoded fallback path of its own.
     */
    private function resolveAvatarUrl(string $avatarUrl): string
    {
        return $this->userService->resolveAvatarUrl($avatarUrl);
    }

    /**
     * Builds the community page's first "Load more" batch - same
     * AJAX-append shape/reasoning as buildBlogFeedViewData() on the profile
     * page (postsApiUrl + nextOffset for users.ts's
     * initCommunityFeedLoadMore(), see handleCommunityPostsRequest() for
     * the API it calls), just site-wide instead of one owner's feed. The
     * API URL carries the active tag filter (if any) so every subsequent
     * "Load more" click stays scoped to it too.
     *
     * @return array{
     *     posts: list<array{
     *         title: ?string, url: ?string, imageUrl: ?string, dateLabel: ?string,
     *         readTimeLabel: string, excerpt: string, tags: string[],
     *         commentCount: int, ratingAverage: float, ratingCount: int,
     *         authorName: string, authorAvatarUrl: string, authorUrl: ?string
     *     }>,
     *     hasPosts: bool, isEmpty: bool, postsApiUrl: string, nextOffset: ?int
     * }
     */
    private function buildCommunityFeedViewData(User $viewer, ?string $tagSlug): array
    {
        $perPage = self::COMMUNITY_POSTS_PER_PAGE;

        $postsPage = $this->feedService->getFeedsByTypePage('blog-post', $viewer, $perPage, 0, $tagSlug);
        $postCards = $this->buildCommunityPostCards($postsPage['items']);
        $total = $postsPage['total'];

        $nextOffset = $total > count($postCards) ? count($postCards) : null;

        $postsApiUrl = '/api/v1/community/blog-posts';
        if ($tagSlug !== null) {
            $postsApiUrl .= '?tag='.rawurlencode($tagSlug);
        }

        return [
            'posts' => $postCards,
            'hasPosts' => $postCards !== [],
            'isEmpty' => $postCards === [],
            'postsApiUrl' => $postsApiUrl,
            'nextOffset' => $nextOffset,
        ];
    }

    /**
     * Decorates a page of blog-post Feeds - the site-wide community.main
     * feed (showCommunityPage(), every blog-post regardless of parent) and
     * a single community's own post list (showCommunityShowPage(), all
     * parented under that one community) both call this - into the same
     * kind of plain card array buildPostCards() builds for the profile
     * page's own feed - title/url/dateLabel/readTimeLabel/excerpt/tags/
     * commentCount/rating - plus an author byline (name/avatar/url), since
     * unlike the single-owner profile feed every card here can belong to a
     * different author. Authors are resolved in one batch
     * (UserService::findPublicUsersByIds()) rather than one
     * findPublicUserById() call per post, same N+1-avoidance reasoning as
     * every other batched decoration in this method's sibling.
     *
     * `url` is just $post->canonicalUrl - UrlGenerator::feed() already
     * builds the right one either way (community.post-show for a post
     * whose Feed::$containerId points at a community, the author's own
     * user.post-show otherwise - see UrlGenerator::buildOwnerScopedPageChain()),
     * so there's no per-post branching to do here at all.
     *
     * @param Feed[] $posts
     * @return list<array{
     *     title: ?string, url: ?string, imageUrl: ?string, dateLabel: ?string,
     *     readTimeLabel: string, excerpt: string, tags: string[],
     *     commentCount: int, ratingAverage: float, ratingCount: int,
     *     audioTrackId: ?string,
     *     authorName: string, authorAvatarUrl: string, authorUrl: ?string
     * }>
     */
    private function buildCommunityPostCards(array $posts): array
    {
        if ($posts === []) {
            return [];
        }

        $postIds = array_map(static fn (Feed $post): int => $post->id, $posts);
        $ownerIds = array_values(array_unique(array_map(static fn (Feed $post): int => $post->ownerId, $posts)));

        $authorsById = $this->userService->findPublicUsersByIds($ownerIds);

        $userShowPage = $this->pageTree->findByAction('user.show');

        $termRepository = new FeedTermRepository($this->db);
        $tagsByFeed = $termRepository->findByFeedIdsAndVocabulary($postIds, 'tag');

        $commentCounts = $this->feedService->countCommentsForFeeds($postIds);
        $playlistItemsByPostId = $this->buildPlaylistItemsByPostId($posts);

        return array_map(
            function (Feed $post) use ($authorsById, $userShowPage, $tagsByFeed, $commentCounts, $playlistItemsByPostId): array {
                $author = $authorsById[$post->ownerId] ?? null;

                return [
                    'title' => $post->title,
                    'url' => $post->canonicalUrl,
                    'imageUrl' => $post->imageUrl,
                    'dateLabel' => $post->createdAt !== null
                        ? $this->formatter->date(new DateTimeImmutable('@'.$post->createdAt))
                        : null,
                    'readTimeLabel' => $this->estimateReadingTime((string) $post->content),
                    'excerpt' => $this->buildExcerpt($post->description !== '' && $post->description !== null ? $post->description : (string) $post->content),
                    'tags' => array_map(static fn ($term): string => $term->name, $tagsByFeed[$post->id] ?? []),
                    'commentCount' => $commentCounts[$post->id] ?? 0,
                    'ratingAverage' => $post->ratingAverage(),
                    'ratingCount' => $post->ratingCount,
                    'audioTrackId' => $playlistItemsByPostId[$post->id]['id'] ?? null,
                    'authorName' => $author?->getDisplayName() ?? $post->authorDisplayName,
                    'authorAvatarUrl' => $this->userService->resolveAvatarUrl($author?->avatarUrl ?: $post->authorAvatarUrl),
                    'authorUrl' => $userShowPage !== null && $author !== null
                        ? $this->urlGenerator->page($userShowPage, ['username' => $author->username])
                        : null,
                ];
            },
            $posts
        );
    }

    /**
     * The community page sidebar's "Popular community tags" widget:
     * the top COMMUNITY_TAG_CLOUD_LIMIT tags across every blog-post the
     * viewer can see, ranked by post count (FeedService::
     * getTopTermsByVocabulary(), ACL-scoped the same as the feed itself).
     * Each tag links back to this same community page filtered to it
     * (?tag=slug) rather than FeedTerm::canonicalUrl - no page in `pages`
     * declares term_vocabulary='tag', so UrlGenerator::feedTerm() would
     * resolve to null for every tag here.
     *
     * No raw count is shown next to a tag (unlike, say, community_stats's
     * rows) - instead each tag's own post count is mapped onto a font-size
     * between COMMUNITY_TAG_CLOUD_MIN_FONT_REM and ..._MAX_FONT_REM (a
     * classic weighted tag cloud: the more a tag is used, the bigger it
     * reads), scaled linearly against the least/most-used tag in this same
     * batch. A single distinct count across the whole batch (countRange=0 -
     * e.g. every tag used exactly once) would divide by zero, so that case
     * just renders everything at the minimum size instead.
     *
     * @return list<array{name: string, slug: string, url: string, fontSize: float}>
     */
    private function buildCommunityTagCloud(string $communityUrl, User $viewer): array
    {
        $terms = $this->feedService->getTopTermsByVocabulary('tag', $viewer, 'blog-post', self::COMMUNITY_TAG_CLOUD_LIMIT);

        if ($terms === []) {
            return [];
        }

        $counts = array_map(static fn (array $row): int => $row['feedCount'], $terms);
        $minCount = min($counts);
        $countRange = max($counts) - $minCount;

        return array_map(
            function (array $row) use ($communityUrl, $minCount, $countRange): array {
                $weight = $countRange > 0 ? ($row['feedCount'] - $minCount) / $countRange : 1.0;
                $fontSize = self::COMMUNITY_TAG_CLOUD_MIN_FONT_REM
                    + $weight * (self::COMMUNITY_TAG_CLOUD_MAX_FONT_REM - self::COMMUNITY_TAG_CLOUD_MIN_FONT_REM);

                return [
                    'name' => $row['term']->name,
                    'slug' => $row['term']->slug,
                    'url' => $communityUrl.'?tag='.rawurlencode($row['term']->slug),
                    'fontSize' => round($fontSize, 2),
                ];
            },
            $terms
        );
    }

    /**
     * The community page sidebar's "New communities" widget: the
     * COMMUNITY_WIDGET_LIST_LIMIT most recently created communities the
     * viewer can see - FeedService::getFeedsByType() already orders by
     * created_at DESC/id DESC (same ACL rule as every other listing here),
     * so this just needs to shape the result for community.twig.
     *
     * @return list<array{title: string, url: ?string, imageUrl: ?string, memberCount: ?int, createdAtLabel: ?string}>
     */
    private function buildNewCommunitiesWidget(User $viewer): array
    {
        $communities = $this->feedService->getFeedsByType('community', $viewer, self::COMMUNITY_WIDGET_LIST_LIMIT);

        return $this->buildCommunityWidgetCards($communities);
    }

    /**
     * The community.main sidebar's "My communities" widget for a logged-in
     * viewer: their own community memberships, in the same owner/moderator/
     * member/pending order MembershipRepository::findUserCommunities() uses
     * for the profile "Subscriptions" tab.
     *
     * @return list<array{title: string, url: ?string, imageUrl: string, statusLabel: string, statusClass: string}>
     */
    private function buildMyCommunitiesWidget(User $viewer): array
    {
        if ($viewer->isGuest()) {
            return [];
        }

        $rows = (new MembershipRepository($this->db))
            ->findUserCommunities($viewer->id, self::MY_COMMUNITIES_WIDGET_LIMIT);

        $communityShowPage = $this->pageTree->findByAction('community.show');

        return array_map(function (array $row) use ($communityShowPage): array {
            $slug = (string) ($row['slug'] ?? '');
            $imageUrl = (string) ($row['imageUrl'] ?? '');

            return [
                'title' => (string) ($row['title'] ?? '') !== '' ? (string) $row['title'] : '#'.$row['id'],
                'url' => $communityShowPage !== null && $slug !== ''
                    ? $this->urlGenerator->page($communityShowPage, ['slug' => $slug])
                    : null,
                'imageUrl' => $imageUrl !== '' ? $imageUrl : CommunityService::DEFAULT_IMAGE_URL,
                ...$this->communityMembershipBadge((int) $row['roleLevel']),
            ];
        }, $rows);
    }

    /**
     * @return array{statusLabel: string, statusClass: string}
     */
    private function communityMembershipBadge(int $roleLevel): array
    {
        return match ($roleLevel) {
            3 => ['statusLabel' => $this->tm->trans('community.role.owner'), 'statusClass' => 'text-bg-primary'],
            2 => ['statusLabel' => $this->tm->trans('community.role.moderator'), 'statusClass' => 'text-bg-info'],
            1 => ['statusLabel' => $this->tm->trans('community.role.member'), 'statusClass' => 'text-bg-success'],
            default => ['statusLabel' => $this->tm->trans('community.role.pending'), 'statusClass' => 'text-bg-secondary'],
        };
    }

    /**
     * The community page sidebar's "Popular communities" widget: the
     * COMMUNITY_WIDGET_LIST_LIMIT communities with the most real members
     * (owner/moderators/members - excluding a pending approval-type
     * subscriber, same MembershipRepository::ROLE_SUBSCRIBER_ID exclusion
     * as MembershipRepository::countMembers()).
     *
     * Goes straight to $this->db rather than through a new
     * FeedRepository/FeedService method - this "rank communities by member
     * count" query has exactly one caller (this one sidebar widget, on
     * this one page), so a reusable repository method would just be
     * speculative generality. Skips FeedRepository::applyAcl()'s
     * membership-container branch entirely too: CommunityService::
     * createCommunity() always writes visibility='public' for a community
     * feed and nothing anywhere sets it to anything else, so filtering on
     * that column here already covers every real row - there's no private
     * community to leak.
     *
     * @return list<array{title: string, url: ?string, imageUrl: ?string, memberCount: ?int, createdAtLabel: ?string}>
     */
    private function buildPopularCommunitiesWidget(): array
    {
        $rows = $this->db->fetchAll(
            '
            SELECT
                f.slug,
                f.title,
                f.image_url,
                (
                    SELECT COUNT(*)
                    FROM memberships m
                    WHERE m.container_id = f.id
                    AND m.membership_role_id != '.MembershipRepository::ROLE_SUBSCRIBER_ID.'
                ) AS member_count
            FROM feeds f
            WHERE f.type = \'community\' AND f.visibility = \'public\'
            ORDER BY member_count DESC, f.id DESC
            LIMIT '.self::COMMUNITY_WIDGET_LIST_LIMIT
        );

        $communityShowPage = $this->pageTree->findByAction('community.show');

        return array_map(function (array $row) use ($communityShowPage): array {
            $url = $communityShowPage !== null && $row['slug']
                ? $this->urlGenerator->page($communityShowPage, ['slug' => $row['slug']])
                : null;

            return [
                'title' => $row['title'] !== null && $row['title'] !== '' ? $row['title'] : '#'.$row['slug'],
                'url' => $url,
                'imageUrl' => $row['image_url'] !== null && $row['image_url'] !== '' ? $row['image_url'] : CommunityService::DEFAULT_IMAGE_URL,
                'memberCount' => (int) $row['member_count'],
                'createdAtLabel' => null,
            ];
        }, $rows);
    }

    /**
     * Card shape for the "New communities" widget above - same
     * one-place-to-avoid-drift reasoning as buildFriendCards()/
     * buildCommunityMemberCards(). No memberCount here (community.twig
     * only shows that row for buildPopularCommunitiesWidget()'s own cards
     * above, which don't go through this helper).
     *
     * @param Feed[] $communities
     * @return list<array{title: string, url: ?string, imageUrl: ?string, memberCount: ?int, createdAtLabel: ?string}>
     */
    private function buildCommunityWidgetCards(array $communities): array
    {
        return array_map(fn (Feed $community): array => [
            'title' => $community->title ?: ('#'.$community->id),
            'url' => $community->canonicalUrl,
            'imageUrl' => $this->communityService->resolveImageUrl($community),
            'memberCount' => null,
            'createdAtLabel' => $community->createdAt !== null
                ? $this->formatter->monthYear(new DateTimeImmutable('@'.$community->createdAt))
                : null,
        ], $communities);
    }

    /**
     * A blog post card's "N min" reading-time estimate, from a plain word
     * count over the post's (HTML) content at WORDS_PER_MINUTE - the same
     * kind of rough estimate as any blog platform's, not tied to actual
     * reading telemetry.
     */
    private function estimateReadingTime(string $html): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
        $wordCount = $text === '' ? 0 : count(preg_split('/\s+/u', $text));
        $minutes = max(1, (int) ceil($wordCount / self::WORDS_PER_MINUTE));

        return $minutes.$this->tm->trans('unit.minute_short');
    }

    /**
     * A blog post card's excerpt: plain text (tags stripped, whitespace
     * collapsed) truncated to $maxLength characters. mb_substr() alone
     * would risk cutting a word in half, so this backs up to the last
     * whitespace boundary within the limit first.
     */
    private function buildExcerpt(string $html, int $maxLength = 160): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $maxLength);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated).'…';
    }

    /**
     * The "new blog post" editor. Mounted as a child of a user's own profile
     * page (parent carries the {username} param), so this only ever renders
     * for the currently logged-in user's own username segment - visiting
     * someone else's is forbidden rather than silently posting as them.
     * Guests never reach here at all: the page's access_rule already
     * keeps AccessService::canAccessPage() from calling show() for them.
     *
     * @throws ForbiddenException
     */
    private function showBlogPostFormPage(Page $page, array $args): ViewModel
    {
        $user = $this->context->user;

        $username = trim((string) ($args['username'] ?? ''));
        if ($username !== '' && strcasecmp($username, $user->username) !== 0) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        $canonical = $this->urlGenerator->page($page, ['username' => $user->username]);
        // A page whose ancestor pattern doesn't actually carry a {username}
        // segment would leave the placeholder untouched here - skip the
        // (broken) canonical tag rather than emit it. Low stakes either way:
        // the page is access_rule-gated and changefreq='noindex', so it's
        // already excluded from the sitemap.
        $canonical = str_contains($canonical, '{') ? null : $canonical;

        // "Cancel" link for the form - back to the user's own profile,
        // which doubles as their blog (user.show has list_feed_type
        // 'blog-post'). Same lookup showBlogPostEditPage() uses to point
        // its own cancel link at the post itself.
        $cancelUrl = null;
        $userShowPage = $this->pageTree->findByAction('user.show');
        if ($userShowPage !== null) {
            $cancelUrl = $this->urlGenerator->page($userShowPage, ['username' => $user->username]);
        }

        // The form's general layout uses the shared site.css, but the Trix
        // editor (and its behavior/attachment wiring) is its own bundle -
        // blog-post-form.js - so pages that only need users.js (e.g.
        // the users list) don't have to load Trix at all.
        //
        // type="module" (same as admin.js, which
        // also bundle real npm deps) matters here: without it this loads as
        // a plain classic script sharing one global scope with site.js
        // (also plain), and since Trix pulls in enough code to collide with
        // one of site.js's own minified top-level names, that throws
        // "Identifier ... has already been declared". A module gets its own
        // scope, so it can't collide with a classic script's top-level vars.
        //
        // trix.css: Trix's own vendor stylesheet (icon backgrounds for every
        // toolbar button) - since both this bundle and Modules\Forums's own
        // forums.js import "trix/dist/trix.css", Vite's CSS code-splitting
        // pulls it out into its own shared css/trix.css asset instead of
        // inlining it into either entry's own .css file (that only happened
        // - invisibly - back when blog-post-form.js was Trix's only
        // importer). Nothing auto-injects a shared chunk's stylesheet here
        // (this app has no Vite HTML-entry point, every page hand-lists its
        // own head_ext tags), so it has to be linked explicitly like every
        // other stylesheet this array already lists.
        $headExt = [
            '<link rel="stylesheet" href="/assets/css/trix.css">',
            '<script type="module" src="/assets/js/blog-post-form.js" defer></script>',
        ];

        return ViewModel::fromPage(
            $page,
            'modules/users/blog-post-form.twig',
            [
                'title' => $page->pageName ?: $this->tm->trans('blog.new_post'),
                'description' => $this->tm->trans('blog.new_post_title'),
                'canonical' => $canonical,
                'head_ext' => $headExt,
                'apiUrl' => '/api/v1/users/blog-posts',
                'uploadsApiUrl' => '/api/v1/uploads',
                'cancelUrl' => $cancelUrl,
                ...$this->buildUploadStorageViewData($user),
            ]
        );
    }

    /**
     * The blog-post editor's "edit" mode - mounted as a child of the post's
     * own {slug} page (action 'user.post-show'), so it shares that exact
     * slug segment (e.g. /blog/{slug}/edit/) instead of needing its own
     * {id} routing. resolvePostForEdit() below mirrors showBlogPostPage()'s
     * own "prefer a literal feedId pinned on the page row, else look the
     * slug up" logic - only it reads that feedId off the *parent* page
     * (the {slug} row), since this page's own row has none.
     *
     * Edit stays author-only here even though delete doesn't (see
     * blog-post.show.twig's own note on that split, and
     * showCommunityPostEditPage() below for the community equivalent that
     * intentionally follows canDelete's broader policy instead) - a
     * personal blog has no moderators to extend it to anyway.
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function showBlogPostEditPage(Page $page, array $args): ViewModel
    {
        $user = $this->context->user;
        $post = $this->resolvePostForEdit($page, $args, $user);

        if ($post->ownerId !== $user->id) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        return $this->buildPostEditViewModel($page, $post, $user);
    }

    /**
     * The community-post editor's "edit" mode - mounted as a child of
     * community.post-show's own {slug} page, same shape as user.post-edit
     * above. Permission here intentionally follows $canDelete's own policy
     * (owner, admin, or this community's moderators - see
     * FeedService::canEditFeed(), the same check showBlogPostPage()/
     * showCommunityPostPage() use to gate their delete button) rather than
     * showBlogPostEditPage()'s author-only rule: a community's moderators
     * are expected to be able to fix up a member's post, the same access
     * they already have to remove it outright.
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    private function showCommunityPostEditPage(Page $page, array $args): ViewModel
    {
        $user = $this->context->user;
        $post = $this->resolvePostForEdit($page, $args, $user);

        if ($post->containerId === null || $post->containerType !== 'community') {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }

        if (! $this->feedService->canEditFeed($post, $user)) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        return $this->buildPostEditViewModel($page, $post, $user);
    }

    /**
     * Resolves the Feed a static 'edit' page (user.post-edit or
     * community.post-edit, both mounted as a child of their post's own
     * {slug} page) is editing: same field/type pattern as
     * resolveBlogPostForShow() - prefer a literal feedId pinned on the
     * *parent* page row, else fall back to this page's own {slug} match -
     * just read off the parent here since this page's own row has none.
     * No permission check here - callers apply their own afterwards, since
     * showBlogPostEditPage()/showCommunityPostEditPage() intentionally
     * differ on that (see each one's own note on why).
     *
     * @throws NotFoundException
     */
    private function resolvePostForEdit(Page $page, array $args, User $user): Feed
    {
        $parentPage = $page->parentId !== null ? $this->pageTree->get($page->parentId) : null;

        if ($parentPage !== null && $parentPage->feedId) {
            $post = $this->feedService->getFeedById($parentPage->feedId, $user);
        } else {
            $slug = trim((string) ($args['slug'] ?? ''));
            if ($slug === '') {
                throw new NotFoundException($this->tm->trans('feed.not_found'));
            }
            $post = $this->resolveBlogPostByRoute($page, $slug, $user);
        }

        if (! $post || $post->type !== 'blog-post') {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }

        return $post;
    }

    /**
     * Shared blog-post-form.twig view model for both edit actions above.
     * Canonical/cancel URLs come straight off $post->canonicalUrl (already
     * correct either way via UrlGenerator::feed(), see BlogPostService::
     * getOrCreateUserBlogFeed()'s own note on how) with a literal '/edit/'
     * suffix appended for canonical, rather than rebuilt via
     * UrlGenerator::page() the way this used to work - that method fills
     * every ancestor page sharing a placeholder *name* from one flat
     * array, which breaks for a community post's edit page specifically:
     * community.show and community.post-show both use {slug}, so passing
     * one value would wrongly fill both instead of each from its own post/
     * community. $post->canonicalUrl has no such issue (UrlGenerator::feed()
     * matches by feed type/position, not placeholder name), and "cancel"
     * is just that URL unchanged - no separate page/params lookup needed
     * for either.
     */
    private function buildPostEditViewModel(Page $page, Feed $post, User $user): ViewModel
    {
        $tags = $this->blogPostService->getBlogPostTags($post->id);

        $track = null;
        $trackUploadId = isset($post->metadata['track_upload_id']) ? (int) $post->metadata['track_upload_id'] : null;
        if ($trackUploadId) {
            $track = $this->uploadService->findOwnedUpload($trackUploadId, $user, 'audio/');
        }

        $isCommunityPost = $post->containerType === 'community';

        $canonical = $post->canonicalUrl !== null ? rtrim($post->canonicalUrl, '/').'/edit/' : null;
        $cancelUrl = $post->canonicalUrl;

        // trix.css: see showBlogPostFormPage()'s own note on why this has to
        // be linked explicitly (Vite splits it into its own shared chunk
        // now that Modules\Forums's forums.js also imports it).
        $headExt = [
            '<link rel="stylesheet" href="/assets/css/trix.css">',
            '<script type="module" src="/assets/js/blog-post-form.js" defer></script>',
        ];

        return ViewModel::fromPage(
            $page,
            'modules/users/blog-post-form.twig',
            [
                'title' => $page->pageName ?: $this->tm->trans('blog.edit_post'),
                'description' => $this->tm->trans($isCommunityPost ? 'blog.edit_community_post' : 'blog.edit_blog_post'),
                'canonical' => $canonical,
                'head_ext' => $headExt,
                'apiUrl' => '/api/v1/users/blog-posts/'.$post->id,
                'uploadsApiUrl' => '/api/v1/uploads',
                'post' => $post,
                'cancelUrl' => $cancelUrl,
                'initialTags' => $tags,
                'trackUploadId' => $trackUploadId,
                'track' => $track,
                'trackSizeLabel' => $track !== null ? $this->formatFileSize($track->size) : null,
                ...$this->buildUploadStorageViewData($user),
                // blog-post-form.twig/js's only community-aware bit - same
                // flag showCommunityPostFormPage() passes for the "new post"
                // form, swapping the 'members' visibility option's label
                // from "Friends only" to "Members only".
                'isCommunityPost' => $isCommunityPost,
            ]
        );
    }

    /**
     * The "Edit" button's href on both post-show pages
     * (showBlogPostPage()/showCommunityPostPage()) - just $post->canonicalUrl
     * with a literal '/edit/' suffix, same reasoning as
     * buildPostEditViewModel()'s own canonical (that method's own docblock
     * explains why this is safer than rebuilding via UrlGenerator::page()).
     * Callers decide *whether* to show it (author-only vs $canDelete's
     * broader policy) - this only builds the URL itself.
     */
    private function buildPostEditUrl(Feed $post): ?string
    {
        return $post->canonicalUrl !== null ? rtrim($post->canonicalUrl, '/').'/edit/' : null;
    }

    /**
     * Same size formatting as blog-post-form.js's bytesToLabel() - kept in
     * sync by hand since one runs at request time (prefilling an existing
     * track) and the other client-side (a track just uploaded in-browser).
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
     * @return array{
     *     uploadStorageUsedBytes:int,
     *     uploadStorageLimitBytes:int,
     *     uploadStorageRemainingBytes:int,
     *     uploadStorageUsedLabel:string,
     *     uploadStorageLimitLabel:string,
     *     uploadStorageRemainingLabel:string,
     *     uploadStoragePercent:int
     * }
     */
    private function buildUploadStorageViewData(User $user): array
    {
        $usage = $this->uploadService->getUserStorageUsage($user);
        $limit = max(1, $usage['limit']);

        return [
            'uploadStorageUsedBytes' => $usage['used'],
            'uploadStorageLimitBytes' => $usage['limit'],
            'uploadStorageRemainingBytes' => $usage['remaining'],
            'uploadStorageUsedLabel' => $this->formatFileSize($usage['used']),
            'uploadStorageLimitLabel' => $this->formatFileSize($usage['limit']),
            'uploadStorageRemainingLabel' => $this->formatFileSize($usage['remaining']),
            'uploadStoragePercent' => min(100, (int) round($usage['used'] / $limit * 100)),
        ];
    }

    /**
     * Resolves the Feed a {slug}-based post page (user.post-show or
     * community.post-show - both feed_type 'blog-post') is showing: a
     * literal feedId pinned on the page row wins, else look it up by its
     * own slug segment. Shared by showBlogPostPage() and
     * showCommunityPostPage() - which page led here doesn't change how the
     * post itself is found, only how its canonical URL/breadcrumb get
     * built afterwards (see each method's own canonical-building code).
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function resolveBlogPostForShow(Page $page, array $args): Feed
    {
        $slug = trim((string) ($args['slug'] ?? ''));

        if ($slug === '') {
            throw new NotFoundException($this->tm->trans('blog.post_not_found'));
        }

        if ($page->feedId) {
            $post = $this->feedService->getFeedById($page->feedId, $this->context->user);
        } else {
            $post = $this->resolveBlogPostByRoute($page, $slug, $this->context->user);
        }

        if (! $post || $post->type !== 'blog-post') {
            throw new NotFoundException($this->tm->trans('blog.post_not_found'));
        }

        return $post;
    }

    /**
     * Resolves a blog post through the route's matched container page.
     * Personal posts use the globally unique personal-blog slug (the user's
     * username); community posts use the top-level community namespace.
     * The post itself is always selected inside that resolved parent.
     */
    private function resolveBlogPostByRoute(Page $page, string $postSlug, User $user): ?Feed
    {
        $current = $page;

        while ($current->parentId !== null) {
            $current = $this->pageTree->get($current->parentId);

            if ($current === null) {
                return null;
            }

            $parent = null;

            if ($current->action === 'user.show') {
                $username = trim((string) ($current->params['username'] ?? ''));
                if ($username === '') {
                    return null;
                }

                $parent = $this->feedService->getFeedByTypeAndSlug('blog', $username, $user);
            } elseif ($current->action === 'community.show') {
                $communitySlug = trim((string) ($current->params['slug'] ?? ''));
                if ($communitySlug === '') {
                    return null;
                }

                $parent = $this->feedService->getFeedByParentAndSlug(
                    null,
                    $communitySlug,
                    $user,
                    'community'
                );
            }

            if ($parent === null) {
                continue;
            }

            return $this->feedService->getFeedByParentAndSlug(
                $parent->id,
                $postSlug,
                $user,
                'blog-post'
            );
        }

        return null;
    }

    /**
     * Reads a single published blog post - mounted at pages.action
     * 'user.post-show' ({slug} under the blog root page, feed_type
     * 'blog-post'). The route resolves its personal-blog parent from the
     * matched username first, so a same-slug post in another personal blog
     * or in a community cannot be selected here.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function showBlogPostPage(Page $page, array $args): ViewModel
    {
        $post = $this->resolveBlogPostForShow($page, $args);

        $comments = null;
        if ($page->commentsEnabled) {
            $comments = $this->feedService->getComments($post->id, null, $this->context->user);
        }

        // The sidebar profile card (components/users/profile-sidebar.twig,
        // shared with showUserPage()) needs the full author profile - bio,
        // gender etc - which Feed doesn't carry, only authorDisplayName/
        // authorAvatarUrl. A missing author (deleted account) degrades
        // gracefully: the post itself still renders, the sidebar card just
        // doesn't.
        $author = $this->userService->findPublicUserById($post->ownerId);
        $viewer = $this->context->user;
        $isOwnProfile = $author !== null && ! $viewer->isGuest() && $viewer->id === $author->id;

        // Prefers the live author's own avatar over the post's cached
        // row snapshot (which can go stale if they've since changed it) -
        // same preference order as buildCommunityPostCards() - resolved to
        // a guaranteed non-empty value via UserService::resolveAvatarUrl()
        // rather than left for the template to fall back on its own.
        $profileUserAvatarUrl = $author !== null ? $this->userService->resolveAvatarUrl($author->avatarUrl) : null;
        $authorAvatarUrl = $this->userService->resolveAvatarUrl($author?->avatarUrl ?: $post->authorAvatarUrl);

        // Generic feed rating - the same FeedService::rateFeed()/
        // getUserRatingValue() other feed types already use via the
        // `feed.rating` API action. Nothing blog-post-specific here, and
        // there's no owner-exclusion rule either, so the author sees the
        // same rateable widget on their own post.
        $userRating = $this->feedService->getUserRatingValue($post->id, $viewer);

        // $post->canonicalUrl is already correct here - resolveBlogPostForShow()
        // resolves via getFeedById() or the typed/scoped feed getters, all of
        // which set it
        // through UrlGenerator::feed(), whose generic chain walker fills a
        // personal post's {username} segment from the blog container's own
        // slug (see BlogPostService::getOrCreateUserBlogFeed()'s own note)
        // and a community post's from its actual community, same as every
        // other caller that resolves a blog-post Feed (search, sitemap, the
        // profile/community feed listings) - nothing left to rebuild here.
        $canonical = $post->canonicalUrl;

        // Author-only here (unlike showCommunityPostPage()'s own editUrl,
        // which follows $canDelete's broader policy instead) - see
        // showBlogPostEditPage()'s own note on why a personal blog keeps
        // editing author-only. $author is guaranteed non-null whenever
        // $isOwnProfile is true.
        $editUrl = $isOwnProfile ? $this->buildPostEditUrl($post) : null;

        // Same two fields buildFriendsViewData() computes for user.show's
        // "Add friend" button - profile-sidebar.twig is shared by
        // both pages, so it needs them here too, or the button falls back
        // to its disabled stub (which is what it was doing before this).
        // Only those two are worth computing here, not the whole
        // buildFriendsViewData() array: this page's sidebar has no friends
        // list of its own, so fetching one via FriendService::getFriends()
        // just to throw it away would be a wasted query on every post view.
        $friendActionUrl = $author !== null
            ? '/api/v1/users/'.rawurlencode($author->username).'/friend'
            : null;
        $relationshipStatus = ($author !== null && ! $isOwnProfile)
            ? $this->friendService->getRelationshipStatus($viewer, $author)
            : null;
        $privacyData = $author !== null
            ? $this->buildProfilePrivacyViewData($author, $isOwnProfile, $relationshipStatus)
            : [
                'canViewProfileGender' => false,
                'canViewProfileBirthDate' => false,
                'canViewProfileHomepage' => false,
                'canViewProfilePresence' => false,
            ];

        $isOnline = $author !== null && $privacyData['canViewProfilePresence']
            ? $this->userService->isOnline($author->id)
            : false;
        $audioPlaylistData = $author !== null
            ? $this->buildUserAudioPlaylistViewData($this->resolvePersonalBlogFeed($author, $viewer), $viewer)
            : ['audioPlaylist' => []];
        $audioPlaylistData['audioPlaylist'] = $this->ensurePostInAudioPlaylist($audioPlaylistData['audioPlaylist'], $post);
        $currentPostAudioTrackId = $this->findAudioTrackIdForPost($audioPlaylistData['audioPlaylist'], $post->id);

        // Same policy deleteFeed() enforces server-side (owner, admin, or -
        // for a community post - a moderator/owner of the container), just
        // exposed as a boolean here so the delete button is only rendered
        // for someone who could actually complete the action. On a
        // personal blog this collapses to "isOwnProfile", since nobody
        // else ever holds a moderator-level membership row there (see
        // AccessService::canEditFeed()'s own note).
        $canDelete = $this->feedService->canEditFeed($post, $viewer);

        // The author byline (blog_post_header block) links to this - same
        // "/users/{username}/" shape deleteRedirectUrl below already used,
        // just under its own name so blog_post_header doesn't have to
        // reach for a delete-specific variable to render a plain profile
        // link.
        $authorUrl = $author !== null ? sprintf('/users/%s/', rawurlencode($author->username)) : null;

        $viewData = [
            'title' => $post->title,
            'description' => $post->description ?: trim(strip_tags((string) $post->content)),
            'image' => $post->imageUrl,
            'canonical' => $canonical,
            'feed' => $post,
            'comments' => $comments,
            'profileUser' => $author,
            'profileUserAvatarUrl' => $profileUserAvatarUrl,
            'profileBirthDateLabel' => $this->formatProfileBirthDate($author),
            'authorAvatarUrl' => $authorAvatarUrl,
            'authorUrl' => $authorUrl,
            'isOwnProfile' => $isOwnProfile,
            'isOnline' => $isOnline,
            'userRating' => $userRating,
            'editUrl' => $editUrl,
            'friendActionUrl' => $friendActionUrl,
            'relationshipStatus' => $relationshipStatus,
            ...$privacyData,
            ...$audioPlaylistData,
            'currentPostAudioTrackId' => $currentPostAudioTrackId,
            'canDelete' => $canDelete,
            // A personal blog post keeps the profile sidebar - only
            // community.post-show (showCommunityPostPage()) sets this true
            // and supplies the community* sidebar variables.
            'isCommunityPost' => false,
            // Delete redirects here on success - back to the author's own
            // profile, same target regardless of who actually clicked
            // delete (author or, per canDelete above, nobody else on a
            // personal blog).
            'deleteRedirectUrl' => $authorUrl ?? '/',
        ];

        // Unlike the delete button (owner-only), the friend-request button
        // needs this same bundle for every *other* viewer - so, same as
        // showUserPage(), it's loaded unconditionally rather than gated to
        // isOwnProfile. That gating was the actual bug behind "Add friend"
        // doing nothing here: relationshipStatus/friendActionUrl
        // being set is necessary but not sufficient if initFriendButton()
        // (in this same users.js bundle) never even runs. Each initializer
        // no-ops when its own DOM hook is absent, so loading it for viewers
        // who see neither button (e.g. the author's own view, which has no
        // friend-request button on your own post) is harmless.
        $viewData['head_ext'] = [
            // type="module" (same as every other users.js tag now) gives
            // this bundle its own scope instead of sharing site.js's
            // classic-script global scope - the two independently
            // minified bundles once collided on an unrelated top-level
            // name ("Identifier 'H' has already been declared"), even
            // though neither file has any actual import/export syntax.
            '<script type="module" src="/assets/js/users.js" defer></script>',
        ];

        return ViewModel::fromPage($page, 'modules/users/blog-post.show.twig', $viewData);
    }

    /**
     * Reads a single published post at its community URL - mounted at
     * pages.action 'community.post-show' ({slug} under community.show,
     * feed_type 'blog-post'). Post resolution is identical to
     * showBlogPostPage() (resolveBlogPostForShow()), but the matched community
     * is resolved first and becomes the post lookup's parent. The defensive
     * guard still requires containerType === 'community',
     * hydrated straight off the feed row - see Feed::$containerType's own
     * docblock, in case a repository/service implementation returns a
     * malformed result.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function showCommunityPostPage(Page $page, array $args): ViewModel
    {
        $post = $this->resolveBlogPostForShow($page, $args);
        $viewer = $this->context->user;

        if ($post->containerId === null || $post->containerType !== 'community') {
            throw new NotFoundException($this->tm->trans('blog.post_not_found'));
        }

        $comments = null;
        if ($page->commentsEnabled) {
            $comments = $this->feedService->getComments($post->id, null, $viewer);
        }

        // Same profile-sidebar reasoning as showBlogPostPage() - the author
        // is a real user regardless of which URL the post is read at.
        $author = $this->userService->findPublicUserById($post->ownerId);
        $isOwnProfile = $author !== null && ! $viewer->isGuest() && $viewer->id === $author->id;

        // Same avatar-resolution reasoning as showBlogPostPage().
        $profileUserAvatarUrl = $author !== null ? $this->userService->resolveAvatarUrl($author->avatarUrl) : null;
        $authorAvatarUrl = $this->userService->resolveAvatarUrl($author?->avatarUrl ?: $post->authorAvatarUrl);

        $userRating = $this->feedService->getUserRatingValue($post->id, $viewer);

        $canonical = $post->canonicalUrl;

        // Same policy as showBlogPostPage() - deleteFeed()/updateFeed()
        // already grant this to the post's author, a site admin, or (via
        // AccessService::isContainerModerator()) this community's
        // moderators/owner, so a moderator who didn't write the post still
        // sees and can use the delete *and* edit buttons here - unlike
        // showBlogPostPage()'s own editUrl, which stays author-only (see
        // showCommunityPostEditPage()'s own note on that split).
        $canDelete = $this->feedService->canEditFeed($post, $viewer);
        $editUrl = $canDelete ? $this->buildPostEditUrl($post) : null;

        $friendActionUrl = $author !== null
            ? '/api/v1/users/'.rawurlencode($author->username).'/friend'
            : null;
        $relationshipStatus = ($author !== null && ! $isOwnProfile)
            ? $this->friendService->getRelationshipStatus($viewer, $author)
            : null;
        $privacyData = $author !== null
            ? $this->buildProfilePrivacyViewData($author, $isOwnProfile, $relationshipStatus)
            : [
                'canViewProfileGender' => false,
                'canViewProfileBirthDate' => false,
                'canViewProfileHomepage' => false,
                'canViewProfilePresence' => false,
            ];

        $isOnline = $author !== null && $privacyData['canViewProfilePresence']
            ? $this->userService->isOnline($author->id)
            : false;

        // Same author-byline link as showBlogPostPage() - unrelated to
        // deleteRedirectUrl below, which points at the community here, not
        // the author's profile.
        $authorUrl = $author !== null ? sprintf('/users/%s/', rawurlencode($author->username)) : null;

        // Loaded once, reused below for both the delete redirect and the
        // sidebar (owner/membership/members/rating) - a community post's
        // sidebar shows its *community*, not the author's profile (see
        // blog-post.show.twig's sidebar_widgets block), same as
        // community.show itself. Caught rather than left to propagate:
        // the post itself can legitimately still be readable even if the
        // community row is gone or this viewer can't access it directly,
        // same reasoning the try/catch here always had for the redirect
        // URL alone - now the sidebar degrades the same way.
        $community = null;
        try {
            $community = $this->feedService->getFeedById($post->containerId, $viewer);
        } catch (NotFoundException|ForbiddenException) {
            // Keep $community null - every community* fallback below
            // already handles that.
        }

        $deleteRedirectUrl = '/';
        if ($community !== null) {
            $communityShowPage = $this->pageTree->findByAction('community.show');
            if ($communityShowPage !== null && $community->slug !== null) {
                $deleteRedirectUrl = $this->urlGenerator->page($communityShowPage, ['slug' => $community->slug]);
            }
        }

        // Same fields/logic as showCommunityShowPage() computes for
        // community-show.twig's sidebar - kept under community*-prefixed
        // keys here (rather than bare owner/relationshipStatus/userRating)
        // since this page already has same-named variables for the
        // *post's* author/friend-status/rating; components/users/
        // community-sidebar.twig is included with `only` from
        // blog-post.show.twig precisely so it never sees the wrong one.
        $communityOwner = null;
        $communityOwnerUrl = null;
        $communityImageUrl = null;
        $communityRelationshipStatus = null;
        $communityMembershipActionUrl = null;
        $communityManageUrl = null;
        $communityMembersData = [
            'members' => [],
            'hasMembers' => false,
            'membersTotal' => 0,
            'membersApiUrl' => null,
            'membersNextOffset' => null,
        ];
        // Same "not a vote on the community feed itself" reasoning as
        // showCommunityShowPage()/buildCommunityRatingViewData() - default
        // empty shape here for the "community inaccessible" fallback case.
        $communityRatingData = [
            'ratingCount' => 0,
            'ratingAverage' => null,
            'ratingFullStars' => 0,
            'ratingHasHalfStar' => false,
            'ratingHint' => null,
        ];
        // Same default-empty-shape reasoning as $communityRatingData above.
        $communityStatsData = [
            'postCount' => 0,
            'commentCount' => 0,
            'createdAtLabel' => null,
        ];
        $audioPlaylistData = ['audioPlaylist' => []];

        if ($community !== null) {
            $communityImageUrl = $this->communityService->resolveImageUrl($community);

            $communityOwner = $this->userService->findPublicUserById($community->ownerId);
            if ($communityOwner !== null && $communityOwner->username) {
                $userShowPage = $this->pageTree->findByAction('user.show');
                if ($userShowPage !== null) {
                    $communityOwnerUrl = $this->urlGenerator->page($userShowPage, ['username' => $communityOwner->username]);
                }
            }

            $communityRelationshipStatus = $this->communityService->getRelationshipStatus($viewer, $community);
            $communityMembershipActionUrl = '/api/v1/communities/'.$community->id.'/membership';

            // Same owner-only "Manage" link as showCommunityShowPage()'s
            // own manageUrl - see that method's docblock.
            if (! $viewer->isGuest() && $viewer->id === $community->ownerId) {
                $managePage = $this->pageTree->findByAction('community.manage');
                if ($managePage !== null && $community->slug !== null) {
                    $communityManageUrl = $this->urlGenerator->page($managePage, ['slug' => $community->slug]);
                }
            }

            $communityMembersData = $this->buildCommunityMembersViewData($community);
            $communityRatingData = $this->buildCommunityRatingViewData($community, $viewer);
            $communityStatsData = $this->buildCommunityStatsViewData($community, $viewer);
            $audioPlaylistData = $this->buildCommunityAudioPlaylistViewData($community, $viewer);
        }
        $audioPlaylistData['audioPlaylist'] = $this->ensurePostInAudioPlaylist($audioPlaylistData['audioPlaylist'], $post);
        $currentPostAudioTrackId = $this->findAudioTrackIdForPost($audioPlaylistData['audioPlaylist'], $post->id);

        $viewData = [
            'title' => $post->title,
            'description' => $post->description ?: trim(strip_tags((string) $post->content)),
            'image' => $post->imageUrl,
            'canonical' => $canonical,
            'feed' => $post,
            'comments' => $comments,
            'profileUser' => $author,
            'profileUserAvatarUrl' => $profileUserAvatarUrl,
            'profileBirthDateLabel' => $this->formatProfileBirthDate($author),
            'authorAvatarUrl' => $authorAvatarUrl,
            'authorUrl' => $authorUrl,
            'isOwnProfile' => $isOwnProfile,
            'isOnline' => $isOnline,
            'userRating' => $userRating,
            'editUrl' => $editUrl,
            'friendActionUrl' => $friendActionUrl,
            'relationshipStatus' => $relationshipStatus,
            ...$privacyData,
            'canDelete' => $canDelete,
            'deleteRedirectUrl' => $deleteRedirectUrl,
            'isCommunityPost' => true,
            'community' => $community,
            'communityImageUrl' => $communityImageUrl,
            'communityOwner' => $communityOwner,
            'communityOwnerUrl' => $communityOwnerUrl,
            'communityRelationshipStatus' => $communityRelationshipStatus,
            'communityMembershipActionUrl' => $communityMembershipActionUrl,
            'communityManageUrl' => $communityManageUrl,
            'communityMembers' => $communityMembersData['members'],
            'hasCommunityMembers' => $communityMembersData['hasMembers'],
            'communityMembersTotal' => $communityMembersData['membersTotal'],
            'communityMembersApiUrl' => $communityMembersData['membersApiUrl'],
            'communityMembersNextOffset' => $communityMembersData['membersNextOffset'],
            'communityRatingCount' => $communityRatingData['ratingCount'],
            'communityRatingAverage' => $communityRatingData['ratingAverage'],
            'communityRatingFullStars' => $communityRatingData['ratingFullStars'],
            'communityRatingHasHalfStar' => $communityRatingData['ratingHasHalfStar'],
            'communityRatingHint' => $communityRatingData['ratingHint'],
            'communityPostCount' => $communityStatsData['postCount'],
            'communityCommentCount' => $communityStatsData['commentCount'],
            'communityCreatedAtLabel' => $communityStatsData['createdAtLabel'],
            ...$audioPlaylistData,
            'currentPostAudioTrackId' => $currentPostAudioTrackId,
            'head_ext' => [
                '<script type="module" src="/assets/js/users.js" defer></script>',
            ],
        ];

        return ViewModel::fromPage($page, 'modules/users/blog-post.show.twig', $viewData);
    }

    /**
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleUsersCollectionRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            echo Formatter::json($this->getUsersListPayload());
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleUserRegisterRequest();
            return;
        }

        throw new ValidationException('Method not allowed', 405);
    }

    /**
     * @throws ForbiddenException
     * @throws Exception
     */
    private function showRegisterPage(Page $page): ViewModel
    {
        if (! $this->context->user->isGuest()) {
            throw new ForbiddenException('Only for guests');
        }

        if ($verifyToken = $this->context->query->string('token')) {
            try {
                $userId = $this->userService->verifyEmail($verifyToken);
            } catch (ValidationException) {
                return ViewModel::fromPage(
                    $page,
                    'modules/users/verify.twig',
                    [
                        'title' => $this->tm->trans('user.registration'),
                    ]
                );
            }
            $this->authService->loginByUserId($userId);
            header('Location: /profile/?registered=1');
            exit;
        }

        if ($this->context->query->string('success') === '1') {
            return ViewModel::fromPage(
                $page,
                'modules/users/register2.twig',
                [
                    'title' => $this->tm->trans('user.registration'),
                ]
            );
        }

        return ViewModel::fromPage(
            $page,
            'modules/users/register1.twig',
            [
                'head_ext' => [
                    '<script type="module" src="/assets/js/register.js" defer></script>',
                ],
                'title' => $this->tm->trans('user.registration'),
            ]
        );
    }

    /**
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    private function showRetrievePage(Page $page): ViewModel
    {
        if (! $this->context->user->isGuest()) {
            throw new ForbiddenException('Only for guests');
        }

        $token = $this->context->query->string('token');

        if ($token) {
            $row = $this->userService->getPasswordResetToken($token);
            if (!$row) {
                throw new NotFoundException('Invalid token');
            }
            if ($row['created_at'] < time() - 3600) {
                throw new NotFoundException('Token expired');
            }

        }

        return ViewModel::fromPage(
            $page,
            $token ? 'modules/users/reset.twig' : 'modules/users/retrieve.twig',
            [
                'head_ext' => [
                    '<script type="module" src="/assets/js/retrieve.js" defer></script>',
                ],
                'title' => $this->tm->trans('user.password_recovery'),
                'token' => $token,
            ]
        );
    }

    /**
     * @param Page $page
     * @param array $args
     * @throws ForbiddenException
     * @throws ValidationException
     */
    public function callApi(Page $page, array $args = []): void
    {
        header('Content-Type: application/json');
        match ($page->action) {
            'users.collection' => $this->handleUsersCollectionRequest(),
            'password.retrieve' => $this->handleUserRetrieveRequest(),
            'user.post-new-create' => $this->handleBlogPostCreateRequest(),
            'user.post-item' => $this->handleBlogPostItemRequest((int) ($args['id'] ?? 0)),
            'user.posts' => $this->handleUserPostsRequest((string) ($args['username'] ?? '')),
            'user.playlist' => $this->handleUserPlaylistRequest((string) ($args['username'] ?? '')),
            'user.friends' => $this->handleUserFriendsRequest((string) ($args['username'] ?? '')),
            'user.friend' => $this->handleUserFriendRequest((string) ($args['username'] ?? '')),
            'community.posts' => $this->handleCommunityPostsRequest(),
            'community.do-create' => $this->handleCommunityCreateRequest(),
            'community.members' => $this->handleCommunityMembersRequest((int) ($args['id'] ?? 0)),
            'community.membership' => $this->handleCommunityMembershipRequest((int) ($args['id'] ?? 0)),
            'community.playlist' => $this->handleCommunityPlaylistRequest((int) ($args['id'] ?? 0)),
            'community.scoped-blog-posts' => $this->handleCommunityScopedBlogPostsRequest((int) ($args['id'] ?? 0)),
            'community.post-new-create' => $this->handleCommunityPostCreateRequest((int) ($args['id'] ?? 0)),
            'community.manage-settings' => $this->handleCommunityManageSettingsRequest((int) ($args['id'] ?? 0)),
            'community.manage-member' => $this->handleCommunityManageMemberRequest(
                (int) ($args['id'] ?? 0),
                (int) ($args['userId'] ?? 0),
            ),
            default => throw new ValidationException('Unknown action'),
        };
    }

    /**
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleUserRegisterRequest(): void
    {
        if (! $this->context->user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('user.already_authenticated'));
        }

        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            throw new ValidationException('Malformed JSON body');
        }

        // Honeypot :) A real browser leaves the hidden field empty; a bot
        // fills every field it finds. Absent means the form was not ours.
        $login = $data['login'] ?? null;

        if (! is_string($login) || $login !== '') {
            throw new ValidationException('Login must be a valid username');
        }

        $email = is_string($data['email'] ?? null) ? trim($data['email']) : '';
        $password = is_string($data['password'] ?? null) ? $data['password'] : '';

        $this->userService->register($email, $password);

        http_response_code(201);
        echo Formatter::json(['success' => true]);
    }

    /**
     * Creates a new post in the current user's personal blog from the
     * uuser.post-new editor.
     *
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleBlogPostCreateRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new ValidationException('Method not allowed', 405);
        }

        $this->requireAuthenticatedUser();
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $input = json_decode(file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];

        $user = $this->context->user;

        $trackUploadId = null;
        if (! empty($input['trackUploadId'])) {
            $trackUploadId = (int) $input['trackUploadId'];

            if ($this->uploadService->findOwnedUpload($trackUploadId, $user, 'audio/') === null) {
                throw new ValidationException($this->tm->trans('feed.blog_track_invalid'));
            }
        }

        $tags = array_key_exists('tags', $input) && is_array($input['tags']) ? $input['tags'] : null;
        $imageUrl = trim((string) ($input['imageUrl'] ?? ''));

        $post = $this->blogPostService->createBlogPost(
            title: trim((string) ($input['title'] ?? '')),
            content: (string) ($input['content'] ?? ''),
            description: null,
            imageUrl: $imageUrl !== '' ? $imageUrl : null,
            user: $user,
            visibility: (string) ($input['visibility'] ?? 'public'),
            tags: $tags,
            trackUploadId: $trackUploadId,
        );

        $this->notifyFriendsAboutPost($post, $user);

        http_response_code(201);
        echo Formatter::json([
            'id' => $post->id,
            'slug' => $post->slug,
            'title' => $post->title,
            'visibility' => $post->visibility,
            'canonicalUrl' => $post->canonicalUrl,
        ]);
    }

    private function notifyFriendsAboutPost(Feed $post, User $author): void
    {
        // In this application a private post is also a draft. Do not announce
        // it until handleBlogPostUpdateRequest() observes its publication.
        if ($post->visibility === 'private' || $post->containerType === 'community') {
            return;
        }

        try {
            $this->notificationService->notifyFriendsAboutPost(
                $post,
                $author,
                $this->memberships->findMutualFriendIds($author->id),
            );
        } catch (Throwable $e) {
            // The post is already persisted. Notification fan-out must not
            // turn the successful publication into a client-visible error.
            error_log('Unable to notify friends about post '.$post->id.': '.$e->getMessage());
        }
    }

    private function notifyCommunityMembersAboutPost(Feed $post, Feed $community, User $author): void
    {
        // In this application a private post is also a draft. Announce it
        // only when handleBlogPostUpdateRequest() observes its publication.
        if ($post->visibility === 'private') {
            return;
        }

        try {
            $this->notificationService->notifyCommunityMembersAboutPost(
                $post,
                $community,
                $author,
                $this->memberships->findMemberIds($community->id),
            );
        } catch (Throwable $e) {
            // The post is already persisted. Notification fan-out must not
            // turn the successful publication into a client-visible error.
            error_log('Unable to notify community members about post '.$post->id.': '.$e->getMessage());
        }
    }

    /**
     * Single dispatcher for the blog-posts/{id} resource - PATCH (edit-page
     * save) and DELETE (post-show's delete button) share one page row/action
     * ('user.post-item'), same as handleUsersCollectionRequest() already
     * does for GET/POST on 'users.collection'.
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleBlogPostItemRequest(int $id): void
    {
        match ($_SERVER['REQUEST_METHOD']) {
            'PATCH' => $this->handleBlogPostUpdateRequest($id),
            'DELETE' => $this->handleBlogPostDeleteRequest($id),
            default => throw new ValidationException('Method not allowed', 405),
        };
    }

    /**
     * Saves an edit from the user.post-edit form - publish or draft, the
     * edit page's JS always sends the resolved visibility (btnDraft forces
     * 'private' client-side, same convention as the create form). Ownership/
     * type checks live in BlogPostService::updateBlogPost(), same division
     * as handleBlogPostCreateRequest().
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleBlogPostUpdateRequest(int $id): void
    {
        $this->requireAuthenticatedUser();
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        if ($id <= 0) {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $input = is_array($input) ? $input : [];

        $user = $this->context->user;

        $trackUploadId = null;
        if (! empty($input['trackUploadId'])) {
            $trackUploadId = (int) $input['trackUploadId'];

            if ($this->uploadService->findOwnedUpload($trackUploadId, $user, 'audio/') === null) {
                throw new ValidationException($this->tm->trans('feed.blog_track_invalid'));
            }
        }

        $tags = array_key_exists('tags', $input) && is_array($input['tags']) ? $input['tags'] : null;
        $imageUrl = trim((string) ($input['imageUrl'] ?? ''));
        $visibility = (string) ($input['visibility'] ?? 'public');
        $publishingDraft = false;

        if ($visibility !== 'private') {
            $existing = $this->feedService->getFeedById($id, $user);
            $publishingDraft = $existing?->visibility === 'private';
        }

        $post = $this->blogPostService->updateBlogPost(
            id: $id,
            title: trim((string) ($input['title'] ?? '')),
            content: (string) ($input['content'] ?? ''),
            description: null,
            imageUrl: $imageUrl !== '' ? $imageUrl : null,
            user: $user,
            visibility: $visibility,
            tags: $tags,
            trackUploadId: $trackUploadId,
        );

        if ($publishingDraft) {
            if ($post->containerType === 'community' && $post->containerId !== null) {
                $community = $this->feedService->getFeedById($post->containerId, $user);

                if ($community !== null && $community->type === 'community') {
                    $this->notifyCommunityMembersAboutPost($post, $community, $user);
                }
            } else {
                $this->notifyFriendsAboutPost($post, $user);
            }
        }

        echo Formatter::json([
            'id' => $post->id,
            'slug' => $post->slug,
            'title' => $post->title,
            'visibility' => $post->visibility,
            'canonicalUrl' => $post->canonicalUrl,
        ]);
    }

    /**
     * Deletes one of the current user's own blog posts from the post-show
     * page's delete button (data-blog-post-delete). Ownership/type checks
     * live in BlogPostService::deleteBlogPost() - this handler's own job is
     * just the request-shape check (method, CSRF), same division as
     * handleBlogPostCreateRequest().
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleBlogPostDeleteRequest(int $id): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            throw new ValidationException('Method not allowed', 405);
        }

        $this->requireAuthenticatedUser();
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        if ($id <= 0) {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }

        $this->blogPostService->deleteBlogPost($id, $this->context->user);

        http_response_code(204);
    }

    /**
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleUserRetrieveRequest(): void
    {
        if (!$this->context->user->isGuest()) {
            throw new ForbiddenException('Only for guests');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            $data = json_decode(file_get_contents('php://input'), true);
            // is_string, not `?? ''`: a null body coalesces harmlessly, but
            // `{"email":[]}` would reach trim() as an array and be a fatal.
            $email = is_string($data['email'] ?? null) ? trim($data['email']) : '';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ValidationException($this->tm->trans('user.email_invalid'));
            }

            $this->userService->createPasswordResetToken($email);
            echo Formatter::json(['success' => true]);

        } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            $data = json_decode(file_get_contents('php://input'), true);
            $token = is_string($data['token'] ?? null) ? $data['token'] : '';
            $password = is_string($data['password'] ?? null) ? $data['password'] : '';

            $this->userService->resetPasswordByToken($token, $password);
            echo Formatter::json(['success' => true]);
        }
    }

    /**
     * Both tasks are hourly sweeps of rows nothing reads any more. They live
     * on this controller because that's the only place cron tasks can be
     * registered (CronRunner resolves a task to a module controller), not
     * because either is a Users-module concern: `users:sessions-cleanup`
     * delegates straight to Core's UserService, which owns `user_sessions`.
     */
    public static function registerCron(CronRegistry $cron): void
    {
        $cron->add(
            'users:cleanup',
            'Users',
            60 * 60
        );

        $cron->add(
            'users:sessions-cleanup',
            'Users',
            60 * 60
        );
    }

    public function runCron(string $task): void
    {
        // No default arm on purpose - an unregistered task should be a loud
        // UnhandledMatchError, not a silent no-op.
        match ($task) {
            'users:cleanup' => $this->cleanupRetrieve(),
            'users:sessions-cleanup' => $this->userService->cleanupExpiredSessions(),
        };
    }

    public function cleanupRetrieve(): void
    {
        $this->db->execute(
            "DELETE FROM password_resets WHERE created_at < UNIX_TIMESTAMP() - 60 * 60",
        );
    }

    public static function registerApi(int $apiPageId, PageTree $pageTree): void
    {
        $usersPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $usersPageId,
                parentId: $apiPageId,
                pattern: 'users',
                requestMethods: ['GET', 'POST'],
                action: 'users.collection'
            )
        );
        $retrievePageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $retrievePageId,
                parentId: $apiPageId,
                pattern: 'retrieve',
                requestMethods: ['POST', 'PUT'],
                action: 'password.retrieve'
            )
        );
        $blogPostsPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $blogPostsPageId,
                parentId: $usersPageId,
                pattern: 'blog-posts',
                requestMethods: ['POST'],
                action: 'user.post-new-create'
            )
        );
        $blogPostIdPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $blogPostIdPageId,
                parentId: $blogPostsPageId,
                pattern: '{id}',
                requestMethods: ['PATCH', 'DELETE'],
                action: 'user.post-item'
            )
        );

        // GET a specific user's own blog-post feed, paginated - the profile
        // page's "Load more" button (see handleUserPostsRequest() and
        // users.ts's initBlogFeedLoadMore()). {username} itself is never
        // meant to be requested on its own (only its 'blog-posts' child
        // below is) - but it still needs a real, unique action, not null:
        // ControllerFactory::createForPage() throws a plain Exception
        // (uncaught by callApi()'s own ValidationException handling) for a
        // null action, so a bare GET /api/v1/users/{username} would 500
        // instead of cleanly 400ing. This placeholder action means
        // callApi()'s match() falls through to its own "Unknown action"
        // ValidationException for that URL shape instead, same as any
        // other unrecognized one. A username that happened to literally be
        // "retrieve" or "blog-posts" would 404 here rather than even reach
        // this page (static routes match before dynamic ones at this same
        // parent - see Router::resolve()'s own doc comment), same known
        // trade-off as BlogPostService::RESERVED_BLOG_POST_SLUGS already
        // accepts for post slugs.
        $usernamePageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $usernamePageId,
                parentId: $usersPageId,
                pattern: '{username}',
                requestMethods: ['GET'],
                action: 'user.posts-parent'
            )
        );
        $userPostsPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $userPostsPageId,
                parentId: $usernamePageId,
                pattern: 'blog-posts',
                requestMethods: ['GET'],
                action: 'user.posts'
            )
        );

        // GET the music playlist for the same post set the profile page
        // shows. Kept separate from 'blog-posts' so the player can fetch a
        // compact, audio-only contract without inheriting card pagination.
        $userPlaylistPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $userPlaylistPageId,
                parentId: $usernamePageId,
                pattern: 'playlist',
                requestMethods: ['GET'],
                action: 'user.playlist'
            )
        );

        // GET a specific user's mutual-friends list, paginated - the
        // profile page's friends-list "Load more" button (see
        // handleUserFriendsRequest()). Same {username}-parent reasoning as
        // 'blog-posts' above.
        $userFriendsPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $userFriendsPageId,
                parentId: $usernamePageId,
                pattern: 'friends',
                requestMethods: ['GET'],
                action: 'user.friends'
            )
        );

        // POST to request friendship with / subscribe to $username (or
        // complete a mutual friendship, if they'd already subscribed back -
        // same call either way, see FriendService::sendRequest()'s own
        // docblock). DELETE to remove that subscription/friendship - the
        // profile-sidebar "Add friend"/"Unsubscribe" button (see
        // handleUserFriendRequest() and users.ts's initFriendButton()).
        $userFriendPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $userFriendPageId,
                parentId: $usernamePageId,
                pattern: 'friend',
                requestMethods: ['POST', 'DELETE'],
                action: 'user.friend'
            )
        );

        // GET the community page's site-wide blog-post feed, paginated -
        // the "Load more" button on action=community.main (see
        // handleCommunityPostsRequest() and users.ts's
        // initCommunityFeedLoadMore()). 'community' itself is never meant
        // to be requested on its own (only its 'blog-posts' child below
        // is) - same real-but-unused-action placeholder reasoning as
        // 'user.posts-parent' above, so a bare GET /api/v1/community 400s
        // via callApi()'s "Unknown action" instead of 500ing.
        $communityPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $communityPageId,
                parentId: $apiPageId,
                pattern: 'community',
                requestMethods: ['GET'],
                action: 'community-parent'
            )
        );
        $communityPostsPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $communityPostsPageId,
                parentId: $communityPageId,
                pattern: 'blog-posts',
                requestMethods: ['GET'],
                action: 'community.posts'
            )
        );

        // POST the community.create form's submit (see
        // handleCommunityCreateRequest()) - a sibling of 'community' rather
        // than a child of it, since (unlike 'blog-posts' above) it isn't
        // scoped to any single community. Authentication is checked by the
        // controller.
        $communitiesPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $communitiesPageId,
                parentId: $apiPageId,
                pattern: 'communities',
                requestMethods: ['POST'],
                action: 'community.do-create',
            )
        );

        // {id} itself is never meant to be requested on its own (only its
        // 'members'/'membership' children below are) - same real-but-
        // unused-action placeholder reasoning as 'user.posts-parent' and
        // 'community-parent' above, so a bare GET /api/v1/communities/{id}
        // 400s via callApi()'s "Unknown action" instead of 500ing.
        $communityIdPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $communityIdPageId,
                parentId: $communitiesPageId,
                pattern: '{id}',
                requestMethods: ['GET'],
                action: 'community.item',
            )
        );

        // GET a single community's members list, paginated - the community
        // page's "Members" widget "Load more" button (see
        // handleCommunityMembersRequest()).
        $communityMembersPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $communityMembersPageId,
                parentId: $communityIdPageId,
                pattern: 'members',
                requestMethods: ['GET'],
                action: 'community.members',
            )
        );

        // POST to join (or request to join) a community, DELETE to leave
        // it/cancel a pending request - the community page's "Join"/
        // "Leave community" button (see handleCommunityMembershipRequest()).
        $communityMembershipPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $communityMembershipPageId,
                parentId: $communityIdPageId,
                pattern: 'membership',
                requestMethods: ['POST', 'DELETE'],
                action: 'community.membership',
            )
        );

        // GET the music playlist for posts directly under this community.
        $communityPlaylistPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $communityPlaylistPageId,
                parentId: $communityIdPageId,
                pattern: 'playlist',
                requestMethods: ['GET'],
                action: 'community.playlist',
            )
        );

        // GET lists this community's own blog posts for the community.show
        // load-more button; POST creates a new post from community.post-new.
        // Same REST collection URL, dispatched by verb in
        // handleCommunityScopedBlogPostsRequest().
        $communityPostsCreatePageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $communityPostsCreatePageId,
                parentId: $communityIdPageId,
                pattern: 'blog-posts',
                requestMethods: ['GET', 'POST'],
                action: 'community.scoped-blog-posts',
            )
        );

        // PATCH the community.manage "Settings" tab's save button (see
        // handleCommunityManageSettingsRequest()). Same {id}-scoped-child
        // shape as 'members'/'membership'/'blog-posts' above.
        $communityManagePageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $communityManagePageId,
                parentId: $communityIdPageId,
                pattern: 'manage',
                requestMethods: ['PATCH'],
                action: 'community.manage-settings',
            )
        );

        // {userId} itself is never meant to be requested on its own (only
        // through PATCH/DELETE below) - same real-but-unused-action
        // placeholder reasoning as 'community-parent'/'user.posts-parent'
        // above, so a bare GET /api/v1/communities/{id}/manage/members
        // 400s via callApi()'s "Unknown action" instead of 500ing.
        $communityManageMembersPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $communityManageMembersPageId,
                parentId: $communityManagePageId,
                pattern: 'members',
                requestMethods: ['GET'],
                action: 'community.manage-members-parent',
            )
        );

        // PATCH to approve a pending subscriber (subscriber -> member),
        // DELETE to remove a member (demoted back to subscriber, not a
        // hard delete) - the community.manage "Members" tab's own
        // "Accept"/"Remove" row buttons (see
        // handleCommunityManageMemberRequest()).
        $communityManageMemberPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $communityManageMemberPageId,
                parentId: $communityManageMembersPageId,
                pattern: '{userId}',
                requestMethods: ['PATCH', 'DELETE'],
                action: 'community.manage-member',
            )
        );
    }
}

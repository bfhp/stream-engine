<?php

namespace StreamEngine\Modules\Profile;

use DateTimeImmutable;
use StreamEngine\Core\AbstractController;
use StreamEngine\Core\Cron\CronRegistry;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\Security;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\UserService;
use StreamEngine\View\ViewModel;

class ProfileController extends AbstractController
{
    /**
     * How many rows the "Friends" tab loads - friends and both kinds of
     * pending request in one list (see handleFriendsRequest()). No "Load
     * more": the tab filters by name client-side, which only works honestly if
     * it holds the whole list, so this is a single generous cap rather than a
     * page size. The response says when the cap actually truncated something,
     * so the tab can say so too.
     */
    private const int FRIENDS_LIST_LIMIT = 200;

    /**
     * Same single-generous-cap arrangement as FRIENDS_LIST_LIMIT, for the
     * "Subscriptions" tab. Smaller because belonging to hundreds of communities is
     * a far less ordinary shape than having hundreds of friends.
     */
    private const int SUBSCRIPTIONS_LIST_LIMIT = 100;

    /**
     * Module-private, so they're built here rather than constructor-injected -
     * see docs/MODULE_CONTRACT.md and each class's own docblock. The
     * repositories they need are stateless wrappers over PdoDatabase, so fresh
     * instances are equivalent to sharing StreamEngine's own.
     */
    private readonly FriendListService $friendListService;

    private readonly CommunityListService $communityListService;

    public static function pageActions(): array
    {
        return [
            'profile.show' => 'Profile page',
        ];
    }

    private readonly UserRepository $users;

    public function __construct(
        PdoDatabase $db,
        RequestContext $context,
        private readonly TranslationManager $tm,
        PageTree $pageTree,
        UrlGenerator $urlGenerator,
        private readonly NotificationService $notifications,
    ) {
        parent::__construct($db, $context);

        // Built once here rather than `new UserRepository($this->db)` inside
        // each write. Same object either way at runtime - repositories in this
        // codebase are stateless wrappers over PdoDatabase - but a property can
        // be swapped in a test, so what the profile writes actually saves is
        // observable. It was not before.
        $this->users = new UserRepository($db);

        $this->friendListService = new FriendListService(
            new FeedRepository($db),
            new MembershipRepository($db),
            $pageTree,
            $urlGenerator,
            $this->tm,
        );

        $this->communityListService = new CommunityListService(
            new MembershipRepository($db),
            $pageTree,
            $urlGenerator,
        );
    }

    public static function registerCron(CronRegistry $cron): void
    {
        $cron->add('notifications:deliveries', 'Profile', 60);
    }

    public function runCron(string $task): void
    {
        match ($task) {
            'notifications:deliveries' => $this->notifications->processQueue(),
        };
    }

    public function show(Page $page, array $args = []): ?ViewModel
    {
        return match ($page->action) {
            'profile.show' => $this->showProfilePage($page),
            'profile.email.unsubscribe' => $this->showEmailUnsubscribePage($page),
            default => throw new ForbiddenException('Unknown page action'),
        };
    }

    private function showProfilePage(Page $page): ViewModel
    {
        $notificationSettings = $this->notifications->preferenceSettingsForUser($this->context->user->id);
        $tabs = [
            ['id' => 'personal', 'label' => $this->tm->trans('profile.tab.personal'), 'template' => 'components/profile/tabs/personal.twig'],
            ['id' => 'privacy', 'label' => $this->tm->trans('profile.tab.privacy'), 'template' => 'components/profile/tabs/privacy.twig'],
            ['id' => 'friends', 'label' => $this->tm->trans('profile.tab.friends'), 'template' => 'components/profile/tabs/friends.twig'],
            ['id' => 'subscriptions', 'label' => $this->tm->trans('profile.tab.subscriptions'), 'template' => 'components/profile/tabs/subscriptions.twig'],
            ['id' => 'mailings', 'label' => $this->tm->trans('profile.tab.mailings'), 'template' => 'components/profile/tabs/mailings.twig'],
            ['id' => 'forum', 'label' => $this->tm->trans('profile.tab.forum'), 'template' => 'components/profile/tabs/forum.twig'],
            ['id' => 'security', 'label' => $this->tm->trans('profile.tab.security'), 'template' => 'components/profile/tabs/security.twig'],
        ];

        return ViewModel::fromPage(
            $page,
            "modules/profile/page.twig",
            [
                'head_ext' => [
                    '<script type="module" src="/assets/js/profile.js" defer></script>',
                ],
                'title' => $this->tm->trans('profile.title'),
                'tabs' => $tabs,
                'notificationPreferences' => $notificationSettings['items'],
                'notificationDigestTime' => $notificationSettings['digestDeliveryTime'],
                // Three states: absent (the visitor navigated here), true
                // (they were just redirected from registration) and false.
                'registered' => $this->context->query->boolOrNull('registered'),
            ]
        );
    }

    private function showEmailUnsubscribePage(Page $page): ViewModel
    {
        $token = $this->context->query->string('token');
        $valid = false;
        $unsubscribed = false;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $oneClick = $_POST['List-Unsubscribe'] ?? null;
            if ($oneClick !== 'One-Click') {
                http_response_code(400);
                $valid = false;
            } else {
                $unsubscribed = $this->notifications->unsubscribeFromEmail($token);
                $valid = $unsubscribed;

                if (! $unsubscribed) {
                    http_response_code(404);
                }
            }
        } else {
            $valid = $this->notifications->canUnsubscribeFromEmail($token);

            if (! $valid) {
                http_response_code(404);
            }
        }

        return ViewModel::fromPage(
            $page,
            'modules/profile/email-unsubscribe.twig',
            [
                'title' => $this->tm->trans('profile.email_unsubscribe'),
                'valid' => $valid,
                'unsubscribed' => $unsubscribed,
            ]
        );
    }

    /**
     * @throws ForbiddenException
     * @throws ValidationException
     */
    public function callApi(Page $page, array $args = []): void
    {
        header('Content-Type: application/json');
        match ($page->action) {
            'profile.update' => $this->handleProfileUpdateRequest(),
            'profile.forum.update' => $this->handleForumProfileUpdateRequest(),
            'profile.privacy.update' => $this->handlePrivacyUpdateRequest(),
            'profile.mailings.update' => $this->handleMailingsUpdateRequest(),
            'profile.friends' => $this->handleFriendsRequest(),
            'profile.friend.reject' => $this->handleFriendRejectRequest((int) ($args['id'] ?? 0)),
            'profile.subscriptions' => $this->handleSubscriptionsRequest(),
            default => throw new ValidationException('Unknown action'),
        };
    }

    /**
     * GET /api/v1/my/friends - the "Friends" tab's list (see
     * initProfileFriends() in assets-src/pages/profile.ts). Self-scoped by
     * definition: it exposes both pending directions - who asked you, who you
     * asked and are still waiting on - which is nobody else's business, so
     * there's no user in the path at all.
     *
     * No total in the meta: the only honest one available here is "how many
     * rows we returned", which the client can count itself, and putting that
     * under a name that reads like a real total next to `truncated` would
     * mislead whoever reaches for it next.
     *
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleFriendsRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            throw new ValidationException('Method not allowed', 405);
        }

        if ($this->context->user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('profile.unauthorized'));
        }

        $connections = $this->friendListService->getConnections(
            $this->context->user,
            self::FRIENDS_LIST_LIMIT
        );

        echo Formatter::json([
            'items' => $connections['items'],
            'meta' => [
                'truncated' => $connections['truncated'],
                'limit' => self::FRIENDS_LIST_LIMIT,
            ],
        ]);
    }

    /**
     * GET /api/v1/my/subscriptions - the "Subscriptions" tab's list of communities
     * the current user belongs to (see initProfileSubscriptions() in
     * assets-src/pages/profile.ts). Self-scoped for the same reason
     * handleFriendsRequest() is: it exposes pending join requests, which are
     * between that user and a community's owner and nobody else's business.
     *
     * Read-only - there's no write counterpart here, because leaving is the
     * Users module's POST/DELETE /api/v1/communities/{id}/membership (see
     * CommunityListService's docblock for where that line is drawn).
     *
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleSubscriptionsRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            throw new ValidationException('Method not allowed', 405);
        }

        if ($this->context->user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('profile.unauthorized'));
        }

        $subscriptions = $this->communityListService->getSubscriptions(
            $this->context->user,
            self::SUBSCRIPTIONS_LIST_LIMIT
        );

        echo Formatter::json([
            'items' => $subscriptions['items'],
            'meta' => [
                'truncated' => $subscriptions['truncated'],
                'limit' => self::SUBSCRIPTIONS_LIST_LIMIT,
            ],
        ]);
    }

    /**
     * DELETE /api/v1/my/friends/{id} - "Reject" on an incoming request:
     * drop that user's subscription to the current user's own blog (see
     * FriendListService::rejectRequest(), including why it's this module's to
     * own while adding/removing your own half stays with Users).
     *
     * Addressed by user id rather than username, unlike the friend endpoint
     * it sits next to: the tab already has the id for its "Message" button,
     * and this way the action still works for someone who hasn't been
     * assigned a public username yet.
     *
     * Answers with the resulting relationship status so the tab can drop or
     * re-render the row without a second round trip.
     *
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleFriendRejectRequest(int $subscriberId): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            throw new ValidationException('Method not allowed', 405);
        }

        if ($this->context->user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('profile.unauthorized'));
        }

        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        echo Formatter::json([
            'status' => $this->friendListService->rejectRequest($this->context->user, $subscriberId),
        ]);
    }

    /**
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleProfileUpdateRequest(): void
    {
        $data = $this->verifiedProfileInput();

        $currentUser = $this->context->user;
        $nick = trim((string) ($data['nick'] ?? ''));
        $bio = trim((string) ($data['bio'] ?? ''));
        $homepage = trim((string) ($data['homepage'] ?? ''));
        $gender = trim((string) ($data['gender'] ?? ''));
        $birthDate = trim((string) ($data['birth_date'] ?? ''));
        $avatarUrl = trim((string) ($data['avatar_url'] ?? ''));

        if (mb_strlen($nick) > 50) {
            throw new ValidationException($this->tm->trans('profile.name_too_long'));
        }

        if (mb_strlen($bio) > 2000) {
            throw new ValidationException($this->tm->trans('profile.bio_too_long'));
        }

        if ($homepage !== '' && ! filter_var($homepage, FILTER_VALIDATE_URL)) {
            throw new ValidationException($this->tm->trans('profile.homepage_invalid'));
        }

        if ($avatarUrl !== '' && ! self::isStoredUploadPath($avatarUrl)) {
            throw new ValidationException($this->tm->trans('profile.avatar_invalid'));
        }

        $allowedGenders = ['male', 'female', 'other', ''];
        if (! in_array($gender, $allowedGenders, true)) {
            throw new ValidationException($this->tm->trans('profile.gender_invalid'));
        }

        if ($birthDate !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
            if (!$date || $date->format('Y-m-d') !== $birthDate) {
                throw new ValidationException($this->tm->trans('profile.birth_date_invalid'));
            }
        }

        $this->users->updateProfilePersonal(
            id: $currentUser->id,
            nick: $nick,
            bio: $bio,
            homepage: $homepage,
            gender: $gender,
            birthDate: $birthDate !== '' ? $birthDate : null,
            avatarUrl: $avatarUrl,
        );

        http_response_code(201);
        echo Formatter::json(['success' => true]);
    }

    /**
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleForumProfileUpdateRequest(): void
    {
        $data = $this->verifiedProfileInput();
        $signature = trim((string) ($data['signature'] ?? ''));

        // Length is checked against the raw input, i.e. against what the author
        // actually typed into the textarea - see UserService::
        // MAX_SIGNATURE_LENGTH's own docblock.
        if (mb_strlen($signature) > UserService::MAX_SIGNATURE_LENGTH) {
            throw new ValidationException($this->tm->trans('profile.signature_too_long'));
        }

        // Every user-supplied HTML in this codebase is purified before storage
        // (FeedService does it for post bodies); a signature is printed with
        // `|raw` under every forum post, so it does too. This is one of the two
        // write paths into users.signature - see sanitizeSignature()'s docblock
        // for the other.
        $signature = UserService::sanitizeSignature($signature);

        $this->users->updateProfileForumSignature(
            id: $this->context->user->id,
            signature: $signature,
        );

        http_response_code(201);
        echo Formatter::json(['success' => true]);
    }

    /**
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handlePrivacyUpdateRequest(): void
    {
        $data = $this->verifiedProfileInput();

        $this->users->updateProfilePrivacy(
            id: $this->context->user->id,
            showGenderPublicly: self::truthyFormValue($data['show_gender_publicly'] ?? null),
            showBirthDatePublicly: self::truthyFormValue($data['show_birth_date_publicly'] ?? null),
            showHomepagePublicly: self::truthyFormValue($data['show_homepage_publicly'] ?? null),
            hidePresence: self::truthyFormValue($data['hide_presence'] ?? null),
            showHiddenProfileToFriends: self::truthyFormValue($data['show_hidden_profile_to_friends'] ?? null),
        );

        http_response_code(201);
        echo Formatter::json(['success' => true]);
    }

    /**
     * @throws ForbiddenException
     * @throws ValidationException
     */
    private function handleMailingsUpdateRequest(): void
    {
        $data = $this->verifiedProfileInput();

        $this->notifications->updatePreferenceSettings($this->context->user->id, $data);

        http_response_code(201);
        echo Formatter::json(['success' => true]);
    }

    /**
     * @throws ForbiddenException
     * @throws ValidationException
     */
    /**
     * Is this a path under /uploads/ that names a stored file?
     *
     * The character class alone isn't enough, which is what this replaced:
     * `~^/uploads/[a-zA-Z0-9/_.-]+$~` allows both `.` and `/`, so
     * `/uploads/../../etc/passwd` matched. That is not a file read - the value
     * is stored and rendered back as an `<img src>`, and a leading single `/`
     * keeps the browser on this origin - but it did let a profile point its
     * avatar at any same-origin path, which is nothing the field is for.
     *
     * So: same character class, then segments checked individually. `..`, `.`
     * and empty segments (from `//`) are all rejected, which also rules out the
     * `/uploads/a/../b.png` shape that resolves outside its own directory.
     */
    private static function isStoredUploadPath(string $path): bool
    {
        $prefix = '/uploads/';

        if (! preg_match('~^/uploads/[a-zA-Z0-9/_.-]+$~', $path)) {
            return false;
        }

        foreach (explode('/', substr($path, strlen($prefix))) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private static function truthyFormValue(mixed $value): bool
    {
        return in_array($value, ['1', 1, true, 'on'], true);
    }

    private function verifiedProfileInput(): array
    {
        if ($this->context->user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('profile.unauthorized'));
        }

        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);

        if (! is_array($data)) {
            throw new ValidationException($this->tm->trans('profile.data_invalid'));
        }

        return $data;
    }

    public static function registerApi(int $apiPageId, PageTree $pageTree): void
    {
        $pageTree->add(
            new Page(
                id: $pageTree->getMaxPageId(),
                parentId: 1,
                pattern: 'unsubscribe',
                pageName: 'Email unsubscribe',
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET', 'POST'],
                responseType: 'html',
                accessRule: AccessService::ACCESS_PUBLIC,
                action: 'profile.email.unsubscribe',
            )
        );

        $pageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $pageId,
                parentId: $apiPageId,
                pattern: 'my',
                requestMethods: ['POST'],
                action: 'profile.update'
            )
        );

        $forumPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $forumPageId,
                parentId: $pageId,
                pattern: 'forum',
                requestMethods: ['POST'],
                action: 'profile.forum.update'
            )
        );

        $privacyPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $privacyPageId,
                parentId: $pageId,
                pattern: 'privacy',
                requestMethods: ['POST'],
                action: 'profile.privacy.update'
            )
        );

        $mailingsPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $mailingsPageId,
                parentId: $pageId,
                pattern: 'mailings',
                requestMethods: ['POST'],
                action: 'profile.mailings.update'
            )
        );

        // The "Friends" tab's own two endpoints, under this module's existing
        // 'my' namespace because they're only ever about the logged-in user
        // (see handleFriendsRequest()/handleFriendRejectRequest()).
        $friendsPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $friendsPageId,
                parentId: $pageId,
                pattern: 'friends',
                requestMethods: ['GET'],
                action: 'profile.friends'
            )
        );
        $friendRejectPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $friendRejectPageId,
                parentId: $friendsPageId,
                pattern: '{id}',
                requestMethods: ['DELETE'],
                action: 'profile.friend.reject'
            )
        );

        // The "Subscriptions" tab's list. Read-only: leaving a community is the
        // Users module's own endpoint, so there's no '{id}' child here.
        $subscriptionsPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $subscriptionsPageId,
                parentId: $pageId,
                pattern: 'subscriptions',
                requestMethods: ['GET'],
                action: 'profile.subscriptions'
            )
        );
    }
}

<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use HTMLPurifier;
use HTMLPurifier_Config;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use PHPMailer\PHPMailer\Exception;
use Random\RandomException;
use RuntimeException;
use StreamEngine\Core\Config;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Notification;
use StreamEngine\Domain\User;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

final class UserService
{
    private const int MIN_PASSWORD_LENGTH = 10;
    private const int PUBLIC_USERS_PER_PAGE = 20;

    /**
     * How long a forum signature may be, in characters. Mirrored by
     * Modules\Profile's own textarea; enforced by
     * ProfileController::handleForumProfileUpdateRequest() against the *raw*
     * input, before sanitizeSignature() runs - purifying can only shrink the
     * string or (by normalizing attributes, e.g. adding rel/target to links)
     * slightly grow it, and rejecting on the post-purify length would mean
     * rejecting input the author was shown as acceptable.
     */
    public const int MAX_SIGNATURE_LENGTH = 2000;

    /**
     * The tags a forum signature may use - inline formatting, links and images.
     * See signaturePurifier() for why this is far shorter than FeedService's
     * post-body whitelist.
     *
     * img gets no width/height/style: CSS sizes signature images (see
     * .forum-signature img in custom-content.css), and an author who could
     * declare their own would just fight it.
     */
    private const string SIGNATURE_ALLOWED_HTML = 'a[href|title],b,strong,i,em,u,s,del,br,span,code,small,img[src|alt|title]';

    /**
     * The generic default avatar shown wherever a user hasn't uploaded
     * their own - a plain person silhouette, not the site favicon/brand
     * mark (which makes an ugly, unreadable avatar at these sizes).
     */
    public const string DEFAULT_AVATAR_URL = '/assets/img/default-avatar.svg';

    /**
     * How stale a session's `last_used_at` may be before its owner stops
     * counting as online - see the presence methods at the bottom of this
     * class.
     *
     * 90 seconds because that's what the messenger has always used, and
     * it's comfortably longer than its own poll interval, so a user sitting
     * on an open chat never flickers offline between polls. On ordinary
     * pages (no polling) it reads as "loaded a page within the last minute
     * and a half", which is the honest meaning of a session-derived
     * presence signal.
     */
    public const int ONLINE_WINDOW_SECONDS = 90;

    /**
     * How stale a session row has to be before another request bothers to
     * update its `last_used_at`.
     *
     * Presence resolves to ONLINE_WINDOW_SECONDS (90), so a write per
     * request buys precision nothing reads. 30 seconds keeps whoever it is
     * comfortably inside the window while reducing an active client to
     * roughly one write every half minute.
     *
     * Public because AuthService applies the same rule to authenticated
     * sessions (its currentUser() is the other writer of this column), and
     * the two must not drift apart - a class constant, not a dependency,
     * so nothing about the layering changes.
     */
    public const int PRESENCE_TOUCH_INTERVAL_SECONDS = 30;

    /**
     * Cookie that identifies one anonymous visitor across requests. Its own
     * cookie, not a reuse of the auth one (AuthService::COOKIE_NAME) or of
     * GuestFeedReadStore's - it holds an opaque, meaningless token whose only
     * job is "these page views are the same browser", and mixing that into a
     * cookie with a different lifetime and different sensitivity would make
     * both harder to reason about.
     */
    private const string VISIT_COOKIE_NAME = 'SE_VISIT';

    /**
     * How long the visit cookie itself stays valid. Long, because it's the
     * only thing that makes a returning visitor recognizable as one - and
     * cheap, because it costs nothing on the server: the token proves
     * itself by its signature, not by a row (see isOwnVisitToken()).
     */
    private const int VISIT_COOKIE_TTL_SECONDS = 30 * 86400;

    /**
     * How long an anonymous visitor's *row* stays valid, which is a
     * different question from the cookie above and gets a much shorter
     * answer: the row is read only within ONLINE_WINDOW_SECONDS of being
     * written, and cleanupExpiredSessions() deletes it once this passes.
     * A day rather than 90 seconds only so the table keeps enough recent
     * history to be worth looking at while debugging.
     *
     * A returning visitor whose row was swept just gets a new one from the
     * same cookie on their next request - nothing is lost.
     */
    private const int VISIT_SESSION_TTL_SECONDS = 86400;

    /**
     * How many rows one DELETE removes, and how many of those a single cron
     * run issues before leaving the rest for the next hour.
     *
     * Bounded on purpose: the first run against a table nobody has ever
     * swept could otherwise be one enormous statement. 20 x 1000 is far
     * more than an hour of this site's traffic ever produces, so in steady
     * state the loop exits on the first short batch - at the cost that a
     * genuinely huge backlog drains at 20k rows/hour rather than in one
     * go, which is the right way round for a sweep nobody is waiting on.
     */
    private const int CLEANUP_BATCH_SIZE = 1000;

    private const int CLEANUP_MAX_BATCHES = 20;

    /**
     * Lazily built by signaturePurifier(), then reused - hence not readonly
     * and not a constructor dependency: most requests never write a signature
     * at all and shouldn't pay for an HTMLPurifier.
     */
    private static ?HTMLPurifier $signaturePurifier = null;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PdoDatabase $db,
        private readonly MailService $mailService,
        private readonly TranslationManager $tm,
        private readonly NotificationService $notifications,
        private readonly UserSessionRepository $sessionRepository,
        // Only for appSecret(), which signs the anonymous visit cookie -
        // see recordGuestPresence().
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @return array{q:string, sort:string, direction:string}
     */
    public function normalizePublicUsersListFilters(array $input): array
    {
        $query = new QueryParams($input);

        return [
            'q' => $query->trimmed('q'),
            'sort' => $this->normalizePublicUsersSort($query->string('sort', 'registered')),
            'direction' => $this->normalizePublicUsersDirection($query->string('direction', 'desc')),
        ];
    }

    /**
     * @param array<string,mixed> $input
     * @return array{
     *     data:list<array{id:int, displayName:string, username:?string, avatarUrl:string, createdAt:int}>,
     *     filters:array{q:string, sort:string, direction:string},
     *     pagination:array{currentPage:int, totalPages:int, total:int, limit:int}
     * }
     */
    public function getPublicUsersListPayload(array $input): array
    {
        $filters = $this->normalizePublicUsersListFilters($input);
        $currentPage = max(1, (int) ($input['page'] ?? 1));
        $total = $this->userRepository->countPublicUsers($filters['q']);
        $totalPages = max(1, (int) ceil($total / self::PUBLIC_USERS_PER_PAGE));
        $currentPage = min($currentPage, $totalPages);

        return [
            'data' => $this->userRepository->findPublicUsers(
                self::PUBLIC_USERS_PER_PAGE,
                ($currentPage - 1) * self::PUBLIC_USERS_PER_PAGE,
                $filters['q'],
                $filters['sort'],
                $filters['direction'],
            ),
            'filters' => $filters,
            'pagination' => [
                'currentPage' => $currentPage,
                'totalPages' => $totalPages,
                'total' => $total,
                'limit' => self::PUBLIC_USERS_PER_PAGE,
            ],
        ];
    }

    public function getPublicUsersTotalPages(string $query): int
    {
        return max(1, (int) ceil($this->userRepository->countPublicUsers($query) / self::PUBLIC_USERS_PER_PAGE));
    }

    /**
     * Looks up a user by their public username (the URL-safe handle, not the
     * free-text `nick`) for the public profile page. Only active accounts
     * are visible, same as the public users list.
     */
    public function findPublicUserByUsername(string $username): ?User
    {
        $username = trim($username);

        if ($username === '') {
            return null;
        }

        return $this->userRepository->findByUsername($username);
    }

    /**
     * Looks up a user by id for a public byline (e.g. a blog post's author),
     * where the caller only has owner_id on hand and not the username -
     * same is_active visibility rule as findPublicUserByUsername().
     */
    public function findPublicUserById(int $id): ?User
    {
        if ($id <= 0) {
            return null;
        }

        return $this->userRepository->findActiveById($id);
    }

    /**
     * Batch form of findPublicUserById() - the community feed's per-post
     * author byline (title, avatar, link) needs every post's owner resolved
     * at once rather than one query per card.
     *
     * @param list<int> $ids
     * @return array<int, User> keyed by id
     */
    public function findPublicUsersByIds(array $ids): array
    {
        return $this->userRepository->findActiveByIds($ids);
    }

    /**
     * Resolves a user's avatar for display, falling back to
     * DEFAULT_AVATAR_URL when unset - the single place that fallback is
     * decided (mirrors CommunityService::resolveImageUrl() for a
     * community's own image). Every controller/template that shows an
     * avatar is expected to call this (or read an already-resolved value a
     * controller built via it) rather than hardcode a fallback path of its
     * own.
     *
     * Static because it needs nothing from an instance, and because a module
     * service that only wants this one decision shouldn't have to pull
     * UserService's seven constructor dependencies into its own graph to get
     * it (Modules\Profile\FriendListService is the case in point). Existing
     * `$this->userService->resolveAvatarUrl(...)` call sites keep working
     * unchanged - PHP allows reaching a static method through an instance.
     */
    public static function resolveAvatarUrl(string $avatarUrl): string
    {
        return $avatarUrl !== '' ? $avatarUrl : self::DEFAULT_AVATAR_URL;
    }

    /**
     * The only place a forum signature is made safe, so every write into
     * users.signature goes through it (the profile form and WpUsersImport).
     * Stored output is trusted HTML, which is what lets forums.topic-view print
     * it with |raw - same sanitize-on-write contract FeedService uses for post
     * bodies.
     *
     * Newlines stay newlines here so the profile textarea round-trips;
     * renderSignature() converts them.
     *
     * Static because it needs nothing but its own purifier, and bin/wp-import
     * shouldn't have to build a whole UserService to clean a string.
     */
    public static function sanitizeSignature(string $raw): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        $clean = trim(self::signaturePurifier()->purify($raw));

        // Added after purify (they're not in the whitelist, so purifying again
        // just strips and re-adds them): don't leak the reader's referrer to
        // whoever hosts the image, and don't fetch it until it scrolls into
        // view.
        return str_replace('<img ', '<img loading="lazy" referrerpolicy="no-referrer" ', $clean);
    }

    /**
     * Display form of an already-stored signature. Does not purify - see
     * sanitizeSignature(). Adds the author's line breaks, and returns '' for an
     * empty signature, which is forums.topic-view's "has one at all?" test.
     */
    public static function renderSignature(string $stored): string
    {
        $stored = trim($stored);

        if ($stored === '') {
            return '';
        }

        // nl2br(), not Twig's |nl2br: the value is printed with |raw and
        // |nl2br would escape it first.
        return nl2br($stored, false);
    }

    /**
     * Built on first use and reused - an import run sanitizes thousands of
     * signatures in one process.
     *
     * Far stricter than FeedService's post-body config: a signature repeats
     * under every post its author has made, so it gets inline formatting,
     * links and images, and no block-level layout at all.
     */
    private static function signaturePurifier(): HTMLPurifier
    {
        if (self::$signaturePurifier instanceof HTMLPurifier) {
            return self::$signaturePurifier;
        }

        $config = HTMLPurifier_Config::createDefault();

        $config->set('HTML.Allowed', self::SIGNATURE_ALLOWED_HTML);
        // No data:/javascript: - so an <img src> can only ever be a real
        // http(s) fetch, never inline script or markup.
        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
            'mailto' => true,
        ]);
        // Links get rel/target forced on rather than left to the author.
        $config->set('HTML.Nofollow', true);
        $config->set('HTML.TargetBlank', true);
        // Every img gets an alt, empty if the author gave none, so a broken
        // image doesn't print a URL under the post.
        $config->set('Attr.DefaultImageAlt', '');
        // No custom element/attribute definitions (unlike FeedService's own
        // purifier), so there's nothing to cache and no DefinitionID to
        // collide with its 'stream-engine-html'.
        $config->set('Cache.DefinitionImpl', null);

        return self::$signaturePurifier = new HTMLPurifier($config);
    }

    private function normalizePublicUsersSort(string $sort): string
    {
        return in_array($sort, ['registered', 'name'], true) ? $sort : 'registered';
    }

    private function normalizePublicUsersDirection(string $direction): string
    {
        return strtolower($direction) === 'asc' ? 'asc' : 'desc';
    }

    /**
     * @param string $email
     * @param string $password
     * @return int
     * @throws ValidationException
     */
    public function register(string $email, string $password): int
    {
        $email = trim(mb_strtolower($email));

        $this->validateEmail($email);
        $this->validatePassword($password);

        if ($this->userRepository->existsByEmail($email)) {
            throw new ValidationException($this->tm->trans('user.email_already_used'));
        }

        $siteUrl = $this->requireSiteUrl();

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $newUserId = $this->userRepository->create(
            $email,
            $hash
        );

        $verificationToken = $this->createEmailVerification($newUserId);

        $activationLink = $siteUrl.'/register/?token='.rawurlencode($verificationToken);

        try {
            $this->mailService->send(
                to: $email,
                subject: $this->tm->trans('user.mail.registration_subject'),
                template: 'users/welcome',
                context: [
                    'activationLink' => $activationLink,
                ]
            );
        } catch (Exception|LoaderError|RuntimeError|SyntaxError $e) {
            throw new RuntimeException("Unable to send mail: {$e->getMessage()}");
        }

        $this->notifications->notify(new Notification(
            recipientUserId: $newUserId,
            type: 'system.welcome',
            messengerText: $this->tm->trans('user.system_message.welcome'),
            deduplicationKey: 'system.welcome:'.$newUserId,
        ));

        return $newUserId;

    }

    /**
     * Uses index on write: token_hash unique constraint protects token lookup.
     * @throws ValidationException
     */
    public function createEmailVerification(int $userId): string
    {
        try {
            $token = bin2hex(random_bytes(32));
        } catch (RandomException $e) {
            error_log($e->getMessage());
            throw new ValidationException('Unable to generate verification code');
        }
        $hash = hash('sha256', $token);

        $this->db->execute(
            '
        INSERT INTO email_verifications
        (user_id, token_hash, expires_at, created_at)
        VALUES (?, ?, UNIX_TIMESTAMP() + 86400, UNIX_TIMESTAMP())
        ',
            [$userId, $hash]
        );

        return $token;
    }

    /**
     * @throws ValidationException
     */
    /**
     * Uses index: token_hash (token_hash)
     * Uses index: PRIMARY(id) on users update.
     * Uses index: user_id (user_id) on email_verifications delete.
     * @throws ValidationException
     */
    public function verifyEmail(string $token): int
    {
        $hash = hash('sha256', $token);

        $row = $this->db->fetchOne(
            '
        SELECT user_id
        FROM email_verifications
        WHERE token_hash = ?
          AND expires_at > UNIX_TIMESTAMP()
        ',
            [$hash]
        );

        if (! $row) {
            throw new ValidationException('Invalid token');
        }

        $userId = (int) $row['user_id'];

        $this->db->execute(
            'UPDATE users SET is_active = 1 WHERE id = ?',
            [$userId]
        );

        $this->db->execute(
            'DELETE FROM email_verifications WHERE user_id = ?',
            [$userId]
        );

        return $userId;
    }

    /**
     * @throws ValidationException
     */
    private function validateEmail(string $email): void
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException($this->tm->trans('user.email_invalid'));
        }
    }

    /**
     * @throws ValidationException
     */
    private function validatePassword(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new ValidationException(
                $this->tm->trans('user.password_too_short', [
                    'min' => self::MIN_PASSWORD_LENGTH,
                ])
            );
        }

        if (mb_strlen($password) > 255) {
            throw new ValidationException($this->tm->trans('user.password_too_long'));
        }
    }

    public function createPasswordResetToken(string $email): void
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user) {
            return; // no response for not to reveal the email
        }

        $siteUrl = $this->requireSiteUrl();

        try {
            $token = bin2hex(random_bytes(32));
        } catch (RandomException $e) {
            throw new RuntimeException("Unable to generate verification code: " . $e->getMessage());
        }

        $this->db->execute(
            "INSERT INTO password_resets (user_id, token, created_at)
         VALUES (?, ?, ?)",
            [$user['id'], $token, time()]
        );

        $retrieveLink = $siteUrl.'/forgot-password/?token='.rawurlencode($token);

        try {
            $this->mailService->send(
                to: $email,
                subject: $this->tm->trans('user.mail.password_reset_subject'),
                template: 'users/retrieve',
                context: [
                    'retrieveLink' => $retrieveLink,
                ]
            );
        } catch (Exception|LoaderError|RuntimeError|SyntaxError $e) {
            throw new RuntimeException("Unable to send mail: " . $e->getMessage());
        }
    }

    private function requireSiteUrl(): string
    {
        return $this->config->siteUrl()
            ?? throw new RuntimeException('Canonical site URL is not configured');
    }


    /**
     * Uses index: token (token)
     */
    public function getPasswordResetToken(string $token): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM password_resets WHERE token = ?",
            [$token]
        );
    }

    /**
     * @param string $token
     * @param string $password
     * @throws ValidationException
     */
    public function resetPasswordByToken(string $token, string $password): void
    {
        $this->validatePassword($password);

        $row = $this->getPasswordResetToken($token);
        if (!$row) {
            throw new ValidationException($this->tm->trans('user.password_reset_invalid'));
        }

        // Checking TTL (1 hour)
        if ($row['created_at'] < time() - 3600) {
            throw new ValidationException($this->tm->trans('user.password_reset_expired'));
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $this->db->execute(
            "UPDATE users SET password_hash = ? WHERE id = ?",
            [$hash, $row['user_id']]
        );

        $this->db->execute(
            "DELETE FROM password_resets WHERE token = ?",
            [$token]
        );

        $user = $this->userRepository->findById($row['user_id']);

        try {
            $this->mailService->send(
                to: $user->email,
                subject: $this->tm->trans('user.mail.password_reset_subject'),
                template: 'users/reset',
            );
        } catch (Exception|LoaderError|RuntimeError|SyntaxError $e) {
            throw new RuntimeException("Unable to reset password: " . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------
    // Presence: "who is online right now"
    //
    // A fact about users, so it lives here rather than in a service of
    // its own, next to the profile lookups the same callers already use.
    // The three methods below are the site's *only* answer to that
    // question outside the messenger: the public profile's online dot and
    // the forum stats card's "Online now" list both come through
    // here, so the window is decided in one place (ONLINE_WINDOW_SECONDS)
    // and the per-user "hide my online status" opt-out has one obvious home.
    //
    // The one deliberate exception is MessageService, which reads
    // UserSessionRepository directly: this service already depends on it
    // (register()'s welcome notification), so having the messenger depend
    // back on this one would be a constructor cycle. That's the trade-off:
    // MessageService applies the same hidePresence filter on its side.
    //
    // No storage of its own: presence is derived from
    // `user_sessions.last_used_at`, which AuthService::currentUser()
    // already touches on every authenticated request (throttled to
    // PRESENCE_TOUCH_INTERVAL_SECONDS). No heartbeat table, no cron, no
    // extra write path for members.
    //
    // Guests and bots live in that same table, as rows with a NULL user_id
    // (and, for a crawler, a bot_name) - see recordGuestPresence() below
    // and migrations/20260912000000_initial.sql.
    // Unlike members, they need a write of their own: an anonymous request
    // touches nothing otherwise. What keeps that affordable is
    // PRESENCE_TOUCH_INTERVAL_SECONDS and the "no row until the visitor
    // hands our cookie back" rule, both explained on that method.
    // ---------------------------------------------------------------

    /**
     * Which of $ids are online - the batch primitive for any caller with a
     * list of users already in hand (a member list, a page of post authors)
     * that wants online dots for all of them in one query. Today only
     * isOnline() below uses it; the messenger's own dots go through
     * UserSessionRepository directly, for the cycle reason above.
     *
     * @param list<int> $ids
     * @return array<int, true> keyed by user id, so callers test with
     *                          isset() instead of in_array()
     */
    public function filterOnline(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->sessionRepository->getOnlineUserIds($ids, self::ONLINE_WINDOW_SECONDS);
    }

    /**
     * Single-user convenience over filterOnline(). Worth its own method
     * only because the isset() dance around a one-element array read badly
     * at the three call sites in Modules\Users that need it.
     */
    public function isOnline(int $userId): bool
    {
        return isset($this->filterOnline([$userId])[$userId]);
    }

    /**
     * Everyone online right now, resolved to real user records.
     *
     * Returns both the full total and a capped display list, because the
     * two are different questions: "12 members online" should stay true
     * even when only the first few names are shown. `hidden` is what the
     * caller's "and N more" tail should say - never negative, and 0 whenever
     * the whole list fits.
     *
     * Only active accounts appear: findActiveByIds() drops deactivated
     * users (a banned account with a still-valid session shouldn't be
     * advertised as present), and the reserved system account is filtered
     * out explicitly - it never logs in today, but it does appear on the
     * public members list, and "system online" would be nonsense if some
     * future admin tool ever signed in as it.
     *
     * Two queries, both bounded by the presence window: the id list is only
     * as long as there are people on the site in the last 90 seconds.
     *
     * @param int $limit how many users to resolve for display; the total is
     *                   unaffected by it
     * @return array{total: int, users: list<User>, hidden: int}
     */
    public function onlineMembers(int $limit = 30): array
    {
        $ids = array_values(array_filter(
            $this->sessionRepository->findOnlineUserIds(self::ONLINE_WINDOW_SECONDS),
            static fn (int $id): bool => $id !== User::SYSTEM_USER_ID
        ));

        if ($ids === []) {
            return ['total' => 0, 'users' => [], 'hidden' => 0];
        }

        $byId = $this->userRepository->findActiveByIds($ids);

        // findActiveByIds() returns a map keyed by id, in whatever order the
        // database handed it back - re-walk the original id list so the
        // "most recently seen first" ordering findOnlineUserIds() went out
        // of its way to produce actually survives to the view.
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id]) && ! $byId[$id]->hidePresence) {
                $ordered[] = $byId[$id];
            }
        }

        $total = count($ordered);
        $shown = $limit > 0 ? array_slice($ordered, 0, $limit) : [];

        return [
            'total' => $total,
            'users' => $shown,
            'hidden' => $total - count($shown),
        ];
    }

    /**
     * How many anonymous humans are on the site right now.
     *
     * Crawlers are not among them: their rows carry a bot_name and
     * countOnlineGuests() filters those out, so a busy Googlebot can never
     * show up as "12 guests". They get their own line, from
     * onlineBotNames().
     *
     * Also a separate figure from onlineMembers()' total on purpose: "N
     * members" should keep meaning members, and one combined number
     * would be the one thing nobody can act on.
     */
    public function onlineGuestCount(): int
    {
        return $this->sessionRepository->countOnlineGuests(self::ONLINE_WINDOW_SECONDS);
    }

    /**
     * Which crawlers are on the site right now, most recently seen first -
     * detected bot names, not a count.
     *
     * @return list<string>
     */
    public function onlineBotNames(): array
    {
        return $this->sessionRepository->findOnlineBotNames(self::ONLINE_WINDOW_SECONDS);
    }

    /**
     * Deletes session rows whose `expires_at` has passed - members' expired
     * logins, yesterday's anonymous visitors, crawlers that stopped coming.
     * Run hourly by the `users:sessions-cleanup` cron task (registered in
     * Modules\Users' controller, the same place the password-reset sweep
     * lives).
     *
     * Only became necessary once anonymous traffic started writing here:
     * before that the table grew one row per login and nobody minded. Now
     * it grows with visitors, and nothing else ever removes a row -
     * logout() deletes one, and that's it.
     *
     * Deletes in bounded batches and stops after CLEANUP_MAX_BATCHES, so a
     * first run against a table nobody has swept can't turn into one
     * enormous statement; whatever is left waits for the next hour. Stops
     * early - the usual case - as soon as a batch comes back short, which
     * means there was nothing more to delete.
     *
     * @return int how many rows went away, for a caller that wants to log it
     */
    public function cleanupExpiredSessions(): int
    {
        $deleted = 0;

        for ($batch = 0; $batch < self::CLEANUP_MAX_BATCHES; $batch++) {
            $removed = $this->sessionRepository->deleteExpired(self::CLEANUP_BATCH_SIZE);
            $deleted += $removed;

            if ($removed < self::CLEANUP_BATCH_SIZE) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * The bot this user agent belongs to, or null if it looks like a human.
     *
     * Crawler detection comes from jaybizzle/crawler-detect, so the bot
     * database updates through Composer with the rest of the PHP deps.
     */
    public function detectBot(string $userAgent): ?string
    {
        if ($userAgent === '') {
            // No User-Agent at all is a strong bot signal, but it's also
            // what a privacy tool or a broken proxy sends - and there'd be
            // no name to put in bot_name anyway. Treated as a person, which
            // in practice costs nothing: a client that sends no UA almost
            // never keeps cookies either, and without a returned cookie it
            // never gets a row at all.
            return null;
        }

        $crawlerDetect = new CrawlerDetect(userAgent: $userAgent);

        if ($crawlerDetect->isCrawler()) {
            return $crawlerDetect->getMatches() ?: 'Crawler';
        }

        return null;
    }

    /**
     * Records that an anonymous visitor is here, for the the guest-count half of
     * the presence figures. Called once per request that isn't
     * authenticated - every request, not only page renders: form POSTs,
     * API calls and 404s all pass through StreamEngine::handleRequest().
     * A logged-in request needs nothing, since AuthService::currentUser()
     * already touches that member's own session row.
     *
     * Three paths, in the order they're tried:
     *
     * 1. **A known crawler** (detectBot()) gets exactly one row for its
     *    whole existence, keyed by a token derived from its name - see
     *    UserSessionRepository::recordBotVisit(). Bots don't keep cookies,
     *    so the rule below can't apply to them; without this they'd either
     *    be invisible or insert a row per request.
     * 2. **A visitor with no visit cookie, or one we didn't sign,** gets a
     *    fresh signed cookie and *no row*. That's the whole flood rule, and
     *    the signature is what makes it one: a client that ignores cookies
     *    (most naive scrapers, and every hit from a crawler not in the map)
     *    never reaches step 3, and a client that invents cookie values
     *    doesn't either. The cost is that a genuine first-time visitor is
     *    counted from their second request, which for a presence figure is
     *    a rounding error.
     * 3. **A visitor with a cookie** is inserted on first sight and then
     *    only re-touched once their row is PRESENCE_TOUCH_INTERVAL_SECONDS
     *    stale.
     *
     * Rows outlive the presence window (they stop counting after
     * ONLINE_WINDOW_SECONDS but stay until VISIT_SESSION_TTL_SECONDS
     * passes) and are then swept by cleanupExpiredSessions(), hourly.
     *
     * Reads `$_COOKIE`/`$_SERVER` and writes a cookie itself rather than
     * taking them as arguments, the same way AuthService::createSession()
     * already does - the alternative is threading three request-scoped
     * values through the bootstrap for one call site.
     *
     * @throws RandomException
     */
    public function recordGuestPresence(): void
    {
        // user_sessions.user_agent is varchar(255) and the connection runs
        // in strict mode: an over-long header would be a fatal write, and
        // unlike login (the only writer before this) that would happen on
        // every request from that client.
        $userAgent = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $expiresAt = time() + self::VISIT_SESSION_TTL_SECONDS;

        $botName = $this->detectBot($userAgent);

        if ($botName !== null) {
            $this->sessionRepository->recordBotVisit(
                tokenHash: $this->botTokenHash($botName),
                botName: $botName,
                userAgent: $userAgent,
                ip: $ip,
                expiresAt: $expiresAt,
                minAgeSeconds: self::PRESENCE_TOUCH_INTERVAL_SECONDS,
            );

            return;
        }

        // A cookie can be anything the client feels like sending, including
        // an array - reject anything that isn't a plain string before it
        // reaches hash().
        $raw = $_COOKIE[self::VISIT_COOKIE_NAME] ?? null;
        $token = is_string($raw) ? $raw : '';

        if (! $this->isOwnVisitToken($token)) {
            // No cookie, or one we didn't issue. Either way: hand out a
            // signed one and write nothing. This is what actually bounds
            // the table - without the signature check, `curl -b
            // SE_VISIT=$RANDOM` in a loop would insert a row per request.
            $this->startVisit();

            return;
        }

        $tokenHash = hash('sha256', $token);
        $session = $this->sessionRepository->findValid($tokenHash);

        if ($session === null) {
            // A token we issued whose row doesn't exist yet (their second
            // request) or has expired. Upsert rather than insert: two
            // concurrent requests from the same browser both land here, and
            // a plain INSERT would make the loser a 500.
            $this->sessionRepository->createGuest($tokenHash, $userAgent, $ip, $expiresAt);

            return;
        }

        $this->sessionRepository->touchIfStale(
            (int)$session['id'],
            self::PRESENCE_TOUCH_INTERVAL_SECONDS
        );
    }

    /**
     * Hands the visitor an opaque token and remembers nothing yet - the row
     * appears on their next request (see recordGuestPresence()'s step 2).
     *
     * `secure` follows the actual connection rather than being hardcoded
     * true the way AuthService's auth cookie is: this token isn't a
     * credential (it identifies a visit, grants nothing), and a
     * permanently-secure cookie would silently never be set over plain HTTP
     * - which is exactly how local development runs, so guest presence
     * would look broken there and nowhere else.
     *
     * @throws RandomException
     */
    private function startVisit(): void
    {
        $random = bin2hex(random_bytes(32));
        $token = $random.'.'.$this->visitTokenSignature($random);

        // Written before the headers_sent() bail below on purpose: a caller
        // (or a test) that reads the cookie back within this same request
        // then sees the token either way, and a second call in one request
        // won't hand out a second one.
        $_COOKIE[self::VISIT_COOKIE_NAME] = $token;

        if (headers_sent()) {
            return;
        }

        setcookie(
            self::VISIT_COOKIE_NAME,
            $token,
            [
                // The cookie's own, much longer lifetime - not the row's.
                // They answer different questions; see the two constants.
                'expires' => time() + self::VISIT_COOKIE_TTL_SECONDS,
                'path' => '/',
                // Three ways a server says "not HTTPS": omit the variable
                // (nginx, this repo's own config), set it empty (some
                // FastCGI/proxy setups), or the literal 'off' (Apache/IIS).
                'secure' => ! in_array($_SERVER['HTTPS'] ?? '', ['', 'off'], true),
                'httponly' => true,
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * Is this cookie value one we issued? A `<random>.<signature>` pair
     * whose signature still checks out.
     *
     * This is what makes "no row until the cookie comes back" an actual
     * bound rather than a description of well-behaved clients: without it,
     * any invented cookie value is a fresh visitor, and a loop of them
     * inserts a row per request - the hourly sweep bounds how long a row
     * lives, it can't bound how fast they appear. It is an abuse speed
     * bump, not an identity check: the token proves only that this browser
     * was handed something by this site, which is all presence needs to
     * know.
     */
    private function isOwnVisitToken(string $token): bool
    {
        if ($token === '' || substr_count($token, '.') !== 1) {
            return false;
        }

        [$random, $signature] = explode('.', $token);

        if ($random === '' || $signature === '') {
            return false;
        }

        return hash_equals($this->visitTokenSignature($random), $signature);
    }

    private function visitTokenSignature(string $random): string
    {
        // Domain-separated so this can never collide with another use of
        // the same secret (Config::appSecret() may well be CRON_KEY).
        return hash_hmac('sha256', 'visit:'.$random, $this->config->appSecret());
    }

    /**
     * The token_hash a crawler's single row is keyed by (see
     * UserSessionRepository::recordBotVisit()).
     *
     * Salted with the app secret rather than being a plain
     * hash('sha256', 'bot:Googlebot') anybody could compute: `token_hash`
     * is also what AuthService looks sessions up by, so a guessable value
     * would let a stranger send it as their auth cookie and touch - or, via
     * logout(), delete - the bot's row.
     */
    private function botTokenHash(string $botName): string
    {
        return hash_hmac('sha256', 'bot:'.$botName, $this->config->appSecret());
    }
}

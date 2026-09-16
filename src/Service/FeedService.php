<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use DateMalformedStringException;
use DateTimeImmutable;
use HTMLPurifier;
use HTMLPurifier_Config;
use InvalidArgumentException;
use RuntimeException;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\GuestFeedReadStore;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\FeedTerm;
use StreamEngine\Domain\User;
use StreamEngine\Repository\FeedFavoriteRepository;
use StreamEngine\Repository\FeedMetadataRepository;
use StreamEngine\Repository\FeedRatingRepository;
use StreamEngine\Repository\FeedReadRepository;
use StreamEngine\Repository\FeedRepository;

class FeedService
{
    /**
     * Single source of truth for how long a generated feed slug (blog,
     * community, blog-post, book, term, ...) is allowed to be, not counting
     * whatever numeric uniqueness suffix ("-2", "-3", ...) a caller appends
     * on collision. Every slug generator in the codebase - live
     * (CommunityService::uniqueCommunitySlug(), BlogPostService::
     * uniqueBlogPostSlug()), and unrelated ones
     * (FeedTermRepository::slugify())
     * - reads this constant rather than hardcoding their own, so changing
     * the limit only ever needs to happen here.
     */
    public const int MAX_SLUG_LENGTH = 75;

    private const int MAX_METADATA_NAME_LENGTH = 64;

    private const int MAX_METADATA_CONTENT_LENGTH = 65535;

    private const int MIN_RATING_VALUE = 1;

    private const int MAX_RATING_VALUE = 5;

    public const int COMMENT_EDIT_WINDOW_SECONDS = 86400;

    private const array ALLOWED_VISIBILITY = ['public', 'members', 'private'];

    private HTMLPurifier $purifier;

    public function __construct(
        private readonly FeedRepository $repository,
        private readonly UrlGenerator $urlGenerator,
        private readonly AccessService $accessService,
        private readonly Formatter $formatter,
        private readonly TranslationManager $tm,
        private readonly ?FeedMetadataRepository $metadataRepository = null,
        private readonly ?FeedRatingRepository $ratingRepository = null,
        private readonly ?FeedFavoriteRepository $favoriteRepository = null,
        private readonly ?FeedReadRepository $readRepository = null,
        private readonly ?GuestFeedReadStore $guestReadStore = null,
    ) {
        $config = HTMLPurifier_Config::createDefault();

        $config->set('URI.AllowedSchemes', [
            'http' => true,
            'https' => true,
        ]);

        $config->set('HTML.DefinitionID', 'stream-engine-html');
        $config->set('HTML.DefinitionRev', 1);
        $config->set('Cache.DefinitionImpl', null);
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addAttribute('div', 'class', 'Text');
            $def->addAttribute('div', 'tabindex', 'Text');
            $def->addAttribute('div', 'data-bs-spy', 'Text');
            $def->addAttribute('div', 'data-bs-target', 'Text');
            $def->addAttribute('div', 'data-bs-offset', 'Text');
            $def->addAttribute('div', 'data-bs-smooth-scroll', 'Text');
            $def->addAttribute('h1', 'class', 'Text');
            $def->addAttribute('h2', 'class', 'Text');
            $def->addAttribute('h3', 'class', 'Text');
            $def->addAttribute('h4', 'class', 'Text');
            $def->addAttribute('h1', 'id', 'Text');
            $def->addAttribute('h2', 'id', 'Text');
            $def->addAttribute('h3', 'id', 'Text');
            $def->addAttribute('h4', 'id', 'Text');
            $def->addAttribute('p', 'class', 'Text');
            $def->addAttribute('img', 'class', 'Text');
            $def->addAttribute('a', 'class', 'Text');
            $def->addAttribute('a', 'target', 'Text');
            $def->addAttribute('a', 'rel', 'Text');
            $def->addAttribute('i', 'class', 'Text');
            $def->addAttribute('span', 'class', 'Text');
            // Quoted-post markup (see normalizeCommentContent()/
            // renderQuoteBlock() below) - blockquote/div/i already get
            // 'class' from HTMLPurifier's default "Common" attribute
            // collection, but every other custom class use above is
            // explicit too, so this stays consistent rather than relying
            // on that default.
            $def->addAttribute('blockquote', 'class', 'Text');

            $def->addElement(
                'nav',      // tag name
                'Block',    // type (block element)
                'Flow',     // allowed child elements
                'Common',   // standard attribute set
                [
                    'class' => 'Text',
                    'style' => 'Text',
                    'id' => 'Text',
                ]
            );

            // Trix serializes image/file attachments as <figure><img/a>...
            // <figcaption>...</figcaption></figure> - not part of the
            // default XHTML1 Transitional profile, so these need to be
            // registered explicitly or the whole attachment gets stripped.
            $def->addElement(
                'figure',
                'Block',
                'Flow',
                'Common',
                [
                    'class' => 'Text',
                ]
            );

            $def->addElement(
                'figcaption',
                'Block',
                'Inline',
                'Common',
                [
                    'class' => 'Text',
                ]
            );
        }

        $this->purifier = new HTMLPurifier($config);

    }

    /**
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    public function getFeedById(int $id, User $user): ?Feed
    {
        $feed = $this->repository->findById($id, $user);
        if (! $feed) {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }

        if (! $this->accessService->canAccessFeed($user, $feed)) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        $feed->canonicalUrl = $this->urlGenerator->feed($feed);
        $this->decorateFeed($feed);
        $this->decorateFeedsWithMetadata([$feed]);

        return $feed;
    }

    public function getFeedByOwnerAndType(int $ownerId, string $type, User $user): ?Feed
    {
        $feed = $this->repository->findByOwnerAndType($ownerId, $type, $user);
        if ($feed === null) {
            return null;
        }

        $feed->canonicalUrl = $this->urlGenerator->feed($feed);
        $this->decorateFeed($feed);
        $this->decorateFeedsWithMetadata([$feed]);

        return $feed;
    }

    /**
     * Return an arbitrary visible match from the global slug namespace.
     *
     * This legacy array-shaped lookup is intentionally unscoped and must not
     * be used to resolve route identity. Route consumers must select either
     * getFeedByTypeAndSlug() or getFeedByParentAndSlug().
     *
     * @return list<Feed>
     */
    public function getFeedBySlug(string $slug, User $user): array
    {
        $feeds = $this->repository->findBySlug(
            slug: $slug,
            user: $user
        );

        $this->decorateFeedsWithUrls($feeds);

        return $feeds;
    }

    public function getFeedByTypeAndSlug(string $type, string $slug, User $user): ?Feed
    {
        $feed = $this->repository->findByTypeAndSlug(
            type: $type,
            slug: $slug,
            user: $user
        );

        if ($feed === null) {
            return null;
        }

        $feed->canonicalUrl = $this->urlGenerator->feed($feed);
        $this->decorateFeed($feed);
        $this->decorateFeedsWithMetadata([$feed]);

        return $feed;
    }

    public function getFeedByParentAndSlug(?int $parentId, string $slug, User $user, ?string $type = null): ?Feed
    {
        $feed = $this->repository->findByParentAndSlug(
            parentId: $parentId,
            slug: $slug,
            user: $user,
            type: $type
        );

        if ($feed === null) {
            return null;
        }

        $feed->canonicalUrl = $this->urlGenerator->feed($feed);
        $this->decorateFeed($feed);
        $this->decorateFeedsWithMetadata([$feed]);

        return $feed;
    }

    public function getFeedsByParent(int $parentId, User $user): array
    {
        $feeds = $this->repository->findByParent(
            parentId: $parentId,
            user: $user
        );

        $this->decorateFeedsWithUrls($feeds);

        return $feeds;
    }

    public function getFeedsByParentAndType(?int $parentId, string $type, User $user, int $limit = 20): array
    {
        $feeds = $this->repository->findByParentAndType(
            parentId: $parentId,
            user: $user,
            type: $type,
            limit: $limit,
        );

        $this->decorateFeedsWithUrls($feeds);

        return $feeds;
    }

    /**
     * A container's own feeds of a type, paginated - e.g. a single community
     * page's own blog-post feed. Decorated the same way as the unpaginated
     * getFeedsByParentAndType() list, with a total for offset pagination.
     *
     * @return array{items: Feed[], total: int}
     */
    public function getFeedsByParentAndTypePage(?int $parentId, string $type, User $user, int $limit, int $offset = 0): array
    {
        $result = $this->repository->findByParentAndTypePage($parentId, $type, $user, $limit, $offset);

        $this->decorateFeedsWithUrls($result['items']);

        return $result;
    }

    public function getPrevNext(Feed $feed, User $user): array
    {
        $prev = $this->repository->findSibling(
            $feed->parentId,
            $feed->position,
            '<',
            'DESC',
            $user
        );

        $next = $this->repository->findSibling(
            $feed->parentId,
            $feed->position,
            '>',
            'ASC',
            $user
        );

        // Filter empty
        $feeds = array_filter([$prev, $next]);

        $this->decorateFeedsWithUrls($feeds);

        return [
            'prev' => $prev,
            'next' => $next,
        ];
    }

    /**
     * $containerId/$containerType (if given) are passed straight through to
     * FeedRepository::insert() - see that method's own docblock for what
     * they're for. Not validated against $parentId/$user here: callers
     * (e.g. Modules\Users\BlogPostService::createBlogPost()) already
     * resolved/authorized the container feed themselves before calling this.
     *
     * @throws ValidationException
     */
    public function createFeed(
        string $title,
        ?string $slug,
        string $type,
        ?int $parentId,
        ?string $description,
        ?string $imageUrl,
        string $content,
        User $user,
        mixed $metadata = null,
        string $visibility = 'public',
        ?int $containerId = null,
        ?string $containerType = null,
    ): Feed {

        $content = $this->purifier->purify($content);
        $description = $this->purifier->purify($description);
        $imageUrl = $this->validateFeedImage($imageUrl ?? null);
        $metadata = $metadata !== null ? $this->normalizeMetadataInput($metadata) : null;
        $visibility = $this->normalizeVisibility($visibility);

        if ($parentId) {
            $parent = $this->repository->findById($parentId, $user);
            if (! $parent) {
                throw new ValidationException($this->tm->trans('feed.parent_not_found'));
            }
        }

        $id = $this->repository->insert(
            ownerId: $user->id,
            title: $title,
            slug: $slug,
            parentId: $parentId,
            type: $type,
            description: $description,
            imageUrl: $imageUrl,
            content: $content,
            visibility: $visibility,
            containerId: $containerId,
            containerType: $containerType,
        );

        if ($metadata !== null) {
            $this->replaceMetadataForFeed($id, $metadata);
        }

        $feed = $this->repository->findById($id, $user);
        if ($feed) {
            $this->decorateFeedsWithMetadata([$feed]);
        }

        return $feed;
    }

    /**
     * Shared visibility validation for any feed type (createFeed() below
     * always runs input through it) - also called directly by
     * Modules\Users\BlogPostService before it does anything else, so an
     * invalid value fails fast without touching the database.
     *
     * @throws ValidationException
     */
    public function normalizeVisibility(string $visibility): string
    {
        $visibility = trim($visibility);

        if (! in_array($visibility, self::ALLOWED_VISIBILITY, true)) {
            throw new ValidationException($this->tm->trans('feed.blog_visibility_invalid'));
        }

        return $visibility;
    }

    /**
     * @throws ForbiddenException
     * @throws ValidationException
     */
    public function createComment(int $parentId, string $content, User $user): Feed
    {
        if ($user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        $parent = $this->repository->findById($parentId, $user);
        if (! $parent) {
            throw new NotFoundException($this->tm->trans('feed.parent_not_found'));
        }

        $normalizedContent = $this->normalizeCommentContent($content);

        $id = $this->repository->insert(
            ownerId: $user->id,
            title: '',
            slug: null,
            parentId: $parentId,
            type: 'comment',
            description: null,
            imageUrl: null,
            content: $normalizedContent
        );

        $comment = $this->repository->findById($id, $user);
        if (! $comment) {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }

        $this->decorateFeed($comment);

        return $comment;
    }

    /**
     * Edits a comment's own content in place - the "Edit" action on a
     * comment (or a forum topic's own opening post: it's a 'forum-post'
     * feed with the exact same owner/content shape, so it's allowed through
     * here too - see the type check below for what's deliberately excluded
     * instead). Same ownership rule as updateFeed()/deleteFeed()
     * (AccessService::canEditFeed() - owner, admin, or container
     * moderator), plus a 24h window (COMMENT_EDIT_WINDOW_SECONDS) that
     * admins bypass, same as a moderator/admin being able to act outside
     * the rules an ordinary owner is held to elsewhere in this class.
     *
     * Deliberately narrower than updateFeed(): only content changes (title/
     * slug/parentId/type/description/imageUrl are read back from the
     * existing row and passed through unchanged) - a comment editor has no
     * business touching any of those, unlike updateFeed()'s admin-only
     * generic feed editor.
     *
     * @throws ForbiddenException
     * @throws ValidationException
     */
    public function editComment(int $commentId, string $content, User $user): Feed
    {
        if ($user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        $comment = $this->repository->findById($commentId, $user);
        if (! $comment || ! in_array($comment->type, ['comment', 'forum-post'], true)) {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }

        if (! $this->accessService->canEditFeed($user, $comment)) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        if (
            ! $this->accessService->isAdmin($user)
            && $comment->createdAt !== null
            && $comment->createdAt < time() - self::COMMENT_EDIT_WINDOW_SECONDS
        ) {
            throw new ForbiddenException($this->tm->trans('feed.comment_edit_window_expired'));
        }

        $normalizedContent = $this->normalizeCommentContent($content);

        $this->repository->update(
            id: $comment->id,
            title: $comment->title,
            slug: $comment->slug,
            parentId: $comment->parentId,
            type: $comment->type,
            description: $comment->description,
            imageUrl: $comment->imageUrl,
            content: $normalizedContent,
        );

        $updated = $this->repository->findById($commentId, $user);
        if (! $updated) {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }

        $this->decorateFeed($updated);

        return $updated;
    }

    /**
     * Deletes a single reply (never a topic's own opening post - see the
     * type check below) - same owner/admin/container-moderator rule as
     * deleteFeed(), which this deliberately doesn't just call: deleteFeed()
     * is generic over every feed type, and a forum topic's opening post
     * ('forum-post') is also, structurally, just another feed a moderator
     * could otherwise delete through it - except doing so would cascade-
     * delete every reply underneath it too (feeds' FK is ON DELETE CASCADE,
     * see FeedRepository::delete()'s own docblock), which is "delete the
     * whole topic", a different and more consequential action than "delete
     * my own reply". That needs its own explicit flow later (see
     * docs/TODO.md) rather than being reachable by accident through this
     * one-reply endpoint.
     *
     * No time window, unlike editComment() - matches the mockup this was
     * built from (its delete button carries no "N hours left" hint the way
     * the edit button's tooltip does).
     *
     * @throws ForbiddenException
     * @throws ValidationException
     */
    public function deleteComment(int $commentId, User $user): void
    {
        if ($user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        $comment = $this->repository->findById($commentId, $user);
        if (! $comment) {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }

        if ($comment->type !== 'comment') {
            throw new ForbiddenException($this->tm->trans('feed.comment_delete_not_allowed'));
        }

        if (! $this->accessService->canEditFeed($user, $comment)) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        $this->repository->delete($commentId);
    }

    /**
     * Casts a single vote for a feed.
     * A user can vote only once per feed; voting again updates their existing
     * vote instead of adding a new one (see FeedRatingRepository::submit()).
     *
     * @throws ForbiddenException
     * @throws ValidationException
     * @throws NotFoundException
     */
    public function rateFeed(int $feedId, int $value, User $user): Feed
    {
        if ($user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        if ($this->ratingRepository === null) {
            throw new ValidationException($this->tm->trans('feed.rating_unavailable'));
        }

        $value = $this->normalizeRatingValue($value);

        $feed = $this->repository->findById($feedId, $user);
        if (! $feed) {
            throw new NotFoundException($this->tm->trans('feed.rating_target_not_found'));
        }

        $this->ratingRepository->submit($feedId, $user->id, $value);

        return $this->getFeedById($feedId, $user);
    }

    /**
     * Generic "someone viewed this feed" counter, per `docs/MODULE_CONTRACT.md`'s
     * "useful to more than one module" test - a Forums topic
     * or a Blog post could all plausibly want a view count, so this
     * lives here rather than in any one module (today, only
     * Modules\Forums\ForumsController::showTopicViewPage() calls it).
     *
     * Deliberately a raw, un-deduped counter: every render of the page that
     * calls this bumps `feeds.views` by one, including repeat views from the
     * same visitor or the same page reloaded - there's no per-user/per-
     * session "already counted this view" tracking (that would need
     * something like the existing per-user read-tracking
     * (getFeedReadAtMap()/markFeedAsRead()), which is a separate, explicitly
     * out-of-scope concern for this counter - see docs/TODO.md's "View
     * counter" bullet). Doesn't throw or return anything - a failed
     * increment shouldn't ever break rendering the page it's counting a
     * view of.
     */
    public function recordView(int $feedId): void
    {
        $this->repository->incrementViews($feedId);
    }

    /**
     * The current user's own vote for a feed, if any - used to pre-select
     * a rating widget's state (e.g. "you rated this 4").
     */
    public function getUserRatingValue(int $feedId, User $user): ?int
    {
        if ($this->ratingRepository === null || $user->isGuest()) {
            return null;
        }

        return $this->ratingRepository->findUserValue($feedId, $user->id);
    }

    /**
     * Batched form of getUserRatingValue() for a whole page of feeds at once
     * (e.g. forums.topic-view's page of posts) - one query instead of one
     * per row.
     *
     * @param int[] $feedIds
     * @return array<int, int> feed id => the given user's own vote, missing
     *     key means they haven't rated that feed
     */
    public function getUserRatingValues(array $feedIds, User $user): array
    {
        if ($this->ratingRepository === null || $user->isGuest() || $feedIds === []) {
            return [];
        }

        return $this->ratingRepository->findUserValues($feedIds, $user->id);
    }

    /**
     * Marks a feed as one of the current user's favorites, for later surfacing
     * across different feed types.
     *
     * @throws ForbiddenException
     * @throws ValidationException
     */
    public function addFavorite(int $feedId, User $user): void
    {
        if ($user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        if ($this->favoriteRepository === null) {
            throw new ValidationException($this->tm->trans('feed.favorite_unavailable'));
        }

        $feed = $this->repository->findById($feedId, $user);
        if (! $feed) {
            throw new NotFoundException($this->tm->trans('feed.favorite_target_not_found'));
        }

        $this->favoriteRepository->add($feedId, $user->id);
    }

    /**
     * Removes a feed from the current user's favorites. Idempotent: removing
     * a favorite that was never set (or already removed) is not an error.
     *
     * @throws ForbiddenException
     * @throws ValidationException
     */
    public function removeFavorite(int $feedId, User $user): void
    {
        if ($user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        if ($this->favoriteRepository === null) {
            throw new ValidationException($this->tm->trans('feed.favorite_unavailable'));
        }

        $this->favoriteRepository->remove($feedId, $user->id);
    }

    /**
     * Whether the current user has favorited a feed - used to pre-select
     * a "favorite" button's state. Guests never have favorites.
     */
    public function isFeedFavorited(int $feedId, User $user): bool
    {
        if ($this->favoriteRepository === null || $user->isGuest()) {
            return false;
        }

        return $this->favoriteRepository->isFavorited($feedId, $user->id);
    }

    /**
     * A user's favorited feeds, most recently favorited first. Guests never
     * have favorites.
     *
     * Note: $limit/$offset are applied to the favorites list itself, before
     * the optional $type filter, so a filtered page can contain fewer than
     * $limit entries.
     *
     * @return Feed[]
     */
    public function getFavoriteFeeds(User $user, ?string $type = null, int $limit = 50, int $offset = 0): array
    {
        if ($this->favoriteRepository === null || $user->isGuest()) {
            return [];
        }

        $feedIds = $this->favoriteRepository->findFeedIdsForUser($user->id, $limit, $offset);

        if ($feedIds === []) {
            return [];
        }

        $feeds = $this->repository->findByIds($feedIds, $user, $type);

        $this->decorateFeedsWithUrls($feeds);

        return $feeds;
    }

    /**
     * Records that the current user has read a parent. Pass a child's
     * position to track progress within that parent, or omit it for simple
     * read/unread tracking. This is a
     * watermark, not a log: calling it again for the same parent overwrites
     * its previous position/read_at rather than adding a new record, so a
     * parent with a thousand children still costs one row per reader.
     *
     * Guests have no user_id to key a feed_reads row on, so they're tracked
     * client-side instead, via a GuestFeedReadStore cookie. Without one
     * configured, guest reads are a silent no-op rather than an error - the
     * same graceful-degradation behavior as a missing FeedReadRepository.
     */
    public function markFeedAsRead(int $parentId, User $user, ?int $position = null): void
    {
        if ($user->isGuest()) {
            $this->guestReadStore?->markAsRead($parentId, $position);

            return;
        }

        if ($this->readRepository === null) {
            return;
        }

        $this->readRepository->markAsRead($parentId, $user->id, $position);
    }

    /**
     * Bulk form of markFeedAsRead(), for "mark all read" actions that can
     * span hundreds or thousands of parents at once (e.g. every topic in a
     * forum section - see Modules\Forums\ForumsController's own "mark all
     * read" endpoint). No position, same reasoning as
     * FeedReadRepository::markManyAsRead()'s own docblock: a batch spanning
     * many different parents has no single meaningful position to record.
     *
     * Guests are a no-op here, not looped through GuestFeedReadStore::
     * markAsRead() per id: that store is a single cookie capped at 100
     * entries (see its own docblock) - re-encoding and re-setting it
     * hundreds or thousands of times in one request, for state that would
     * immediately get pruned back down anyway, isn't worth doing. "Mark all
     * read" is gated to logged-in users at the UI layer for this reason.
     *
     * @param int[] $feedIds
     */
    public function markFeedsAsRead(array $feedIds, User $user): void
    {
        if ($feedIds === [] || $user->isGuest()) {
            return;
        }

        $this->readRepository?->markManyAsRead($feedIds, $user->id);
    }

    /**
     * When the current user last read a parent, or null if they never have.
     * For guests this comes from their GuestFeedReadStore cookie rather than
     * feed_reads; null if there's no store configured or nothing recorded.
     */
    public function getFeedReadAt(int $feedId, User $user): ?int
    {
        if ($user->isGuest()) {
            return $this->guestReadStore?->findReadAt($feedId);
        }

        return $this->readRepository?->findReadAt($feedId, $user->id);

    }

    /**
     * Batch form of getFeedReadAt(), for rendering "unread" badges across a
     * list of feeds (e.g. a forum's topic list, a book's table of contents)
     * without one query per row. Guests are served from their
     * GuestFeedReadStore cookie, same as getFeedReadAt().
     *
     * @param int[] $feedIds
     * @return array<int, int> read_at keyed by feed_id; feeds never read
     *     (or, without a configured store, any feed for a guest) are absent
     */
    public function getFeedReadAtMap(array $feedIds, User $user): array
    {
        if ($feedIds === []) {
            return [];
        }

        if ($user->isGuest()) {
            return $this->guestReadStore?->findReadAtForFeeds($feedIds) ?? [];
        }

        if ($this->readRepository === null) {
            return [];
        }

        return $this->readRepository->findReadAtForFeeds($feedIds, $user->id);
    }

    /**
     * Whether the current user should see a feed as "read". With $since
     * omitted, this just checks whether they've ever read it at all.
     * Passing $since (e.g. a topic's or page's updated_at) additionally
     * requires the read to be no older than that, for "new activity since
     * your last visit" indicators.
     */
    public function isFeedRead(int $feedId, User $user, ?int $since = null): bool
    {
        $readAt = $this->getFeedReadAt($feedId, $user);

        if ($readAt === null) {
            return false;
        }

        return $since === null || $readAt >= $since;
    }

    /** @return Feed[] */
    public function getUnfinishedFeeds(
        User $user,
        string $parentType,
        string $childType,
        int $limit = 10
    ): array {
        $children = $this->resolveReadChildren($user, $parentType);

        if ($children === []) {
            return [];
        }

        $lastReadPosition = [];
        $parentOrder = [];

        foreach ($children as $child) {
            $lastReadPosition[$child['parentId']] = $child['position'] ?? 0;
            $parentOrder[] = $child['parentId'];
        }

        $lastPositionByParent = $this->repository->findLastChildPositions($parentOrder, $childType);

        $unfinishedParentIds = [];

        foreach ($parentOrder as $parentId) {
            if (! isset($lastPositionByParent[$parentId])) {
                continue;
            }

            if ($lastReadPosition[$parentId] >= $lastPositionByParent[$parentId]) {
                continue;
            }

            $unfinishedParentIds[] = $parentId;

            if (count($unfinishedParentIds) >= $limit) {
                break;
            }
        }

        if ($unfinishedParentIds === []) {
            return [];
        }

        $feeds = $this->repository->findByIds($unfinishedParentIds, $user, $parentType);

        $this->decorateFeedsWithUrls($feeds);
        $this->decorateFeedsWithReadingProgress(
            $feeds,
            $lastReadPosition,
            $lastPositionByParent,
            $childType,
            $user
        );

        return $feeds;
    }

    /**
     * @param Feed[] $feeds
     * @param array<int, int> $lastReadPosition
     * @param array<int, int> $lastPositionByParent
     */
    private function decorateFeedsWithReadingProgress(
        array $feeds,
        array $lastReadPosition,
        array $lastPositionByParent,
        string $childType,
        User $user
    ): void {
        if ($feeds === []) {
            return;
        }

        $positionByParentId = [];
        foreach ($feeds as $feed) {
            $positionByParentId[$feed->id] = $lastReadPosition[$feed->id];
        }

        $resumeChildIdByParentId = $this->repository->findChildIdsAtPositions($positionByParentId, $childType);

        $resumeChildren = $this->repository->findByIds(array_values($resumeChildIdByParentId), $user, $childType);
        $this->decorateFeedsWithUrls($resumeChildren);

        $resumeUrlByChildId = [];
        foreach ($resumeChildren as $resumeChild) {
            $resumeUrlByChildId[$resumeChild->id] = $resumeChild->canonicalUrl;
        }

        foreach ($feeds as $feed) {
            $position = $lastReadPosition[$feed->id];
            $lastPosition = $lastPositionByParent[$feed->id];

            $resumeChildId = $resumeChildIdByParentId[$feed->id] ?? null;
            $resumeUrl = $resumeChildId !== null ? ($resumeUrlByChildId[$resumeChildId] ?? null) : null;
            if ($resumeUrl !== null) {
                $feed->canonicalUrl = $resumeUrl;
            }

            $feed->readingProgress = $lastPosition > 0
                ? (int) round((($position + 1) / ($lastPosition + 1)) * 100)
                : null;
        }
    }

    public function removeReadProgressForFeed(int $feedId, User $user): void
    {
        if ($user->isGuest()) {
            $this->guestReadStore?->removeForParent($feedId);

            return;
        }

        $this->readRepository?->deleteForParent($user->id, $feedId);
    }

    /**
     * @return list<array{parentId: int, position: ?int}>
     */
    private function resolveReadChildren(User $user, string $parentType): array
    {
        if ($user->isGuest()) {
            if ($this->guestReadStore === null) {
                return [];
            }

            $entries = $this->guestReadStore->all();

            if ($entries === []) {
                return [];
            }

            uasort($entries, static fn (array $a, array $b): int => $b['readAt'] <=> $a['readAt']); // Most recently read first

            $pages = [];

            foreach ($entries as $parentId => $entry) {
                $pages[] = [
                    'parentId' => $parentId,
                    'position' => $entry['position'],
                ];
            }

            return $pages;
        }

        if ($this->readRepository === null) {
            return [];
        }

        return $this->readRepository->findReadParentsByType($user->id, $parentType);
    }

    /**
     * Partially updates a feed. Missing keys preserve the stored value;
     * explicit null clears nullable fields. Metadata, visibility and container
     * columns keep their own opt-in update rules below.
     *
     * @param array<string, mixed> $data
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function updateFeed(
        int $id,
        array $data,
        User $user
    ): Feed {

        $feed = $this->getFeedById($id, $user);

        if (! $feed) {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }

        if (! $this->accessService->canEditFeed($user, $feed)) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        // update() still accepts a complete row because internal callers use
        // it that way. Merge the PATCH payload with the stored feed here so an
        // omitted key is preserved, while an explicitly supplied null can
        // still clear a nullable column.
        $title = array_key_exists('title', $data) ? $data['title'] : $feed->title;
        $slug = array_key_exists('slug', $data) ? $data['slug'] : $feed->slug;
        $parentId = array_key_exists('parentId', $data)
            ? (! empty($data['parentId']) ? (int) $data['parentId'] : null)
            : $feed->parentId;
        $type = array_key_exists('type', $data) ? $data['type'] : $feed->type;

        if (! is_string($type) || $type === '') {
            throw new ValidationException('Feed type must be a non-empty string');
        }

        $content = array_key_exists('content', $data)
            ? ($data['content'] !== null ? $this->purifier->purify($data['content']) : null)
            : $feed->content;
        $description = array_key_exists('description', $data)
            ? ($data['description'] !== null ? $this->purifier->purify($data['description']) : null)
            : $feed->description;
        $imageUrl = array_key_exists('imageUrl', $data)
            ? $this->validateFeedImage($data['imageUrl'])
            : $feed->imageUrl;
        $metadata = array_key_exists('metadata', $data)
            ? $this->normalizeMetadataInput($data['metadata'])
            : null;

        if (array_key_exists('parentId', $data) && $parentId !== null) {
            $parent = $this->repository->findById($parentId, $user);
            if (! $parent) {
                throw new ValidationException($this->tm->trans('feed.parent_not_found'));
            }
        }

        // Only touch visibility when the caller actually sent one - see
        // FeedRepository::update()'s own doc comment for why null means
        // "leave it alone" rather than "reset to public".
        $visibility = array_key_exists('visibility', $data) && $data['visibility'] !== null
            ? $this->normalizeVisibility((string) $data['visibility'])
            : null;
        $updateContainer = array_key_exists('containerId', $data) || array_key_exists('containerType', $data);
        $containerId = array_key_exists('containerId', $data)
            ? ($data['containerId'] !== null ? (int) $data['containerId'] : null)
            : $feed->containerId;
        $containerType = array_key_exists('containerType', $data)
            ? $data['containerType']
            : $feed->containerType;

        $this->repository->update(
            id: $id,
            title: $title,
            slug: $slug,
            parentId: $parentId,
            type: $type,
            description: $description,
            imageUrl: $imageUrl,
            content: $content,
            visibility: $visibility,
            containerId: $containerId,
            containerType: $containerType,
            updateContainer: $updateContainer,
        );

        if ($metadata !== null) {
            $this->replaceMetadataForFeed($id, $metadata);
        }

        return $this->getFeedById($id, $user);
    }

    /**
     * Thin public wrapper over AccessService::canEditFeed() for callers
     * (the blog-post/community-post show pages, to decide whether to
     * render the "Delete"/"Edit" buttons) that need a plain
     * boolean rather than a thrown ForbiddenException - same owner/admin/
     * container-moderator policy deleteFeed()/updateFeed() enforce
     * server-side, just exposed for view-layer gating so it can never
     * drift from the actual enforcement.
     */
    public function canEditFeed(Feed $feed, User $user): bool
    {
        return $this->accessService->canEditFeed($user, $feed);
    }

    public function isWithinEditWindow(Feed $feed, User $user): bool
    {
        if ($this->accessService->isAdmin($user)) {
            return true;
        }

        if ($feed->createdAt === null) {
            return true;
        }

        return $feed->createdAt >= time() - self::COMMENT_EDIT_WINDOW_SECONDS;
    }

    /**
     * Hard-deletes a feed, same ownership rule as updateFeed(): owner,
     * admin, or container moderator (AccessService::canEditFeed() - no
     * separate "canDelete" concept exists yet). No canonicalUrl/cache
     * invalidation needed here the way some callers do after a write,
     * since UrlGenerator's cache is keyed by feed id and simply becomes
     * unreachable once the row is gone.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    public function deleteFeed(int $id, User $user): void
    {
        $feed = $this->getFeedById($id, $user);

        if (! $feed) {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }

        if (! $this->accessService->canEditFeed($user, $feed)) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        $this->repository->delete($id);
    }

    public function listFeeds(
        ?User $user = null,
        int $limit = 20,
        int $offset = 0,
        ?string $type = null,
        ?string $title = null,
        ?int $id = null,
        ?string $slug = null,
        ?int $parentId = null,
        ?int $ownerId = null,
        ?string $sort = null
    ): array {
        $feeds = $this->repository->list(
            user: $user,
            limit: $limit,
            offset: $offset,
            id: $id,
            slug: $slug,
            type: $type,
            title: $title,
            parentId: $parentId,
            ownerId: $ownerId,
            sort: $sort,
        );

        $this->decorateFeedsWithUrls($feeds);

        return [
            'data' => $feeds,
            'meta' => [
                'limit' => $limit,
                'offset' => $offset,
                'type' => $type,
                'title' => $title,
                'id' => $id,
                'slug' => $slug,
                'parentId' => $parentId,
                'ownerId' => $ownerId,
                'sort' => $sort,
            ],
        ];
    }

    /**
     * @return Feed[]
     */
    public function getFeedsByType(
        string $type,
        User $user,
        int $limit = 20,
        int $offset = 0
    ): array {
        $feeds = $this->repository->findByType($type, $user, $limit, $offset);

        $this->decorateFeedsWithUrls($feeds);

        return $feeds;
    }

    /**
     * Top-rated feeds of a type.
     *
     * @return Feed[]
     */
    public function getTopRatedFeedsByType(
        string $type,
        User $user,
        int $limit = 50
    ): array {
        $feeds = $this->repository->findTopRatedByType($type, $user, $limit);

        $this->decorateFeedsWithUrls($feeds);

        return $feeds;
    }

    public function getRandomFeedByType(string $type, User $user): ?Feed
    {
        $feed = $this->repository->findRandomByType($type, $user);

        if ($feed === null) {
            return null;
        }

        $this->decorateFeedsWithUrls([$feed]);

        return $feed;
    }

    /**
     * @return Feed[]
     */
    public function getFeedsByTerm(
        string $vocabulary,
        string $slug,
        User $user,
        ?string $type = null,
        int $limit = 20,
        int $offset = 0
    ): array {
        $feeds = $this->repository->findByTerm($vocabulary, $slug, $user, $type, $limit, $offset);

        $this->decorateFeedsWithUrls($feeds);

        return $feeds;
    }

    public function countFeedsByType(string $type, User $user): int
    {
        return $this->repository->countByType($type, $user);
    }

    /**
     * Site-wide feeds of a type, paginated, with an optional tag filter -
     * action=community.main's global blog-post feed and its per-tag view
     * (?tag=slug). Same decoration/ACL contract as getFeedsByOwnerAndTypePage()
     * below, just not owner-scoped; callers that need a per-owner
     * canonicalUrl (blog posts do) are expected to override feed->canonicalUrl
     * afterwards, same as showBlogPostPage()/buildPostCards() already do.
     *
     * @return array{items: Feed[], total: int}
     */
    public function getFeedsByTypePage(string $type, User $user, int $limit, int $offset = 0, ?string $tagSlug = null): array
    {
        $result = $this->repository->findByTypePage($type, $user, $limit, $offset, $tagSlug);

        $this->decorateFeedsWithUrls($result['items']);

        return $result;
    }

    /**
     * A specific user's authored feeds of a type, paginated. Decorated the
     * same way every other listing method here is (canonicalUrl via the
     * generic per-type resolver, createdAtLabel, metadata) - callers that
     * need a different canonicalUrl (blog posts do; see BlogPostService::
     * buildPostCanonicalUrl()'s own doc comment on why) are expected to
     * override feed->canonicalUrl afterwards, same as showBlogPostPage()
     * already does for a single post.
     *
     * @return array{items: Feed[], total: int}
     */
    public function getFeedsByOwnerAndTypePage(int $ownerId, string $type, User $user, int $limit, int $offset = 0): array
    {
        $result = $this->repository->findByOwnerAndTypePage($ownerId, $type, $user, $limit, $offset);

        $this->decorateFeedsWithUrls($result['items']);

        return $result;
    }

    /**
     * Total feeds of a type owned by a specific user - e.g. a public
     * profile's "comments written" stat.
     */
    public function countFeedsByOwnerAndType(int $ownerId, string $type, User $user): int
    {
        return $this->repository->countByOwnerAndType($ownerId, $type, $user);
    }

    /**
     * Aggregate rating across every feed of a type owned by a user.
     *
     * @return array{sum: int, count: int}
     */
    public function getRatingTotalsByOwnerAndType(int $ownerId, string $type, User $user): array
    {
        return $this->repository->sumRatingByOwnerAndType($ownerId, $type, $user);
    }

    /**
     * Aggregate rating across a container's own posts (e.g. a community's
     * blog-post children) - the community sidebar's "Rating" card. Same
     * shape as getRatingTotalsByOwnerAndType(), parent-scoped instead of
     * owner-scoped since a community's posts have many different owners.
     *
     * @return array{sum: int, count: int}
     */
    public function getRatingTotalsByParentAndType(int $parentId, string $type, User $user): array
    {
        return $this->repository->sumRatingByParentAndType($parentId, $type, $user);
    }

    /**
     * Total feeds of a type parented under a container - the community
     * sidebar's "Statistics" card's "Community posts" row. Same shape
     * as countFeedsByOwnerAndType(), parent-scoped instead of owner-scoped,
     * same as getRatingTotalsByParentAndType() vs getRatingTotalsByOwnerAndType().
     */
    public function countFeedsByParentAndType(int $parentId, string $type, User $user): int
    {
        return $this->repository->countByParentAndType($parentId, $type, $user);
    }

    /**
     * Total comments left on any post parented under a container - the
     * community sidebar's "Statistics" card's "Comments" row.
     */
    public function countCommentsByParentContainer(int $parentId, User $user): int
    {
        return $this->repository->countCommentsByParentContainer($parentId, $user);
    }

    /**
     * Root-comment count for a batch of parent feeds - e.g. the little
     * comment-bubble badge on each card of a blog feed listing.
     *
     * @param int[] $parentIds
     * @return array<int, int> comment count keyed by feed id
     */
    public function countCommentsForFeeds(array $parentIds): array
    {
        return $this->repository->countCommentsForParents($parentIds);
    }

    /**
     * @return array<int, int> feed count keyed by term id
     */
    public function countFeedsGroupedByTermsAndVocabulary(string $vocabulary, User $user, ?string $type = null): array
    {
        return $this->repository->countGroupedByTermsAndVocabulary($vocabulary, $user, $type);
    }

    /**
     * Top N terms of a vocabulary ranked by feed count, with canonical URLs
     * already resolved for display.
     *
     * @return list<array{term: FeedTerm, feedCount: int}>
     */
    public function getTopTermsByVocabulary(string $vocabulary, User $user, ?string $type, int $limit): array
    {
        $rows = $this->repository->findTopTermsByVocabularyAndFeedCount($vocabulary, $user, $type, $limit);

        return array_map(
            function (array $row): array {
                $term = FeedTerm::fromRow($row);
                $term->canonicalUrl = $this->urlGenerator->feedTerm($term);

                return ['term' => $term, 'feedCount' => (int) $row['total']];
            },
            $rows
        );
    }

    /**
     * @throws ValidationException
     */
    public function search(string $search, int $limit, ?string $cursor, User $user): array
    {
        $encodeCursor = function (int $priority, float $rank, int $id): string {
            return base64_encode(json_encode(['priority' => $priority, 'rank' => $rank, 'id' => $id]));
        };

        $decodeCursor = function (string $encodedCursor): object {
            $decoded = json_decode(base64_decode($encodedCursor), true);

            if (! is_array($decoded)
                || ! isset($decoded['priority'], $decoded['rank'], $decoded['id'])
                || ! is_int($decoded['priority'])
                || $decoded['priority'] < 0 || $decoded['priority'] > 2
                || ! is_numeric($decoded['rank']) || ! is_finite((float) $decoded['rank'])
                || ! is_int($decoded['id']) || $decoded['id'] < 1
            ) {
                throw new ValidationException($this->tm->trans('feed.cursor_invalid'));
            }

            return (object) $decoded;
        };

        if (mb_strlen($search) < 3) {
            throw new ValidationException($this->tm->trans('feed.search_too_short'));
        }

        if ($cursor === null) {
            $cursorObject = null; // The first page
        } else {
            $cursorObject = $decodeCursor($cursor);
        }

        $rows = $this->repository->search(
            search: $search,
            limit: $limit + 1, // Increase limit by 1 to check if there are any more rows
            cursor: $cursorObject,
            user: $user
        );

        $hasMore = count($rows) > $limit;
        $nextCursor = null;

        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
            $lastItem = end($rows) ?: null;

            if ($lastItem) {
                $nextCursor = $encodeCursor($lastItem->getSearchPriority(), $lastItem->getRelevance(), $lastItem->id);
            }
        }

        $this->decorateFeedsWithUrls($rows);

        return [
            'data' => $rows,
            'meta' => [
                'limit' => $limit,
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
            ],
        ];
    }

    /**
     * @param int $parentFeedId
     * @param string|null $cursor
     * @param User $user
     * @param int $limit Don't set it 20 or more because we have lovely N=$limit SQL queries below. You were warned.
     * @param int $childLimit You can set any positive value.
     * @return array
     * @throws ValidationException
     */
    public function getComments(int $parentFeedId, ?string $cursor, User $user, int $limit = 5, int $childLimit = 3): array
    {
        $encodeCursor = function (int $created_at, int $id): string {
            return base64_encode(json_encode(['created_at' => $created_at, 'id' => $id]));
        };

        $decodeCursor = function (string $encodedCursor): object {
            $decoded = json_decode(base64_decode($encodedCursor), true);

            if (! isset($decoded['created_at'], $decoded['id'])) {
                throw new ValidationException($this->tm->trans('feed.cursor_invalid'));
            }
            return (object) $decoded;
        };

        if ($cursor === null) {
            $cursorObject = null; // The first page
        } else {
            $cursorObject = $decodeCursor($cursor);
        }

        $rootsPage = $this->repository->findCommentsPage(
            parentId: $parentFeedId,
            limit: $limit + 1,
            cursor: $cursorObject,
            user: $user
        );

        $roots = $rootsPage['items'];
        $total = $rootsPage['total'];

        $roots = array_slice($roots, 0, $limit);
        $lastRoot = end($roots) ?: null;
        $nextRootCount = min($limit, max(0, $total - count($roots)));

        foreach ($roots as $root) {
            $this->decorateFeed($root);
        }

        if ($childLimit > 0 && $roots !== []) {
            $childrenByParent = $this->repository->findCommentsByParents(
                parentIds: array_map(
                    static fn (Feed $feed): int => $feed->id,
                    $roots
                ),
                limitPerParent: $childLimit,
                user: $user
            );

            foreach ($roots as $root) {
                $childrenPage = $childrenByParent[$root->id] ?? ['items' => [], 'total' => 0];

                if ($childrenPage['items'] === []) {
                    continue;
                }

                foreach ($childrenPage['items'] as $child) {
                    $this->decorateFeed($child);
                }

                $lastChild = end($childrenPage['items']);
                $loadedChildren = count($childrenPage['items']);
                $nextChildCount = min($childLimit, max(0, $childrenPage['total'] - $loadedChildren));

                $root->children = [
                    'items' => $childrenPage['items'],
                    'meta' => [
                        'limit' => $childLimit,
                        'next_count' => $nextChildCount,
                        'next_cursor' => $nextChildCount > 0 && $lastChild
                            ? $encodeCursor($lastChild->createdAt, $lastChild->id)
                            : null,
                    ],
                ];
            }
        }

        return [
            'items' => $roots,
            'meta' => [
                'limit' => $limit,
                'next_count' => $nextRootCount,
                'next_cursor' => $nextRootCount > 0 && $lastRoot
                    ? $encodeCursor($lastRoot->createdAt, $lastRoot->id)
                    : null,
            ]
        ];

    }

    /**
     * @throws ValidationException
     */
    private function validateFeedImage(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $path = trim($path);

        if (strlen($path) > 255) {
            throw new ValidationException($this->tm->trans('feed.image_path_too_long'));
        }

        if ($path[0] !== '/') {
            throw new ValidationException($this->tm->trans('feed.image_path_must_be_absolute'));
        }

        if (str_contains($path, '..')) {
            throw new ValidationException($this->tm->trans('feed.image_path_invalid_path'));
        }

        if (! preg_match('~^/[a-zA-Z0-9/_.-]+$~', $path)) {
            throw new ValidationException($this->tm->trans('feed.image_path_invalid_characters'));
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        if (! in_array($ext, $allowed, true)) {
            throw new ValidationException($this->tm->trans('feed.image_path_must_be_image'));
        }

        return $path;
    }

    /**
     * @throws ValidationException
     */
    private function normalizeCommentContent(string $content): string
    {
        $content = trim($content);

        if ($content === '') {
            throw new ValidationException($this->tm->trans('feed.comment_empty'));
        }

        if (mb_strlen($content) > 5000) {
            throw new ValidationException($this->tm->trans('feed.comment_too_long'));
        }

        // A reply's own plain-text quoting convention (see
        // assets-src/pages/forums.ts's insertQuote()/buildQuoteBlock()):
        // one or more leading "> Author wrote: / > quoted line(s)"
        // blocks get rendered as real <blockquote> markup rather than
        // literal "&gt;" text - same idea as Markdown/email quoting, just
        // recognized only at the very start of the message rather than
        // anywhere inline (a real quote parser embedded mid-message would
        // need to disambiguate a user's own "> " from a quote, which this
        // sidesteps entirely). $rest is whatever plain text follows the
        // last recognized quote block - normal comment body from here on.
        ['quotesHtml' => $quotesHtml, 'rest' => $rest] = $this->extractLeadingQuotes($content);

        $body = nl2br(htmlspecialchars(trim($rest), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        return $this->purifier->purify($quotesHtml.$body);
    }

    /**
     * Peels zero or more leading quote blocks off $content, each shaped
     * exactly like buildQuoteBlock() (assets-src/pages/forums.ts) produces:
     *   > Author wrote:
     *   > quoted line 1
     *   > quoted line 2
     *   <blank line>
     * Stops at the first line that isn't part of a recognized block - that
     * and everything after it becomes $rest. A header line with nothing
     * quoted under it (shouldn't happen from the real button, but content
     * arrives as free-form text from any API caller) isn't treated as a
     * quote at all, so it's left in $rest untouched rather than swallowed.
     *
     * @return array{quotesHtml: string, rest: string}
     */
    private function extractLeadingQuotes(string $content): array
    {
        $lines = preg_split('~\r\n|\r|\n~', $content) ?: [$content];
        $count = count($lines);
        $index = 0;
        $quotesHtml = '';

        while ($index < $count) {
            if (! preg_match('~^> '.$this->quoteHeaderPattern('(.+)').'$~u', $lines[$index], $matches)) {
                break;
            }

            $author = $matches[1];
            $bodyStart = $index + 1;
            $bodyEnd = $bodyStart;

            while ($bodyEnd < $count && str_starts_with($lines[$bodyEnd], '> ')) {
                $bodyEnd++;
            }

            if ($bodyEnd === $bodyStart) {
                // A quote header with nothing quoted under it is not a
                // real quote block, leave the header line itself as plain
                // text rather than discarding it.
                break;
            }

            $quotedLines = array_map(
                static fn (string $line): string => substr($line, 2),
                array_slice($lines, $bodyStart, $bodyEnd - $bodyStart)
            );

            $quotesHtml .= $this->renderQuoteBlock($author, implode("\n", $quotedLines));

            $index = $bodyEnd;
            if ($index < $count && trim($lines[$index]) === '') {
                // Skip the single blank separator line buildQuoteBlock()
                // always inserts after a quote - not part of the next
                // block or of $rest.
                $index++;
            }
        }

        return [
            'quotesHtml' => $quotesHtml,
            'rest' => implode("\n", array_slice($lines, $index)),
        ];
    }

    /**
     * Renders one quoted post the same way the mockup's static example
     * did: a bordered blockquote with a localized author header line
     * above the quoted text. $author/$quotedText are raw, still-unescaped
     * plain text at this point (extractLeadingQuotes() only split lines),
     * so both get the same htmlspecialchars+nl2br treatment the rest of
     * the comment gets, just built by hand instead of going through the
     * purifier's normal input path (the surrounding purify() call still
     * runs over the result, same as everything else this method returns).
     */
    private function renderQuoteBlock(string $author, string $quotedText): string
    {
        $safeAuthor = htmlspecialchars($author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeText = nl2br(htmlspecialchars($quotedText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

        return '<blockquote class="comment-quote mb-3 px-3 py-2 rounded-2">'
            .'<div class="text-body-secondary mb-1 comment-quote-author">'
            .'<i class="bi bi-quote me-1"></i>'
            .$this->tm->trans('feed.quote_header', ['author' => $safeAuthor])
            .'</div>'
            .'<div class="text-body-secondary comment-quote-text">'.$safeText.'</div>'
            .'</blockquote>';
    }

    private function quoteHeaderPattern(string $authorPattern): string
    {
        $marker = '__QUOTE_AUTHOR__';
        $header = preg_quote($this->tm->trans('feed.quote_header', ['author' => $marker]), '~');

        return str_replace(preg_quote($marker, '~'), $authorPattern, $header);
    }

    /**
     * @throws ValidationException
     */
    private function normalizeRatingValue(int $value): int
    {
        if ($value < self::MIN_RATING_VALUE || $value > self::MAX_RATING_VALUE) {
            throw new ValidationException($this->tm->trans('feed.rating_invalid'));
        }

        return $value;
    }

    private function decorateFeedsWithUrls(array $feeds): void
    {
        $urls = $this->urlGenerator->feeds($feeds);
        $this->decorateFeedsWithMetadata($feeds);

        foreach ($feeds as $feed) {
            $feed->canonicalUrl = $urls[$feed->id] ?? null;
            $this->decorateFeed($feed);
        }
    }

    /**
     * @param Feed[] $feeds
     */
    private function decorateFeedsWithMetadata(array $feeds): void
    {
        if ($this->metadataRepository === null || $feeds === []) {
            return;
        }

        $metadataByFeed = $this->metadataRepository->findByFeedIds(
            array_map(static fn (Feed $feed): int => $feed->id, $feeds)
        );

        foreach ($feeds as $feed) {
            $feed->metadata = $metadataByFeed[$feed->id] ?? [];
        }
    }

    /**
     * @param mixed $metadata
     * @return array<string, scalar|null>
     * @throws ValidationException
     */
    private function normalizeMetadataInput(mixed $metadata): array
    {
        if (! is_array($metadata)) {
            throw new ValidationException('Feed metadata must be an object');
        }

        $result = [];

        foreach ($metadata as $name => $content) {
            $name = mb_strtolower(trim((string) $name));

            if ($name === '') {
                continue;
            }

            if (! preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $name)) {
                throw new ValidationException('Invalid feed metadata name: '.$name);
            }

            if (mb_strlen($name) > self::MAX_METADATA_NAME_LENGTH) {
                throw new ValidationException('Feed metadata name is too long: '.$name);
            }

            if ($content !== null && ! is_scalar($content)) {
                throw new ValidationException('Feed metadata content must be scalar: '.$name);
            }

            if ($content !== null && mb_strlen(trim((string) $content)) > self::MAX_METADATA_CONTENT_LENGTH) {
                throw new ValidationException('Feed metadata content is too long: '.$name);
            }

            $result[$name] = $content;
        }

        return $result;
    }

    /**
     * @param array<string, scalar|null> $metadata
     * @throws ValidationException
     */
    private function replaceMetadataForFeed(int $feedId, array $metadata): void
    {
        if ($this->metadataRepository === null) {
            return;
        }

        try {
            $this->metadataRepository->replaceForFeed($feedId, $metadata);
        } catch (InvalidArgumentException $exception) {
            throw new ValidationException($exception->getMessage());
        }
    }

    private function decorateFeed(Feed $feed): void
    {
        if ($feed->createdAt === null) {
            return;
        }

        try {
            $createdAt = new DateTimeImmutable('@' . $feed->createdAt);
        } catch (DateMalformedStringException $e) {
            error_log(sprintf(
                'Invalid createdAt "%s": %s',
                $feed->createdAt,
                $e->getMessage()
            ));
            throw new RuntimeException('Invalid timestamp');
        }

        $feed->createdAtLabel = $this->formatter->relative($createdAt);
        $feed->createdAtTitle = $this->formatter->datetime($createdAt);
    }
}

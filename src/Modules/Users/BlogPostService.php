<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Users;

use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\FeedTerm;
use StreamEngine\Domain\User;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\FeedTermRepository;
use StreamEngine\Service\FeedService;

/**
 * Business logic for the Users module's "write a blog post" feature. This
 * used to live inline in FeedService, but unlike comments/ratings/favorites
 * (which are shared across several modules - forum, articles),
 * blog posts are only ever created from UsersController, so the module owns
 * this logic directly instead of it living in the generic Feed service.
 *
 * createFeed() and normalizeVisibility() stay on FeedService because they're
 * genuinely generic - createFeed() is also used directly by the admin API
 * to create other feed types (articles etc.), and visibility is a concept
 * that applies to any feed, not just blog posts.
 */
class BlogPostService
{
    private const int MAX_BLOG_TAGS = 8;

    private const int MAX_BLOG_TAG_LENGTH = 50;

    /**
     * Slugs a blog post is never allowed to land on - they'd collide with
     * other static pages already mounted as siblings of user.post-show-slug
     * under user.show ({username}), e.g. 'post' is user.post-new's own
     * pattern. Router::resolve() matches static routes before dynamic ones
     * (see its own doc comment), so a post whose slug happened to be one of
     * these would silently become unreachable at its own URL - the request
     * would resolve to the static page instead of user.post-show-slug with
     * slug='post'/'friends'/etc. uniqueBlogPostSlug() treats these exactly
     * like an already-taken slug, appending the same numeric suffix.
     *
     * @var string[]
     */
    private const array RESERVED_BLOG_POST_SLUGS = ['post', 'friends', 'rating', 'members', 'manage'];

    public function __construct(
        private readonly FeedService $feedService,
        private readonly FeedRepository $repository,
        private readonly UrlGenerator $urlGenerator,
        private readonly TranslationManager $tm,
        private readonly ?FeedTermRepository $termRepository = null,
    ) {
    }

    /**
     * The personal blog feed every user gets lazily on their first post - it
     * becomes the `parent_id` for their `blog-post` feeds, the same nesting
     * mechanism article-section/article and forum/topic already use for
     * their own content trees. At most one per user, by convention (not a DB
     * constraint - findByOwnerAndType() just returns whichever it finds
     * first if that were ever violated).
     *
     * A community's blog-post feeds are expected to eventually parent off
     * the community's own feed instead - createBlogPost() already takes the
     * parent feed as a plain id, so that's a case of picking a different
     * parent, not a structural change.
     *
     * slug is set to the owner's own username, not left null: the
     * 'user.show' page is registered with feed_type='blog' (it doubles as
     * this feed's "root" page, same as community.show for a community feed),
     * and UrlGenerator::buildUrlFromPageChain() fills whatever single
     * placeholder that page's pattern has ({username}) from this feed's own
     * slug column, the same way it fills {slug} for every other feed-typed
     * page - there's no separate mechanism for it. A blank username (should
     * never happen for a real account, but createFeed() takes a nullable
     * slug) falls back to null rather than an empty-string slug.
     *
     * @throws ValidationException
     */
    public function getOrCreateUserBlogFeed(User $user): Feed
    {
        $blog = $this->repository->findByOwnerAndType($user->id, 'blog', $user);

        if ($blog !== null) {
            return $blog;
        }

        return $this->feedService->createFeed(
            title: $user->getDisplayName(),
            slug: $user->username !== '' ? $user->username : null,
            type: 'blog',
            parentId: null,
            description: null,
            imageUrl: null,
            content: '',
            user: $user,
        );
    }

    /**
     * Publishes a new post to the current user's personal blog, creating
     * the blog container feed itself on first use.
     *
     * $visibility maps directly to feeds.visibility ('public'/'members'/
     * 'private'). There's no separate draft state in the schema, so a
     * "save as draft" action from the UI is expected to pass 'private' -
     * same as a post the author genuinely wants nobody else to see.
     *
     * $tags (if not null) fully replaces the post's 'tag' vocabulary terms
     * via FeedTermRepository::replaceForFeed() - pass null to leave tags
     * untouched (not applicable on create, but keeps the signature reusable).
     *
     * $trackUploadId (if given) is stored as feed_metadata's
     * 'track_upload_id' - there's no dedicated attachment column, so the
     * single attached track is just a pointer to an existing uploads row.
     *
     * $parent (if given) is the container feed the post is nested under
     * instead of the author's own personal blog - a community feed, for
     * UsersController::handleCommunityPostCreateRequest() (see
     * getOrCreateUserBlogFeed()'s own docblock, which anticipated exactly
     * this). Membership/role checks (who's even allowed to post into that
     * community) are the caller's job - CommunityService::canPost() - same
     * division as everywhere else here: this method only validates the post
     * itself, not who's allowed to write it into $parent.
     *
     * canonicalUrl comes straight from UrlGenerator::feed() - its generic
     * feed-type chain walker builds both shapes correctly the same way (a
     * personal post via user.show/{username} + user.post-show-slug/{slug}, and a
     * community post via community.show-slug/{slug} + community.post-show-slug/{slug}):
     * each root page's one placeholder is filled from its matched feed's own
     * slug column, whatever that placeholder is named (see
     * UrlGenerator::fillFeedPlaceholder()) - which is exactly why
     * getOrCreateUserBlogFeed() gives the personal blog feed itself a slug
     * (the owner's username) instead of leaving it null. No per-post-type
     * hand-rolling needed here - every other caller that resolves a
     * blog-post Feed (search, sitemap, the profile/community feed listings)
     * gets the same, correct URL for free through the exact same path.
     *
     * @param string[]|null $tags
     * @throws ForbiddenException
     * @throws ValidationException
     */
    public function createBlogPost(
        string $title,
        string $content,
        ?string $description,
        ?string $imageUrl,
        User $user,
        string $visibility = 'public',
        ?array $tags = null,
        ?int $trackUploadId = null,
        ?Feed $parent = null,
    ): Feed {
        if ($user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        $title = $this->normalizeBlogPostTitle($title);
        $content = trim($content);

        // Trix always serializes its (possibly empty) document as HTML, e.g.
        // "<div><br></div>" when there's no text at all - so a plain empty-
        // string check no longer works. Strip tags to check for real text,
        // but still accept an image/file-only post via its <figure> markup.
        if (trim(strip_tags($content)) === '' && ! str_contains($content, '<figure')) {
            throw new ValidationException($this->tm->trans('feed.blog_content_required'));
        }

        $visibility = $this->feedService->normalizeVisibility($visibility);
        $tags = $tags !== null ? $this->normalizeBlogPostTags($tags) : null;

        if ($trackUploadId !== null && $trackUploadId <= 0) {
            throw new ValidationException($this->tm->trans('feed.blog_track_invalid'));
        }

        $metadata = $trackUploadId !== null
            ? ['track_upload_id' => (string) $trackUploadId]
            : null;

        $blog = $parent ?? $this->getOrCreateUserBlogFeed($user);
        $slug = $this->uniqueBlogPostSlug($blog->id, $title, $user);

        [$containerId, $containerType] = $this->containerForNewPost($visibility, $blog, $parent);

        $post = $this->feedService->createFeed(
            title: $title,
            slug: $slug,
            type: 'blog-post',
            parentId: $blog->id,
            description: $description,
            imageUrl: $imageUrl,
            content: $content,
            user: $user,
            metadata: $metadata,
            visibility: $visibility,
            containerId: $containerId,
            containerType: $containerType,
        );

        if ($tags !== null && $this->termRepository !== null) {
            $this->termRepository->replaceForFeed($post->id, 'tag', $tags);
        }

        $post->canonicalUrl = $this->urlGenerator->feed($post);

        return $post;
    }

    /**
     * Updates one of the current user's own blog posts (or any post, for an
     * admin) from the edit form. Mirrors createBlogPost()'s validation, but
     * keeps the post's existing slug and blog-container parent instead of
     * generating a new slug - editing a post never changes its URL.
     * Ownership/admin rules live on FeedService::updateFeed() (via
     * AccessService::canEditFeed()), same division as deleteBlogPost().
     *
     * Same "no separate draft state" note as createBlogPost() applies here:
     * $visibility is the only way "save as draft" is represented, so passing
     * 'private' from the edit form's draft button is what keeps a post
     * unpublished.
     *
     * @param string[]|null $tags
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function updateBlogPost(
        int $id,
        string $title,
        string $content,
        ?string $description,
        ?string $imageUrl,
        User $user,
        string $visibility = 'public',
        ?array $tags = null,
        ?int $trackUploadId = null,
    ): Feed {
        if ($user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        // getFeedById() only checks view access (canAccessFeed) - the actual
        // edit-ownership check (owner or admin) happens inside
        // FeedService::updateFeed() below via canEditFeed(). This first call
        // just resolves the existing slug/parent and guards the type.
        $existing = $this->feedService->getFeedById($id, $user);
        if (! $existing || $existing->type !== 'blog-post') {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }

        $title = $this->normalizeBlogPostTitle($title);
        $content = trim($content);

        if (trim(strip_tags($content)) === '' && ! str_contains($content, '<figure')) {
            throw new ValidationException($this->tm->trans('feed.blog_content_required'));
        }

        $visibility = $this->feedService->normalizeVisibility($visibility);
        $tags = $tags !== null ? $this->normalizeBlogPostTags($tags) : null;

        if ($trackUploadId !== null && $trackUploadId <= 0) {
            throw new ValidationException($this->tm->trans('feed.blog_track_invalid'));
        }

        $data = [
            'title' => $title,
            'slug' => $existing->slug,
            'parentId' => $existing->parentId,
            'type' => 'blog-post',
            'description' => $description,
            'imageUrl' => $imageUrl,
            'content' => $content,
            'visibility' => $visibility,
        ];

        [$containerId, $containerType] = $this->containerForUpdatedPost($visibility, $existing);
        $data['containerId'] = $containerId;
        $data['containerType'] = $containerType;

        if ($trackUploadId !== null) {
            $data['metadata'] = ['track_upload_id' => (string) $trackUploadId];
        }

        $post = $this->feedService->updateFeed($id, $data, $user);

        if ($tags !== null && $this->termRepository !== null) {
            $this->termRepository->replaceForFeed($post->id, 'tag', $tags);
        }

        $post->canonicalUrl = $this->urlGenerator->feed($post);

        return $post;
    }

    /**
     * @return array{0: ?int, 1: ?string}
     */
    private function containerForNewPost(string $visibility, Feed $blog, ?Feed $parent): array
    {
        if ($parent !== null) {
            return [$parent->id, $parent->type];
        }

        if ($visibility === 'members') {
            return [$blog->id, 'blog'];
        }

        return [null, null];
    }

    /**
     * @return array{0: ?int, 1: ?string}
     */
    private function containerForUpdatedPost(string $visibility, Feed $existing): array
    {
        if ($existing->containerType === 'community' && $existing->containerId !== null) {
            return [$existing->containerId, 'community'];
        }

        if ($visibility === 'members' && $existing->parentId !== null) {
            return [$existing->parentId, 'blog'];
        }

        return [null, null];
    }

    /**
     * The current tag names attached to a post - used by the edit form to
     * pre-fill its tag chips. Read-only counterpart to createBlogPost()'s/
     * updateBlogPost()'s $tags write path, so it stays behind the same
     * "no term repository configured" guard those already have.
     *
     * @return string[]
     */
    public function getBlogPostTags(int $feedId): array
    {
        if ($this->termRepository === null) {
            return [];
        }

        $terms = $this->termRepository->findByFeedIdsAndVocabulary([$feedId], 'tag')[$feedId] ?? [];

        return array_map(static fn (FeedTerm $term): string => $term->name, $terms);
    }

    /**
     * Deletes one of the current user's own blog posts (or any post, for an
     * admin). Only the type check is blog-specific - ownership/admin rules
     * and the actual row delete (with its ON DELETE CASCADE cleanup of
     * comments/metadata/tags/ratings/favorites/reads) live on
     * FeedService::deleteFeed(), same as every other feed type. The type
     * check here means an id that resolves to some other feed (an article,
     * a forum topic) reads as "not found" through this action rather than
     * quietly deleting the wrong kind of content.
     *
     * @throws NotFoundException
     * @throws ForbiddenException
     */
    public function deleteBlogPost(int $id, User $user): void
    {
        $post = $this->feedService->getFeedById($id, $user);

        if (! $post || $post->type !== 'blog-post') {
            throw new NotFoundException($this->tm->trans('feed.not_found'));
        }

        $this->feedService->deleteFeed($id, $user);
    }

    /**
     * Trims/dedupes (case-insensitively) a blog post's requested tags,
     * stripping a leading '#' the way the editor's tag chips do. Enforces
     * the same "up to 8 tags" limit the UI advertises.
     *
     * @param string[] $tags
     * @return string[]
     * @throws ValidationException
     */
    private function normalizeBlogPostTags(array $tags): array
    {
        $result = [];
        $seen = [];

        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }

            $tag = trim(preg_replace('/\s+/u', ' ', ltrim(trim($tag), '#')));

            if ($tag === '') {
                continue;
            }

            if (mb_strlen($tag) > self::MAX_BLOG_TAG_LENGTH) {
                throw new ValidationException($this->tm->trans('feed.blog_tag_too_long'));
            }

            $key = mb_strtolower($tag);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $tag;
        }

        if (count($result) > self::MAX_BLOG_TAGS) {
            throw new ValidationException($this->tm->trans('feed.blog_tags_too_many'));
        }

        return $result;
    }

    /**
     * @throws ValidationException
     */
    private function normalizeBlogPostTitle(string $title): string
    {
        $title = trim($title);

        if ($title === '') {
            throw new ValidationException($this->tm->trans('feed.blog_title_required'));
        }

        if (mb_strlen($title) > 200) {
            throw new ValidationException($this->tm->trans('feed.blog_title_too_long'));
        }

        return $title;
    }

    /**
     * Slugifies $title and appends a numeric suffix until the result is free
     * under $blogId. The application-level check chooses a friendly suffix;
     * the database constraint closes the concurrent-create race for this
     * parent/type scope. RESERVED_BLOG_POST_
     * SLUGS is folded into the same loop, so e.g. a post titled "Post" (or
     * one whose title has no latin/digit characters at all, which falls back
     * to the literal 'post' below) gets suffixed exactly like a genuine
     * duplicate would.
     */
    private function uniqueBlogPostSlug(int $blogId, string $title, User $user): string
    {
        $maxLength = FeedService::MAX_SLUG_LENGTH;
        $base = Formatter::slugify($title, '-');

        if ($base === '') {
            $base = 'post';
        }

        $base = mb_substr($base, 0, $maxLength);

        $candidate = $base;
        $suffix = 1;

        while (
            in_array($candidate, self::RESERVED_BLOG_POST_SLUGS, true)
            || $this->repository->findByParentAndSlug($blogId, $candidate, $user, 'blog-post') !== null
        ) {
            $suffix++;
            $suffixPart = '-'.$suffix;
            $candidate = mb_substr($base, 0, $maxLength - mb_strlen($suffixPart)).$suffixPart;
        }

        return $candidate;
    }
}

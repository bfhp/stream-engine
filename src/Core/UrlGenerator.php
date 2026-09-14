<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use RuntimeException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\FeedTerm;
use StreamEngine\Domain\Page;

/**
 * UrlGenerator
 *
 * Responsible for building canonical URLs.
 */
final class UrlGenerator
{
    private const int CACHE_TTL = 86400;

    public function __construct(
        private readonly PageTree $pageTree,
        private readonly FeedRepositoryInterface $feedRepository,
        private readonly CacheInterface $cache
    ) {}

    public function pageNumber(QueryParams $query): int
    {
        $page = $query->has('page') ? $query->int('page', 0) : 1;
        if ($page < 1) {
            throw new NotFoundException('Page not found');
        }

        return $page;
    }

    /** @param array<string, string> $filters Normalized content filters only. */
    public function listing(?string $baseUrl, int $page = 1, array $filters = []): ?string
    {
        if ($baseUrl === null) {
            return null;
        }
        if ($page > 1) {
            $filters['page'] = $page;
        }
        $query = http_build_query($filters, '', '&', PHP_QUERY_RFC3986);

        return $baseUrl.($query !== '' ? '?'.$query : '');
    }

    /**
     * Single feed mode
     */
    public function feed(Feed $feed): ?string
    {
        return $this->feeds([$feed])[$feed->id] ?? null;
    }

    public function feedTerm(FeedTerm $term): ?string
    {
        $page = $this->pageTree->findByTermVocabulary($term->vocabulary);

        if (! $page) {
            return null;
        }

        return $this->page($page, ['slug' => $term->slug]);
    }

    /**
     * Resolves the URL of a page that isn't tied to a feed/term - e.g. a
     * static list/landing page - by its (assumed-unique) action string, e.g.
     * 'forums.list'. Null if no page declares that
     * action (page not registered, or a bare PageTree in tests).
     *
     * Centralizes the PageTree::findByAction() + page() pair every module
     * was otherwise repeating itself via its own private resolve*Url()
     * helper -
     * callers no longer need their own PageTree dependency just for this.
     */
    public function action(string $action, array $params = []): ?string
    {
        $page = $this->pageTree->findByAction($action);

        return $page !== null ? $this->page($page, $params) : null;
    }

    /**
     * Batch mode
     *
     * @param  Feed[]  $feeds
     * @return array<int,string> map[feedId => url]
     */
    public function feeds(array $feeds): array
    {
        if (empty($feeds)) {
            return [];
        }

        $result = [];
        $missing = [];

        // 1. checking cache
        foreach ($feeds as $feed) {

            $cacheKey = $this->cacheKey($feed->id);

            $cached = $this->cache->get($cacheKey);

            if (is_string($cached)) {
                $result[$feed->id] = $cached;
            } else {
                $missing[] = $feed->id;
            }
        }

        if (! empty($missing)) {
            // 2. hydrating tree in batch
            $feedMap = $this->feedRepository->hydrateTree($missing);

            foreach ($missing as $id) {

                try {
                    $url = $this->buildFeedUrl($id, $feedMap);
                } catch (RuntimeException $e) {
                    error_log($e->getMessage(), E_USER_WARNING);

                    continue;
                }

                $result[$id] = $url;

                $this->cache->set(
                    $this->cacheKey($id),
                    $url,
                    self::CACHE_TTL
                );
            }
        }

        return $result;
    }

    private function buildFeedUrl(int $feedId, array $feedMap): string
    {
        // 1. Lookup page.feed_id
        if ($page = $this->pageTree->findByFeedId($feedId)) {
            return $this->pageTree->buildPath($page);
        }

        $feedChain = $this->buildChain($feedId, $feedMap);

        if (empty($feedChain)) {
            throw new RuntimeException("Feed not found: $feedId");
        }

        // Collapse self-referential runs (a forum nested under another
        // forum, nested under another, ...) down to their deepest entry
        // before any type-to-page matching happens - see
        // collapseConsecutiveSameType()'s own docblock for why.
        $feedChain = $this->collapseConsecutiveSameType($feedChain);

        // 2. building page chain
        $pageChain = $this->buildPageChainForFeed($feedChain);

        return $this->buildUrlFromPageChain($pageChain, $feedChain);
    }

    /**
     * Collapses a maximal run of consecutive same-type feeds in a root-to-
     * leaf chain down to just its deepest (last) entry; every other feed in
     * the chain is left untouched.
     *
     * Exists for self-referential feed types - forums' 'forum' being the
     * first one, since a subforum is itself a `type='forum'` feed nested
     * under another `type='forum'` feed, arbitrarily deep. The feed-type
     * <-> page matching this class does everywhere else
     * (buildPageChainFromRootFeedType() / appendFeedTypePages() /
     * buildUrlFromPageChain()) assumes each chain entry maps to a
     * *distinct* page level - that's true for a parent and child with two
     * different types and pages, but a recursive type has no
     * second page to map to, and doesn't need one: every forum, whatever
     * its nesting depth, resolves through the one page registered for
     * feed_type 'forum', with its OWN slug - never a path that grows with
     * depth (see the forum mockup's breadcrumb hrefs: a subforum's URL is
     * '/forums/{its own slug}/', not '/forums/{parent}/{itself}/').
     * Collapsing before chain-building lets the existing type-to-page
     * matching run completely unchanged: the one shared page's placeholder
     * just ends up filled by the deepest same-type feed instead of the
     * root one, and any distinct-typed feed further down the chain (e.g. a
     * topic, type 'forum-post') still gets its own page level exactly as
     * before.
     *
     * @param  Feed[]  $feedChain  Root-to-leaf.
     * @return Feed[] Root-to-leaf, with every non-final feed of a same-type
     *                run removed.
     */
    private function collapseConsecutiveSameType(array $feedChain): array
    {
        $collapsed = [];

        foreach ($feedChain as $i => $feed) {
            $next = $feedChain[$i + 1] ?? null;

            if ($next !== null && $next->type === $feed->type) {
                // A deeper feed of the same type follows - it'll carry this
                // level's slug instead, so this entry is redundant.
                continue;
            }

            $collapsed[] = $feed;
        }

        return $collapsed;
    }

    /**
     * Builds the page chain that should render the feed chain.
     *
     * @param  Feed[]  $feedChain  Root-to-leaf feed chain.
     * @return Page[] Root-to-leaf page chain.
     */
    private function buildPageChainForFeed(array $feedChain): array
    {
        // A parent feed can be mounted directly to a page via pages.feed_id.
        // Descendant feeds should continue from that page, not from the generic route for the root feed type.
        $pageBoundFeedIndex = $this->findDeepestPageBoundFeedIndex($feedChain);

        if ($pageBoundFeedIndex !== null) {
            $pageBoundFeed = $feedChain[$pageBoundFeedIndex];
            $page = $this->pageTree->findByFeedId($pageBoundFeed->id);

            if (! $page) {
                throw new RuntimeException("No page bound to feed $pageBoundFeed->id");
            }

            return $this->appendFeedTypePages(
                $this->buildPageAncestors($page),
                array_slice($feedChain, $pageBoundFeedIndex + 1)
            );
        }

        return $this->buildPageChainFromRootFeedType($feedChain);
    }

    /**
     * Builds a page chain by matching the root feed type to the first dynamic page.
     *
     * @param  Feed[]  $feedChain  Root-to-leaf feed chain.
     * @return Page[] Root-to-leaf page chain.
     */
    private function buildPageChainFromRootFeedType(array $feedChain): array
    {
        $rootFeed = $feedChain[0];

        $page = $this->pageTree->findByFeedType($rootFeed->type);

        if (! $page) {
            throw new RuntimeException("No page defined for feed type '$rootFeed->type'");
        }

        return $this->appendFeedTypePages(
            $this->buildPageAncestors($page),
            array_slice($feedChain, 1)
        );
    }

    /**
     * Appends child pages that correspond to the given descendant feed types.
     *
     * @param  Page[]  $pageChain
     * @param  Feed[]  $feeds
     * @return Page[]
     */
    private function appendFeedTypePages(array $pageChain, array $feeds): array
    {
        $currentPage = end($pageChain);

        foreach ($feeds as $feed) {

            $childPage = $this->findChildPageByFeedType(
                $currentPage->id,
                $feed->type
            );

            if (! $childPage) {
                throw new RuntimeException(
                    "No child page for feed type '$feed->type'"
                );
            }

            $pageChain[] = $childPage;
            $currentPage = $childPage;
        }

        return $pageChain;
    }

    /**
     * Finds the deepest feed in the chain that is mounted directly to a page.
     *
     * @param  Feed[]  $feedChain  Root-to-leaf feed chain.
     */
    private function findDeepestPageBoundFeedIndex(array $feedChain): ?int
    {
        for ($i = count($feedChain) - 1; $i >= 0; $i--) {
            if ($this->pageTree->findByFeedId($feedChain[$i]->id)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Builds the static page ancestry for a page, including the page itself.
     *
     * @return Page[] Root-to-page ancestor chain.
     */
    private function buildPageAncestors(Page $page): array
    {
        $staticPages = [];
        $current = $page;

        while ($current) {
            array_unshift($staticPages, $current);
            $current = $current->parentId
                ? $this->pageTree->get($current->parentId)
                : null;
        }

        return $staticPages;
    }

    private function buildUrlFromPageChain(array $pageChain, array $feedChain): string
    {
        $segments = [];
        // Keep the current position in the feed chain because feed types may repeat.
        // Example: article root -> article-section -> article leaf.
        // Each dynamic page should consume the next matching feed, not always the first one.
        $feedCursor = 0;

        foreach ($pageChain as $page) {

            $pattern = $page->pattern;

            if ($page->feedType !== null) {

                [$feed, $feedCursor] = $this->findNextFeedByType($feedChain, $page->feedType, $feedCursor);

                if (! $feed) {
                    throw new RuntimeException("Feed for type '$page->feedType' not found");
                }

                $pattern = $this->fillFeedPlaceholder($pattern, $feed);
            }

            $segments[] = $pattern;
        }

        return '/'.trim(implode('/', array_filter($segments)), '/').'/';
    }

    /**
     * Fills a feed-type page's own placeholder from its matched feed's slug -
     * whatever that placeholder happens to be named. Most pages use {slug},
     * but a page can double as the "root" for a feed type that has no page
     * of its own, matched via feed_type directly on a page whose placeholder
     * is named for something else - e.g. user.show ({username}) doubling as
     * the personal-blog root (feed_type 'blog'), the blog container feed's
     * own slug set to its owner's username by
     * Modules\Users\BlogPostService::getOrCreateUserBlogFeed(). Either way
     * it's the same rule: this page's one dynamic segment comes from this
     * feed's slug column, whatever the placeholder is called - so a purely
     * static pattern (no placeholder at all, e.g. the 'comments' page in
     * testStaticChildSegment) is left untouched.
     */
    private function fillFeedPlaceholder(string $pattern, Feed $feed): string
    {
        if (! preg_match('/\{(\w+)(?::[^}]+)?}/', $pattern, $matches)) {
            return $pattern;
        }

        if (! $feed->slug) {
            throw new RuntimeException("Feed $feed->id has no slug");
        }

        return str_replace($matches[0], $feed->slug, $pattern);
    }

    /**
     * Finds the next feed of the requested type from the current feed cursor.
     * Returns the feed and the next cursor position after the matched feed.
     *
     * @param  Feed[]  $feedChain
     * @return array{Feed|null, int}
     */
    private function findNextFeedByType(array $feedChain, string $type, int $startAt): array
    {
        for ($i = $startAt; $i < count($feedChain); $i++) {
            $feed = $feedChain[$i];

            if ($feed->type === $type) {
                return [$feed, $i + 1];
            }
        }

        return [null, $startAt];
    }

    private function buildChain(int $feedId, array $feedMap): array
    {
        $chain = [];
        $current = $feedMap[$feedId] ?? null;

        while ($current) {
            array_unshift($chain, $current);
            $current = $current->parentId
                ? ($feedMap[$current->parentId] ?? null)
                : null;
        }

        return $chain;
    }

    private function findChildPageByFeedType(int $parentId, string $type): ?Page
    {
        return $this->pageTree->findChildByFeedType($parentId, $type);
    }

    private function cacheKey(int $feedId): string
    {
        return 'feed_url_'.$feedId;
    }

    /**
     * Generate canonical URL for a page.
     */
    public function page(Page $page, array $params = []): string
    {
        $segments = [];

        foreach ($this->buildPageAncestors($page) as $page) {
            $segments[] = $this->applyPageParams($page->pattern, $params);
        }

        $path = '/'.trim(implode('/', array_filter($segments)), '/').'/';

        return rtrim($path, '/').'/';
    }

    /**
     * @param  array<string,string|int>  $params
     */
    private function applyPageParams(string $pattern, array $params): string
    {
        return preg_replace_callback(
            '/\{(\w+)(?::[^}]+)?}/',
            static function (array $matches) use ($params): string {
                $name = $matches[1];

                if (! array_key_exists($name, $params)) {
                    return $matches[0];
                }

                return rawurlencode((string) $params[$name]);
            },
            $pattern
        );
    }
}

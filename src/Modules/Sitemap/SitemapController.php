<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Sitemap;

use RuntimeException;
use StreamEngine\Core\AbstractController;
use StreamEngine\Core\CacheInterface;
use StreamEngine\Core\Config;
use StreamEngine\Core\Cron\CronRegistry;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\FeedTerm;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\TermService;

class SitemapController extends AbstractController
{
    private const int MAX_SITEMAP_URLS = 30000;

    /**
     * Rendered sitemaps are cached gzip-compressed (a 30 000-URL file is
     * ~4 MB of XML, a few hundred KB compressed - below Memcached's 1 MB item
     * limit). The cron task re-renders everything hourly; the TTL only
     * bounds how long a file outlives a stopped scheduler.
     */
    private const string CACHE_PREFIX = 'sitemap:v1:';

    private const int CACHE_TTL = 6 * 60 * 60;

    private const string CRON_TASK = 'sitemap:warm';

    /**
     * Chunk numbers in file names: 1 has no suffix, so 2 and up, without
     * leading zeros (`-10`, `-11`, ... included - `[2-9][0-9]*` once
     * rejected every number starting with 1).
     */
    private const string CHUNK_SUFFIX = '/\A(?:[2-9]|[1-9][0-9]+)\z/';

    public function __construct(
        PdoDatabase $db,
        RequestContext $context,
        private readonly PageTree $pageTree,
        private readonly UrlGenerator $urlGenerator,
        private readonly FeedService $feedService,
        private readonly TermService $termService,
        private readonly Config $config,
        private readonly ?CacheInterface $cache = null,
    ) {
        parent::__construct($db, $context);
    }

    /**
     * @throws NotFoundException
     */
    public function callApi(Page $page, array $args = []): void
    {
        match ($page->action) {
            'sitemap.robots' => $this->handleRobotsTxtRequest(),
            'sitemap.sitemap' => $this->handleSitemapRequest($args['slug']),
            default => throw new NotFoundException('Unknown page'),
        };
    }


    /**
     * @throws NotFoundException
     */
    private function handleSitemapRequest(string $slug): void
    {
        $xml = $this->cachedSitemap($slug) ?? throw new NotFoundException('Unknown page');

        // The one visitor-independent response: replaces the default
        // `private, no-store` policy (see Security::defaultResponseHeaders()).
        header('Content-Type: application/xml; charset=UTF-8');
        header('Cache-Control: public, max-age=3600');
        echo $xml;
    }

    private function cachedSitemap(string $slug): ?string
    {
        $cached = $this->cache?->get(self::CACHE_PREFIX.$slug);

        if (is_string($cached)) {
            $xml = @gzdecode($cached);

            if ($xml !== false) {
                return $xml;
            }
        }

        $xml = $this->renderSitemap($slug);

        // An empty chunk past the end is valid but not worth a cache entry:
        // any number would do, so caching it would let anyone fill the cache.
        if ($xml !== null && ($slug === 'index' || str_contains($xml, '<url>'))) {
            $this->storeSitemap($slug, $xml);
        }

        return $xml;
    }

    private function storeSitemap(string $slug, string $xml): void
    {
        $compressed = gzencode($xml, 6);

        if ($compressed !== false) {
            $this->cache?->set(self::CACHE_PREFIX.$slug, $compressed, self::CACHE_TTL);
        }
    }

    /**
     * Renders every sitemap the index lists, then the index itself, so a
     * crawler never waits for the feed counts or a 30 000-row query.
     */
    public function warmCache(): void
    {
        $files = $this->sitemapFiles();

        foreach ($files as $file) {
            $slug = $this->slugFromFile($file);
            $xml = $this->renderSitemap($slug);

            if ($xml !== null) {
                $this->storeSitemap($slug, $xml);
            }
        }

        $this->storeSitemap('index', $this->renderSitemapIndex($files));
    }

    public static function registerCron(CronRegistry $cron): void
    {
        $cron->add(self::CRON_TASK, 'Sitemap', 60 * 60);
    }

    public function runCron(string $task): void
    {
        if ($task === self::CRON_TASK) {
            $this->warmCache();
        }
    }

    private function renderSitemap(string $slug): ?string
    {
        if ($slug === 'index') {
            return $this->renderSitemapIndex();
        }

        if ($slug === 'pages') {
            return $this->renderUrlSet($this->pageUrls());
        }

        $feedSitemap = $this->parseFeedSitemapSlug($slug);

        if ($feedSitemap !== null) {
            return $this->renderUrlSet($this->feedUrls($feedSitemap['type'], $feedSitemap['page']));
        }

        $termSitemap = $this->parseTermSitemapSlug($slug);

        if ($termSitemap !== null) {
            return $this->renderUrlSet($this->termUrls($termSitemap['vocabulary'], $termSitemap['page']));
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function sitemapFiles(): array
    {
        return ['sitemap-pages.xml', ...$this->feedSitemapFiles(), ...$this->termSitemapFiles()];
    }

    /**
     * @param list<string>|null $files
     */
    private function renderSitemapIndex(?array $files = null): string
    {
        $locations = array_map(
            fn (string $file): array => ['loc' => $this->absoluteSitemapFileUrl($file)],
            $files ?? $this->sitemapFiles()
        );

        $items = array_map(
            fn (array $entry): string => sprintf(
                "  <sitemap>\n    <loc>%s</loc>\n  </sitemap>",
                $this->xml($entry['loc'])
            ),
            $locations
        );

        return $this->xmlHeader()
            ."<sitemapindex xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            .implode("\n", $items)
            ."\n</sitemapindex>";
    }

    /**
     * @param list<array{loc:string,lastmod?:int|null,changefreq?:string|null}> $entries
     */
    private function renderUrlSet(array $entries): string
    {
        $items = array_map(
            fn (array $entry): string => $this->renderUrlEntry($entry),
            array_slice($entries, 0, self::MAX_SITEMAP_URLS)
        );

        return $this->xmlHeader()
            ."<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            .implode("\n", $items)
            ."\n</urlset>";
    }

    /**
     * @param array{loc:string,lastmod?:int|null,changefreq?:string|null} $entry
     */
    private function renderUrlEntry(array $entry): string
    {
        $xml = "  <url>\n    <loc>".$this->xml($entry['loc'])."</loc>";

        if (!empty($entry['lastmod'])) {
            $xml .= "\n    <lastmod>".gmdate('Y-m-d', (int) $entry['lastmod']).'</lastmod>';
        }

        if (!empty($entry['changefreq'])) {
            $xml .= "\n    <changefreq>".$this->xml($entry['changefreq']).'</changefreq>';
        }

        return $xml."\n  </url>";
    }

    /**
     * @return list<array{loc:string,lastmod?:int|null,changefreq?:string|null}>
     */
    private function pageUrls(): array
    {
        $entries = [];

        foreach ($this->pageTree->all() as $page) {
            if (!$this->isSitemapPage($page)) {
                continue;
            }

            $entries[] = [
                'loc' => $this->absoluteUrl($this->urlGenerator->page($page)),
                'lastmod' => $page->updated,
                'changefreq' => $page->changefreq,
            ];
        }

        return $this->uniqueEntries($entries);
    }

    private function isSitemapPage(Page $page): bool
    {
        return $page->accessRule === AccessService::ACCESS_PUBLIC
            && $page->responseType === 'html'
            && $page->changefreq !== 'noindex'
            && in_array('GET', $page->requestMethods ?? ['GET'], true)
            && $page->feedType === null
            && !str_contains($this->pageTree->buildPath($page), '{');
    }

    /**
     * @return list<string>
     */
    private function sitemapFeedTypes(): array
    {
        $types = [];

        foreach ($this->pageTree->all() as $page) {
            if ($page->accessRule === AccessService::ACCESS_PUBLIC
                && $page->feedType !== null
                && str_contains($page->pattern, '{')) {
                $types[] = $page->feedType;
            }
        }

        sort($types);

        return array_values(array_unique($types));
    }

    /**
     * @return list<string>
     */
    private function feedSitemapFiles(): array
    {
        $files = [];

        foreach ($this->sitemapFeedTypes() as $type) {
            $pageCount = $this->feedSitemapPageCount($type);

            for ($page = 1; $page <= $pageCount; $page++) {
                $suffix = $page === 1 ? '' : '-'.$page;
                $files[] = 'sitemap-'.$type.$suffix.'.xml';
            }
        }

        return $files;
    }

    private function feedSitemapPageCount(string $type): int
    {
        $count = $this->feedService->countFeedsByType($type, $this->guest());

        return max(1, (int) ceil($count / self::MAX_SITEMAP_URLS));
    }

    /**
     * @return list<string>
     */
    private function termVocabularies(): array
    {
        $vocabularies = [];

        foreach ($this->pageTree->all() as $page) {
            if ($page->accessRule === AccessService::ACCESS_PUBLIC
                && $page->termVocabulary !== null
                && str_contains($page->pattern, '{slug}')) {
                $vocabularies[] = $page->termVocabulary;
            }
        }

        sort($vocabularies);

        return array_values(array_unique($vocabularies));
    }

    /**
     * @return list<string>
     */
    private function termSitemapFiles(): array
    {
        $files = [];

        foreach ($this->termVocabularies() as $vocabulary) {
            $pageCount = $this->termSitemapPageCount($vocabulary);

            for ($page = 1; $page <= $pageCount; $page++) {
                $suffix = $page === 1 ? '' : '-'.$page;
                $files[] = 'sitemap-term-'.$vocabulary.$suffix.'.xml';
            }
        }

        return $files;
    }

    private function termSitemapPageCount(string $vocabulary): int
    {
        $count = $this->termService->countTermsByVocabulary($vocabulary);

        return max(1, (int) ceil($count / self::MAX_SITEMAP_URLS));
    }

    /**
     * @return array{type:string,page:int}|null
     */
    private function parseFeedSitemapSlug(string $slug): ?array
    {
        $types = $this->sitemapFeedTypes();
        usort($types, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($types as $type) {
            if ($slug === $type) {
                return ['type' => $type, 'page' => 1];
            }

            $prefix = $type.'-';

            if (str_starts_with($slug, $prefix)) {
                $page = substr($slug, strlen($prefix));

                if (preg_match(self::CHUNK_SUFFIX, $page) === 1) {
                    return ['type' => $type, 'page' => (int) $page];
                }
            }
        }

        return null;
    }

    /**
     * @return array{vocabulary:string,page:int}|null
     */
    private function parseTermSitemapSlug(string $slug): ?array
    {
        $vocabularies = $this->termVocabularies();
        usort($vocabularies, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($vocabularies as $vocabulary) {
            $base = 'term-'.$vocabulary;

            if ($slug === $base) {
                return ['vocabulary' => $vocabulary, 'page' => 1];
            }

            $prefix = $base.'-';

            if (str_starts_with($slug, $prefix)) {
                $page = substr($slug, strlen($prefix));

                if (preg_match(self::CHUNK_SUFFIX, $page) === 1) {
                    return ['vocabulary' => $vocabulary, 'page' => (int) $page];
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{loc:string,lastmod?:int|null}>
     */
    private function feedUrls(string $type, int $page): array
    {
        $offset = ($page - 1) * self::MAX_SITEMAP_URLS;

        return array_values(array_filter(array_map(
            fn (Feed $feed): ?array => $feed->canonicalUrl
                ? [
                    'loc' => $this->absoluteUrl($feed->canonicalUrl),
                    'lastmod' => $feed->updatedAt,
                ]
                : null,
            // URLs and dates only: loading 30 000 bodies took ~400 MB.
            $this->feedService->getFeedsByType($type, $this->guest(), self::MAX_SITEMAP_URLS, $offset, withContent: false)
        )));
    }

    /**
     * @return list<array{loc:string,lastmod?:int|null}>
     */
    private function termUrls(string $vocabulary, int $page): array
    {
        $offset = ($page - 1) * self::MAX_SITEMAP_URLS;

        return array_values(array_filter(array_map(
            function (FeedTerm $term): ?array {
                $url = $term->canonicalUrl;

                return $url
                    ? [
                        'loc' => $this->absoluteUrl($url),
                        'lastmod' => $term->updatedAt,
                    ]
                    : null;
            },
            $this->termService->getTermsByVocabulary($vocabulary, self::MAX_SITEMAP_URLS, $offset)
        )));
    }

    /**
     * @param list<array{loc:string,lastmod?:int|null,changefreq?:string|null}> $entries
     * @return list<array{loc:string,lastmod?:int|null,changefreq?:string|null}>
     */
    private function uniqueEntries(array $entries): array
    {
        $result = [];

        foreach ($entries as $entry) {
            $result[$entry['loc']] = $entry;
        }

        return array_values($result);
    }

    private function guest(): User
    {
        return new User(id: 0, email: '');
    }

    private function absoluteUrl(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $siteUrl = $this->config->siteUrl()
            ?? throw new RuntimeException('Canonical site URL is not configured');

        return $siteUrl.'/'.ltrim($path, '/');
    }

    private function absoluteSitemapUrl(string $slug): string
    {
        $path = $this->urlGenerator->action('sitemap.sitemap', ['slug' => $slug])
            ?? throw new RuntimeException('Sitemap page URL is not configured');

        return rtrim($this->absoluteUrl($path), '/');
    }

    private function absoluteSitemapFileUrl(string $file): string
    {
        return $this->absoluteSitemapUrl($this->slugFromFile($file));
    }

    private function slugFromFile(string $file): string
    {
        return substr($file, strlen('sitemap-'), -strlen('.xml'));
    }

    private function xmlHeader(): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    private function handleRobotsTxtRequest(): void
    {
        $modified = (int) filemtime(__FILE__);

        if ($this->notModifiedSince($modified, $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null)) {
            http_response_code(304);
            exit;
        }
        header('Last-Modified: '.gmdate('D, d M Y H:i:s', $modified).' GMT');
        header('Cache-Control: public, max-age=60');
        header('Expires: 0');
        header('Content-Type: text/plain');
        printf("User-agent: *
Disallow: /admin/
Disallow: /api/

Sitemap: %s", $this->absoluteSitemapUrl('index'));
    }

    /**
     * Only a validator at or after the last change earns a 304; a stale or
     * unparsable date gets the current file.
     */
    private function notModifiedSince(int $modified, mixed $header): bool
    {
        if (!is_string($header) || $header === '') {
            return false;
        }

        $since = strtotime($header);

        return $since !== false && $since >= $modified;
    }

    public static function registerApi(int $apiPageId, PageTree $pageTree): void
    {
        $pageTree->add(
            new Page(
                id: $pageTree->getMaxPageId(),
                parentId: 1,
                pattern: 'robots.txt',
                pageName: null,
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                requestMethods: ['GET'],
                responseType: 'raw',
                accessRule: AccessService::ACCESS_PUBLIC,
                action: 'sitemap.robots',
            )
        );

        $pageTree->add(
            new Page(
                id: $pageTree->getMaxPageId(),
                parentId: 1,
                pattern: 'sitemap-{slug}.xml',
                pageName: null,
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                requestMethods: ['GET'],
                responseType: 'raw',
                accessRule: AccessService::ACCESS_PUBLIC,
                action: 'sitemap.sitemap',
            )
        );
    }
}

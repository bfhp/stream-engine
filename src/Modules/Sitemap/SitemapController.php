<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Sitemap;

use RuntimeException;
use StreamEngine\Core\AbstractController;
use StreamEngine\Core\Config;
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

    public function __construct(
        PdoDatabase $db,
        RequestContext $context,
        private readonly PageTree $pageTree,
        private readonly UrlGenerator $urlGenerator,
        private readonly FeedService $feedService,
        private readonly TermService $termService,
        private readonly Config $config,
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
        header('Content-Type: application/xml; charset=UTF-8');

        if ($slug === 'index') {
            echo $this->renderSitemapIndex();
            return;
        }

        if ($slug === 'pages') {
            echo $this->renderUrlSet($this->pageUrls());
            return;
        }

        $feedSitemap = $this->parseFeedSitemapSlug($slug);

        if ($feedSitemap !== null) {
            echo $this->renderUrlSet($this->feedUrls($feedSitemap['type'], $feedSitemap['page']));
            return;
        }

        $termSitemap = $this->parseTermSitemapSlug($slug);

        if ($termSitemap !== null) {
            echo $this->renderUrlSet($this->termUrls($termSitemap['vocabulary'], $termSitemap['page']));
            return;
        }

        throw new NotFoundException('Unknown page');
    }

    private function renderSitemapIndex(): string
    {
        $locations = [
            ['loc' => $this->absoluteSitemapUrl('pages')],
        ];

        foreach ($this->feedSitemapFiles() as $file) {
            $locations[] = ['loc' => $this->absoluteSitemapFileUrl($file)];
        }

        foreach ($this->termSitemapFiles() as $file) {
            $locations[] = ['loc' => $this->absoluteSitemapFileUrl($file)];
        }

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
            && !str_contains($page->pattern, '{');
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
                && str_contains($page->pattern, '{slug}')) {
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

                if (preg_match('/^[2-9][0-9]*$/', $page) === 1) {
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

                if (preg_match('/^[2-9][0-9]*$/', $page) === 1) {
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
            $this->feedService->getFeedsByType($type, $this->guest(), self::MAX_SITEMAP_URLS, $offset)
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
        $slug = substr($file, strlen('sitemap-'), -strlen('.xml'));

        return $this->absoluteSitemapUrl($slug);
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
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            http_response_code(304);
            exit;
        }
        header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime(__FILE__)).' GMT');
        header('Cache-Control: public, max-age=60');
        header('Expires: 0');
        header('Content-Type: text/plain');
        printf("User-agent: *
Disallow: /admin/
Disallow: /api/

Sitemap: %s", $this->absoluteSitemapUrl('index'));
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
                commentsEnabled: false,
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
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'raw',
                accessRule: AccessService::ACCESS_PUBLIC,
                action: 'sitemap.sitemap',
            )
        );
    }
}

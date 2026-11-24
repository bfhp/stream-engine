<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use StreamEngine\Domain\Page;

/**
 * @phpstan-type ResolveResult array{
 *     page: Page,
 *     params: array<string,string>,
 *     breadcrumbs: list<Page>
 * }
 */
final class Router
{
    /**
     * @var array<int,array<string,Page>>
     */
    private array $staticRoutes = [];

    /**
     * @var array<int,list<array{page:Page, regex:string}>>
     */
    private array $dynamicRoutes = [];

    public function __construct(
        private readonly PageTree $pageTree
    ) {
        $this->indexPages();
    }

    /**
     * Build internal route index from PageTree.
     */
    private function indexPages(): void
    {
        foreach ($this->pageTree->all() as $page) {

            $parentId = $page->parentId ?? 0;

            if ($this->isDynamic($page->pattern)) {
                $this->dynamicRoutes[$parentId][] = [
                    'page' => $page,
                    'regex' => $this->compilePattern($page->pattern),
                ];
            } else {
                $this->staticRoutes[$parentId][$page->pattern] = $page;
            }
        }
    }

    /**
     * @return ResolveResult|null
     */
    public function resolve(string $uri): ?array
    {
        $uri = trim($uri, '/');

        $segments = $uri === '' ? [''] : array_merge([''], explode('/', $uri));
        $parentId = 0;
        $breadcrumbs = [];

        $params = [];

        foreach ($segments as $segment) {
            // 1️⃣ STATIC
            if (isset($this->staticRoutes[$parentId][$segment])) {
                $page = clone $this->staticRoutes[$parentId][$segment];
                $page->params = [];
                $this->pageTree->replaceWithResolved($page);
                $breadcrumbs[] = $page;
                $parentId = $page->id;
                continue;
            }

            // 2️⃣ DYNAMIC
            $matched = false;

            foreach ($this->dynamicRoutes[$parentId] ?? [] as $route) {

                if (! preg_match($route['regex'], $segment, $matches)) {
                    continue;
                }

                $page = clone $route['page'];

                $parentId = $page->id;

                $pageParams = [];
                foreach ($matches as $key => $value) {
                    if (is_string($key)) {
                        $pageParams[$key] = $value;
                        $params[$key] = $value;
                    }
                }

                $page->params = $pageParams;
                $this->pageTree->replaceWithResolved($page);

                $breadcrumbs[] = $page;

                $matched = true;
                break;
            }

            if (! $matched) {
                return null;
            }
        }

        if (! $breadcrumbs) {
            return null;
        }

        return [
            'page' => end($breadcrumbs),
            'params' => $params,
            'breadcrumbs' => $breadcrumbs,
        ];
    }

    private function isDynamic(string $pattern): bool
    {
        return str_contains($pattern, '{');
    }

    private function compilePattern(string $pattern): string
    {
        $regex = preg_replace_callback(
            '/\{(\w+)(?::([^}]+))?}/',
            static function (array $m): string {
                $name = $m[1];
                $rule = $m[2] ?? '[^/]+';

                return '(?P<'.$name.'>'.$rule.')';
            },
            $pattern
        );

        return '#^'.$regex.'$#';
    }

    /**
     * The one URL form for an HTML page: the path ends with a slash, as every link
     * UrlGenerator builds does. resolve() trims slashes, so `/forums`, `/forums/`
     * and `//forums//` all reach the same page; this names the address to
     * redirect the other forms to.
     *
     * Only GET and HEAD are redirected (a 301 turns a POST into a GET and drops its
     * body), only for HTML pages (API clients do not expect redirects), and only
     * once the caller has resolved the page and checked access, so a 301 never
     * reveals a page that would otherwise answer 404. A last segment with a dot
     * (`robots.txt`, `sitemap.xml`) is a file name and keeps its form.
     *
     * @param string $requestUri the raw REQUEST_URI: path plus optional query
     * @return string|null the path-and-query to redirect to, or null to serve as is
     */
    public static function trailingSlashRedirect(string $method, string $requestUri): ?string
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }

        $queryAt = strpos($requestUri, '?');
        $path = $queryAt === false ? $requestUri : substr($requestUri, 0, $queryAt);
        $query = $queryAt === false ? '' : substr($requestUri, $queryAt);

        // Collapsing repeated slashes also keeps the Location from starting
        // with `//`, which a browser would read as another host.
        $normalized = '/'.trim((string) preg_replace('~/{2,}~', '/', $path), '/');
        if ($normalized !== '/') {
            $last = substr($normalized, (int) strrpos($normalized, '/') + 1);
            if (! str_contains($last, '.')) {
                $normalized .= '/';
            }
        }

        return $normalized === $path ? null : $normalized.$query;
    }
}

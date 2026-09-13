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
                $this->pageTree->add($page);
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
                $this->pageTree->add($page);

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
}

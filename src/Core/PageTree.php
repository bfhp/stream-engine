<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use RuntimeException;
use StreamEngine\Domain\Page;

/**
 * PageTree
 *
 * Keeps all pages indexed in memory.
 * Used by Router and UrlGenerator.
 */
final class PageTree
{
    /** @var array<int, Page> */
    private array $pagesById = [];

    private array $pagesByFeedId = [];

    private array $pagesByFeedType = [];

    private array $pagesByTermVocabulary = [];

    private array $pagesByAction = [];

    public function __construct(array $pages)
    {
        foreach ($pages as $page) {
            $this->indexPage($page);
        }
    }

    private function indexPage(Page $page): void
    {
        $this->pagesById[$page->id] = $page;

        if ($page->feedId !== null) {
            $this->pagesByFeedId[$page->feedId] = $page;
        }

        if ($page->feedType !== null) {
            $this->pagesByFeedType[$page->feedType] = $page;
        }

        if ($page->termVocabulary !== null) {
            $this->pagesByTermVocabulary[$page->termVocabulary] = $page;
        }

        if ($page->action !== null) {
            $this->pagesByAction[$page->action] = $page;
        }
    }

    public function get(int $id): ?Page
    {
        return $this->pagesById[$id] ?? null;
    }

    public function findByFeedId(int $feedId): ?Page
    {
        return $this->pagesByFeedId[$feedId] ?? null;
    }

    public function findByFeedType(string $type): ?Page
    {
        return $this->pagesByFeedType[$type] ?? null;
    }

    public function findByTermVocabulary(string $vocabulary): ?Page
    {
        return $this->pagesByTermVocabulary[$vocabulary] ?? null;
    }

    /**
     * Looks up a page by its assumed-unique action string.
     * Used to resolve a canonical URL for a page that isn't tied to a feed/term
     * (see UrlGenerator, which resolves feed and term pages differently).
     */
    public function findByAction(string $action): ?Page
    {
        return $this->pagesByAction[$action] ?? null;
    }

    /**
     * Build canonical path for a page.
     */
    public function buildPath(Page $page): string
    {
        $segments = array_map(
            static fn (Page $ancestor): string => $ancestor->pattern,
            $this->ancestors($page)
        );

        $path = '/'.trim(implode('/', array_filter($segments)), '/').'/';

        return rtrim($path, '/').'/';
    }

    /**
     * Returns the root-to-page chain and fails fast for corrupt cyclic data.
     *
     * @return list<Page>
     */
    public function ancestors(Page $page): array
    {
        $ancestors = [];
        $visited = [];
        $current = $page;

        while ($current !== null) {
            if (isset($visited[$current->id])) {
                throw new RuntimeException('Page hierarchy cycle detected at page '.$current->id);
            }

            $visited[$current->id] = true;
            $ancestors[] = $current;
            $current = $current->parentId !== null
                ? $this->get($current->parentId)
                : null;
        }

        return array_reverse($ancestors);
    }

    /**
     * @return list<Page>
     */
    public function findChildren(int $parentId): array
    {
        $result = [];

        foreach ($this->pagesById as $page) {
            if ($page->parentId === $parentId) {
                $result[] = $page;
            }
        }

        return $result;
    }

    public function findChildByFeedType(int $parentId, string $type): ?Page
    {
        foreach ($this->pagesById as $page) {
            if ($page->parentId === $parentId
                && $page->feedType === $type) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Build canonical path for a page.
     */
    public function add(Page $page): void
    {
        $this->indexPage($page);
    }

    public function getMaxPageId(): int
    {
        return empty($this->pagesById) ? 1 : max(array_keys($this->pagesById)) + 1;
    }

    /**
     * @return list<Page>
     */
    public function all(): array
    {
        return array_values($this->pagesById);
    }
}

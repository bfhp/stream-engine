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
    /**
     * Registration history is kept separately from the indexes so boot-time
     * validation can see duplicate definitions even though the live index is
     * allowed to hold a resolved clone with the same id.
     *
     * @var list<Page>
     */
    private array $definitions = [];

    /** @var array<int, Page> */
    private array $pagesById = [];

    private array $pagesByFeedId = [];

    private array $pagesByFeedType = [];

    private array $pagesByTermVocabulary = [];

    private array $pagesByAction = [];

    public function __construct(array $pages)
    {
        foreach ($pages as $page) {
            $this->add($page);
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

    /** Register a page definition and update the live indexes. */
    public function add(Page $page): void
    {
        $this->definitions[] = $page;
        $this->indexPage($page);
    }

    /** Store a request-specific clone without treating it as a definition. */
    public function replaceWithResolved(Page $page): void
    {
        $this->indexPage($page);
    }

    public function definitionCount(): int
    {
        return count($this->definitions);
    }

    /** @return list<Page> */
    public function definitionsSince(int $offset): array
    {
        return array_slice($this->definitions, $offset);
    }

    /**
     * Validate the definitions collected during boot. This deliberately is
     * not part of add() so modules can register a complete tree before it is
     * checked and produce a precise boot error.
     */
    public function validateDefinitions(): void
    {
        $ids = [];
        $actions = [];
        $pagesById = [];
        $staticPatterns = [];
        $dynamicPatterns = [];

        foreach ($this->definitions as $page) {
            if (isset($ids[$page->id])) {
                throw new RuntimeException("Duplicate page definition id '{$page->id}'");
            }
            $ids[$page->id] = true;
            $pagesById[$page->id] = $page;

            if ($page->action !== null) {
                if (isset($actions[$page->action])) {
                    throw new RuntimeException("Duplicate page definition action '{$page->action}'");
                }
                $actions[$page->action] = true;
            }

            $parent = $page->parentId ?? 0;
            if (str_contains($page->pattern, '{')) {
                if (isset($dynamicPatterns[$parent])) {
                    throw new RuntimeException(
                        "Ambiguous dynamic page patterns '{$dynamicPatterns[$parent]}' and '{$page->pattern}' "
                        ."under parent '{$parent}'"
                    );
                }
                $dynamicPatterns[$parent] = $page->pattern;
            } else {
                if (isset($staticPatterns[$parent][$page->pattern])) {
                    throw new RuntimeException(
                        "Duplicate static page pattern '{$page->pattern}' under parent '{$parent}'"
                    );
                }
                $staticPatterns[$parent][$page->pattern] = true;
            }
        }

        foreach ($this->definitions as $page) {
            if ($page->parentId !== null && ! isset($pagesById[$page->parentId])) {
                throw new RuntimeException(
                    "Page definition '{$page->id}' references missing parent '{$page->parentId}'"
                );
            }
        }

        foreach ($this->definitions as $page) {
            $this->ancestors($page);
        }
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

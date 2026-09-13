<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use StreamEngine\Core\PageTree;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\MenuItem;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;

/**
 * MenuService
 *
 * Responsible for:
 *  - filtering by group
 *  - filtering by audience rule
 *  - building tree structure
 *  - marking active items
 *
 * Does NOT access database.
 */
final readonly class MenuService
{
    public function __construct(
        private UrlGenerator $urlGenerator,
        private PageTree     $pageTree
    ) {

    }

    /**
     * Build menu tree for a specific group.
     *
     * @param list<MenuItem> $allItems
     * @param string $group
     * @param list<Page> $breadcrumbs
     * @param User $currentUser
     *
     * @return list<MenuItem>
     */
    public function build(
        array $allItems,
        string $group,
        array $breadcrumbs,
        User $currentUser
    ): array {

        // Filter by menu group
        $items = array_filter(
            $allItems,
            static fn (MenuItem $item) =>
                $item->menuGroup === $group
                && AccessService::allows($currentUser, $item->accessRule)
        );

        // Group by parent
        $byParent = [];
        foreach ($items as $item) {
            $parent = $item->parentId ?? 0;
            $byParent[$parent][] = $item;
        }

        // Collect active page ids from breadcrumbs
        $activePageIds = array_map(
            static fn (Page $page) => $page->id,
            $breadcrumbs
        );

        return $this->buildTree(0, $byParent, $activePageIds, $currentUser);
    }

    /**
     * Recursively build menu tree.
     *
     * @param int $parentId
     * @param list<MenuItem> $byParent
     * @param list<int> $activePageIds
     * @param User|null $currentUser
     *
     * @return list<MenuItem>
     */
    private function buildTree(
        int $parentId,
        array $byParent,
        array $activePageIds,
        ?User $currentUser
    ): array {

        $result = [];

        foreach ($byParent[$parentId] ?? [] as $item) {

            // Resolve canonical URL
            if ($item->url === null && $item->pageId !== null) {
                $page = $this->pageTree->get($item->pageId);
                if ($page !== null) {
                    $item->url = $item->isDynamic()
                        ? $this->resolveDynamicUrl($page, $currentUser)
                        : $this->urlGenerator->page($page);
                }
            }

            $children = $this->buildTree(
                $item->id,
                $byParent,
                $activePageIds,
                $currentUser
            );

            $item->children = $children;

            if ($item->isDynamic() && $item->url === null) {
                continue;
            }

            $isActive = false;

            if ($item->pageId !== null &&
                in_array($item->pageId, $activePageIds, true)
            ) {
                $isActive = true;
            }

            if (!$isActive && $this->hasActiveChild($children)) {
                $isActive = true;
            }

            $item->active = $isActive;

            $result[] = $item;
        }

        return $result;
    }

    private function resolveDynamicUrl(Page $page, ?User $currentUser): ?string
    {
        if ($currentUser === null || $currentUser->isGuest()) {
            return null;
        }

        $params = $this->resolveDynamicPageParams($page, $currentUser);

        if ($params === null) {
            return null;
        }

        return $this->urlGenerator->page($page, $params);
    }

    /**
     * Dynamic menu links are ordinary pages whose path placeholders are filled
     * from the current user. Example: page pattern "{username}" becomes the
     * logged-in user's public blog/profile URL.
     *
     * @return array<string,string>|null
     */
    private function resolveDynamicPageParams(Page $page, User $currentUser): ?array
    {
        $params = [];

        foreach ($this->pageAncestors($page) as $ancestor) {
            if (! preg_match_all('/\{(\w+)(?::[^}]+)?}/', $ancestor->pattern, $matches)) {
                continue;
            }

            foreach ($matches[1] as $name) {
                $value = $this->currentUserParam($name, $currentUser);

                if ($value === null || $value === '') {
                    return null;
                }

                $params[$name] = $value;
            }
        }

        return $params;
    }

    /**
     * @return list<Page>
     */
    private function pageAncestors(Page $page): array
    {
        $ancestors = [];
        $current = $page;

        while ($current !== null) {
            array_unshift($ancestors, $current);
            $current = $current->parentId !== null
                ? $this->pageTree->get($current->parentId)
                : null;
        }

        return $ancestors;
    }

    private function currentUserParam(string $name, User $currentUser): ?string
    {
        return match ($name) {
            'username' => $currentUser->username,
            default => null,
        };
    }

    /**
     * Check if any child is active.
     *
     * @param list<MenuItem> $children
     */
    private function hasActiveChild(array $children): bool
    {
        foreach ($children as $child) {
            if ($child->active) {
                return true;
            }
        }

        return false;
    }
}

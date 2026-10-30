<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use RuntimeException;
use StreamEngine\Domain\Page;
use StreamEngine\Service\AccessService;

/**
 * Builds the complete runtime page tree from the persisted pages.
 *
 * Boot and the administration API both go through this class, so a page edit
 * can be checked against exactly the tree the next request will assemble:
 * module runtime pages, the hardcoded api/admin entry points and every page a
 * controller declares in registerApi().
 */
final readonly class PageTreeAssembler
{
    public function __construct(
        private ModuleRegistry $modules,
        private TranslationManager $tm,
    ) {
    }

    /**
     * Add every non-persisted page to the tree, in boot order.
     *
     * $bindAction receives each action a module adds together with the module id
     * that owns it. Boot binds them in the ModuleRegistry; a dry run passes null.
     *
     * @param (callable(string, string): void)|null $bindAction
     */
    public function extend(PageTree $tree, ?callable $bindAction = null): void
    {
        $this->registerRuntimePages($tree, $bindAction);
        $this->registerApiPages($tree, $bindAction);
    }

    /**
     * Let each active module extend the database-backed page tree.
     *
     * @param (callable(string, string): void)|null $bindAction
     */
    public function registerRuntimePages(PageTree $tree, ?callable $bindAction = null): void
    {
        foreach ($this->modules->entries() as ['id' => $id, 'controllerClass' => $controllerClass]) {
            $offset = $tree->definitionCount();
            $controllerClass::registerRuntimePages($tree, $this->tm);

            foreach ($tree->definitionsSince($offset) as $page) {
                if ($page->action === null) {
                    throw new RuntimeException(
                        "Runtime page '{$page->pattern}' declared by $id must have an action"
                    );
                }

                if ($bindAction !== null) {
                    $bindAction($page->action, $id);
                }
            }
        }
    }

    /**
     * Add /api, /api/v1 and /admin, then the API pages of every controller.
     *
     * @param (callable(string, string): void)|null $bindAction
     */
    public function registerApiPages(PageTree $tree, ?callable $bindAction = null): void
    {
        $bind = $bindAction ?? static function (string $action, string $id): void {
        };

        $apiPageId = $tree->getMaxPageId();
        $tree->add(
            Page::api(
                id: $apiPageId,
                parentId: 1,
                pattern: 'api',
                requestMethods: ['GET'],
                action: 'api.index',
            )
        );
        $bind('api.index', 'API');

        $apiV1PageId = $tree->getMaxPageId();
        $tree->add(
            Page::api(
                id: $apiV1PageId,
                parentId: $apiPageId,
                pattern: 'v1',
                requestMethods: ['GET'],
                action: 'api.v1.index',
            )
        );
        $bind('api.v1.index', 'API');

        $tree->add(
            new Page(
                id: $tree->getMaxPageId(),
                parentId: 1,
                pattern: 'admin',
                pageName: 'Administrator interface',
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                requestMethods: ['GET'],
                responseType: 'html',
                accessRule: AccessService::ACCESS_ADMIN,
                action: 'admin.index',
            )
        );

        // Actions the pages already carry (DB pages + the hardcoded ones above)
        // are handled via pageActions()/explicit registration; everything a controller
        // adds in registerApi() below is an API action owned by that controller.
        $seenIds = array_fill_keys(array_map(static fn (Page $p) => $p->id, $tree->all()), true);

        foreach ($this->modules->entries() as ['id' => $id, 'controllerClass' => $controllerClass]) {
            if (! is_subclass_of($controllerClass, ControllerInterface::class)) {
                continue;
            }
            $controllerClass::registerApi($apiV1PageId, $tree);

            foreach ($tree->all() as $page) {
                if (isset($seenIds[$page->id])) {
                    continue;
                }
                $seenIds[$page->id] = true;
                if ($page->action !== null) {
                    $bind($page->action, $id);
                }
            }
        }
    }

    /** Run the boot-time structural checks against an assembled tree. */
    public function validate(PageTree $tree): void
    {
        $tree->validateDefinitions($this->modules->singletonPageActions());
    }

    /**
     * Assemble a throwaway tree from the given persisted pages and validate it,
     * throwing the same RuntimeException the next boot would.
     *
     * @param list<Page> $pages
     */
    public function check(array $pages): void
    {
        $tree = new PageTree($pages);
        $this->extend($tree);
        $this->validate($tree);
    }
}

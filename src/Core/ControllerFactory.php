<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use Exception;
use RuntimeException;
use StreamEngine\Domain\Page;

/**
 * Resolves and instantiates the controller responsible for a page/action.
 *
 * This class used to hold the full controller list itself (CONTROLLERS const)
 * and a hand-maintained match() of every service type any controller could
 * need. ModuleRegistry now owns the feature list, while this factory resolves
 * constructor arguments from the shared services handed to it at bootstrap.
 */
final readonly class ControllerFactory
{
    private ServiceResolver $resolver;

    public function __construct(
        private ModuleRegistry $modules,
        object ...$services,
    ) {
        $this->resolver = new ServiceResolver($modules, ...$services);
    }

    /** The resolver controllers are built with; extensions are built with the same one. */
    public function resolver(): ServiceResolver
    {
        return $this->resolver;
    }

    public static function shortName(string $controllerClass): string
    {
        return substr((string) strrchr($controllerClass, '\\'), 1);
    }

    /**
     * Bind an action to the module/controller id that handles it. Used for API
     * actions that controllers declare through registerApi() rather than
     * pageActions().
     */
    public function registerAction(string $action, string $id): void
    {
        $this->modules->registerAction($action, $id);
    }

    /**
     * Let each active module extend the database-backed page tree, then bind
     * every newly declared action to the controller that declared it.
     */
    public function registerRuntimePages(PageTree $pageTree, TranslationManager $tm): void
    {
        (new PageTreeAssembler($this->modules, $tm))
            ->registerRuntimePages($pageTree, $this->registerAction(...));
    }

    /**
     * Resolve and instantiate the controller responsible for the page's action.
     *
     * @throws Exception
     */
    public function createForPage(Page $page, RequestContext $context): ControllerInterface
    {
        $action = $page->action;
        $id = $action !== null ? $this->modules->idForAction($action) : null;

        if ($id === null) {
            throw new Exception("No controller resolves page action '".($action ?? 'null')."'");
        }

        return $this->create($id, $context);
    }

    /**
     * @throws Exception
     */
    public function create(string $name, RequestContext $context): ControllerInterface
    {
        $className = $this->modules->controllerClassFor($name);

        if ($className === null) {
            throw new Exception("Controller $name not found");
        }

        if (! is_subclass_of($className, ControllerInterface::class)) {
            throw new Exception('Invalid controller');
        }

        $instance = $this->instantiate($className, $context);

        if (! $instance instanceof ControllerInterface) {
            throw new RuntimeException("$className must implement ControllerInterface");
        }

        return $instance;
    }

    /** @param class-string $className */
    private function instantiate(string $className, RequestContext $context): object
    {
        return $this->resolver->instantiate($className, [RequestContext::class => $context]);
    }
}

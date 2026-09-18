<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use Exception;
use ReflectionClass;
use ReflectionNamedType;
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
    /** @var array<class-string, object> */
    private array $services;

    public function __construct(
        private ModuleRegistry $modules,
        object ...$services,
    ) {
        $indexed = [];

        foreach ($services as $service) {
            foreach ([$service::class, ...class_parents($service), ...class_implements($service)] as $type) {
                $indexed[$type] = $service;
            }
        }

        $this->services = $indexed;
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
        $reflection = new ReflectionClass($className);
        $constructor = $reflection->getConstructor();

        if (! $constructor) {
            return new $className();
        }

        $args = [];

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

            $args[] = match (true) {
                $typeName === null => throw new Exception(
                    "Cannot resolve untyped parameter \${$param->getName()} of $className"
                ),
                $typeName === RequestContext::class => $context,
                $typeName === ModuleRegistry::class => $this->modules,
                isset($this->services[$typeName]) => $this->services[$typeName],
                default => throw new Exception("Unknown dependency: $typeName"),
            };
        }

        return $reflection->newInstanceArgs($args);
    }
}

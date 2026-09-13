<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use RuntimeException;

/** Stores module controllers and checks module ids and actions for conflicts. */
final class ModuleRegistry
{
    /** @var array<string, class-string<ControllerInterface>> id => controller class */
    private array $controllers = [];

    /** @var array<string, string> action => id */
    private array $actionToId = [];

    /** @var array<string, string> feed type => display label */
    private array $feedTypes = [];

    public function __construct()
    {
        $registrations = [];
        foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
            foreach (array_keys($loader->getClassMap()) as $class) {
                if (is_subclass_of($class, ControllerInterface::class)
                    && (new \ReflectionClass($class))->isInstantiable()) {
                    $registrations[$class] = $class;
                }
            }
        }
        ksort($registrations);

        foreach ($registrations as $entry) {
            [$id, $controllerClass] = $this->describe($entry);

            if (isset($this->controllers[$id])) {
                throw new RuntimeException("Duplicate module id '$id' (declared by $controllerClass)");
            }

            $this->controllers[$id] = $controllerClass;

            foreach (array_keys($controllerClass::pageActions()) as $action) {
                $this->registerAction($action, $id);
            }

            foreach ($controllerClass::feedTypes() as $type => $label) {
                if (isset($this->feedTypes[$type])) {
                    throw new RuntimeException("Duplicate feed type '$type' (declared by $controllerClass)");
                }

                $this->feedTypes[$type] = $label;
            }
        }

        ksort($this->feedTypes);
    }

    /**
     * @return array{0:string,1:class-string<ControllerInterface>}
     */
    private function describe(string $controllerClass): array
    {
        if (!is_subclass_of($controllerClass, ControllerInterface::class)) {
            throw new RuntimeException("$controllerClass is not a ControllerInterface class");
        }
        if (!(new \ReflectionClass($controllerClass))->isInstantiable()) {
            throw new RuntimeException("$controllerClass is not an instantiable ControllerInterface class");
        }
        $shortName = ControllerFactory::shortName($controllerClass);
        $namespace = substr($controllerClass, 0, strrpos($controllerClass, '\\'));
        $module = substr($namespace, strrpos($namespace, '\\') + 1);

        return [$shortName === $module.'Controller' ? $module : $shortName, $controllerClass];
    }

    /**
     * Bind an action to the module/controller id that handles it. Used both
     * internally (from pageActions() above) and externally for API actions
     * controllers declare through registerApi() rather than pageActions().
     */
    public function registerAction(string $action, string $id): void
    {
        if (isset($this->actionToId[$action])) {
            throw new RuntimeException(
                "Duplicate page action '$action' declared by $id and {$this->actionToId[$action]}"
            );
        }

        $this->actionToId[$action] = $id;
    }

    public function idForAction(string $action): ?string
    {
        return $this->actionToId[$action] ?? null;
    }

    public function controllerClassFor(string $id): ?string
    {
        return $this->controllers[$id] ?? null;
    }

    /** @return list<class-string<ControllerInterface>> */
    public function controllerClasses(): array
    {
        return array_values($this->controllers);
    }

    /** @return array<string, string> */
    public function feedTypes(): array
    {
        return $this->feedTypes;
    }

    /**
     * @return list<array{id: string, controllerClass: class-string<ControllerInterface>}>
     */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->controllers as $id => $controllerClass) {
            $entries[] = ['id' => $id, 'controllerClass' => $controllerClass];
        }

        return $entries;
    }

    /** @return list<string> */
    public function viewsPaths(): array
    {
        $paths = [];
        foreach ($this->controllers as $controllerClass) {
            $path = dirname((new \ReflectionClass($controllerClass))->getFileName()).'/views';
            if (is_dir($path)) {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }
}

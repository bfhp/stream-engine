<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use RuntimeException;

/** Stores module controllers and checks module ids and actions for conflicts. */
final class ModuleRegistry
{
    public const array PAGE_ACTION_FIELDS = [
        'feedId',
        'feedType',
        'listFeedType',
        'termVocabulary',
    ];

    private const array FIELD_STATUSES = [
        'unsupported',
        'optional',
        'required',
    ];

    /** @var array<string, class-string<ControllerInterface>> id => controller class */
    private array $controllers = [];

    /** @var array<string, string> action => id */
    private array $actionToId = [];

    /**
     * @var list<array{
     *     action: string,
     *     label: string,
     *     module: string,
     *     fields: array<string, array{status: string, values?: list<string>, feedTypes?: list<string>}>,
     *     requirements: list<array{oneOf: list<string>}>
     * }>
     */
    private array $pageActions = [];

    /** @var array<string, string> feed type => display label */
    private array $feedTypes = [];

    public function __construct()
    {
        $registrations = [];
        foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
            foreach (array_keys($loader->getClassMap()) as $class) {
                try {
                    $isController = is_subclass_of($class, ControllerInterface::class);
                } catch (\Throwable) {
                    // Optimized classmaps may contain optional integration classes
                    // whose development-only parents are not installed in production.
                    continue;
                }

                if ($isController
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

            foreach ($controllerClass::pageActions() as $action => $descriptor) {
                $normalized = $this->normalizePageAction($action, $descriptor);
                $this->registerAction($action, $id);
                $this->pageActions[] = $normalized + [
                    'action' => $action,
                    'module' => $id,
                ];
            }

            foreach ($controllerClass::feedTypes() as $type => $label) {
                if (isset($this->feedTypes[$type])) {
                    throw new RuntimeException("Duplicate feed type '$type' (declared by $controllerClass)");
                }

                $this->feedTypes[$type] = $label;
            }
        }

        ksort($this->feedTypes);
        $this->validatePageActionFeedTypes();
        usort(
            $this->pageActions,
            static fn (array $a, array $b): int => [$a['module'], $a['action']] <=> [$b['module'], $b['action']]
        );
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

    /** @return list<array<string, mixed>> */
    public function pageActions(): array
    {
        return $this->pageActions;
    }

    /** @return array<string, mixed>|null */
    public function pageAction(string $action): ?array
    {
        foreach ($this->pageActions as $descriptor) {
            if ($descriptor['action'] === $action) {
                return $descriptor;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     label: string,
     *     fields: array<string, array{status: string, values?: list<string>, feedTypes?: list<string>}>,
     *     requirements: list<array{oneOf: list<string>}>
     * }
     */
    private function normalizePageAction(string $action, mixed $descriptor): array
    {
        if (! is_string($action) || trim($action) === '') {
            throw new RuntimeException('Page action name must be a non-empty string');
        }

        if (is_string($descriptor)) {
            $descriptor = ['label' => $descriptor];
        }
        if (! is_array($descriptor)) {
            throw new RuntimeException("Invalid page action descriptor for '$action'");
        }
        if (array_diff(array_keys($descriptor), ['label', 'fields', 'requirements']) !== []) {
            throw new RuntimeException("Unknown page action descriptor property in '$action'");
        }

        $label = $descriptor['label'] ?? null;
        if (! is_string($label) || trim($label) === '') {
            throw new RuntimeException("Page action '$action' must have a label");
        }

        $declaredFields = $descriptor['fields'] ?? [];
        if (! is_array($declaredFields)) {
            throw new RuntimeException("Page action '$action' fields must be an array");
        }

        $fields = [];
        foreach (self::PAGE_ACTION_FIELDS as $field) {
            $fields[$field] = ['status' => 'unsupported'];
        }
        foreach ($declaredFields as $field => $fieldDescriptor) {
            if (! is_string($field) || ! in_array($field, self::PAGE_ACTION_FIELDS, true)) {
                throw new RuntimeException("Unknown page action field '$field' in '$action'");
            }
            if (is_string($fieldDescriptor)) {
                $fieldDescriptor = ['status' => $fieldDescriptor];
            }
            if (! is_array($fieldDescriptor)) {
                throw new RuntimeException("Invalid descriptor for page action field '$field' in '$action'");
            }
            if (array_diff(array_keys($fieldDescriptor), ['status', 'values', 'feedTypes']) !== []) {
                throw new RuntimeException("Unknown descriptor property for page action field '$field' in '$action'");
            }

            $status = $fieldDescriptor['status'] ?? null;
            if (! is_string($status) || ! in_array($status, self::FIELD_STATUSES, true)) {
                throw new RuntimeException("Invalid status for page action field '$field' in '$action'");
            }

            $normalizedField = ['status' => $status];
            if (array_key_exists('values', $fieldDescriptor)) {
                $values = $fieldDescriptor['values'];
                if ($field === 'feedId'
                    || ! is_array($values)
                    || $values === []
                    || array_filter($values, static fn (mixed $value): bool => ! is_string($value) || $value === '') !== []) {
                    throw new RuntimeException("Invalid values for page action field '$field' in '$action'");
                }
                $normalizedField['values'] = array_values(array_unique($values));
            }
            if (array_key_exists('feedTypes', $fieldDescriptor)) {
                $feedTypes = $fieldDescriptor['feedTypes'];
                if ($field !== 'feedId'
                    || ! is_array($feedTypes)
                    || $feedTypes === []
                    || array_filter($feedTypes, static fn (mixed $value): bool => ! is_string($value) || $value === '') !== []) {
                    throw new RuntimeException("Invalid feedTypes for page action field '$field' in '$action'");
                }
                $normalizedField['feedTypes'] = array_values(array_unique($feedTypes));
            }
            $fields[$field] = $normalizedField;
        }

        $requirements = $descriptor['requirements'] ?? [];
        if (! is_array($requirements)) {
            throw new RuntimeException("Page action '$action' requirements must be an array");
        }
        $normalizedRequirements = [];
        foreach ($requirements as $requirement) {
            $oneOf = is_array($requirement) ? ($requirement['oneOf'] ?? null) : null;
            if (! is_array($requirement)
                || array_keys($requirement) !== ['oneOf']
                || ! is_array($oneOf)
                || count($oneOf) < 2) {
                throw new RuntimeException("Invalid oneOf requirement in page action '$action'");
            }
            $oneOf = array_values(array_unique($oneOf));
            foreach ($oneOf as $field) {
                if (! is_string($field)
                    || ! in_array($field, self::PAGE_ACTION_FIELDS, true)
                    || $fields[$field]['status'] === 'unsupported') {
                    throw new RuntimeException("Invalid oneOf field in page action '$action'");
                }
            }
            $normalizedRequirements[] = ['oneOf' => $oneOf];
        }

        return [
            'label' => trim($label),
            'fields' => $fields,
            'requirements' => $normalizedRequirements,
        ];
    }

    private function validatePageActionFeedTypes(): void
    {
        foreach ($this->pageActions as $action) {
            foreach ($action['fields'] as $field => $descriptor) {
                $types = $descriptor['feedTypes']
                    ?? (in_array($field, ['feedType', 'listFeedType'], true) ? ($descriptor['values'] ?? []) : []);

                foreach ($types as $type) {
                    if (! isset($this->feedTypes[$type])) {
                        throw new RuntimeException(
                            "Unknown feed type '$type' in page action '{$action['action']}'"
                        );
                    }
                }
            }
        }
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

<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use Exception;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Constructs classes from the shared services handed over at bootstrap.
 *
 * Each service is indexed by its concrete class, parent classes, and
 * implemented interfaces. A constructor parameter is resolved, in order, from
 * the per-call context, the shared services, or its default value. Anything
 * else is a construction error. Controllers and extensions are built by the
 * same resolver, so both can ask for the same dependencies.
 */
final readonly class ServiceResolver
{
    /** @var array<class-string, object> */
    private array $services;

    public function __construct(object ...$services)
    {
        $indexed = [];

        foreach ($services as $service) {
            foreach ([$service::class, ...class_parents($service), ...class_implements($service)] as $type) {
                $indexed[$type] = $service;
            }
        }

        $this->services = $indexed;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $className
     * @param  array<class-string, object>  $context  per-call values, e.g. [RequestContext::class => $context]
     * @return T
     *
     * @throws Exception
     */
    public function instantiate(string $className, array $context = []): object
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
                isset($context[$typeName]) => $context[$typeName],
                isset($this->services[$typeName]) => $this->services[$typeName],
                $param->isDefaultValueAvailable() => $param->getDefaultValue(),
                $typeName === RequestContext::class => throw new Exception(
                    "RequestContext is not available to $className; pass request data as arguments instead"
                ),
                default => throw new Exception("Unknown dependency: $typeName"),
            };
        }

        return $reflection->newInstanceArgs($args);
    }
}

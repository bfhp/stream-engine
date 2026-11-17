<?php

declare(strict_types=1);

namespace StreamEngine\Extension;

use LogicException;
use StreamEngine\Core\ServiceResolver;
use Throwable;

/**
 * Calls extension points on the site's extensions.
 *
 * Platform code calls a point as a method of this registry:
 *
 *     $accepted = $this->extensions->verifyPasswordFallback($request);
 *
 * Every extension that has a public method with that name is called in
 * class-name order until one returns a non-null result, which is returned.
 * When there are no such extensions, or all of them return null, the call
 * returns null and the caller keeps its default behavior.
 *
 * An exception thrown by an extension method is logged and treated as null,
 * so a failing extension can never turn into a positive answer. Exceptions
 * thrown while constructing extensions are configuration errors and are not
 * caught.
 */
final class ExtensionRegistry
{
    private ?ServiceResolver $resolver = null;

    /** @var list<Extension>|null */
    private ?array $instances = null;

    /** @var list<class-string<Extension>> */
    private readonly array $classes;

    /** @param list<class-string<Extension>> $classes */
    public function __construct(array $classes = [])
    {
        sort($classes);
        $this->classes = array_values(array_unique($classes));
    }

    /**
     * Makes the registry usable. Called by the composition root once every
     * shared service exists.
     */
    public function bind(ServiceResolver $resolver): void
    {
        $this->resolver = $resolver;
    }

    /** @return list<class-string<Extension>> */
    public function classes(): array
    {
        return $this->classes;
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $point, array $arguments): mixed
    {
        foreach ($this->instances() as $extension) {
            if (! is_callable([$extension, $point])) {
                continue;
            }

            try {
                $result = $extension->$point(...$arguments);
            } catch (Throwable $e) {
                error_log(sprintf(
                    'Extension %s::%s() failed: %s: %s',
                    $extension::class,
                    $point,
                    $e::class,
                    $e->getMessage(),
                ));

                continue;
            }

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /** @return list<Extension> */
    private function instances(): array
    {
        if ($this->resolver === null) {
            throw new LogicException('Extension points cannot be called before the registry is bound');
        }

        return $this->instances ??= array_map(
            fn (string $class): Extension => $this->resolver->instantiate($class),
            $this->classes,
        );
    }
}

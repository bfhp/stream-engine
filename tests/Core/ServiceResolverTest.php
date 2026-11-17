<?php

declare(strict_types=1);

namespace Tests\Core;

use Exception;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Config;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\ServiceResolver;

final class ServiceResolverTest extends TestCase
{
    public function testResolvesByClassInterfaceContextAndDefault(): void
    {
        $config = new Config([]);
        $resolver = new ServiceResolver($config, new \ArrayObject());

        $built = $resolver->instantiate(ResolverProbe::class, [\stdClass::class => $marker = new \stdClass()]);

        self::assertSame($config, $built->config);
        self::assertInstanceOf(\ArrayObject::class, $built->countable);
        self::assertSame($marker, $built->marker);
        self::assertSame(42, $built->number);
    }

    public function testContextTakesPrecedenceOverSharedServices(): void
    {
        $shared = new Config([]);
        $perCall = new Config([]);

        $built = (new ServiceResolver($shared))->instantiate(
            ResolverProbe::class,
            [Config::class => $perCall, \stdClass::class => new \stdClass()],
        );

        self::assertSame($perCall, $built->config);
    }

    public function testUnknownDependencyIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown dependency: StreamEngine\Core\Config');

        (new ServiceResolver())->instantiate(ResolverProbe::class);
    }

    public function testMissingRequestContextHasADedicatedMessage(): void
    {
        $this->expectExceptionMessage('RequestContext is not available to '.ContextProbe::class);

        (new ServiceResolver())->instantiate(ContextProbe::class);
    }

    public function testUntypedParameterIsRejected(): void
    {
        $this->expectExceptionMessage('Cannot resolve untyped parameter $value');

        (new ServiceResolver())->instantiate(UntypedProbe::class);
    }
}

final class ResolverProbe
{
    public function __construct(
        public readonly Config $config,
        public readonly \stdClass $marker,
        public readonly \Countable $countable = new \ArrayObject(),
        public readonly int $number = 42,
    ) {
    }
}

final class ContextProbe
{
    public function __construct(public readonly RequestContext $context)
    {
    }
}

final class UntypedProbe
{
    /** @noinspection PhpMissingParamTypeInspection */
    public function __construct(public $value)
    {
    }
}

<?php

declare(strict_types=1);

namespace Tests\Extension;

use LogicException;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\ServiceResolver;
use StreamEngine\Extension\Extension;
use StreamEngine\Extension\ExtensionRegistry;
use StreamEngine\Core\RequestContext;
use StreamEngine\Repository\UserRepository;
use Tests\Support\Extensions\AAbstainingExtension;
use Tests\Support\Extensions\BAnsweringExtension;
use Tests\Support\Extensions\CallLog;
use Tests\Support\Extensions\CAnsweringExtension;
use Tests\Support\Extensions\DependentExtension;
use Tests\Support\Extensions\FalseAnsweringExtension;
use Tests\Support\Extensions\PrivatePointExtension;
use Tests\Support\Extensions\PropertyProbeExtension;
use Tests\Support\Extensions\RequestContextExtension;
use Tests\Support\Extensions\ThrowingExtension;
use Tests\Support\Extensions\ZAnsweringExtension;

require_once __DIR__.'/../Support/ExtensionFixtures.php';

final class ExtensionRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CallLog::reset();
    }

    /** Silences the error_log() a failing extension writes, and hands it back. */
    private function captureLog(callable $run): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'ext');
        $previous = ini_set('error_log', $file);

        try {
            $run();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $written = (string) file_get_contents($file);
        unlink($file);

        return $written;
    }

    /** @param list<class-string> $classes */
    private function bound(array $classes, object ...$services): ExtensionRegistry
    {
        $registry = new ExtensionRegistry($classes);
        $registry->bind(new ServiceResolver(...$services));

        return $registry;
    }

    public function testCallWithoutExtensionsReturnsNull(): void
    {
        self::assertNull($this->bound([])->probe('x'));
    }

    public function testCallBeforeBindIsRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('before the registry is bound');

        (new ExtensionRegistry([]))->probe('x');
    }

    public function testFirstNonNullResultWinsInClassNameOrder(): void
    {
        $registry = $this->bound([
            CAnsweringExtension::class,
            BAnsweringExtension::class,
            AAbstainingExtension::class,
        ]);

        self::assertSame('B:x', $registry->probe('x'));
        self::assertSame([AAbstainingExtension::class, BAnsweringExtension::class], CallLog::$calls);
    }

    public function testFalseIsAnAnswerAndStopsTheChain(): void
    {
        $registry = $this->bound([FalseAnsweringExtension::class, ThrowingExtension::class]);
        // FalseAnswering sorts first; ThrowingExtension must never be reached.

        self::assertFalse($registry->probe('x'));
        self::assertSame([FalseAnsweringExtension::class], CallLog::$calls);
    }

    public function testFailingExtensionIsLoggedAndSkipped(): void
    {
        $registry = $this->bound([ThrowingExtension::class, ZAnsweringExtension::class]);

        $log = $this->captureLog(static fn () => self::assertSame('Z:x', $registry->probe('x')));
        self::assertStringContainsString(ThrowingExtension::class.'::probe() failed', $log);
        self::assertStringContainsString('probe exploded', $log);
    }

    public function testExtensionsWithoutAPublicMethodAreSkipped(): void
    {
        $registry = $this->bound([PrivatePointExtension::class]);

        self::assertNull($registry->probe('x'));
        self::assertSame([], CallLog::$calls);
    }

    public function testExtensionsAreConstructedLazilyAndOnce(): void
    {
        $registry = $this->bound([AAbstainingExtension::class, BAnsweringExtension::class]);
        self::assertSame(0, CallLog::$constructed);

        $registry->probe('x');
        $registry->probe('y');

        self::assertSame(2, CallLog::$constructed);
    }

    public function testExtensionsReceiveSharedServices(): void
    {
        $users = new UserRepository($this->createStub(PdoDatabase::class));
        $registry = $this->bound([DependentExtension::class], $users);

        self::assertSame($users, $registry->repository());
    }

    public function testRequestContextIsNotInjected(): void
    {
        $registry = $this->bound([RequestContextExtension::class]);

        $this->expectExceptionMessage('RequestContext is not available');

        $registry->probe('x');
    }

    public function testDuplicateClassesAreCalledOnce(): void
    {
        $registry = $this->bound([AAbstainingExtension::class, AAbstainingExtension::class]);

        $registry->probe('x');

        self::assertSame([AAbstainingExtension::class], CallLog::$calls);
    }

    public function testSharedServicesAreAvailableAsProperties(): void
    {
        $services = [];
        foreach ((new \ReflectionClass(Extension::class))->getConstructor()->getParameters() as $param) {
            $type = (string) $param->getType();
            $services[$type] = (new \ReflectionClass($type))->newInstanceWithoutConstructor();
        }

        $registry = $this->bound([PropertyProbeExtension::class], ...array_values($services));

        self::assertSame([
            $services[PdoDatabase::class],
            $services[\StreamEngine\Service\AccessService::class],
            $services[UserRepository::class],
        ], $registry->probeProperties());
    }

    public function testBaseConstructorDoesNotAskForRequestContext(): void
    {
        foreach ((new \ReflectionClass(Extension::class))->getConstructor()->getParameters() as $param) {
            self::assertNotSame(RequestContext::class, (string) $param->getType());
        }
    }

    /**
     * Extension's constructor is resolved from the services StreamEngine hands
     * to ControllerFactory. A property missing from that list would only fail
     * on a site's first extension call, so the list is checked here.
     */
    public function testEveryBasePropertyIsHandedToTheResolverByStreamEngine(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../src/StreamEngine.php');
        self::assertSame(1, preg_match('/new ControllerFactory\((.*?)\);/s', $source, $match));

        foreach ((new \ReflectionClass(Extension::class))->getConstructor()->getParameters() as $param) {
            self::assertMatchesRegularExpression(
                '/\$(this->)?'.$param->getName().'\b/',
                $match[1],
                "Extension::\${$param->getName()} is not passed to ControllerFactory in StreamEngine",
            );
        }
    }
}

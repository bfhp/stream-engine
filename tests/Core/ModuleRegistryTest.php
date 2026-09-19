<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Controllers\ActionProbeController;
use StreamEngine\Controllers\FactoryProbeController;
use StreamEngine\Controllers\InvalidActionContractController;
use StreamEngine\Controllers\PlainClass;
use StreamEngine\Modules\Forums\ForumsController;

require_once __DIR__.'/../Support/ControllerFactoryFixtures.php';

class_alias(ActionProbeController::class, 'RegistryFixtures\First\FirstController');
class_alias(ActionProbeController::class, 'RegistryFixtures\Second\SecondController');

final class ModuleRegistryTest extends TestCase
{
    private const string FIRST = 'RegistryFixtures\First\FirstController';
    private const string SECOND = 'RegistryFixtures\Second\SecondController';

    public function testDuplicateActionsNameBothModules(): void
    {
        try {
            \StreamEngine\Controllers\registryWithFixtures([self::FIRST, self::SECOND]);
            self::fail('Duplicate action accepted');
        } catch (RuntimeException $e) {
            self::assertStringContainsString("Duplicate page action 'probe.show'", $e->getMessage());
            self::assertStringContainsString('First', $e->getMessage());
            self::assertStringContainsString('Second', $e->getMessage());
        }
    }

    public function testApiActionCannotCollideWithPageAction(): void
    {
        $registry = \StreamEngine\Controllers\registryWithFixtures([self::FIRST]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Duplicate page action 'probe.show'");
        $registry->registerAction('probe.show', 'Second');
    }

    public function testResolvesIdsActionsAndControllerLists(): void
    {
        $classes = [self::FIRST, FactoryProbeController::class];
        $registry = \StreamEngine\Controllers\registryWithFixtures($classes);
        self::assertSame('First', $registry->idForAction('probe.show'));
        self::assertSame(self::FIRST, $registry->controllerClassFor('First'));
        foreach ($classes as $class) {
            self::assertContains($class, $registry->controllerClasses());
        }
        self::assertContains(['id' => 'First', 'controllerClass' => self::FIRST], $registry->entries());
        self::assertContains(['id' => 'FactoryProbeController', 'controllerClass' => FactoryProbeController::class], $registry->entries());
        self::assertSame('Factory probe', $registry->feedTypes()['factory-probe']);
        self::assertSame('Probe', $registry->feedTypes()['probe']);
        self::assertNull($registry->idForAction('missing'));
        self::assertNull($registry->controllerClassFor('Missing'));

        $action = $registry->pageAction('probe.show');
        self::assertNotNull($action);
        self::assertSame('Probe show page', $action['label']);
        self::assertSame(['status' => 'required', 'values' => ['probe']], $action['fields']['feedType']);
        self::assertSame(['status' => 'optional'], $action['fields']['feedId']);
        self::assertSame(['status' => 'unsupported'], $action['fields']['termVocabulary']);
        self::assertSame([['oneOf' => ['feedType', 'feedId']]], $action['requirements']);
        self::assertNull($registry->pageAction('missing'));
    }

    public function testStandaloneControllerRetainsItsShortName(): void
    {
        $registry = \StreamEngine\Controllers\registryWithFixtures([ActionProbeController::class]);
        self::assertSame('ActionProbeController', $registry->idForAction('probe.show'));
    }

    public function testMalformedPageActionContractIsRejectedDuringBootstrap(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unknown page action field 'mysteryField' in 'invalid.show'");

        \StreamEngine\Controllers\registryWithFixtures([InvalidActionContractController::class]);
    }

    public function testEveryRegisteredActionHasANormalizedConfigurationContract(): void
    {
        $registry = new ModuleRegistry();
        $feedTypes = array_keys($registry->feedTypes());

        foreach ($registry->pageActions() as $action) {
            self::assertSame(ModuleRegistry::PAGE_ACTION_FIELDS, array_keys($action['fields']), $action['action']);

            foreach ($action['fields'] as $field => $descriptor) {
                self::assertContains($descriptor['status'], ['unsupported', 'optional', 'required']);

                if (isset($descriptor['values']) && in_array($field, ['feedType', 'listFeedType'], true)) {
                    self::assertSame([], array_diff($descriptor['values'], $feedTypes), $action['action']);
                }
                if (isset($descriptor['feedTypes'])) {
                    self::assertSame([], array_diff($descriptor['feedTypes'], $feedTypes), $action['action']);
                }
            }
        }
    }

    public function testNonControllerIsIgnored(): void
    {
        $registry = \StreamEngine\Controllers\registryWithFixtures([PlainClass::class]);
        self::assertNull($registry->controllerClassFor('PlainClass'));
    }

    public function testResourcesAreTakenFromControllerDirectory(): void
    {
        $registry = \StreamEngine\Controllers\registryWithFixtures([ForumsController::class, ActionProbeController::class]);
        self::assertContains(realpath(__DIR__.'/../../src/Modules/Forums/views'), $registry->viewsPaths());
    }
}

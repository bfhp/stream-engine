<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Controllers\ActionProbeController;
use StreamEngine\Controllers\FactoryProbeController;
use StreamEngine\Controllers\InvalidActionContractController;
use StreamEngine\Controllers\DashboardProbeController;
use StreamEngine\Controllers\InvalidDashboardProbeController;
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
        self::assertSame([], $action['settings']);
        self::assertNull($registry->pageAction('missing'));
    }

    public function testSelectSettingsSchemaIsNormalized(): void
    {
        $action = $this->normalizePageAction([
            'label' => 'Configurable page',
            'settings' => [
                'type' => [
                    'control' => 'select',
                    'label' => 'module.page_setting.type',
                    'required' => true,
                    'options' => [
                        ['value' => 'first', 'label' => 'module.type.first'],
                        ['value' => 'second', 'label' => 'module.type.second'],
                    ],
                ],
            ],
        ]);

        self::assertSame([
            'type' => [
                'control' => 'select',
                'label' => 'module.page_setting.type',
                'required' => true,
                'options' => [
                    ['value' => 'first', 'label' => 'module.type.first'],
                    ['value' => 'second', 'label' => 'module.type.second'],
                ],
            ],
        ], $action['settings']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSettingsSchemas')]
    public function testInvalidSettingsSchemaIsRejected(array $descriptor, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $this->normalizePageAction($descriptor);
    }

    public static function invalidSettingsSchemas(): array
    {
        $valid = [
            'control' => 'select',
            'label' => 'Type',
            'required' => true,
            'options' => [['value' => 'first', 'label' => 'First']],
        ];

        return [
            'unknown action property' => [['label' => 'Page', 'mystery' => true], 'Unknown page action descriptor property'],
            'empty setting key' => [['label' => 'Page', 'settings' => ['' => $valid]], 'Invalid page action setting key'],
            'unknown setting property' => [['label' => 'Page', 'settings' => ['type' => $valid + ['mystery' => true]]], 'Unknown descriptor property'],
            'unsupported control' => [['label' => 'Page', 'settings' => ['type' => [...$valid, 'control' => 'text']]], 'Unsupported control'],
            'empty options' => [['label' => 'Page', 'settings' => ['type' => [...$valid, 'options' => []]]], 'must have options'],
            'empty option value' => [['label' => 'Page', 'settings' => ['type' => [...$valid, 'options' => [['value' => '', 'label' => 'First']]]]], 'Invalid option'],
            'empty option label' => [['label' => 'Page', 'settings' => ['type' => [...$valid, 'options' => [['value' => 'first', 'label' => '']]]]], 'Invalid option'],
            'duplicate option values' => [['label' => 'Page', 'settings' => ['type' => [...$valid, 'options' => [
                ['value' => 'first', 'label' => 'First'],
                ['value' => 'first', 'label' => 'Again'],
            ]]]], 'Duplicate option value'],
            'non-boolean required' => [['label' => 'Page', 'settings' => ['type' => [...$valid, 'required' => 1]]], 'required must be boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private function normalizePageAction(array $descriptor): array
    {
        $method = new \ReflectionMethod(ModuleRegistry::class, 'normalizePageAction');

        return $method->invoke(new ModuleRegistry(), 'settings.test', $descriptor);
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

    public function testModulesCanRegisterNormalizedDashboardCards(): void
    {
        $registry = \StreamEngine\Controllers\registryWithFixtures([DashboardProbeController::class]);
        $card = $registry->dashboardCard('probe.summary');

        self::assertNotNull($card);
        self::assertSame('Probe summary', $card['label']);
        self::assertSame(['small', 'wide'], $card['sizes']);
        self::assertSame(DashboardProbeController::class, $card['provider']);
        self::assertContains($card, $registry->dashboardCards());
    }

    public function testMalformedDashboardCardContractIsRejectedDuringBootstrap(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid dashboard card id');

        \StreamEngine\Controllers\registryWithFixtures([InvalidDashboardProbeController::class]);
    }

    public function testEveryRegisteredActionHasANormalizedConfigurationContract(): void
    {
        $registry = new ModuleRegistry();
        $feedTypes = array_keys($registry->feedTypes());

        foreach ($registry->pageActions() as $action) {
            self::assertSame(ModuleRegistry::PAGE_ACTION_FIELDS, array_keys($action['fields']), $action['action']);
            self::assertArrayHasKey('settings', $action, $action['action']);

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

    public function testViewsAreNamespacedByModuleDirectory(): void
    {
        $registry = \StreamEngine\Controllers\registryWithFixtures([ForumsController::class, ActionProbeController::class]);
        self::assertSame(
            realpath(__DIR__.'/../../src/Modules/Forums/views'),
            $registry->viewsNamespaces()['Forums'] ?? null,
        );
    }
}

<?php

declare(strict_types=1);

// Intentionally declared under StreamEngine\Controllers (not Tests\Support) so these
// fixture classes are resolvable by ControllerFactory's controller-class lookup, which
// builds class names as StreamEngine\Controllers\{name}. Loaded via require_once,
// not PSR-4 autoloading, so this deliberately doesn't follow the tests/ PSR-4 root.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace StreamEngine\Controllers;

use RuntimeException;
use StreamEngine\Core\Cache;
use StreamEngine\Core\Config;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\ControllerInterface;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\Cron\CronRegistry;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\AuthService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\SettingsService;
use StreamEngine\Service\TermService;
use StreamEngine\Service\UploadService;
use StreamEngine\Service\UserService;
use StreamEngine\View\Breadcrumb;
use StreamEngine\View\ViewModel;

final class FactoryProbeController implements ControllerInterface
{
    public function __construct(
        private readonly PdoDatabase $db,
        private readonly RequestContext $context,
        public readonly SettingsService $settingsService,
        public readonly FeedService $feedService,
        public readonly AuthService $authService,
        public readonly UserService $userService,
        public readonly AccessService $accessService,
        public readonly UploadService $uploadService,
        public readonly PageTree $pageTree,
        public readonly UrlGenerator $urlGenerator,
        public readonly Config $config,
        public readonly Cache $cache,
        public readonly Formatter $formatter,
        public readonly TranslationManager $translationManager,
        public readonly TermService $termService,
    ) {
    }

    public static function pageActions(): array
    {
        return [];
    }

    public static function feedTypes(): array
    {
        return ['factory-probe' => 'Factory probe'];
    }

    public function getBreadcrumb(Page $page): ?Breadcrumb
    {
        return null;
    }

    public function show(Page $page, array $args = []): ?ViewModel
    {
        return null;
    }

    public static function registerApi(int $apiPageId, PageTree $pageTree): void
    {
    }

    public function callApi(Page $page, array $args = []): void
    {
    }

    public static function registerCron(CronRegistry $cron): void
    {
    }

    public function runCron(string $task): void
    {
    }

    public function exposedDb(): PdoDatabase
    {
        return $this->db;
    }

    public function exposedContext(): RequestContext
    {
        return $this->context;
    }
}

final class ControllerWithUnknownDependency implements ControllerInterface
{
    public function __construct(
        private readonly PdoDatabase $db,
        private readonly RequestContext $context,
        public readonly \stdClass $unknown,
    ) {
    }

    public static function pageActions(): array
    {
        return [];
    }

    public static function feedTypes(): array
    {
        return [];
    }

    public function getBreadcrumb(Page $page): ?Breadcrumb
    {
        return null;
    }

    public function show(Page $page, array $args = []): ?ViewModel
    {
        return null;
    }

    public static function registerApi(int $apiPageId, PageTree $pageTree): void
    {
    }

    public function callApi(Page $page, array $args = []): void
    {
    }

    public static function registerCron(CronRegistry $cron): void
    {
    }

    public function runCron(string $task): void
    {
    }
}

/** Exercises automatic action registration for a standalone controller. */
final class ActionProbeController implements ControllerInterface
{
    public function __construct(
        private readonly PdoDatabase $db,
        private readonly RequestContext $context,
    ) {
    }

    public static function pageActions(): array
    {
        return [
            'probe.show' => 'Probe show page',
        ];
    }

    public static function feedTypes(): array
    {
        return ['probe' => 'Probe'];
    }

    public function getBreadcrumb(Page $page): ?Breadcrumb
    {
        return null;
    }

    public function show(Page $page, array $args = []): ?ViewModel
    {
        return null;
    }

    public static function registerApi(int $apiPageId, PageTree $pageTree): void
    {
    }

    public function callApi(Page $page, array $args = []): void
    {
    }

    public static function registerCron(CronRegistry $cron): void
    {
    }

    public function runCron(string $task): void
    {
    }
}

final class PlainClass
{
}

final class CronProbeController implements ControllerInterface
{
    public static array $ranTasks = [];

    /**
     * Task names runCron() should throw on, for the test that checks one
     * failing task doesn't take the rest of the tick with it.
     *
     * @var list<string>
     */
    public static array $failingTasks = [];

    public function __construct(
        private readonly PdoDatabase $db,
        private readonly RequestContext $context,
    ) {
    }

    public static function pageActions(): array
    {
        return [];
    }

    public static function feedTypes(): array
    {
        return [];
    }

    public function getBreadcrumb(Page $page): ?Breadcrumb
    {
        return null;
    }

    public function show(Page $page, array $args = []): ?ViewModel
    {
        return null;
    }

    public static function registerApi(int $apiPageId, PageTree $pageTree): void
    {
    }

    public function callApi(Page $page, array $args = []): void
    {
    }

    public static function registerCron(CronRegistry $cron): void
    {
    }

    public function runCron(string $task): void
    {
        self::$ranTasks[] = $task;

        if (in_array($task, self::$failingTasks, true)) {
            throw new RuntimeException('cron probe failure: '.$task);
        }
    }

    public static function reset(): void
    {
        self::$ranTasks = [];
        self::$failingTasks = [];
    }
}

/** Adds fixture classes to Composer only while the registry is constructed. */
function registryWithFixtures(array $classes): \StreamEngine\Core\ModuleRegistry
{
    $loader = new \Composer\Autoload\ClassLoader(__DIR__.'/fixture-vendor');
    foreach ($classes as $class) {
        $loader->addClassMap([$class => (new \ReflectionClass($class))->getFileName()]);
    }
    $loader->register(true);
    try {
        return new \StreamEngine\Core\ModuleRegistry();
    } finally {
        $loader->unregister();
    }
}

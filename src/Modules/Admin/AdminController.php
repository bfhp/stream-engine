<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Admin;

use Throwable;
use StreamEngine\Core\AbstractController;
use StreamEngine\Core\Config;
use StreamEngine\Core\Cron\CronRegistry;
use StreamEngine\Core\Cron\CronRepository;
use StreamEngine\Core\Cron\CronTrigger;
use StreamEngine\Core\DashboardCardProviderInterface;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\FileProcessing\ImageProcessor;
use StreamEngine\Core\FileProcessing\MimeDetector;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\Security;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UploadDirectoryBrowser;
use StreamEngine\Domain\Page;
use StreamEngine\Repository\PageRepository;
use StreamEngine\Repository\UploadRepository;
use StreamEngine\Repository\MenuRepository;
use StreamEngine\Repository\SettingsRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\AdminDashboardService;
use StreamEngine\Service\CronStatusService;
use StreamEngine\Service\SettingsService;
use StreamEngine\Service\ThemeService;
use StreamEngine\Service\UploadService;
use StreamEngine\Repository\DashboardLayoutRepository;
use StreamEngine\View\ViewModel;

class AdminController extends AbstractController implements DashboardCardProviderInterface
{
    private const int SITE_ICON_MAX_SIZE = 2 * 1024 * 1024;
    private const int HEADER_LOGO_MAX_SIZE = 2 * 1024 * 1024;

    public static function pageActions(): array
    {
        return [
            'admin.index' => 'js.admin.page_action.admin',
        ];
    }

    public static function dashboardCards(): array
    {
        return [
            [
                'id' => 'admin.users-summary',
                'label' => 'js.admin.dashboard.cards.users',
                'kind' => 'metrics',
                'permission' => AccessService::ACCESS_ADMIN,
                'sizes' => ['small', 'medium'],
                'defaultSize' => 'small',
                'defaultPosition' => 10,
            ],
            [
                'id' => 'admin.content-summary',
                'label' => 'js.admin.dashboard.cards.content',
                'kind' => 'metrics',
                'permission' => AccessService::ACCESS_ADMIN,
                'sizes' => ['small', 'medium'],
                'defaultSize' => 'small',
                'defaultPosition' => 20,
            ],
            [
                'id' => 'admin.shortcuts',
                'label' => 'js.admin.dashboard.cards.shortcuts',
                'kind' => 'links',
                'permission' => AccessService::ACCESS_ADMIN,
                'sizes' => ['small', 'medium', 'wide'],
                'defaultSize' => 'medium',
                'defaultPosition' => 30,
            ],
            [
                'id' => 'admin.recent-activity',
                'label' => 'js.admin.dashboard.cards.recent',
                'kind' => 'list',
                'permission' => AccessService::ACCESS_ADMIN,
                'sizes' => ['medium', 'wide'],
                'defaultSize' => 'wide',
                'defaultPosition' => 40,
            ],
            [
                'id' => 'admin.moderation-summary',
                'label' => 'js.admin.dashboard.cards.moderation',
                'kind' => 'metrics',
                'permission' => AccessService::ACCESS_ADMIN,
                'sizes' => ['small', 'medium'],
                'defaultSize' => 'small',
                'defaultPosition' => 50,
            ],
            [
                'id' => 'admin.system-health',
                'label' => 'js.admin.dashboard.cards.health',
                'kind' => 'metrics',
                'permission' => AccessService::ACCESS_ADMIN,
                'sizes' => ['medium', 'wide'],
                'defaultSize' => 'medium',
                'defaultPosition' => 60,
            ],
        ];
    }

    public static function dashboardCardData(string $cardId, PdoDatabase $db, RequestContext $context): array
    {
        return match ($cardId) {
            'admin.users-summary' => self::usersSummaryCard($db),
            'admin.content-summary' => self::contentSummaryCard($db),
            'admin.shortcuts' => [
                'status' => 'ready',
                'data' => [
                    ['label' => 'js.admin.dashboard.manage_users', 'href' => '#/users'],
                    ['label' => 'js.admin.dashboard.create_page', 'href' => '#/pages/new'],
                    ['label' => 'js.admin.nav.settings', 'href' => '#/settings'],
                ],
            ],
            'admin.recent-activity' => self::recentActivityCard($db),
            'admin.moderation-summary' => self::moderationSummaryCard($db),
            'admin.system-health' => self::systemHealthCard($db),
            default => ['status' => 'unavailable', 'data' => null],
        };
    }

    private static function usersSummaryCard(PdoDatabase $db): array
    {
        $row = $db->fetchOne(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(is_active = 1), 0) AS active,
                    COALESCE(SUM(is_active = 0), 0) AS inactive
             FROM users'
        ) ?? [];

        return [
            'status' => (int) ($row['total'] ?? 0) > 0 ? 'ready' : 'empty',
            'data' => [
                ['label' => 'js.admin.total', 'value' => (int) ($row['total'] ?? 0), 'href' => '#/users'],
                ['label' => 'js.admin.status.active', 'value' => (int) ($row['active'] ?? 0), 'href' => '#/users'],
                ['label' => 'js.admin.status.inactive', 'value' => (int) ($row['inactive'] ?? 0), 'href' => '#/users'],
            ],
        ];
    }

    private static function contentSummaryCard(PdoDatabase $db): array
    {
        $row = $db->fetchOne(
            'SELECT COUNT(*) AS total, COUNT(DISTINCT type) AS types FROM feeds'
        ) ?? [];

        return [
            'status' => (int) ($row['total'] ?? 0) > 0 ? 'ready' : 'empty',
            'data' => [
                ['label' => 'js.admin.items', 'value' => (int) ($row['total'] ?? 0), 'href' => '#/feeds'],
                ['label' => 'js.admin.types', 'value' => (int) ($row['types'] ?? 0), 'href' => '#/feeds'],
            ],
        ];
    }

    private static function recentActivityCard(PdoDatabase $db): array
    {
        $items = [];
        foreach ($db->fetchAll(
            'SELECT id, nick, username, created_at FROM users WHERE id <> ? ORDER BY created_at DESC, id DESC LIMIT 5',
            [\StreamEngine\Domain\User::SYSTEM_USER_ID],
        ) as $row) {
            $name = trim((string) ($row['nick'] ?? '')) ?: '#'.(int) $row['id'];
            $items[] = [
                'id' => 'user-'.(int) $row['id'],
                'label' => $name,
                'description' => 'js.admin.dashboard.new_user',
                'timestamp' => (int) ($row['created_at'] ?? 0),
                'href' => '#/users',
            ];
        }
        foreach ($db->fetchAll(
            'SELECT id, title, type, created_at FROM feeds ORDER BY created_at DESC, id DESC LIMIT 5'
        ) as $row) {
            $title = trim((string) ($row['title'] ?? '')) ?: '#'.(int) $row['id'];
            $items[] = [
                'id' => 'feed-'.(int) $row['id'],
                'label' => $title,
                'description' => (string) ($row['type'] ?? 'js.admin.content'),
                'timestamp' => (int) ($row['created_at'] ?? 0),
                'href' => '#/feeds/'.(int) $row['id'],
            ];
        }
        usort($items, static fn (array $a, array $b): int => [$b['timestamp'], $b['id']] <=> [$a['timestamp'], $a['id']]);
        $items = array_slice($items, 0, 8);

        return ['status' => $items === [] ? 'empty' : 'ready', 'data' => $items];
    }

    private static function moderationSummaryCard(PdoDatabase $db): array
    {
        $row = $db->fetchOne(
            'SELECT COUNT(*) AS pending
             FROM memberships m
             INNER JOIN membership_roles mr ON mr.id = m.membership_role_id
             WHERE mr.role_level = 0'
        ) ?? [];
        $pending = (int) ($row['pending'] ?? 0);

        return [
            'status' => $pending > 0 ? 'ready' : 'empty',
            'data' => [['label' => 'js.admin.dashboard.pending_requests', 'value' => $pending]],
        ];
    }

    private static function systemHealthCard(PdoDatabase $db): array
    {
        $cron = $db->fetchOne(
            'SELECT COUNT(*) AS tasks, MAX(last_run) AS last_run,
                    COALESCE(SUM(locked_at IS NOT NULL AND locked_at < UNIX_TIMESTAMP() - 3600), 0) AS stale_locks
             FROM cron_tasks'
        ) ?? [];
        $deliveries = $db->fetchOne(
            "SELECT COALESCE(SUM(status = 'pending'), 0) AS pending,
                    COALESCE(SUM(status = 'failed'), 0) AS failed
             FROM notification_deliveries"
        ) ?? [];

        return [
            'status' => 'ready',
            'data' => [
                ['label' => 'js.admin.dashboard.cron_tasks', 'value' => (int) ($cron['tasks'] ?? 0), 'href' => '#/cron'],
                [
                    'label' => 'js.admin.dashboard.last_cron',
                    'value' => (int) ($cron['last_run'] ?? 0),
                    'format' => 'timestamp',
                    'href' => '#/cron',
                ],
                ['label' => 'js.admin.dashboard.stale_locks', 'value' => (int) ($cron['stale_locks'] ?? 0), 'href' => '#/cron'],
                ['label' => 'js.admin.dashboard.pending_deliveries', 'value' => (int) ($deliveries['pending'] ?? 0)],
                ['label' => 'js.admin.dashboard.failed_deliveries', 'value' => (int) ($deliveries['failed'] ?? 0)],
            ],
        ];
    }

    private const array CHANGEFREQ_VALUES = [
        'always',
        'hourly',
        'daily',
        'weekly',
        'monthly',
        'yearly',
        'never',
        'noindex',
    ];

    private const array MENU_TYPES = [
        'internal',
        'external',
        'action',
        'divider',
        'dynamic',
    ];

    /** Frontend hooks that the default theme implements for action items. */
    private const array MENU_ACTIONS = ['logout'];

    private readonly PageRepository $pageRepository;
    private readonly MenuRepository $menuRepository;
    private readonly SettingsRepository $settingsRepository;
    private readonly UserRepository $userRepository;
    private readonly AdminDashboardService $dashboardService;
    private readonly CronStatusService $cronStatusService;
    private readonly CronRegistry $cronRegistry;
    private readonly CronRepository $cronRepository;
    private readonly CronTrigger $cronTrigger;
    private readonly SettingsService $settingsService;

    public function __construct(
        PdoDatabase $db,
        RequestContext $context,
        private readonly AccessService $accessService,
        private readonly TranslationManager $tm,
        private readonly ModuleRegistry $modules,
        private readonly ThemeService $themeService,
        private readonly UploadService $uploadService,
        private readonly Config $config,
        ?SettingsService $settings = null,
        ?CronRegistry $cronRegistry = null,
        ?CronTrigger $cronTrigger = null,
    ) {
        parent::__construct($db, $context);
        $this->pageRepository = new PageRepository($db);
        $this->menuRepository = new MenuRepository($db);
        $this->settingsRepository = new SettingsRepository($db);
        $this->userRepository = new UserRepository($db);
        $this->dashboardService = new AdminDashboardService(
            new DashboardLayoutRepository($db),
            $modules,
            $db,
            $tm,
        );
        $this->cronRegistry = $cronRegistry ?? new CronRegistry();
        $this->cronRepository = new CronRepository($db);
        $this->cronTrigger = $cronTrigger ?? new CronTrigger();
        $this->settingsService = $settings ?? new SettingsService($this->settingsRepository);
        $this->cronStatusService = new CronStatusService(
            $this->cronRegistry,
            $this->cronRepository,
            $this->settingsService,
        );
    }

    /**
     * @throws ForbiddenException
     */
    public function show(Page $page, array $args = []): ?ViewModel
    {
        if (! $this->accessService->isAdmin($this->context->user)) {
            throw new ForbiddenException($this->tm->trans('admin.error.forbidden'));
        }

        return ViewModel::fromPage(
            $page,
            "modules/admin/page.twig"
        );
    }

    /**
     * @throws ValidationException
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    public function callApi(Page $page, array $args = []): void
    {
        header('Content-Type: application/json');

        if (! $this->accessService->isAdmin($this->context->user)) {
            throw new ForbiddenException($this->tm->trans('admin.error.forbidden'));
        }

        switch ($page->action) {
            case 'admin.pages':
                $this->handlePagesRequest();
                break;
            case 'admin.page':
                $this->handlePageRequest((int) ($args['id'] ?? 0));
                break;
            case 'admin.page-actions':
                $this->handlePageActionsRequest();
                break;
            case 'admin.menus':
                $this->handleMenusRequest();
                break;
            case 'admin.menu':
                $this->handleMenuRequest((int) ($args['id'] ?? 0));
                break;
            case 'admin.menu-preview':
                $this->handleMenuPreviewRequest();
                break;
            case 'admin.settings':
                $this->handleSettingsRequest();
                break;
            case 'admin.registration':
                $this->handleRegistrationSettingsRequest();
                break;
            case 'admin.site-icon':
                $this->handleSiteIconRequest();
                break;
            case 'admin.header-logo':
                $this->handleHeaderLogoRequest();
                break;
            case 'admin.themes':
                $this->handleThemesRequest();
                break;
            case 'admin.setting':
                $this->handleSettingRequest((string) ($args['key'] ?? ''));
                break;
            case 'admin.users':
                $this->handleUsersRequest();
                break;
            case 'admin.user':
                $this->handleUserRequest((int) ($args['id'] ?? 0));
                break;
            case 'admin.dashboard':
                $this->handleDashboardRequest();
                break;
            case 'admin.dashboard-card':
                $this->handleDashboardCardRequest((string) ($args['id'] ?? ''));
                break;
            case 'admin.file-browser':
                $this->handleFileBrowserRequest();
                break;
            case 'admin.cron':
                $this->handleCronRequest();
                break;
            case 'admin.cron-task':
                $this->handleCronTaskRequest((string) ($args['task'] ?? ''));
                break;
            case 'admin.cron-task-run':
                $this->handleCronTaskRunRequest((string) ($args['task'] ?? ''));
                break;
            default:
                parent::callApi($page, $args);
        }
    }

    public static function registerApi(int $apiPageId, PageTree $pageTree): void
    {
        $adminApiPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $adminApiPageId,
                parentId: $apiPageId,
                pattern: 'admin',
                requestMethods: ['GET'],
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $dashboardPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $dashboardPageId,
                parentId: $adminApiPageId,
                pattern: 'dashboard',
                requestMethods: ['GET', 'PATCH', 'DELETE'],
                action: 'admin.dashboard',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $dashboardCardsPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $dashboardCardsPageId,
                parentId: $dashboardPageId,
                pattern: 'cards',
                requestMethods: ['GET'],
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $dashboardCardPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $dashboardCardPageId,
                parentId: $dashboardCardsPageId,
                pattern: '{id:[a-z][a-z0-9.-]*}',
                requestMethods: ['GET'],
                action: 'admin.dashboard-card',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $fileBrowserPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $fileBrowserPageId,
                parentId: $adminApiPageId,
                pattern: 'file-browser',
                requestMethods: ['GET', 'POST'],
                action: 'admin.file-browser',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $cronPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $cronPageId,
                parentId: $adminApiPageId,
                pattern: 'cron',
                requestMethods: ['GET'],
                action: 'admin.cron',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $cronTaskPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $cronTaskPageId,
                parentId: $cronPageId,
                pattern: '{task:[a-z][a-z0-9:._-]*}',
                requestMethods: ['GET', 'PATCH'],
                action: 'admin.cron-task',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $cronTaskRunPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $cronTaskRunPageId,
                parentId: $cronTaskPageId,
                pattern: 'run',
                requestMethods: ['POST'],
                action: 'admin.cron-task-run',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $menusPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $menusPageId,
                parentId: $adminApiPageId,
                pattern: 'menus',
                requestMethods: ['GET', 'POST', 'PATCH'],
                action: 'admin.menus',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $menuPreviewPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $menuPreviewPageId,
                parentId: $menusPageId,
                pattern: 'preview',
                requestMethods: ['GET'],
                action: 'admin.menu-preview',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $menuPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $menuPageId,
                parentId: $menusPageId,
                pattern: '{id:\d+}',
                requestMethods: ['GET', 'PATCH', 'DELETE'],
                action: 'admin.menu',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $pagesPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $pagesPageId,
                parentId: $adminApiPageId,
                pattern: 'pages',
                requestMethods: ['GET', 'POST'],
                action: 'admin.pages',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $pagePageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $pagePageId,
                parentId: $pagesPageId,
                pattern: '{id:\d+}',
                requestMethods: ['GET', 'PATCH'],
                action: 'admin.page',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $pageActionsPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $pageActionsPageId,
                parentId: $adminApiPageId,
                pattern: 'page-actions',
                requestMethods: ['GET'],
                action: 'admin.page-actions',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $settingsPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $settingsPageId,
                parentId: $adminApiPageId,
                pattern: 'settings',
                requestMethods: ['GET', 'POST'],
                action: 'admin.settings',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $registrationPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $registrationPageId,
                parentId: $adminApiPageId,
                pattern: 'registration',
                requestMethods: ['GET', 'PATCH'],
                action: 'admin.registration',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $siteIconPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $siteIconPageId,
                parentId: $settingsPageId,
                pattern: 'site-icon',
                requestMethods: ['POST', 'DELETE'],
                action: 'admin.site-icon',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $headerLogoPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $headerLogoPageId,
                parentId: $settingsPageId,
                pattern: 'header-logo',
                requestMethods: ['POST', 'DELETE'],
                action: 'admin.header-logo',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $settingPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $settingPageId,
                parentId: $settingsPageId,
                pattern: '{key:[A-Za-z0-9_.-]+}',
                requestMethods: ['GET', 'PATCH'],
                action: 'admin.setting',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $themesPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $themesPageId,
                parentId: $adminApiPageId,
                pattern: 'themes',
                requestMethods: ['GET', 'PATCH'],
                action: 'admin.themes',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $usersPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $usersPageId,
                parentId: $adminApiPageId,
                pattern: 'users',
                requestMethods: ['GET'],
                action: 'admin.users',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

        $userPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $userPageId,
                parentId: $usersPageId,
                pattern: '{id:\d+}',
                requestMethods: ['GET', 'PATCH'],
                action: 'admin.user',
                accessRule: AccessService::ACCESS_ADMIN,
            )
        );

    }

    private function handleCronRequest(): void
    {
        echo Formatter::json($this->cronStatusService->payload());
    }

    /** @throws ValidationException */
    private function handleCronTaskRequest(string $task): void
    {
        $this->requireRegisteredCronTask($task);
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            echo Formatter::json([
                'data' => array_map(static fn (array $run): array => [
                    'id' => (int) ($run['id'] ?? 0),
                    'trigger' => (string) ($run['trigger'] ?? ''),
                    'status' => (string) ($run['status'] ?? ''),
                    'requestedAt' => isset($run['requested_at']) ? (int) $run['requested_at'] : null,
                    'startedAt' => isset($run['started_at']) ? (int) $run['started_at'] : null,
                    'finishedAt' => isset($run['finished_at']) ? (int) $run['finished_at'] : null,
                    'durationMs' => isset($run['duration_ms']) ? (int) $run['duration_ms'] : null,
                    'error' => is_string($run['error'] ?? null) ? $run['error'] : null,
                    'requestedByUserId' => isset($run['requested_by_user_id'])
                        ? (int) $run['requested_by_user_id']
                        : null,
                ], $this->cronRepository->history($task)),
                'limit' => CronRepository::HISTORY_LIMIT_PER_TASK,
            ]);

            return;
        }
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $input = $this->jsonBody();
        if (! array_key_exists('enabled', $input) || ! is_bool($input['enabled'])) {
            throw new ValidationException('The enabled field must be a boolean.');
        }

        $this->cronRepository->setEnabled($task, $input['enabled']);
        echo Formatter::json(['task' => $task, 'enabled' => $input['enabled']]);
    }

    /** @throws ValidationException */
    private function handleCronTaskRunRequest(string $task): void
    {
        $this->requireRegisteredCronTask($task);
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

        $runId = $this->cronRepository->queueManualRun($task, $this->context->user->id);
        if ($runId === null) {
            throw new ValidationException('Cron task is disabled, running, or already queued.', 409, 'conflict');
        }

        try {
            $spawned = $this->cronTrigger->spawnTask($task);
        } catch (Throwable $e) {
            $this->cronRepository->markManualStartFailed($task, $runId, $e->getMessage());
            throw $e;
        }
        if (! $spawned) {
            $this->cronRepository->markManualStartFailed($task, $runId, 'Could not start the cron worker.');
            throw new ValidationException('Could not start the cron worker.', 503, 'unavailable');
        }
        http_response_code(202);
        echo Formatter::json(['task' => $task, 'runId' => $runId, 'accepted' => true]);
    }

    /** @throws NotFoundException */
    private function requireRegisteredCronTask(string $task): void
    {
        if (! $this->cronRegistry->has($task)) {
            throw new NotFoundException('Cron task not found.');
        }
    }

    /** @throws ValidationException */
    private function handleDashboardRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);
            echo Formatter::json($this->dashboardService->save($this->context, $this->jsonBody()));

            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);
            echo Formatter::json($this->dashboardService->reset($this->context));

            return;
        }

        echo Formatter::json($this->dashboardService->payload($this->context));
    }

    /**
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleFileBrowserRequest(): void
    {
        $browser = new UploadDirectoryBrowser($this->config->uploadsPath(dirname(__DIR__, 3)));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            if (isset($_FILES['file']) && is_array($_FILES['file'])) {
                if (($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new ValidationException('Upload failed');
                }

                $directory = isset($_POST['path']) && is_string($_POST['path']) ? $_POST['path'] : '';
                $upload = $this->uploadService->uploadForAdmin($this->context->user, $_FILES['file'], $directory);
                echo Formatter::json([
                    'id' => $upload->id,
                    'path' => $upload->path,
                    'url' => '/uploads/'.$upload->path,
                    'mime' => $upload->mime,
                    'size' => $upload->size,
                    'originalName' => $upload->originalName,
                ]);

                return;
            }

            $input = $this->jsonBody();
            $operation = $input['operation'] ?? null;
            if (! is_string($operation)) {
                throw new ValidationException($this->tm->trans(UploadDirectoryBrowser::INVALID_PATH));
            }

            try {
                if ($operation === 'create-directory'
                    && is_string($input['path'] ?? null) && is_string($input['name'] ?? null)) {
                    $payload = $browser->createDirectory($input['path'], $input['name']);
                } elseif ($operation === 'relocate'
                    && is_string($input['source'] ?? null)
                    && is_string($input['destination'] ?? null)
                    && is_string($input['name'] ?? null)) {
                    $move = $browser->relocate($input['source'], $input['destination'], $input['name']);
                    if ($move === null) {
                        $payload = null;
                    } else {
                        try {
                            (new UploadRepository($this->db))->relocatePath(
                                $move['from'],
                                $move['to'],
                                $move['isDir'],
                            );
                        } catch (\Throwable $exception) {
                            try {
                                $browser->relocate(
                                    $move['to'],
                                    dirname($move['from']) === '.' ? '' : dirname($move['from']),
                                    basename($move['from']),
                                );
                            } catch (\Throwable) {
                                // Preserve the database error if the best-effort filesystem rollback also fails.
                            }
                            throw $exception;
                        }
                        $payload = $move['payload'];
                    }
                } elseif ($operation === 'delete'
                    && is_array($input['paths'] ?? null)
                    && array_is_list($input['paths'])
                    && array_filter(
                        $input['paths'],
                        static fn (mixed $path): bool => ! is_string($path),
                    ) === []) {
                    $deleted = $browser->delete($input['paths']);
                    if ($deleted === null) {
                        $payload = null;
                    } else {
                        $uploads = new UploadRepository($this->db);
                        foreach ($deleted['entries'] as $entry) {
                            $uploads->deletePath($entry['path'], $entry['isDir']);
                        }
                        $payload = $deleted['payload'];
                    }
                } elseif ($operation === 'copy'
                    && is_array($input['paths'] ?? null)
                    && array_is_list($input['paths'])
                    && array_filter(
                        $input['paths'],
                        static fn (mixed $path): bool => ! is_string($path),
                    ) === []
                    && is_string($input['destination'] ?? null)) {
                    $copied = $browser->copy($input['paths'], $input['destination']);
                    if ($copied === null) {
                        $payload = null;
                    } else {
                        try {
                            $this->db->begin();
                            $uploads = new UploadRepository($this->db);
                            foreach ($copied['entries'] as $entry) {
                                $uploads->copyPath($entry['from'], $entry['to'], $entry['isDir']);
                            }
                            $this->db->commit();
                        } catch (\Throwable $exception) {
                            $this->db->rollback();
                            try {
                                $browser->delete(array_column($copied['entries'], 'to'));
                            } catch (\Throwable) {
                                // Preserve the database error if filesystem cleanup also fails.
                            }
                            throw $exception;
                        }
                        $payload = $copied['payload'];
                    }
                } else {
                    throw new \InvalidArgumentException(UploadDirectoryBrowser::INVALID_PATH);
                }
            } catch (\InvalidArgumentException $exception) {
                throw new ValidationException($this->tm->trans($exception->getMessage()));
            }

            if ($payload === null) {
                throw new NotFoundException($this->tm->trans('admin.error.uploads_directory_not_found'));
            }

            echo Formatter::json($payload);

            return;
        }

        try {
            $payload = $browser->browse(QueryParams::fromGlobals()->string('path'));
        } catch (\InvalidArgumentException $exception) {
            throw new ValidationException($this->tm->trans($exception->getMessage()));
        }

        if ($payload === null) {
            throw new NotFoundException($this->tm->trans('admin.error.uploads_directory_not_found'));
        }

        echo Formatter::json($payload);
    }

    /** @throws NotFoundException */
    private function handleDashboardCardRequest(string $id): void
    {
        echo Formatter::json($this->dashboardService->card($id, $this->context));
    }

    /** @throws ValidationException */
    private function handleUsersRequest(): void
    {
        $query = QueryParams::fromGlobals();
        $page = max(1, $query->int('page', 1));
        $perPage = 25;
        $role = $query->trimmed('role');
        if ($role !== '' && ! in_array($role, AccessService::ROLES, true)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_role_filter'));
        }

        $status = $query->trimmed('status');
        if (! in_array($status, ['', 'active', 'inactive'], true)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_status_filter'));
        }

        $search = $query->trimmed('q');
        if (mb_strlen($search) > 255) {
            throw new ValidationException($this->tm->trans('admin.error.search_too_long'));
        }

        $isActive = match ($status) {
            'active' => true,
            'inactive' => false,
            default => null,
        };
        $total = $this->userRepository->countForAdmin($search, $role !== '' ? $role : null, $isActive);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        echo Formatter::json([
            'data' => $this->userRepository->findForAdmin(
                $perPage,
                ($page - 1) * $perPage,
                $search,
                $role !== '' ? $role : null,
                $isActive,
            ),
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => $totalPages,
                'total' => $total,
                'limit' => $perPage,
            ],
            'meta' => [
                'currentUserId' => $this->context->user->id,
                'systemUserId' => \StreamEngine\Domain\User::SYSTEM_USER_ID,
            ],
        ]);
    }

    /**
     * @throws NotFoundException
     * @throws ValidationException
     * @throws ForbiddenException
     */
    private function handleUserRequest(int $id): void
    {
        if ($id <= 0) {
            throw new NotFoundException($this->tm->trans('admin.error.user_not_found'));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);
        }

        $existing = $this->userRepository->findForAdminById($id);
        if ($existing === null) {
            throw new NotFoundException($this->tm->trans('admin.error.user_not_found'));
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
            echo Formatter::json($existing);

            return;
        }

        if ($id === \StreamEngine\Domain\User::SYSTEM_USER_ID) {
            throw new ForbiddenException($this->tm->trans('admin.error.system_account'));
        }

        $data = $this->validatedUserData($this->jsonBody(), $existing);
        $changesPrivileges = $data['role'] !== $existing['role']
            || $data['isActive'] !== $existing['isActive'];

        if ($id === $this->context->user->id && $changesPrivileges) {
            throw new ForbiddenException($this->tm->trans('admin.error.own_privileges'));
        }

        if ($existing['role'] === AccessService::ROLE_ADMIN
            && $existing['isActive']
            && ($data['role'] !== AccessService::ROLE_ADMIN || ! $data['isActive'])
            && $this->userRepository->countActiveAdministrators() <= 1) {
            throw new ValidationException($this->tm->trans('admin.error.last_admin'));
        }

        if ($this->userRepository->emailBelongsToAnotherUser($data['email'], $id)) {
            throw new ValidationException($this->tm->trans('admin.error.email_used'));
        }
        if ($this->userRepository->usernameBelongsToAnotherUser($data['username'], $id)) {
            throw new ValidationException($this->tm->trans('admin.error.username_used'));
        }

        $this->userRepository->updateFromAdminData($id, $data);

        echo Formatter::json([...$existing, ...$data, 'id' => $id]);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing
     * @return array{email:string,nick:string,username:string,role:string,isActive:bool}
     * @throws ValidationException
     */
    private function validatedUserData(array $input, array $existing): array
    {
        $email = trim((string) ($input['email'] ?? $existing['email']));
        $nick = trim((string) ($input['nick'] ?? $existing['nick']));
        $username = trim((string) ($input['username'] ?? $existing['username']));
        $role = $input['role'] ?? $existing['role'];
        $isActive = $input['isActive'] ?? $existing['isActive'];

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_email'));
        }
        if (mb_strlen($nick) > 50) {
            throw new ValidationException($this->tm->trans('admin.error.display_name_too_long'));
        }
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{2,29}\z/D', $username)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_username'));
        }
        if (! is_string($role) || ! in_array($role, AccessService::ROLES, true)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_user_role'));
        }
        if (! is_bool($isActive)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_active'));
        }

        return compact('email', 'nick', 'username', 'role', 'isActive');
    }

    /**
     * @throws ValidationException
     */
    private function handlePagesRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            $data = $this->validatedPageData($this->jsonBody());

            echo Formatter::json($this->pageRepository->createFromAdminData($data));

            return;
        }

        echo Formatter::json(['data' => $this->pageRepository->findAllForAdmin()]);
    }

    /**
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handlePageRequest(int $id): void
    {
        if ($id <= 0) {
            throw new NotFoundException($this->tm->trans('admin.error.page_not_found'));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            $existing = $this->pageRepository->findForAdminById($id);

            echo Formatter::json($this->pageRepository->updateFromAdminData(
                $id,
                $this->validatedPageData($this->jsonBody(), $existing)
            ));

            return;
        }

        $page = $this->pageRepository->findForAdminById($id);

        if ($page === null) {
            throw new NotFoundException($this->tm->trans('admin.error.page_not_found'));
        }

        echo Formatter::json($page);
    }

    private function handlePageActionsRequest(): void
    {
        $actions = array_map(function (array $descriptor): array {
            $key = 'admin.page_action.'.$descriptor['action'];
            $translated = $this->tm->trans($key);
            $descriptor['label'] = $translated === $key ? $descriptor['label'] : $translated;

            return $descriptor;
        }, $this->modules->pageActions());

        echo Formatter::json(['data' => $actions]);
    }

    /** @throws ValidationException */
    private function handleMenusRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            $data = $this->validatedMenuData($this->jsonBody());

            echo Formatter::json($this->menuRepository->createFromAdminData($data));

            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);
            $items = $this->validatedMenuOrder($this->jsonBody());
            $this->menuRepository->reorder($items);
            echo Formatter::json(['data' => $this->menuRepository->findAllForAdmin()]);

            return;
        }

        echo Formatter::json(['data' => $this->menuRepository->findAllForAdmin()]);
    }

    private function handleMenuPreviewRequest(): void
    {
        $items = array_values(array_filter(
            $this->menuRepository->findAllForAdmin(),
            fn (array $item): bool => $item['enabled']
                && AccessService::allows($this->context->user, $item['accessRule'])
        ));
        $byParent = [];
        foreach ($items as $item) {
            $byParent[$item['parentId'] ?? 0][] = $item;
        }

        $build = function (int $parentId, string $group) use (&$build, $byParent): array {
            return array_map(
                fn (array $item): array => $item + ['children' => $build($item['id'], $group)],
                array_values(array_filter(
                    $byParent[$parentId] ?? [],
                    static fn (array $item): bool => $item['menuGroup'] === $group
                ))
            );
        };

        $groups = [];
        foreach ($items as $item) {
            $groups[$item['menuGroup']] = $item['groupOrder'];
        }
        asort($groups, SORT_NUMERIC);

        echo Formatter::json(['groups' => array_map(
            static fn (string $name): array => ['name' => $name, 'items' => $build(0, $name)],
            array_keys($groups)
        )]);
    }

    /**
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleMenuRequest(int $id): void
    {
        if ($id <= 0) {
            throw new NotFoundException($this->tm->trans('admin.error.menu_not_found'));
        }

        if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            if ($this->menuRepository->findForAdminById($id) === null) {
                throw new NotFoundException($this->tm->trans('admin.error.menu_not_found'));
            }

            echo Formatter::json($this->menuRepository->updateFromAdminData(
                $id,
                $this->validatedMenuData($this->jsonBody(), $id)
            ));

            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            if ($this->menuRepository->findForAdminById($id) === null) {
                throw new NotFoundException($this->tm->trans('admin.error.menu_not_found'));
            }

            $input = $this->jsonBody();
            $strategy = (string) ($input['children'] ?? 'reject');
            if (! in_array($strategy, ['reject', 'promote', 'delete'], true)) {
                throw new ValidationException($this->tm->trans('admin.error.invalid_child_strategy'));
            }
            if ($this->menuRepository->hasChildren($id) && $strategy === 'reject') {
                throw new ValidationException($this->tm->trans('admin.error.choose_child_strategy'));
            }

            if ($strategy === 'reject') {
                $this->menuRepository->delete($id);
            } else {
                $this->menuRepository->deleteWithChildren($id, $strategy);
            }
            echo Formatter::json(['deleted' => true]);

            return;
        }

        $item = $this->menuRepository->findForAdminById($id);

        if ($item === null) {
            throw new NotFoundException($this->tm->trans('admin.error.menu_not_found'));
        }

        echo Formatter::json($item);
    }

    /**
     * @throws ValidationException
     */
    private function handleSettingsRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            ['key' => $key, 'value' => $value] = $this->validatedSettingData($this->jsonBody(), true);
            $this->validateSettingValue($key, $value);

            echo Formatter::json($this->settingsRepository->setForAdmin($key, $value));

            return;
        }

        echo Formatter::json([
            'data' => array_values(array_filter(
                $this->settingsRepository->findAllForAdmin(),
                static fn (array $setting): bool => ! str_starts_with($setting['key'], 'registration.'),
            )),
            'siteIcon' => $this->siteIconPayload(),
            'headerLogo' => $this->headerLogoPayload(),
        ]);
    }

    /** @throws ValidationException */
    private function handleRegistrationSettingsRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);
            $input = $this->jsonBody();
            $mode = is_string($input['mode'] ?? null) ? $input['mode'] : '';
            $honeypotField = is_string($input['honeypotField'] ?? null) ? trim($input['honeypotField']) : '';
            $captchaProvider = is_string($input['captchaProvider'] ?? null) ? $input['captchaProvider'] : '';
            $captchaSiteKey = is_string($input['captchaSiteKey'] ?? null) ? trim($input['captchaSiteKey']) : '';
            $captchaSecret = is_string($input['captchaSecret'] ?? null) ? trim($input['captchaSecret']) : '';

            $this->validateSettingValue(SettingsService::REGISTRATION_MODE_KEY, $mode);
            $this->validateSettingValue(SettingsService::REGISTRATION_HONEYPOT_FIELD_KEY, $honeypotField);
            $this->validateSettingValue(SettingsService::REGISTRATION_CAPTCHA_PROVIDER_KEY, $captchaProvider);

            $currentCaptcha = $this->settingsService->registrationCaptcha();
            if ($captchaProvider === 'none') {
                $captchaSiteKey = '';
                $captchaSecret = '';
            } else {
                if ($captchaSecret === '' && $captchaProvider === $currentCaptcha['provider']) {
                    $captchaSecret = $currentCaptcha['secret'];
                }
                if ($captchaSiteKey === '' || $captchaSecret === ''
                    || strlen($captchaSiteKey) > 512 || strlen($captchaSecret) > 2048) {
                    throw new ValidationException($this->tm->trans('admin.error.invalid_captcha_settings'));
                }
            }

            $this->settingsService->setMany([
                SettingsService::REGISTRATION_MODE_KEY => $mode,
                SettingsService::REGISTRATION_HONEYPOT_FIELD_KEY => $honeypotField,
                SettingsService::REGISTRATION_CAPTCHA_PROVIDER_KEY => $captchaProvider,
                SettingsService::REGISTRATION_CAPTCHA_SITE_KEY => $captchaSiteKey,
                SettingsService::REGISTRATION_CAPTCHA_SECRET_KEY => $captchaSecret,
            ]);

            echo Formatter::json([
                'mode' => $mode,
                'honeypotField' => $honeypotField,
                'captchaProvider' => $captchaProvider,
                'captchaSiteKey' => $captchaSiteKey,
                'captchaSecretConfigured' => $captchaSecret !== '',
            ]);

            return;
        }

        $captcha = $this->settingsService->registrationCaptcha();
        echo Formatter::json([
            'mode' => $this->settingsService->registrationMode(),
            'honeypotField' => $this->settingsService->registrationHoneypotField(),
            'captchaProvider' => $captcha['provider'],
            'captchaSiteKey' => $captcha['siteKey'],
            'captchaSecretConfigured' => $captcha['secret'] !== '',
        ]);
    }

    /** @throws ValidationException */
    private function handleSiteIconRequest(): void
    {
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);
        $oldBase = $this->settingsService->getString(SettingsService::SITE_ICON_KEY);

        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            $this->settingsRepository->setMany([
                SettingsService::SITE_ICON_KEY => '',
                SettingsService::SITE_ICON_SVG_KEY => '',
            ]);
            $this->removeSiteIconFiles($oldBase);

            echo Formatter::json([
                'customized' => false,
                'converterAvailable' => (new ImageProcessor($this->config->tempDir()))->supportsSvg(),
                'svg' => '/favicon.svg',
                'ico' => '/favicon.ico',
                'apple' => '/apple-touch-icon.png',
            ]);

            return;
        }

        $file = $_FILES['file'] ?? null;
        if (! is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException($this->tm->trans('admin.error.site_icon_upload'));
        }
        $size = (int) ($file['size'] ?? 0);
        $tmpFile = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        if ($size <= 0 || $size > self::SITE_ICON_MAX_SIZE || ! is_file($tmpFile)) {
            throw new ValidationException($this->tm->trans('admin.error.site_icon_size'));
        }

        $mime = (new MimeDetector())->detect($tmpFile);
        $processor = new ImageProcessor($this->config->tempDir());
        $variants = $processor->processSiteIcon($tmpFile, $mime);
        $hash = substr(hash_file('sha256', $tmpFile), 0, 16);
        $baseUrl = '/uploads/site-icons/favicon-'.$hash;
        $directory = $this->config->uploadsPath(dirname(__DIR__, 3)).'/site-icons';
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new ValidationException($this->tm->trans('admin.error.site_icon_store'));
        }

        $basePath = $directory.'/favicon-'.$hash;
        $written = [];
        try {
            if ($this->writeSiteIconFile($basePath.'.ico', $variants['ico'])) {
                $written[] = $basePath.'.ico';
            }
            if ($this->writeSiteIconFile($basePath.'.png', $variants['png'])) {
                $written[] = $basePath.'.png';
            }
            if ($variants['svg'] !== null) {
                if ($this->writeSiteIconFile($basePath.'.svg', $variants['svg'])) {
                    $written[] = $basePath.'.svg';
                }
            }

            $this->settingsRepository->setMany([
                SettingsService::SITE_ICON_KEY => $baseUrl,
                SettingsService::SITE_ICON_SVG_KEY => $variants['svg'] !== null ? $baseUrl.'.svg' : '',
            ]);
        } catch (\Throwable $error) {
            foreach ($written as $path) {
                @unlink($path);
            }
            throw $error;
        }

        if ($variants['svg'] === null) {
            @unlink($basePath.'.svg');
        }
        if ($oldBase !== $baseUrl) {
            $this->removeSiteIconFiles($oldBase);
        }

        echo Formatter::json([
            'customized' => true,
            'converterAvailable' => $processor->supportsSvg(),
            'svg' => $variants['svg'] !== null ? $baseUrl.'.svg' : null,
            'ico' => $baseUrl.'.ico',
            'apple' => $baseUrl.'.png',
        ]);
    }

    /** @return array{customized:bool, converterAvailable:bool, svg:?string, ico:string, apple:string} */
    private function siteIconPayload(): array
    {
        $icons = $this->settingsService->siteIcons();

        return [
            'customized' => $icons['ico'] !== '/favicon.ico',
            'converterAvailable' => (new ImageProcessor($this->config->tempDir()))->supportsSvg(),
            ...$icons,
        ];
    }

    /** @throws ValidationException */
    private function writeSiteIconFile(string $path, string $contents): bool
    {
        if (is_file($path)) {
            return false;
        }
        $temporary = tempnam(dirname($path), '.site-icon-');
        if ($temporary === false || file_put_contents($temporary, $contents, LOCK_EX) === false
            || ! rename($temporary, $path)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            throw new ValidationException($this->tm->trans('admin.error.site_icon_store'));
        }
        chmod($path, 0644);

        return true;
    }

    private function removeSiteIconFiles(string $baseUrl): void
    {
        if (preg_match('~\A/uploads/site-icons/favicon-[a-f0-9]{16}\z~', $baseUrl) !== 1) {
            return;
        }
        $name = basename($baseUrl);
        $directory = $this->config->uploadsPath(dirname(__DIR__, 3)).'/site-icons';
        foreach (['ico', 'png', 'svg'] as $extension) {
            @unlink($directory.'/'.$name.'.'.$extension);
        }
    }

    /** @throws ValidationException */
    private function handleHeaderLogoRequest(): void
    {
        Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);
        $oldUrl = $this->settingsService->headerLogo();

        if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            $this->settingsRepository->setMany([SettingsService::HEADER_LOGO_KEY => '']);
            $this->removeHeaderLogoFile($oldUrl);

            echo Formatter::json(['customized' => false, 'url' => null]);

            return;
        }

        $file = $_FILES['file'] ?? null;
        if (! is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException($this->tm->trans('admin.error.header_logo_upload'));
        }
        $size = (int) ($file['size'] ?? 0);
        $tmpFile = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        if ($size <= 0 || $size > self::HEADER_LOGO_MAX_SIZE || ! is_file($tmpFile)) {
            throw new ValidationException($this->tm->trans('admin.error.header_logo_size'));
        }

        $mime = (new MimeDetector())->detect($tmpFile);
        $processedFile = $tmpFile;
        try {
            [$processedFile, $mime] = (new ImageProcessor($this->config->tempDir()))->process($tmpFile, $mime);
            $extension = match ($mime) {
                'image/gif' => 'gif',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => throw new ValidationException($this->tm->trans('admin.error.header_logo_upload')),
            };
            $hash = substr(hash_file('sha256', $processedFile), 0, 16);
            $name = 'header-logo-'.$hash.'.'.$extension;
            $url = '/uploads/site-brand/'.$name;
            $directory = $this->config->uploadsPath(dirname(__DIR__, 3)).'/site-brand';
            if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                throw new ValidationException($this->tm->trans('admin.error.header_logo_store'));
            }

            $path = $directory.'/'.$name;
            $written = $this->writeUploadedFile($path, $processedFile, 'header-logo');
            try {
                $this->settingsRepository->setMany([SettingsService::HEADER_LOGO_KEY => $url]);
            } catch (\Throwable $error) {
                if ($written) {
                    @unlink($path);
                }
                throw $error;
            }
        } finally {
            if ($processedFile !== $tmpFile) {
                @unlink($processedFile);
            }
        }

        if ($oldUrl !== $url) {
            $this->removeHeaderLogoFile($oldUrl);
        }

        echo Formatter::json(['customized' => true, 'url' => $url]);
    }

    /** @return array{customized:bool, url:?string} */
    private function headerLogoPayload(): array
    {
        $url = $this->settingsService->headerLogo();

        return ['customized' => $url !== null, 'url' => $url];
    }

    /** @throws ValidationException */
    private function writeUploadedFile(string $path, string $source, string $temporaryPrefix): bool
    {
        if (is_file($path)) {
            return false;
        }
        $temporary = tempnam(dirname($path), '.'.$temporaryPrefix.'-');
        if ($temporary === false || ! copy($source, $temporary) || ! rename($temporary, $path)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
            throw new ValidationException($this->tm->trans('admin.error.header_logo_store'));
        }
        chmod($path, 0644);

        return true;
    }

    private function removeHeaderLogoFile(?string $url): void
    {
        if ($url === null || preg_match(
            '~\A/uploads/site-brand/(header-logo-[a-f0-9]{16}\.(?:gif|png|webp))\z~',
            $url,
            $matches,
        ) !== 1) {
            return;
        }

        $directory = $this->config->uploadsPath(dirname(__DIR__, 3)).'/site-brand';
        @unlink($directory.'/'.$matches[1]);
    }

    /**
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleSettingRequest(string $key): void
    {
        $this->validateSettingKey($key);

        if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            ['value' => $value] = $this->validatedSettingData($this->jsonBody(), false);
            $this->validateSettingValue($key, $value);

            echo Formatter::json($this->settingsRepository->setForAdmin($key, $value));

            return;
        }

        $setting = $this->settingsRepository->findForAdminByKey($key);

        if ($setting === null) {
            throw new NotFoundException($this->tm->trans('admin.error.setting_not_found'));
        }

        echo Formatter::json($setting);
    }

    /** @throws ValidationException */
    private function handleThemesRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);
            $input = $this->jsonBody();
            $themeId = $input['themeId'] ?? null;
            $settings = $input['settings'] ?? [];
            if (! is_string($themeId) || ! is_array($settings)) {
                throw new ValidationException($this->tm->trans('admin.error.invalid_theme'));
            }

            echo Formatter::json($this->themeService->save($themeId, $settings));

            return;
        }

        echo Formatter::json($this->themeService->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $input = json_decode(file_get_contents('php://input'), true);

        return is_array($input) ? $input : [];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function validatedPageData(array $input, ?array $existing = null): array
    {
        $pattern = trim((string) ($input['pattern'] ?? ''));
        $action = trim((string) ($input['action'] ?? ''));
        $settings = trim((string) ($input['settings'] ?? ''));
        $changefreq = trim((string) ($input['changefreq'] ?? ''));

        if ($action === '') {
            throw new ValidationException($this->tm->trans('admin.error.action_required'));
        }

        if ($settings !== '') {
            json_decode($settings, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ValidationException($this->tm->trans('admin.error.settings_json'));
            }
        }

        if ($changefreq !== '' && ! in_array($changefreq, self::CHANGEFREQ_VALUES, true)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_changefreq'));
        }

        $accessRule = $input['accessRule'] ?? null;
        if (! is_string($accessRule) || ! in_array($accessRule, AccessService::ACCESS_RULES, true)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_access'));
        }

        $parentId = $this->optionalPositiveInt($input['parentId'] ?? null, $this->tm->trans('admin.error.invalid_parent_page'));
        $currentId = isset($existing['id']) ? (int) $existing['id'] : null;
        if ($currentId === 1 && ($parentId !== null || $pattern !== '')) {
            throw new ValidationException($this->tm->trans('admin.error.root_page'));
        }
        $this->validatePageParent($parentId, $currentId);

        $data = [
            'parentId' => $parentId,
            'pattern' => $pattern,
            'action' => $action,
            'pageName' => $this->optionalString($input['pageName'] ?? null),
            'settings' => $settings !== '' ? $settings : null,
            'feedType' => $this->optionalString($input['feedType'] ?? null),
            'listFeedType' => $this->optionalString($input['listFeedType'] ?? null),
            'termVocabulary' => $this->optionalString($input['termVocabulary'] ?? null),
            'feedId' => $this->optionalPositiveInt($input['feedId'] ?? null, $this->tm->trans('admin.error.invalid_feed_id')),
            'changefreq' => $changefreq !== '' ? $changefreq : null,
            'accessRule' => $accessRule,
        ];

        $this->validatePageActionContract($data, $existing);

        if ($currentId !== 1 && $parentId === null) {
            throw new ValidationException($this->tm->trans('admin.error.parent_page_required'));
        }

        return $data;
    }

    /** @throws ValidationException */
    private function validatePageParent(?int $parentId, ?int $currentId): void
    {
        if ($parentId === null) {
            return;
        }

        if ($parentId === $currentId) {
            throw new ValidationException($this->tm->trans('admin.error.page_own_parent'));
        }

        $byId = [];
        foreach ($this->pageRepository->findAllForAdmin() as $page) {
            $byId[$page['id']] = $page;
        }

        if (! isset($byId[$parentId])) {
            throw new ValidationException($this->tm->trans('admin.error.parent_page_not_found'));
        }

        $ancestorId = $parentId;
        $visited = [];
        while ($ancestorId !== null) {
            if ($ancestorId === $currentId) {
                throw new ValidationException($this->tm->trans('admin.error.page_descendant'));
            }
            if (isset($visited[$ancestorId])) {
                throw new ValidationException($this->tm->trans('admin.error.page_cycle'));
            }

            $visited[$ancestorId] = true;
            $ancestorId = $byId[$ancestorId]['parentId'] ?? null;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $existing
     * @throws ValidationException
     */
    private function validatePageActionContract(array $data, ?array $existing): void
    {
        $action = $data['action'];
        $descriptor = $this->modules->pageAction($action);

        if ($descriptor === null) {
            if ($existing === null || ($existing['action'] ?? null) !== $action) {
                throw new ValidationException($this->tm->trans('admin.error.unknown_page_action', ['action' => $action]));
            }

            foreach (ModuleRegistry::PAGE_ACTION_FIELDS as $field) {
                $existingValue = $field === 'feedId'
                    ? $this->optionalInt($existing[$field] ?? null)
                    : $this->optionalString($existing[$field] ?? null);
                if ($data[$field] !== $existingValue) {
                    throw new ValidationException(
                        $this->tm->trans('admin.error.unknown_action_fields', ['action' => $action])
                    );
                }
            }

            return;
        }

        if ($descriptor['singleton']) {
            $currentId = isset($existing['id']) ? (int) $existing['id'] : null;
            foreach ($this->pageRepository->findAllForAdmin() as $page) {
                if ($page['action'] === $action && $page['id'] !== $currentId) {
                    throw new ValidationException(
                        $this->tm->trans('admin.error.singleton_page_action', ['action' => $action])
                    );
                }
            }
        }

        foreach ($descriptor['fields'] as $field => $fieldDescriptor) {
            $value = $data[$field];
            $hasValue = $value !== null;
            $label = $this->pageActionFieldLabel($field);

            if ($fieldDescriptor['status'] === 'unsupported' && $hasValue) {
                throw new ValidationException($this->tm->trans('admin.error.action_field_unsupported', ['label' => $label, 'action' => $action]));
            }
            if ($fieldDescriptor['status'] === 'required' && ! $hasValue) {
                throw new ValidationException($this->tm->trans('admin.error.action_field_required', ['label' => $label, 'action' => $action]));
            }
            if ($hasValue
                && isset($fieldDescriptor['values'])
                && ! in_array($value, $fieldDescriptor['values'], true)) {
                throw new ValidationException($this->tm->trans('admin.error.action_field_invalid', ['label' => $label, 'action' => $action]));
            }
            if ($hasValue && $field === 'feedId' && $fieldDescriptor['status'] !== 'unsupported') {
                $feed = $this->db->fetchOne('SELECT type FROM feeds WHERE id = ?', [$value]);
                if ($feed === null) {
                    throw new ValidationException($this->tm->trans('admin.error.feed_not_found', ['id' => $value]));
                }
                if (isset($fieldDescriptor['feedTypes'])
                    && ! in_array($feed['type'] ?? null, $fieldDescriptor['feedTypes'], true)) {
                    throw new ValidationException($this->tm->trans('admin.error.feed_invalid_type', ['id' => $value, 'action' => $action]));
                }
            }
        }

        foreach ($descriptor['requirements'] as $requirement) {
            if (array_filter(
                $requirement['oneOf'],
                static fn (string $field): bool => $data[$field] !== null
            ) === []) {
                $labels = array_map($this->pageActionFieldLabel(...), $requirement['oneOf']);
                throw new ValidationException(
                    $this->tm->trans('admin.error.action_one_of', [
                        'labels' => implode(', ', $labels),
                        'action' => $action,
                    ])
                );
            }
        }

        $this->validatePageActionSettings($descriptor, $data['settings']);
    }

    /**
     * @param array<string, mixed> $descriptor
     * @throws ValidationException
     */
    private function validatePageActionSettings(array $descriptor, ?string $settingsJson): void
    {
        if ($descriptor['settings'] === []) {
            return;
        }

        $settings = $settingsJson === null ? null : json_decode($settingsJson);
        if (! is_object($settings)) {
            throw new ValidationException($this->tm->trans('admin.error.action_settings_object'));
        }

        $values = get_object_vars($settings);
        foreach ($descriptor['settings'] as $key => $setting) {
            $exists = array_key_exists($key, $values);
            $value = $exists ? $values[$key] : null;
            $label = $this->tm->trans($setting['label']);

            if (! $exists) {
                if ($setting['required']) {
                    throw new ValidationException($this->tm->trans(
                        'admin.error.action_setting_required',
                        ['label' => $label],
                    ));
                }

                continue;
            }
            if (! is_string($value)) {
                throw new ValidationException($this->tm->trans(
                    'admin.error.action_setting_type',
                    ['label' => $label],
                ));
            }
            if ($setting['required'] && trim($value) === '') {
                throw new ValidationException($this->tm->trans(
                    'admin.error.action_setting_required',
                    ['label' => $label],
                ));
            }

            $allowed = array_column($setting['options'], 'value');
            if (! in_array($value, $allowed, true)) {
                throw new ValidationException($this->tm->trans(
                    'admin.error.action_setting_invalid',
                    ['label' => $label],
                ));
            }
        }
    }

    private function pageActionFieldLabel(string $field): string
    {
        return match ($field) {
            'feedId' => $this->tm->trans('js.admin.feed_id'),
            'feedType' => $this->tm->trans('js.admin.feed_type'),
            'listFeedType' => $this->tm->trans('js.admin.list_feed_type'),
            'termVocabulary' => $this->tm->trans('js.admin.term_vocabulary'),
            default => $field,
        };
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     * @throws ValidationException
     */
    private function validatedMenuData(array $input, ?int $currentId = null): array
    {
        $menuGroup = trim((string) ($input['menuGroup'] ?? ''));
        $type = trim((string) ($input['type'] ?? ''));
        $label = $this->optionalString($input['label'] ?? null);
        $url = $this->optionalString($input['url'] ?? null);
        $action = $this->optionalString($input['action'] ?? null);
        $parentId = $this->optionalPositiveInt($input['parentId'] ?? null, $this->tm->trans('admin.error.invalid_parent'));
        $pageId = $this->optionalPositiveInt($input['pageId'] ?? null, $this->tm->trans('admin.error.invalid_page'));
        $enabled = $input['enabled'] ?? true;

        if ($menuGroup === '' || strlen($menuGroup) > 100) {
            throw new ValidationException($this->tm->trans('admin.error.menu_group'));
        }

        if (! in_array($type, self::MENU_TYPES, true)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_menu_type'));
        }

        $accessRule = $input['accessRule'] ?? null;
        if (! is_string($accessRule) || ! in_array($accessRule, AccessService::ACCESS_RULES, true)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_access'));
        }

        if ($label !== null && strlen($label) > 150) {
            throw new ValidationException($this->tm->trans('admin.error.label_too_long'));
        }

        if ($url !== null && strlen($url) > 255) {
            throw new ValidationException($this->tm->trans('admin.error.url_too_long'));
        }

        if ($action !== null && strlen($action) > 100) {
            throw new ValidationException($this->tm->trans('admin.error.action_too_long'));
        }

        if (in_array($type, ['internal', 'dynamic'], true) && $pageId === null) {
            throw new ValidationException($this->tm->trans('admin.error.menu_page_required'));
        }

        if ($type === 'external' && $url === null) {
            throw new ValidationException($this->tm->trans('admin.error.menu_url_required'));
        }

        if ($type === 'action' && $action === null) {
            throw new ValidationException($this->tm->trans('admin.error.menu_action_required'));
        }
        if ($type === 'action' && ! in_array($action, self::MENU_ACTIONS, true)) {
            throw new ValidationException($this->tm->trans('admin.error.unknown_menu_action'));
        }

        if ($type !== 'divider' && $label === null) {
            throw new ValidationException($this->tm->trans('admin.error.label_required'));
        }

        if ($currentId !== null && $parentId === $currentId) {
            throw new ValidationException($this->tm->trans('admin.error.menu_own_parent'));
        }

        $items = $this->menuRepository->findAllForAdmin();
        $byId = [];
        foreach ($items as $item) {
            $byId[$item['id']] = $item;
        }

        if (! is_bool($enabled)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_enabled'));
        }

        if ($parentId !== null) {
            $parent = $byId[$parentId] ?? null;
            if ($parent === null) {
                throw new ValidationException($this->tm->trans('admin.error.parent_menu_not_found'));
            }
            if ($parent['menuGroup'] !== $menuGroup) {
                throw new ValidationException($this->tm->trans('admin.error.parent_menu_group'));
            }

            $ancestorId = $parentId;
            $visited = [];
            while ($ancestorId !== null && ! isset($visited[$ancestorId])) {
                if ($ancestorId === $currentId) {
                    throw new ValidationException($this->tm->trans('admin.error.menu_descendant'));
                }
                $visited[$ancestorId] = true;
                $ancestorId = $byId[$ancestorId]['parentId'] ?? null;
            }
        }

        if (in_array($type, ['internal', 'dynamic'], true)
            && $this->pageRepository->findForAdminById((int) $pageId) === null) {
            throw new ValidationException($this->tm->trans('admin.error.referenced_page'));
        }

        if (in_array($type, ['external', 'action', 'divider'], true)) {
            $pageId = null;
        }
        if ($type !== 'external') {
            $url = null;
        }
        if ($type !== 'action') {
            $action = null;
        }
        if ($type === 'divider') {
            $label = null;
        }

        $current = $currentId !== null ? ($byId[$currentId] ?? null) : null;
        $sortOrder = $current !== null
            && $current['menuGroup'] === $menuGroup
            && $current['parentId'] === $parentId
                ? $current['sortOrder']
                : $this->nextMenuSortOrder($items, $menuGroup, $parentId, $currentId);

        return [
            'parentId' => $parentId,
            'menuGroup' => $menuGroup,
            'type' => $type,
            'pageId' => $pageId,
            'url' => $url,
            'action' => $action,
            'label' => $label,
            'accessRule' => $accessRule,
            'sortOrder' => $sortOrder,
            'groupOrder' => $this->menuGroupOrder($menuGroup, $items),
            'enabled' => $enabled,
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function nextMenuSortOrder(array $items, string $menuGroup, ?int $parentId, ?int $excludedId): int
    {
        $maximum = 0;
        foreach ($items as $item) {
            if ($item['id'] !== $excludedId
                && $item['menuGroup'] === $menuGroup
                && $item['parentId'] === $parentId) {
                $maximum = max($maximum, $item['sortOrder']);
            }
        }

        return $maximum + 10;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function menuGroupOrder(string $group, array $items): int
    {
        $maximum = 0;
        foreach ($items as $item) {
            if ($item['menuGroup'] === $group) {
                return (int) ($item['groupOrder'] ?? 0);
            }
            $maximum = max($maximum, (int) ($item['groupOrder'] ?? 0));
        }

        return $maximum + 10;
    }

    /**
     * @param array<string, mixed> $input
     * @return list<array{id:int,parentId:?int,menuGroup:string,sortOrder:int,groupOrder:int}>
     * @throws ValidationException
     */
    private function validatedMenuOrder(array $input): array
    {
        $submitted = $input['items'] ?? null;
        $groups = $input['groups'] ?? null;
        if (! is_array($submitted) || ! is_array($groups)) {
            throw new ValidationException($this->tm->trans('admin.error.complete_menu_order'));
        }

        $existing = $this->menuRepository->findAllForAdmin();
        $existingIds = array_map(static fn (array $item): int => $item['id'], $existing);
        $existingById = array_column($existing, null, 'id');
        $existingGroups = array_values(array_unique(array_column($existing, 'menuGroup')));
        sort($existingGroups);
        $submittedIds = [];
        $normalizedGroups = [];
        foreach ($groups as $group) {
            if (! is_string($group) || trim($group) === '' || in_array($group, $normalizedGroups, true)) {
                throw new ValidationException($this->tm->trans('admin.error.invalid_menu_group_order'));
            }
            $normalizedGroups[] = $group;
        }
        $checkGroups = $normalizedGroups;
        sort($checkGroups);
        if ($checkGroups !== $existingGroups) {
            throw new ValidationException($this->tm->trans('admin.error.complete_menu_group_order'));
        }

        $normalized = [];
        $siblingPositions = [];
        foreach ($submitted as $item) {
            if (! is_array($item) || ! is_int($item['id'] ?? null) || ! is_string($item['menuGroup'] ?? null)) {
                throw new ValidationException($this->tm->trans('admin.error.invalid_menu_order_item'));
            }
            $id = $item['id'];
            $parentId = $this->optionalPositiveInt($item['parentId'] ?? null, $this->tm->trans('admin.error.invalid_menu_parent'));
            $group = $item['menuGroup'];
            if (in_array($id, $submittedIds, true) || ! in_array($group, $normalizedGroups, true)) {
                throw new ValidationException($this->tm->trans('admin.error.invalid_menu_order_item'));
            }
            if (($existingById[$id]['menuGroup'] ?? null) !== $group) {
                throw new ValidationException($this->tm->trans('admin.error.menu_group_reorder'));
            }
            $submittedIds[] = $id;
            $key = $group.'/'.($parentId ?? 'root');
            $siblingPositions[$key] = ($siblingPositions[$key] ?? 0) + 10;
            $normalized[$id] = [
                'id' => $id,
                'parentId' => $parentId,
                'menuGroup' => $group,
                'sortOrder' => $siblingPositions[$key],
                'groupOrder' => (array_search($group, $normalizedGroups, true) + 1) * 10,
            ];
        }

        sort($existingIds);
        $checkIds = $submittedIds;
        sort($checkIds);
        if ($checkIds !== $existingIds) {
            throw new ValidationException($this->tm->trans('admin.error.complete_menu_order'));
        }

        foreach ($normalized as $id => $item) {
            $parentId = $item['parentId'];
            if ($parentId === null) {
                continue;
            }
            if (! isset($normalized[$parentId]) || $normalized[$parentId]['menuGroup'] !== $item['menuGroup']) {
                throw new ValidationException($this->tm->trans('admin.error.parent_menu_group'));
            }
            $seen = [$id => true];
            while ($parentId !== null) {
                if (isset($seen[$parentId])) {
                    throw new ValidationException($this->tm->trans('admin.error.menu_cycle'));
                }
                $seen[$parentId] = true;
                $parentId = $normalized[$parentId]['parentId'] ?? null;
            }
        }

        return array_values($normalized);
    }

    private function optionalString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }

    private function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /** @throws ValidationException */
    private function optionalPositiveInt(mixed $value, string $message): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) || $value <= 0) {
            throw new ValidationException($message);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{key?: string, value: string}
     * @throws ValidationException
     */
    private function validatedSettingData(array $input, bool $requiresKey): array
    {
        $data = ['value' => (string) ($input['value'] ?? '')];

        if (! $requiresKey) {
            return $data;
        }

        $key = trim((string) ($input['key'] ?? ''));
        $this->validateSettingKey($key);

        return ['key' => $key] + $data;
    }

    /**
     * @throws ValidationException
     */
    private function validateSettingKey(string $key): void
    {
        if ($key === ''
            || strlen($key) > 100
            || ! preg_match('/\A[A-Za-z0-9_.-]+\z/', $key)
            || $key === ThemeService::ACTIVE_KEY
            || in_array($key, [
                SettingsService::SITE_ICON_KEY,
                SettingsService::SITE_ICON_SVG_KEY,
                SettingsService::HEADER_LOGO_KEY,
            ], true)
            || str_starts_with($key, 'registration.')
            || str_starts_with($key, 'theme.')) {
            throw new ValidationException($this->tm->trans('admin.error.setting_key'));
        }
    }

    /** @throws ValidationException */
    private function validateSettingValue(string $key, string $value): void
    {
        if ($key === 'locale' && ! in_array($value, TranslationManager::availableLocales(), true)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_locale'));
        }
        if ($key === 'date_format' && ! in_array($value, Formatter::DATE_FORMATS, true)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_date_format'));
        }
        if ($key === 'time_format' && ! in_array($value, Formatter::TIME_FORMATS, true)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_time_format'));
        }
        if ($key === 'cron.mode' && ! in_array($value, SettingsService::CRON_MODES, true)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_cron_mode'));
        }
        if ($key === SettingsService::REGISTRATION_HONEYPOT_FIELD_KEY
            && ! SettingsService::isValidRegistrationHoneypotField($value)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_honeypot_field'));
        }
        if ($key === SettingsService::REGISTRATION_MODE_KEY
            && ! in_array($value, SettingsService::REGISTRATION_MODES, true)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_registration_mode'));
        }
        if ($key === SettingsService::REGISTRATION_CAPTCHA_PROVIDER_KEY
            && ! in_array($value, SettingsService::REGISTRATION_CAPTCHA_PROVIDERS, true)) {
            throw new ValidationException($this->tm->trans('admin.error.invalid_captcha_provider'));
        }
    }
}

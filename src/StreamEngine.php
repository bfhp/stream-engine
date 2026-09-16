<?php

declare(strict_types=1);

namespace StreamEngine;

use DateTimeZone;
use Exception;
use StreamEngine\Core\ApiErrorResponse;
use StreamEngine\Core\Cache;
use StreamEngine\Core\Config;
use StreamEngine\Core\ControllerFactory;
use StreamEngine\Core\ControllerInterface;
use StreamEngine\Core\Cron\CronRegistry;
use StreamEngine\Core\Cron\CronRepository;
use StreamEngine\Core\Cron\CronRunner;
use StreamEngine\Core\Cron\CronTrigger;
use StreamEngine\Core\FileProcessing\FileStorage;
use StreamEngine\Core\FileProcessing\ImageProcessor;
use StreamEngine\Core\FileProcessing\MimeDetector;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\GuestFeedReadStore;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\Router;
use StreamEngine\Core\Security;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Repository\ConversationRepository;
use StreamEngine\Repository\FeedFavoriteRepository;
use StreamEngine\Repository\FeedMetadataRepository;
use StreamEngine\Repository\FeedRatingRepository;
use StreamEngine\Repository\FeedReadRepository;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Repository\FeedTermRepository;
use StreamEngine\Repository\MembershipRepository;
use StreamEngine\Repository\MenuRepository;
use StreamEngine\Repository\MessageRepository;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\PageRepository;
use StreamEngine\Repository\ParticipantRepository;
use StreamEngine\Repository\PollRepository;
use StreamEngine\Repository\SettingsRepository;
use StreamEngine\Repository\UploadRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\AuthService;
use StreamEngine\Service\BreadcrumbsService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MenuService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\PollService;
use StreamEngine\Service\SettingsService;
use StreamEngine\Service\TermService;
use StreamEngine\Service\UploadService;
use StreamEngine\Service\UserService;
use StreamEngine\Service\WidgetService;
use StreamEngine\Twig\CachedEnvironment;
use StreamEngine\View\Breadcrumb;
use Throwable;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class StreamEngine
{
    private Config $config;
    private PdoDatabase $db;

    private Cache $cache;

    private SettingsService $settings;

    private WidgetService $widgets;

    private PageTree $pageTree;

    private UrlGenerator $urlGenerator;

    private AccessService $accessService;

    private FeedService $feedService;

    private TermService $termService;

    private AuthService $authService;

    private UserService $userService;

    private MailService $mailService;

    private MessageService $messageService;

    private NotificationService $notificationService;

    private PollService $pollService;

    private UploadService $uploadService;

    private ControllerFactory $controllerFactory;

    private ModuleRegistry $modules;

    private CronRegistry $cronRegistry;

    private TranslationManager $tm;

    private Formatter $fmt;

    private float $startTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
        $this->modules = new ModuleRegistry();
        $this->config = Config::fromEnvironment();

        $this->db = new PdoDatabase($this->config);
        $this->cache = new Cache(
            servers: [['host' => $this->config->cacheHost(), 'port' => $this->config->cachePort()]],
            prefix: $this->config->cachePrefix()
        );
        if ($this->config->isDevelopment()) {
            $this->cache->flush();
        }

        $this->settings = new SettingsService(new SettingsRepository($this->db));
        $this->widgets = new WidgetService($this->settings);

        $this->tm = new TranslationManager($this->settings->getString('locale'), 'en');
        $this->fmt = new Formatter($this->tm, $this->settings->getString('locale'));

        $pageRepository = new PageRepository($this->db);
        $pages = $pageRepository->findAll();

        $feedRepository = new FeedRepository($this->db);
        $feedMetadataRepository = new FeedMetadataRepository($this->db);
        $feedRatingRepository = new FeedRatingRepository($this->db);
        $feedFavoriteRepository = new FeedFavoriteRepository($this->db);
        $feedReadRepository = new FeedReadRepository($this->db);
        $guestFeedReadStore = new GuestFeedReadStore();
        $feedTermRepository = new FeedTermRepository($this->db);

        $membershipRepository = new MembershipRepository($this->db);

        $this->accessService = new AccessService(
            $membershipRepository,
            $feedRepository,
        );

        $this->pageTree = new PageTree($pages);
        $this->urlGenerator = new UrlGenerator(
            $this->pageTree,
            $feedRepository,
            $this->cache
        );

        $this->feedService = new FeedService(
            $feedRepository,
            $this->urlGenerator,
            $this->accessService,
            $this->fmt,
            $this->tm,
            $feedMetadataRepository,
            $feedRatingRepository,
            $feedFavoriteRepository,
            $feedReadRepository,
            $guestFeedReadStore,
        );

        $this->termService = new TermService(
            $feedTermRepository,
            $this->urlGenerator,
        );

        $this->pollService = new PollService(
            new PollRepository($this->db),
            $this->feedService,
            $this->tm,
        );

        $userRepository = new UserRepository($this->db);
        $sessionRepository = new UserSessionRepository($this->db);

        $this->authService = new AuthService(
            $userRepository,
            $sessionRepository
        );

        $this->mailService = new MailService(
            $this->config,
            $this->settings,
            $this->tm,
            __DIR__.'/../views/email'
        );

        $this->messageService = new MessageService(
            $this->db,
            new MessageRepository($this->db),
            new ParticipantRepository($this->db),
            new ConversationRepository($this->db),
            $userRepository,
            $sessionRepository,
            new UploadRepository($this->db),
            $this->tm,
        );

        $this->notificationService = new NotificationService(
            $this->messageService,
            new NotificationDeliveryRepository($this->db),
            new NotificationPreferenceRepository($this->db),
            $userRepository,
            $this->mailService,
            $this->tm,
            $this->config,
        );

        $this->userService = new UserService(
            $userRepository,
            $this->db,
            $this->mailService,
            $this->tm,
            $this->notificationService,
            $sessionRepository,
            $this->config,
        );

        $uploadRepository = new UploadRepository($this->db);

        $this->uploadService = new UploadService(
            uploads: $uploadRepository,
            mime: new MimeDetector(),
            storage: new FileStorage($this->config->uploadsDir()),
            images: new ImageProcessor($this->config->tempDir()),
            access: $this->accessService,
            tm: $this->tm,
            settings: $this->settings,
        );

        $this->cronRegistry = new CronRegistry();

        $this->controllerFactory = new ControllerFactory(
            $this->modules,
            $this->db,
            $this->settings,
            $this->widgets,
            $this->feedService,
            $this->authService,
            $this->userService,
            $userRepository,
            $this->accessService,
            $this->termService,
            $this->uploadService,
            $this->pageTree,
            $this->urlGenerator,
            $this->config,
            $this->cache,
            $this->fmt,
            $this->tm,
            $this->messageService,
            $this->notificationService,
            $this->pollService,
        );

        $this->initCronAndApi();
    }

    /**
     * @throws Exception
     */
    public function handleRequest(): void
    {
        $loader = new FilesystemLoader();

        if ($themeDir = $this->config->themeDir()) {
            $loader->addPath($themeDir);
        }

        $loader->addPath(__DIR__ . '/../views/themes/default');
        $loader->addPath(__DIR__ . '/../views/themes/default', 'default');

        foreach ($this->modules->viewsPaths() as $moduleViewsPath) {
            $loader->addPath($moduleViewsPath);
        }

        $twig = new CachedEnvironment($loader, [
            'cache' => $this->config->twigCacheDir(),
            'auto_reload' => $this->config->isDevelopment(),
        ]);

        $twig->addGlobal('locale', $this->tm->getLocale());

        $twig->addFunction(new TwigFunction('trans', [$this->tm, 'trans']));
        $twig->addFunction(new TwigFunction('action_url', [$this->urlGenerator, 'action']));

        $router = new Router($this->pageTree);

        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        $pageData = $router->resolve($path);

        $menuRepository = new MenuRepository($this->db);
        $menuService = new MenuService($this->urlGenerator, $this->pageTree);
        $allMenuItems = $menuRepository->findAll();

        $currentUser = $this->authService->currentUser();
        $currentUser = $currentUser->withAvatarUrl(
            UserService::resolveAvatarUrl($currentUser->avatarUrl)
        );

        if ($currentUser->isGuest()) {
            $this->userService->recordGuestPresence();
        }

        $timezone = $this->authService->getUserTimezone($currentUser);

        // The query string is snapshotted here, once, rather than read from
        // $_GET wherever it happens to be needed - see Core\QueryParams.
        $requestContext = new RequestContext($currentUser, $timezone, QueryParams::fromGlobals());

        $pageContent = [];

        // Get page data for HTML or 404 page
        if (! is_array($pageData) || $pageData['page']->responseType === 'html') {
            $pageContent['topMenu'] = $menuService->build(
                allItems: $allMenuItems,
                group: 'top',
                breadcrumbs: $pageData['breadcrumbs'] ?? [],
                currentUser: $currentUser
            );

            $pageContent['bottomMenu'] = $menuService->build(
                allItems: $allMenuItems,
                group: 'bottom',
                breadcrumbs: $pageData['breadcrumbs'] ?? [],
                currentUser: $currentUser
            );

            if (! $currentUser->isGuest()) {
                $pageContent['userMenu'] = $menuService->build(
                    allItems: $allMenuItems,
                    group: 'user',
                    breadcrumbs: $pageData['breadcrumbs'] ?? [],
                    currentUser: $currentUser
                );
            }

            $pageContent['user'] = $currentUser;
            $pageContent['siteName'] = $this->settings->getString('site_name');
            $pageContent['siteUrl'] = $this->config->siteUrl();
            $pageContent['locale'] = $this->settings->getString('locale');
            $pageContent['widgets'] = $this->widgets->placements();

        }

        // Handle not found case
        if (! is_array($pageData)) {
            $pageContent['title'] = $this->tm->trans('error.page_not_found');
            $this->render404($twig, $pageContent);

            return;
        }

        if (! $this->accessService->canAccessPage($currentUser, $pageData['page'])) {
            if ($pageData['page']->responseType !== 'html') {
                http_response_code(403);
                header('Content-Type: application/json');
                echo Formatter::json(['error' => 'Forbidden']);

                return;
            }

            $this->render404($twig, $pageContent);

            return;
        }

        $thisPageController = $this->controllerFactory->createForPage(
            $pageData['page'],
            $requestContext
        );

        if ($pageData['page']->responseType !== 'html') {
            if (! $pageData['page']->allowsMethod($_SERVER['REQUEST_METHOD'])) {
                http_response_code(405);
                exit;
            }
            try {
                $thisPageController->callApi($pageData['page'], $pageData['params']);
            } catch (Throwable $e) {
                // See Core\ApiErrorResponse: a ValidationException is the
                // handler's own answer and passes through; anything else is a
                // bug, and a bug is a 500 in the log rather than a 403 in the
                // client's face.
                $error = ApiErrorResponse::forThrowable(
                    $e,
                    $this->config->isDevelopment(),
                    $this->tm->trans('error.internal'),
                );

                if ($error->isBug) {
                    error_log(ApiErrorResponse::logLine($e, $_SERVER['REQUEST_METHOD'], (string) $path));
                }

                // A handler that echoed part of its payload before throwing has
                // already sent the headers; the status can no longer be set and
                // trying warns. The body still goes out - a truncated JSON
                // document fails the client's parse, which is the honest
                // outcome and better than silence.
                if (! headers_sent()) {
                    http_response_code($error->status);
                }

                echo Formatter::json($error->body());
                exit;
            }
            return;
        }

        // Call controller
        try {
            $view = $thisPageController->show($pageData['page'], $pageData['params']);
        } catch (Exception $exception) {
            $pageContent['exception'] = $exception->getMessage();
            $pageContent['title'] = $this->tm->trans('error.page_not_found');
            $this->render404($twig, $pageContent);
            return;
        }

        $view->data = array_merge($view->data, $pageContent);

        // addFor() rather than add(): the trail is decoration on a page that
        // has already rendered, so a crumb that throws must not turn a working
        // 200 into a 500. See BreadcrumbsService::addFor().
        $breadcrumbs = new BreadcrumbsService();
        foreach ($pageData['breadcrumbs'] as $page) {
            $breadcrumbs->addFor(
                $page,
                fn (Page $crumbPage): ?Breadcrumb => $this->controllerFactory
                    ->createForPage($crumbPage, $requestContext)
                    ->getBreadcrumb($crumbPage)
            );
        }

        $view->data['breadcrumbs'] = $breadcrumbs->finalize();
        $view->data['runtime'] = sprintf('%.1fms', (microtime(true) - $this->startTime) * 1000);

        // Get page HTML content
        $content = $twig->render($view->template, $view->data);

        // Checking If-Modified-Since
        $watcher = $twig->getWatcher();
        $watcher->forceUpdate($this->settings->lastModified());
        $lastModified = $watcher->getLastModified();

        Security::checkAndCreateCsrfToken();

        // In production CRON_MODE=os keeps scheduling out of the request path.
        // The web mode is a development/compatibility fallback only.
        if (CronTrigger::shouldTrigger(
            $this->config->cronMode(),
            $this->config->appEnvironment(),
            CronTrigger::roll(),
        )) {
            self::triggerCronProcess();
        }

        /*
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $clientTime = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
            if ($lastModified <= $clientTime) {
                http_response_code(304);
                exit;
            }
        }*/

        header('Last-Modified: '.gmdate('D, d M Y H:i:s', $lastModified).' GMT');
        header('Cache-Control: public, max-age=60');
        header('Expires: 0');
        echo $content;
    }

    private function initCronAndApi(): void
    {
        $apiPageId = $this->pageTree->getMaxPageId();
        $this->pageTree->add(
            Page::api(
                id: $apiPageId,
                parentId: 1,
                pattern: 'api',
                requestMethods: ['GET'],
                action: 'api.index',
            )
        );
        $this->controllerFactory->registerAction('api.index', 'API');

        $apiV1PageId = $this->pageTree->getMaxPageId();
        $this->pageTree->add(
            Page::api(
                id: $apiV1PageId,
                parentId: $apiPageId,
                pattern: 'v1',
                requestMethods: ['GET'],
                action: 'api.v1.index',
            )
        );
        $this->controllerFactory->registerAction('api.v1.index', 'API');
        $adminPageId = $this->pageTree->getMaxPageId();
        $this->pageTree->add(
            new Page(
                id: $adminPageId,
                parentId: 1,
                pattern: 'admin',
                pageName: 'Administrator interface',
                settings: null,
                feedType: null,
                listFeedType: null,
                feedId: null,
                commentsEnabled: false,
                requestMethods: ['GET'],
                responseType: 'html',
                accessRule: AccessService::ACCESS_ADMIN,
                action: 'admin.index',
            )
        );

        // Actions the pages already carry (DB pages + the hardcoded ones above)
        // are handled via pageActions()/explicit registration; everything a controller
        // adds in registerApi() below is an API action owned by that controller.
        $seenIds = array_fill_keys(array_map(static fn (Page $p) => $p->id, $this->pageTree->all()), true);

        foreach ($this->modules->entries() as ['id' => $id, 'controllerClass' => $controllerClass]) {
            if (! is_subclass_of($controllerClass, ControllerInterface::class)) {
                continue;
            }
            $controllerClass::registerApi($apiV1PageId, $this->pageTree);

            foreach ($this->pageTree->all() as $page) {
                if (isset($seenIds[$page->id])) {
                    continue;
                }
                $seenIds[$page->id] = true;
                if ($page->action !== null) {
                    $this->controllerFactory->registerAction($page->action, $id);
                }
            }

            $controllerClass::registerCron($this->cronRegistry);
        }
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     */
    private function render404(CachedEnvironment $twig, array $pageContent = []): void
    {
        http_response_code(404);
        echo $twig->render('404.twig', $pageContent);
    }

    /* Doesn't lock process */
    /**
     * Static entry point for requesting a background cron run.
     */
    public static function triggerCronProcess(): void
    {
        (new CronTrigger())->spawn();
    }

    /**
     * @throws Exception
     */
    public function runCron(): void
    {
        $context = new RequestContext(
            $this->authService->guest(),
            new DateTimeZone('UTC')
        );

        $cronRepository = new CronRepository($this->db);
        $cronRunner = new CronRunner(
            $this->cronRegistry,
            $cronRepository,
            $this->controllerFactory,
            $context
        );
        $cronRunner->run();
    }
}

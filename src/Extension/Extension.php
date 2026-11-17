<?php

declare(strict_types=1);

namespace StreamEngine\Extension;

use StreamEngine\Core\Cache;
use StreamEngine\Core\Config;
use StreamEngine\Core\Cron\CronRegistry;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\AuthService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\MentionService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\PollService;
use StreamEngine\Service\SettingsService;
use StreamEngine\Service\TermService;
use StreamEngine\Service\ThemeService;
use StreamEngine\Service\UploadService;
use StreamEngine\Service\UserService;
use StreamEngine\Service\WidgetService;

/**
 * Base class for site code that changes the behavior of platform operations.
 *
 * A subclass defines public methods named after the extension points it
 * handles. The available points are listed in the Extensions wiki page.
 *
 * Subclasses are discovered from the Composer classmap and constructed on the
 * first call of any extension point. The shared services below are available
 * as properties; they are the services module controllers can receive, named
 * as in StreamEngine. RequestContext is not available: data a point needs
 * from the request is passed as arguments.
 *
 * A subclass normally declares no constructor. One that does must call
 * parent::__construct() with every argument, or the properties stay unset.
 *
 * A method returns null when it does not want to change the outcome. The
 * first non-null result among all extensions wins.
 */
abstract class Extension
{
    public function __construct(
        protected readonly PdoDatabase $db,
        protected readonly Config $config,
        protected readonly Cache $cache,
        protected readonly SettingsService $settings,
        protected readonly TranslationManager $tm,
        protected readonly Formatter $fmt,
        protected readonly ModuleRegistry $modules,
        protected readonly PageTree $pageTree,
        protected readonly UrlGenerator $urlGenerator,
        protected readonly AccessService $accessService,
        protected readonly AuthService $authService,
        protected readonly UserService $userService,
        protected readonly UserRepository $userRepository,
        protected readonly FeedService $feedService,
        protected readonly TermService $termService,
        protected readonly PollService $pollService,
        protected readonly UploadService $uploadService,
        protected readonly MessageService $messageService,
        protected readonly NotificationService $notificationService,
        protected readonly MentionService $mentionService,
        protected readonly ThemeService $themeService,
        protected readonly WidgetService $widgets,
        protected readonly CronRegistry $cronRegistry,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use StreamEngine\Core\Cron\CronRegistry;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Domain\Page;
use StreamEngine\View\Breadcrumb;
use StreamEngine\View\ViewModel;

abstract class AbstractController implements ControllerInterface
{
    public function __construct(
        protected PdoDatabase $db,
        protected RequestContext $context
    ) {

    }

    //TODO No need for this function actually, maybe for admin UI only
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
        return new Breadcrumb($page->pageName, $page->pattern);
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

    protected function requireAuthenticatedUser(): void
    {
        if ($this->context->user->isGuest()) {
            throw new ForbiddenException('Forbidden');
        }
    }

    public static function registerCron(CronRegistry $cron): void
    {
    }

    public function runCron(string $task): void
    {
    }
}

<?php

declare(strict_types=1);

namespace StreamEngine\Core;

use StreamEngine\Core\Cron\CronRegistry;
use StreamEngine\Domain\Page;
use StreamEngine\View\Breadcrumb;
use StreamEngine\View\ViewModel;

interface ControllerInterface
{
    /**
     * Each action may use the legacy display-label shorthand or a descriptor
     * consumed by ModuleRegistry and the pages editor.
     *
     * @return array<string, string|array{
     *     label: string,
     *     fields?: array<string, string|array{status: string, values?: list<string>, feedTypes?: list<string>}>,
     *     requirements?: list<array{oneOf: list<string>}>
     * }>
     */
    public static function pageActions(): array;

    /**
     * @return array<string, string> feed type => display label
     */
    public static function feedTypes(): array;

    public function getBreadcrumb(Page $page): ?Breadcrumb;

    public function show(Page $page, array $args = []): ?ViewModel;

    public static function registerApi(int $apiPageId, PageTree $pageTree): void;

    public function callApi(Page $page, array $args = []): void;

    public static function registerCron(CronRegistry $cron): void;

    public function runCron(string $task): void;
}

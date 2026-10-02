<?php

declare(strict_types=1);

namespace StreamEngine\Core;

/**
 * Optional module capability for generic administration dashboard cards.
 * Definitions are static so ModuleRegistry can discover them without building
 * request-scoped controllers. Providers receive the two dependencies needed
 * for authorization-aware reads when the dashboard endpoint resolves data.
 */
interface DashboardCardProviderInterface
{
    /**
     * @return list<array{
     *     id:string,
     *     label:string,
     *     kind:string,
     *     permission:string,
     *     sizes:list<string>,
     *     defaultSize:string,
     *     defaultPosition:int
     * }>
     */
    public static function dashboardCards(): array;

    /**
     * @return array{status:string,data:mixed}
     */
    public static function dashboardCardData(string $cardId, PdoDatabase $db, RequestContext $context): array;
}

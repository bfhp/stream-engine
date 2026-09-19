<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Admin;

use StreamEngine\Core\AbstractController;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\Security;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Page;
use StreamEngine\Repository\PageRepository;
use StreamEngine\Repository\MenuRepository;
use StreamEngine\Repository\SettingsRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\View\ViewModel;

class AdminController extends AbstractController
{
    public static function pageActions(): array
    {
        return [
            'admin.index' => 'Administrator interface',
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

    private readonly PageRepository $pageRepository;
    private readonly MenuRepository $menuRepository;
    private readonly SettingsRepository $settingsRepository;

    public function __construct(
        PdoDatabase $db,
        RequestContext $context,
        private readonly AccessService $accessService,
        private readonly TranslationManager $tm,
        private readonly ModuleRegistry $modules,
    ) {
        parent::__construct($db, $context);
        $this->pageRepository = new PageRepository($db);
        $this->menuRepository = new MenuRepository($db);
        $this->settingsRepository = new SettingsRepository($db);
    }

    /**
     * @throws ForbiddenException
     */
    public function show(Page $page, array $args = []): ?ViewModel
    {
        if (! $this->accessService->isAdmin($this->context->user)) {
            throw new ForbiddenException('Forbidden');
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
            throw new ForbiddenException('Forbidden');
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
            case 'admin.settings':
                $this->handleSettingsRequest();
                break;
            case 'admin.setting':
                $this->handleSettingRequest((string) ($args['key'] ?? ''));
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

        $menusPageId = $pageTree->getMaxPageId();
        $pageTree->add(
            Page::api(
                id: $menusPageId,
                parentId: $adminApiPageId,
                pattern: 'menus',
                requestMethods: ['GET', 'POST'],
                action: 'admin.menus',
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
            throw new NotFoundException('Page not found');
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
            throw new NotFoundException('Page not found');
        }

        echo Formatter::json($page);
    }

    private function handlePageActionsRequest(): void
    {
        echo Formatter::json(['data' => $this->modules->pageActions()]);
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

        echo Formatter::json(['data' => $this->menuRepository->findAllForAdmin()]);
    }

    /**
     * @throws NotFoundException
     * @throws ValidationException
     */
    private function handleMenuRequest(int $id): void
    {
        if ($id <= 0) {
            throw new NotFoundException('Menu item not found');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
            Security::verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null, $this->tm);

            if ($this->menuRepository->findForAdminById($id) === null) {
                throw new NotFoundException('Menu item not found');
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
                throw new NotFoundException('Menu item not found');
            }

            if ($this->menuRepository->hasChildren($id)) {
                throw new ValidationException('Move or delete child menu items first');
            }

            $this->menuRepository->delete($id);
            echo Formatter::json(['deleted' => true]);

            return;
        }

        $item = $this->menuRepository->findForAdminById($id);

        if ($item === null) {
            throw new NotFoundException('Menu item not found');
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

            echo Formatter::json($this->settingsRepository->setForAdmin($key, $value));

            return;
        }

        echo Formatter::json(['data' => $this->settingsRepository->findAllForAdmin()]);
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

            echo Formatter::json($this->settingsRepository->setForAdmin($key, $value));

            return;
        }

        $setting = $this->settingsRepository->findForAdminByKey($key);

        if ($setting === null) {
            throw new NotFoundException('Setting not found');
        }

        echo Formatter::json($setting);
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
            throw new ValidationException('Action is required');
        }

        if ($settings !== '') {
            json_decode($settings, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ValidationException('Settings must be valid JSON');
            }
        }

        if ($changefreq !== '' && ! in_array($changefreq, self::CHANGEFREQ_VALUES, true)) {
            throw new ValidationException('Invalid changefreq');
        }

        $accessRule = $input['accessRule'] ?? null;
        if (! is_string($accessRule) || ! in_array($accessRule, AccessService::ACCESS_RULES, true)) {
            throw new ValidationException('Invalid access rule');
        }

        $data = [
            'parentId' => $this->optionalInt($input['parentId'] ?? null),
            'pattern' => $pattern,
            'action' => $action,
            'pageName' => $this->optionalString($input['pageName'] ?? null),
            'settings' => $settings !== '' ? $settings : null,
            'feedType' => $this->optionalString($input['feedType'] ?? null),
            'listFeedType' => $this->optionalString($input['listFeedType'] ?? null),
            'termVocabulary' => $this->optionalString($input['termVocabulary'] ?? null),
            'feedId' => $this->optionalPositiveInt($input['feedId'] ?? null, 'Feed ID must be a positive integer'),
            'changefreq' => $changefreq !== '' ? $changefreq : null,
            'accessRule' => $accessRule,
        ];

        $this->validatePageActionContract($data, $existing);

        return $data;
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
                throw new ValidationException("Unknown page action '$action'");
            }

            foreach (ModuleRegistry::PAGE_ACTION_FIELDS as $field) {
                $existingValue = $field === 'feedId'
                    ? $this->optionalInt($existing[$field] ?? null)
                    : $this->optionalString($existing[$field] ?? null);
                if ($data[$field] !== $existingValue) {
                    throw new ValidationException(
                        "Configuration fields of unknown page action '$action' cannot be changed"
                    );
                }
            }

            return;
        }

        foreach ($descriptor['fields'] as $field => $fieldDescriptor) {
            $value = $data[$field];
            $hasValue = $value !== null;
            $label = $this->pageActionFieldLabel($field);

            if ($fieldDescriptor['status'] === 'unsupported' && $hasValue) {
                throw new ValidationException("$label is not supported by page action '$action'");
            }
            if ($fieldDescriptor['status'] === 'required' && ! $hasValue) {
                throw new ValidationException("$label is required for page action '$action'");
            }
            if ($hasValue
                && isset($fieldDescriptor['values'])
                && ! in_array($value, $fieldDescriptor['values'], true)) {
                throw new ValidationException("Invalid $label for page action '$action'");
            }
            if ($hasValue && $field === 'feedId' && $fieldDescriptor['status'] !== 'unsupported') {
                $feed = $this->db->fetchOne('SELECT type FROM feeds WHERE id = ?', [$value]);
                if ($feed === null) {
                    throw new ValidationException("Feed ID $value does not exist");
                }
                if (isset($fieldDescriptor['feedTypes'])
                    && ! in_array($feed['type'] ?? null, $fieldDescriptor['feedTypes'], true)) {
                    throw new ValidationException("Feed ID $value has an invalid type for page action '$action'");
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
                    sprintf(
                        "At least one of %s is required for page action '%s'",
                        implode(', ', $labels),
                        $action
                    )
                );
            }
        }
    }

    private function pageActionFieldLabel(string $field): string
    {
        return match ($field) {
            'feedId' => 'Feed ID',
            'feedType' => 'Feed type',
            'listFeedType' => 'List feed type',
            'termVocabulary' => 'Term vocabulary',
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
        $parentId = $this->optionalPositiveInt($input['parentId'] ?? null, 'Invalid parent');
        $pageId = $this->optionalPositiveInt($input['pageId'] ?? null, 'Invalid page');

        if ($menuGroup === '' || strlen($menuGroup) > 100) {
            throw new ValidationException('Menu group is required and must not exceed 100 characters');
        }

        if (! in_array($type, self::MENU_TYPES, true)) {
            throw new ValidationException('Invalid menu item type');
        }

        $accessRule = $input['accessRule'] ?? null;
        if (! is_string($accessRule) || ! in_array($accessRule, AccessService::ACCESS_RULES, true)) {
            throw new ValidationException('Invalid access rule');
        }

        $sortOrder = $input['sortOrder'] ?? null;
        if (! is_int($sortOrder) || $sortOrder < 0) {
            throw new ValidationException('Sort order must be a non-negative integer');
        }

        if ($label !== null && strlen($label) > 150) {
            throw new ValidationException('Label must not exceed 150 characters');
        }

        if ($url !== null && strlen($url) > 255) {
            throw new ValidationException('URL must not exceed 255 characters');
        }

        if ($action !== null && strlen($action) > 100) {
            throw new ValidationException('Action must not exceed 100 characters');
        }

        if (in_array($type, ['internal', 'dynamic'], true) && $pageId === null) {
            throw new ValidationException('Page is required for internal and dynamic menu items');
        }

        if ($type === 'external' && $url === null) {
            throw new ValidationException('URL is required for external menu items');
        }

        if ($type === 'action' && $action === null) {
            throw new ValidationException('Action is required for action menu items');
        }

        if ($type !== 'divider' && $label === null) {
            throw new ValidationException('Label is required');
        }

        if ($currentId !== null && $parentId === $currentId) {
            throw new ValidationException('A menu item cannot be its own parent');
        }

        $items = $this->menuRepository->findAllForAdmin();
        $byId = [];
        foreach ($items as $item) {
            $byId[$item['id']] = $item;

            if (
                $item['id'] !== $currentId
                && $item['menuGroup'] === $menuGroup
                && $item['parentId'] === $parentId
                && $item['sortOrder'] === $sortOrder
            ) {
                throw new ValidationException('Another item at this level already uses the same sort order');
            }
        }

        if ($parentId !== null) {
            $parent = $byId[$parentId] ?? null;
            if ($parent === null) {
                throw new ValidationException('Parent menu item not found');
            }
            if ($parent['menuGroup'] !== $menuGroup) {
                throw new ValidationException('Parent must belong to the same menu group');
            }

            $ancestorId = $parentId;
            $visited = [];
            while ($ancestorId !== null && ! isset($visited[$ancestorId])) {
                if ($ancestorId === $currentId) {
                    throw new ValidationException('A menu item cannot be moved below its descendant');
                }
                $visited[$ancestorId] = true;
                $ancestorId = $byId[$ancestorId]['parentId'] ?? null;
            }
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
        ];
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
        if ($key === '' || strlen($key) > 100 || ! preg_match('/\A[A-Za-z0-9_.-]+\z/', $key)) {
            throw new ValidationException('Setting key must contain only letters, numbers, dots, dashes and underscores');
        }
    }
}

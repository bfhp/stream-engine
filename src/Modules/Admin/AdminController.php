<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Admin;

use StreamEngine\Core\AbstractController;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\Security;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Page;
use StreamEngine\Repository\PageRepository;
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

    private readonly PageRepository $pageRepository;
    private readonly SettingsRepository $settingsRepository;

    public function __construct(
        PdoDatabase $db,
        RequestContext $context,
        private readonly AccessService $accessService,
        private readonly TranslationManager $tm,
    ) {
        parent::__construct($db, $context);
        $this->pageRepository = new PageRepository($db);
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

            echo Formatter::json($this->pageRepository->updateFromAdminData(
                $id,
                $this->validatedPageData($this->jsonBody())
            ));

            return;
        }

        $page = $this->pageRepository->findForAdminById($id);

        if ($page === null) {
            throw new NotFoundException('Page not found');
        }

        echo Formatter::json($page);
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
    private function validatedPageData(array $input): array
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

        return [
            'parentId' => $this->optionalInt($input['parentId'] ?? null),
            'pattern' => $pattern,
            'action' => $action,
            'pageName' => $this->optionalString($input['pageName'] ?? null),
            'settings' => $settings !== '' ? $settings : null,
            'feedType' => $this->optionalString($input['feedType'] ?? null),
            'listFeedType' => $this->optionalString($input['listFeedType'] ?? null),
            'termVocabulary' => $this->optionalString($input['termVocabulary'] ?? null),
            'feedId' => $this->optionalInt($input['feedId'] ?? null),
            'changefreq' => $changefreq !== '' ? $changefreq : null,
            'updated' => $this->optionalInt($input['updated'] ?? null) ?? time(),
            'accessRule' => $accessRule,
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

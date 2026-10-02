<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use StreamEngine\Core\DashboardCardProviderInterface;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\ModuleRegistry;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\User;
use StreamEngine\Repository\DashboardLayoutRepository;
use RuntimeException;
use Throwable;

final readonly class AdminDashboardService
{
    public const int LAYOUT_VERSION = 1;

    public function __construct(
        private DashboardLayoutRepository $layouts,
        private ModuleRegistry $modules,
        private PdoDatabase $db,
        private ?TranslationManager $tm = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function payload(RequestContext $context): array
    {
        $catalog = $this->permittedCatalog($context->user);
        $stored = $this->layouts->find($context->user->id);
        $layout = $this->effectiveLayout($stored, $catalog);

        return [
            'version' => self::LAYOUT_VERSION,
            'catalog' => array_map($this->publicDescriptor(...), $catalog),
            'layout' => $layout,
            'cards' => $this->resolveCards($layout, $catalog, $context),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function save(RequestContext $context, array $input): array
    {
        $catalog = $this->permittedCatalog($context->user);
        $layout = $this->validateLayout($input, $catalog);
        $this->layouts->save($context->user->id, self::LAYOUT_VERSION, $layout['items']);

        return $this->payload($context);
    }

    /** @return array<string,mixed> */
    public function reset(RequestContext $context): array
    {
        $this->layouts->delete($context->user->id);

        return $this->payload($context);
    }

    /** @return array{id:string,status:string,data:mixed} */
    public function card(string $id, RequestContext $context): array
    {
        $catalog = $this->permittedCatalog($context->user);
        $descriptor = null;
        foreach ($catalog as $candidate) {
            if ($candidate['id'] === $id) {
                $descriptor = $candidate;
                break;
            }
        }
        if ($descriptor === null) {
            // Unknown and unauthorized cards deliberately look identical.
            throw new NotFoundException($this->message('admin.error.dashboard_card_not_found', 'Dashboard card not found'));
        }

        return $this->resolveCards(
            ['items' => [['id' => $id, 'size' => $descriptor['defaultSize'], 'position' => 0]]],
            [$descriptor],
            $context,
        )[0];
    }

    /** @return list<array<string,mixed>> */
    private function permittedCatalog(User $user): array
    {
        return array_values(array_filter(
            $this->modules->dashboardCards(),
            static fn (array $card): bool => AccessService::allows($user, $card['permission']),
        ));
    }

    /**
     * Stored layouts are untrusted and may be from an older module set. Bad,
     * missing, or no-longer-permitted entries disappear; an obsolete layout
     * version safely falls back to the current default.
     *
     * @param array{version:int,items:list<array<string,mixed>>}|null $stored
     * @param list<array<string,mixed>> $catalog
     * @return array{version:int,items:list<array{id:string,size:string,position:int}>}
     */
    private function effectiveLayout(?array $stored, array $catalog): array
    {
        if ($stored === null || $stored['version'] !== self::LAYOUT_VERSION) {
            return $this->defaultLayout($catalog);
        }

        $byId = [];
        foreach ($catalog as $card) {
            $byId[$card['id']] = $card;
        }
        $items = [];
        $seen = [];
        foreach ($stored['items'] as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;
            $size = is_array($item) ? ($item['size'] ?? null) : null;
            $position = is_array($item) ? ($item['position'] ?? null) : null;
            if (! is_string($id) || isset($seen[$id]) || ! isset($byId[$id])) {
                continue;
            }
            if (! is_string($size) || ! in_array($size, $byId[$id]['sizes'], true)) {
                $size = $byId[$id]['defaultSize'];
            }
            if (! is_int($position) || $position < 0) {
                $position = $byId[$id]['defaultPosition'];
            }
            $seen[$id] = true;
            $items[] = compact('id', 'size', 'position');
        }
        usort($items, static fn (array $a, array $b): int => [$a['position'], $a['id']] <=> [$b['position'], $b['id']]);

        return ['version' => self::LAYOUT_VERSION, 'items' => $items];
    }

    /** @param list<array<string,mixed>> $catalog @return array{version:int,items:list<array{id:string,size:string,position:int}>} */
    private function defaultLayout(array $catalog): array
    {
        $items = array_map(
            static fn (array $card): array => [
                'id' => $card['id'],
                'size' => $card['defaultSize'],
                'position' => $card['defaultPosition'],
            ],
            $catalog,
        );
        usort($items, static fn (array $a, array $b): int => [$a['position'], $a['id']] <=> [$b['position'], $b['id']]);

        return ['version' => self::LAYOUT_VERSION, 'items' => $items];
    }

    /** @param array<string,mixed> $input @param list<array<string,mixed>> $catalog */
    private function validateLayout(array $input, array $catalog): array
    {
        if (($input['version'] ?? null) !== self::LAYOUT_VERSION || ! is_array($input['items'] ?? null)) {
            throw new ValidationException($this->message('admin.error.dashboard_layout', 'Invalid dashboard layout version or items'));
        }
        $byId = [];
        foreach ($catalog as $card) {
            $byId[$card['id']] = $card;
        }
        $items = [];
        $seenIds = [];
        $seenPositions = [];
        foreach ($input['items'] as $item) {
            if (! is_array($item) || array_diff(array_keys($item), ['id', 'size', 'position']) !== []) {
                throw new ValidationException($this->message('admin.error.dashboard_item', 'Invalid dashboard layout item'));
            }
            $id = $item['id'] ?? null;
            $size = $item['size'] ?? null;
            $position = $item['position'] ?? null;
            if (! is_string($id) || ! isset($byId[$id]) || isset($seenIds[$id])) {
                throw new ValidationException($this->message('admin.error.dashboard_unknown_card', 'Unknown or duplicate dashboard card'));
            }
            if (! is_string($size) || ! in_array($size, $byId[$id]['sizes'], true)) {
                throw new ValidationException($this->message('admin.error.dashboard_size', "Invalid size for dashboard card '$id'", ['id' => $id]));
            }
            if (! is_int($position) || $position < 0 || isset($seenPositions[$position])) {
                throw new ValidationException($this->message('admin.error.dashboard_positions', 'Dashboard card positions must be unique non-negative integers'));
            }
            $seenIds[$id] = true;
            $seenPositions[$position] = true;
            $items[] = compact('id', 'size', 'position');
        }
        usort($items, static fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return ['version' => self::LAYOUT_VERSION, 'items' => $items];
    }

    /** @param array<string,mixed> $card @return array<string,mixed> */
    private function publicDescriptor(array $card): array
    {
        unset($card['provider'], $card['permission']);

        return $card;
    }

    /** @param array{items:list<array{id:string,size:string,position:int}>} $layout @param list<array<string,mixed>> $catalog */
    private function resolveCards(array $layout, array $catalog, RequestContext $context): array
    {
        $byId = [];
        foreach ($catalog as $card) {
            $byId[$card['id']] = $card;
        }
        $resolved = [];
        foreach ($layout['items'] as $item) {
            $descriptor = $byId[$item['id']];
            /** @var class-string<DashboardCardProviderInterface> $provider */
            $provider = $descriptor['provider'];
            try {
                $result = $provider::dashboardCardData($item['id'], $this->db, $context);
                $status = $result['status'] ?? null;
                if (! in_array($status, ['ready', 'empty', 'unavailable'], true)
                    || ! array_key_exists('data', $result)) {
                    throw new RuntimeException('Invalid dashboard card result');
                }
                $this->validateCardData($descriptor, $status, $result['data']);
                $resolved[] = ['id' => $item['id'], 'status' => $status, 'data' => $result['data']];
            } catch (Throwable) {
                $resolved[] = ['id' => $item['id'], 'status' => 'error', 'data' => null];
            }
        }

        return $resolved;
    }

    /** @param array<string,mixed> $descriptor */
    private function validateCardData(array $descriptor, string $status, mixed $data): void
    {
        if ($status !== 'ready') {
            return;
        }
        if (! is_array($data) || ! array_is_list($data) || $data === []) {
            throw new RuntimeException("Dashboard card '{$descriptor['id']}' returned invalid data");
        }

        foreach ($data as $item) {
            if (! is_array($item)) {
                throw new RuntimeException("Dashboard card '{$descriptor['id']}' returned an invalid item");
            }
            match ($descriptor['kind']) {
                'metrics' => $this->validateMetric($item),
                'links' => $this->validateLink($item),
                'list' => $this->validateListItem($item),
                default => throw new RuntimeException('Unknown dashboard card kind'),
            };
        }
    }

    /** @param array<string,mixed> $item */
    private function validateMetric(array $item): void
    {
        $this->requireKeys($item, ['label', 'value'], ['format', 'href']);
        if (! is_string($item['label']) || trim($item['label']) === ''
            || ! is_string($item['value']) && ! is_int($item['value']) && ! is_float($item['value'])
            || isset($item['format']) && $item['format'] !== 'timestamp'
            || isset($item['href']) && ! $this->isSafeAdminHref($item['href'])) {
            throw new RuntimeException('Invalid dashboard metric');
        }
    }

    /** @param array<string,mixed> $item */
    private function validateLink(array $item): void
    {
        $this->requireKeys($item, ['label', 'href']);
        if (! is_string($item['label']) || trim($item['label']) === '' || ! $this->isSafeAdminHref($item['href'])) {
            throw new RuntimeException('Invalid dashboard link');
        }
    }

    /** @param array<string,mixed> $item */
    private function validateListItem(array $item): void
    {
        $this->requireKeys($item, ['id', 'label'], ['description', 'timestamp', 'href']);
        if (! is_string($item['id']) || trim($item['id']) === ''
            || ! is_string($item['label']) || trim($item['label']) === ''
            || isset($item['description']) && ! is_string($item['description'])
            || isset($item['timestamp']) && (! is_int($item['timestamp']) || $item['timestamp'] < 0)
            || isset($item['href']) && ! $this->isSafeAdminHref($item['href'])) {
            throw new RuntimeException('Invalid dashboard list item');
        }
    }

    /** @param array<string,mixed> $item @param list<string> $required @param list<string> $optional */
    private function requireKeys(array $item, array $required, array $optional = []): void
    {
        if (array_diff($required, array_keys($item)) !== []
            || array_diff(array_keys($item), [...$required, ...$optional]) !== []) {
            throw new RuntimeException('Invalid dashboard card item fields');
        }
    }

    private function isSafeAdminHref(mixed $href): bool
    {
        return is_string($href)
            && preg_match('/\A#\/[a-z0-9][a-z0-9\/._?=&%-]*\z/D', $href) === 1;
    }

    /** @param array<string, scalar> $params */
    private function message(string $key, string $fallback, array $params = []): string
    {
        return $this->tm?->trans($key, $params) ?? $fallback;
    }
}

<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\MenuItem;

/**
 * MenuRepository
 *
 * Persists menu rows. Filtering and tree construction remain in MenuService.
 */
final readonly class MenuRepository
{
    private const array ADMIN_COLUMNS = [
        'id',
        'parent',
        'menu_group',
        'type',
        'page_id',
        'url',
        'action',
        'label',
        'access_rule',
        'sort_order',
        'group_order',
        'enabled',
    ];

    public function __construct(
        private PdoDatabase $db
    ) {
    }

    /**
     * Load all menu items from database.
     * Uses index: menu_group_order_index (group_order, menu_group, sort_order, id)
     *
     * @return list<MenuItem>
     */
    public function findAll(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM menu ORDER BY group_order, sort_order, id');
        return array_map([MenuItem::class, 'fromRow'], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllForAdmin(): array
    {
        return array_map(
            $this->mapAdminRow(...),
            $this->db->fetchAll(
                'SELECT '.implode(', ', self::ADMIN_COLUMNS).' FROM menu ORDER BY group_order, menu_group, parent, sort_order, id'
            )
        );
    }

    public function findForAdminById(int $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT '.implode(', ', self::ADMIN_COLUMNS).' FROM menu WHERE id = ?',
            [$id]
        );

        return $row !== null ? $this->mapAdminRow($row) : null;
    }

    /** @param array<string, mixed> $data */
    public function createFromAdminData(array $data): array
    {
        $this->db->execute(
            'INSERT INTO menu (
                parent, menu_group, type, page_id, url,
                action, label, access_rule, sort_order, group_order, enabled
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $this->adminParams($data)
        );

        $id = $this->db->lastInsertId();

        return $this->findForAdminById($id) ?? ['id' => $id] + $data;
    }

    /** @param array<string, mixed> $data */
    public function updateFromAdminData(int $id, array $data): array
    {
        $this->db->execute(
            'UPDATE menu SET
                parent = ?,
                menu_group = ?,
                type = ?,
                page_id = ?,
                url = ?,
                action = ?,
                label = ?,
                access_rule = ?,
                sort_order = ?,
                group_order = ?,
                enabled = ?
            WHERE id = ?',
            [...$this->adminParams($data), $id]
        );

        return $this->findForAdminById($id) ?? ['id' => $id] + $data;
    }

    public function hasChildren(int $id): bool
    {
        return $this->db->fetchOne('SELECT id FROM menu WHERE parent = ? LIMIT 1', [$id]) !== null;
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM menu WHERE id = ?', [$id]);
    }

    /**
     * Persist the complete menu forest in one transaction. Moving every row to
     * a temporary unique order first avoids transient unique-key collisions.
     *
     * @param list<array{id:int,parentId:?int,menuGroup:string,sortOrder:int,groupOrder:int}> $items
     */
    public function reorder(array $items): void
    {
        $this->db->begin();

        try {
            foreach ($items as $item) {
                $this->db->execute(
                    'UPDATE menu SET menu_group = ?, parent = NULL, sort_order = 0 WHERE id = ?',
                    ['__menu_reorder__'.$item['id'], $item['id']]
                );
            }
            foreach ($items as $item) {
                $this->db->execute(
                    'UPDATE menu SET parent = ?, menu_group = ?, sort_order = ?, group_order = ? WHERE id = ?',
                    [$item['parentId'], $item['menuGroup'], $item['sortOrder'], $item['groupOrder'], $item['id']]
                );
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    public function deleteWithChildren(int $id, string $strategy): void
    {
        $items = $this->findAllForAdmin();
        $byId = array_column($items, null, 'id');
        $item = $byId[$id] ?? null;
        if ($item === null) {
            return;
        }

        $this->db->begin();
        try {
            if ($strategy === 'promote') {
                $this->db->execute(
                    'UPDATE menu SET parent = ?, sort_order = sort_order + ? WHERE parent = ?',
                    [$item['parentId'], 1_000_000_000, $id]
                );
                $this->db->execute('DELETE FROM menu WHERE id = ?', [$id]);
            } else {
                $ids = [$id];
                for ($index = 0; $index < count($ids); $index++) {
                    foreach ($items as $candidate) {
                        if ($candidate['parentId'] === $ids[$index] && ! in_array($candidate['id'], $ids, true)) {
                            $ids[] = $candidate['id'];
                        }
                    }
                }
                $placeholders = implode(', ', array_fill(0, count($ids), '?'));
                $this->db->execute("DELETE FROM menu WHERE id IN ($placeholders)", $ids);
            }

            $this->normalizeOrders();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function normalizeOrders(): void
    {
        $rows = $this->findAllForAdmin();
        $positions = [];
        foreach ($rows as $row) {
            $key = $row['menuGroup'].'/'.($row['parentId'] ?? 'root');
            $positions[$key] = ($positions[$key] ?? 0) + 10;
            $this->db->execute(
                'UPDATE menu SET sort_order = ? WHERE id = ?',
                [$positions[$key], $row['id']]
            );
        }
    }

    /** @param array<string, mixed> $row */
    private function mapAdminRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'parentId' => $row['parent'] !== null ? (int) $row['parent'] : null,
            'menuGroup' => $row['menu_group'],
            'type' => $row['type'],
            'pageId' => $row['page_id'] !== null ? (int) $row['page_id'] : null,
            'url' => $row['url'],
            'action' => $row['action'],
            'label' => $row['label'],
            'accessRule' => $row['access_rule'],
            'sortOrder' => (int) $row['sort_order'],
            'groupOrder' => (int) ($row['group_order'] ?? 0),
            'enabled' => (bool) ($row['enabled'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<mixed>
     */
    private function adminParams(array $data): array
    {
        return [
            $data['parentId'],
            $data['menuGroup'],
            $data['type'],
            $data['pageId'],
            $data['url'],
            $data['action'],
            $data['label'],
            $data['accessRule'],
            $data['sortOrder'],
            $data['groupOrder'],
            $data['enabled'] ? 1 : 0,
        ];
    }
}

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
    ];

    public function __construct(
        private PdoDatabase $db
    ) {
    }

    /**
     * Load all menu items from database.
     * Uses index: menu_sort_order_id_index (sort_order, id)
     *
     * @return list<MenuItem>
     */
    public function findAll(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM menu ORDER BY sort_order');
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
                'SELECT '.implode(', ', self::ADMIN_COLUMNS).' FROM menu ORDER BY menu_group, parent, sort_order, id'
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
                action, label, access_rule, sort_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                sort_order = ?
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
        ];
    }
}

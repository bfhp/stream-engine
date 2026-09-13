<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\MenuItem;

/**
 * MenuRepository
 *
 * Responsible only for reading menu data from database.
 * No filtering, no tree logic.
 */
final readonly class MenuRepository
{
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
        $rows = $this->db->fetchAll('SELECT * FROM menu  ORDER BY sort_order');
        return array_map([MenuItem::class, 'fromRow'], $rows);
    }
}

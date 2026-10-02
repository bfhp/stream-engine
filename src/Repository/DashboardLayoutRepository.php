<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use JsonException;
use StreamEngine\Core\PdoDatabase;

final readonly class DashboardLayoutRepository
{
    public function __construct(private PdoDatabase $db)
    {
    }

    /** @return array{version:int,items:list<array<string,mixed>>}|null */
    public function find(int $userId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT layout_version, layout_json FROM admin_dashboard_layouts WHERE user_id = ?',
            [$userId],
        );
        if ($row === null) {
            return null;
        }

        try {
            $items = json_decode((string) $row['layout_json'], true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($items) ? [
            'version' => (int) $row['layout_version'],
            'items' => array_values($items),
        ] : null;
    }

    /** @param list<array{id:string,size:string,position:int}> $items */
    public function save(int $userId, int $version, array $items): void
    {
        $json = json_encode($items, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->db->execute(
            "INSERT INTO admin_dashboard_layouts (user_id, layout_version, layout_json, updated_at)
             VALUES (?, ?, ?, UNIX_TIMESTAMP())
             ON DUPLICATE KEY UPDATE layout_version = ?, layout_json = ?, updated_at = UNIX_TIMESTAMP()",
            [$userId, $version, $json, $version, $json],
        );
    }

    public function delete(int $userId): void
    {
        $this->db->execute('DELETE FROM admin_dashboard_layouts WHERE user_id = ?', [$userId]);
    }
}

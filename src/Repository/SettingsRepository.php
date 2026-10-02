<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;

final readonly class SettingsRepository
{
    public function __construct(
        private PdoDatabase $db
    ) {
    }

    /**
     * Warning: no index; intentional full table load for small key-value settings cache.
     *
     * @return array{data: array<string, string>, lastModified: int}
     */
    public function load(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT setting_key, setting_value, updated_at FROM settings"
        );

        $data = [];
        $lastModified = 0;

        foreach ($rows as $row) {
            $data[$row['setting_key']] = $row['setting_value'];
            $lastModified = max($lastModified, (int)$row['updated_at']);
        }

        return [
            'data' => $data,
            'lastModified' => $lastModified,
        ];
    }

    /**
     * Uses index: PRIMARY(setting_key)
     */
    public function set(string $key, string $value): void
    {
        $this->db->execute(
            "INSERT INTO settings (setting_key, setting_value, updated_at)
             VALUES (?, ?, UNIX_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                 setting_value = ?,
                 updated_at = UNIX_TIMESTAMP()",
            [$key, $value, $value]
        );
    }

    /** @param array<string, string> $settings */
    public function setMany(array $settings): void
    {
        $this->db->begin();
        try {
            foreach ($settings as $key => $value) {
                $this->set($key, $value);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    /**
     * @return list<array{key: string, value: string, updatedAt: int}>
     */
    public function findAllForAdmin(): array
    {
        return array_map(
            $this->mapAdminRow(...),
            $this->db->fetchAll(
                "SELECT setting_key, setting_value, updated_at FROM settings ORDER BY setting_key ASC"
            )
        );
    }

    /**
     * @return array{key: string, value: string, updatedAt: int}|null
     */
    public function findForAdminByKey(string $key): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT setting_key, setting_value, updated_at FROM settings WHERE setting_key = ?",
            [$key]
        );

        return $row !== null ? $this->mapAdminRow($row) : null;
    }

    /**
     * @return array{key: string, value: string, updatedAt: int}
     */
    public function setForAdmin(string $key, string $value): array
    {
        $this->set($key, $value);

        return $this->findForAdminByKey($key) ?? [
            'key' => $key,
            'value' => $value,
            'updatedAt' => time(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{key: string, value: string, updatedAt: int}
     */
    private function mapAdminRow(array $row): array
    {
        return [
            'key' => (string) $row['setting_key'],
            'value' => (string) $row['setting_value'],
            'updatedAt' => (int) $row['updated_at'],
        ];
    }
}

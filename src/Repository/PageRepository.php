<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\Page;

final readonly class PageRepository
{
    private const array ADMIN_COLUMNS = [
        'id',
        'parent',
        'pattern',
        'action',
        'page_name',
        'settings',
        'feed_type',
        'list_feed_type',
        'term_vocabulary',
        'feed_id',
        'changefreq',
        'updated',
        'access_rule',
    ];

    public function __construct(
        private PdoDatabase $db
    ) {

    }

    /**
     * Warning: no index; intentional full table load at boot for page tree construction.
     *
     * @return list<Page>
     */
    public function findAll(): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM pages');

        return array_map(
            static fn (array $row) => new Page(
                id: (int) $row['id'],
                parentId: $row['parent'] !== null ? (int) $row['parent'] : null,
                pattern: $row['pattern'],
                pageName: $row['page_name'] ?? null,
                settings: $row['settings'] ? (object) json_decode($row['settings'], true) : null,
                feedType: $row['feed_type'] ?? null,
                listFeedType: $row['list_feed_type'] ?? null,
                feedId: $row['feed_id'] !== null ? (int) $row['feed_id'] : null,
                commentsEnabled: (bool) (json_decode($row['settings'] ?? '{}', true)['commentsEnabled'] ?? false),
                requestMethods: ['GET'],
                responseType: 'html',
                accessRule: $row['access_rule'],
                action: $row['action'] ?? null,
                changefreq: $row['changefreq'] ?? null,
                updated: isset($row['updated']) ? (int) $row['updated'] : null,
                termVocabulary: $row['term_vocabulary'] ?? null,
            ),
            $rows
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllForAdmin(): array
    {
        return array_map(
            $this->mapAdminRow(...),
            $this->db->fetchAll(
                'SELECT '.implode(', ', self::ADMIN_COLUMNS).' FROM pages ORDER BY id ASC'
            )
        );
    }

    public function findForAdminById(int $id): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT '.implode(', ', self::ADMIN_COLUMNS).' FROM pages WHERE id = ?',
            [$id]
        );

        return $row !== null ? $this->mapAdminRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createFromAdminData(array $data): array
    {
        $this->db->execute(
            'INSERT INTO pages (
                parent, pattern, action, page_name, settings, feed_type,
                list_feed_type, term_vocabulary, feed_id,
                changefreq, updated, access_rule
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP(), ?)',
            $this->adminParams($data)
        );

        $id = $this->db->lastInsertId();

        return $this->findForAdminById($id) ?? ['id' => $id] + $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateFromAdminData(int $id, array $data): array
    {
        $this->db->execute(
            'UPDATE pages SET
                parent = ?,
                pattern = ?,
                action = ?,
                page_name = ?,
                settings = ?,
                feed_type = ?,
                list_feed_type = ?,
                term_vocabulary = ?,
                feed_id = ?,
                changefreq = ?,
                updated = UNIX_TIMESTAMP(),
                access_rule = ?
            WHERE id = ?',
            [...$this->adminParams($data), $id]
        );

        return $this->findForAdminById($id) ?? ['id' => $id] + $data;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapAdminRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'parentId' => $row['parent'] !== null ? (int) $row['parent'] : null,
            'pattern' => $row['pattern'] ?? '',
            'action' => $row['action'] ?? '',
            'pageName' => $row['page_name'] ?? '',
            'settings' => $row['settings'] ?? '',
            'feedType' => $row['feed_type'] ?? '',
            'listFeedType' => $row['list_feed_type'] ?? '',
            'termVocabulary' => $row['term_vocabulary'] ?? '',
            'feedId' => $row['feed_id'] !== null ? (int) $row['feed_id'] : null,
            'changefreq' => $row['changefreq'] ?? '',
            'updated' => isset($row['updated']) ? (int) $row['updated'] : null,
            'accessRule' => $row['access_rule'],
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
            $data['pattern'],
            $data['action'],
            $data['pageName'],
            $data['settings'],
            $data['feedType'],
            $data['listFeedType'],
            $data['termVocabulary'],
            $data['feedId'],
            $data['changefreq'],
            $data['accessRule'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use RuntimeException;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\FeedTerm;
use StreamEngine\Service\FeedService;
use Throwable;

final readonly class FeedTermRepository
{
    public function __construct(
        private PdoDatabase $db
    ) {

    }

    /**
     * Uses index: feed_terms_vocabulary_parent_name_unique (vocabulary, parent_key, name)
     * Warning: ORDER BY name, id cannot fully use the index because parent_key is not constrained.
     *
     * @return list<FeedTerm>
     */
    public function findByVocabulary(string $vocabulary, int $limit = 20, int $offset = 0): array
    {
        $rows = $this->db->fetchAll(
            "
            SELECT id, parent_id, vocabulary, name, slug, updated_at
            FROM feed_terms
            WHERE vocabulary = ?
            ORDER BY name, id
            LIMIT $limit OFFSET $offset
            ",
            [$vocabulary]
        );

        return array_map(
            static fn (array $row): FeedTerm => FeedTerm::fromRow($row),
            $rows
        );
    }

    /**
     * Uses index: feed_terms_parent_id_index (parent_id)
     *
     * @return list<FeedTerm>
     */
    public function findChildren(string $vocabulary, int $parentId): array
    {
        $rows = $this->db->fetchAll(
            "
            SELECT id, parent_id, vocabulary, name, slug, updated_at
            FROM feed_terms
            WHERE vocabulary = ?
              AND parent_id = ?
            ORDER BY name, id
            ",
            [$vocabulary, $parentId]
        );

        return array_map(
            static fn (array $row): FeedTerm => FeedTerm::fromRow($row),
            $rows
        );
    }

    /**
     * Uses index: feed_terms_vocabulary_slug_index (vocabulary, slug)
     */
    public function countByVocabulary(string $vocabulary): int
    {
        $row = $this->db->fetchOne(
            "
            SELECT COUNT(*) AS total
            FROM feed_terms
            WHERE vocabulary = ?
            ",
            [$vocabulary]
        );

        return (int) ($row['total'] ?? 0);
    }

    /**
     * Uses index: feed_terms_vocabulary_slug_index (vocabulary, slug)
     */
    public function findByVocabularyAndSlug(string $vocabulary, string $slug): ?FeedTerm
    {
        $row = $this->db->fetchOne(
            "
            SELECT id, parent_id, vocabulary, name, slug, updated_at
            FROM feed_terms
            WHERE vocabulary = ?
              AND slug = ?
            LIMIT 1
            ",
            [$vocabulary, $slug]
        );

        return $row ? FeedTerm::fromRow($row) : null;
    }

    /**
     * Uses index: PRIMARY(feed_id, term_id) on feed_term_links.
     * Uses index: PRIMARY(id) on feed_terms join.
     *
     * @param int[] $feedIds
     * @return array<int, list<FeedTerm>>
     */
    public function findByFeedIdsAndVocabulary(array $feedIds, string $vocabulary): array
    {
        $feedIds = array_values(array_unique(array_map('intval', $feedIds)));

        if ($feedIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($feedIds), '?'));
        $rows = $this->db->fetchAll(
            "
            SELECT
                ftl.feed_id,
                ftl.position,
                ft.id,
                ft.parent_id,
                ft.vocabulary,
                ft.name,
                ft.slug
            FROM feed_term_links ftl
            JOIN feed_terms ft ON ft.id = ftl.term_id
            WHERE ftl.feed_id IN ($placeholders)
              AND ft.vocabulary = ?
            ORDER BY ftl.feed_id, ftl.position, ft.name
            ",
            [...$feedIds, $vocabulary]
        );

        $result = [];
        foreach ($rows as $row) {
            $feedId = (int) $row['feed_id'];
            $result[$feedId] ??= [];
            $result[$feedId][] = FeedTerm::fromRow($row);
        }

        return $result;
    }

    /**
     * Uses index: PRIMARY(feed_id, term_id) on feed_term_links for the delete scan.
     * Uses index: PRIMARY(id) on feed_terms join.
     *
     * @param string[] $names
     */
    public function replaceForFeed(int $feedId, string $vocabulary, array $names): void
    {
        $names = $this->normalizeNames($names);

        $this->db->begin();

        try {
            $this->db->execute(
                "
                DELETE ftl
                FROM feed_term_links ftl
                JOIN feed_terms ft ON ft.id = ftl.term_id
                WHERE ftl.feed_id = ?
                  AND ft.vocabulary = ?
                ",
                [$feedId, $vocabulary]
            );

            foreach ($names as $position => $name) {
                $termId = $this->findOrCreate($vocabulary, $name);
                $this->db->execute(
                    "
                    INSERT INTO feed_term_links (feed_id, term_id, position)
                    VALUES (?, ?, ?)
                    ",
                    [$feedId, $termId, $position]
                );
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw new RuntimeException("Unable to update feed. {$exception->getMessage()}");
        }
    }

    /**
     * Uses index: feed_terms_vocabulary_parent_name_unique (vocabulary, parent_key, name)
     */
    private function findOrCreate(string $vocabulary, string $name): int
    {
        $this->db->execute(
            "
            INSERT IGNORE INTO feed_terms (parent_id, vocabulary, name, slug, created_at, updated_at)
            VALUES (NULL, ?, ?, ?, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
            ",
            [$vocabulary, $name, $this->slugify($name)]
        );

        $row = $this->db->fetchOne(
            "
            SELECT id
            FROM feed_terms
            WHERE vocabulary = ?
              AND name = ?
              AND parent_id IS NULL
            LIMIT 1
            ",
            [$vocabulary, $name]
        );

        return (int) $row['id'];
    }

    /**
     * @param string[] $names
     * @return string[]
     */
    private function normalizeNames(array $names): array
    {
        $result = [];

        foreach ($names as $name) {
            $name = trim(preg_replace('/\s+/u', ' ', $name));

            if ($name === '') {
                continue;
            }

            $result[mb_strtolower($name)] = $name;
        }

        return array_values($result);
    }

    /**
     * Formatter::unicodeSlug(), not slugify(): term slugs keep their letters,
     * so a mixed-case Cyrillic tag stays Cyrillic and becomes lowercase. See the note there, and
     * FeedTermRepositoryTest, for why that is a decision and not an oversight.
     *
     * One behaviour change from the version this replaces: the length cut is
     * followed by another trim, so a name truncated mid-word no longer leaves
     * a trailing hyphen.
     */
    private function slugify(string $name): string
    {
        return Formatter::unicodeSlug($name, '-', FeedService::MAX_SLUG_LENGTH, 'term');
    }
}

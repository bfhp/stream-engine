<?php

declare(strict_types=1);

namespace StreamEngine\Domain;

final class FeedTerm
{
    public function __construct(
        public readonly int $id,
        public readonly string $vocabulary,
        public readonly string $name,
        public readonly string $slug,
        public readonly ?int $parentId = null,
        public readonly int $position = 0,
        public readonly ?int $updatedAt = null,
        public ?string $canonicalUrl = null,
    ) {

    }

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            vocabulary: $row['vocabulary'],
            name: $row['name'],
            slug: $row['slug'],
            parentId: isset($row['parent_id']) ? (int) $row['parent_id'] : null,
            position: (int) ($row['position'] ?? 0),
            updatedAt: isset($row['updated_at']) ? (int) $row['updated_at'] : null,
        );
    }
}

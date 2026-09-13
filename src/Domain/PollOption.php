<?php

declare(strict_types=1);

namespace StreamEngine\Domain;

/**
 * One answer option of a Poll. votesCount is a denormalized snapshot
 * (feed_poll_options.votes_count, kept in sync by
 * Repository\PollRepository::vote()) rather than something computed here -
 * same posture as Feed::$ratingSum/$ratingCount.
 */
final class PollOption
{
    public function __construct(
        public readonly int $id,
        public readonly int $pollId,
        public readonly string $text,
        public readonly int $position,
        public readonly int $votesCount,
    ) {
    }

    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            pollId: (int) $row['poll_id'],
            text: (string) $row['text'],
            position: (int) $row['position'],
            votesCount: (int) ($row['votes_count'] ?? 0),
        );
    }
}

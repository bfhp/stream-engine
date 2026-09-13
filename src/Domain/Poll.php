<?php

declare(strict_types=1);

namespace StreamEngine\Domain;

/**
 * A poll attached to a single feed (feed_polls.feed_id is unique - see
 * migrations/20260912000000_initial.sql). Generic per
 * docs/MODULE_CONTRACT.md's "useful to more than one module" test, same as
 * Feed's own rating/favorite machinery: Forums is the first caller
 * (Modules\Forums attaching one to a topic), but nothing here is
 * forum-specific.
 */
final class Poll
{
    public const string VISIBILITY_ALWAYS = 'always';

    public const string VISIBILITY_AFTER_VOTE = 'after_vote';

    public const string VISIBILITY_AFTER_CLOSE = 'after_close';

    /** @var string[] */
    public const array VISIBILITIES = [
        self::VISIBILITY_ALWAYS,
        self::VISIBILITY_AFTER_VOTE,
        self::VISIBILITY_AFTER_CLOSE,
    ];

    /**
     * @param PollOption[] $options ordered by position - callers
     *     (Repository\PollRepository::findByFeedId()/findById()) are
     *     responsible for that ordering, this class doesn't re-sort.
     */
    public function __construct(
        public readonly int $id,
        public readonly int $feedId,
        public readonly string $question,
        public readonly int $maxChoices,
        public readonly bool $allowRevote,
        public readonly string $resultsVisibility,
        public readonly ?int $closesAt,
        public readonly int $votersCount,
        public readonly int $createdAt,
        public readonly int $updatedAt,
        public readonly array $options = [],
    ) {
    }

    /**
     * A poll with no closesAt is open forever - never considered closed
     * regardless of how much time has passed.
     */
    public function isClosed(?int $now = null): bool
    {
        return $this->closesAt !== null && $this->closesAt <= ($now ?? time());
    }

    public function isMultipleChoice(): bool
    {
        return $this->maxChoices > 1;
    }

    /**
     * Whether a viewer in the given state is allowed to see this poll's
     * results, per its own resultsVisibility setting:
     * - 'always': anyone, any time.
     * - 'after_vote': only once they've cast a vote (their own vote, not
     *   necessarily on every option).
     * - 'after_close': only once the poll itself has closed - never true
     *   for an open-ended poll (see isClosed()), by construction of how
     *   Service\PollService::createPoll() validates this combination at
     *   creation time, not by any special-casing here.
     */
    public function canSeeResults(bool $viewerHasVoted, ?int $now = null): bool
    {
        return match ($this->resultsVisibility) {
            self::VISIBILITY_ALWAYS => true,
            self::VISIBILITY_AFTER_VOTE => $viewerHasVoted,
            self::VISIBILITY_AFTER_CLOSE => $this->isClosed($now),
            default => false,
        };
    }

    /**
     * @param PollOption[] $options
     */
    public static function fromRow(array $row, array $options = []): self
    {
        return new self(
            id: (int) $row['id'],
            feedId: (int) $row['feed_id'],
            question: (string) $row['question'],
            maxChoices: (int) $row['max_choices'],
            allowRevote: (bool) $row['allow_revote'],
            resultsVisibility: (string) $row['results_visibility'],
            closesAt: isset($row['closes_at']) ? (int) $row['closes_at'] : null,
            votersCount: (int) ($row['voters_count'] ?? 0),
            createdAt: (int) $row['created_at'],
            updatedAt: (int) $row['updated_at'],
            options: $options,
        );
    }
}

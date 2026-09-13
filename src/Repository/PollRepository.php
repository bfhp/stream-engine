<?php

declare(strict_types=1);

namespace StreamEngine\Repository;

use RuntimeException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\Poll;
use StreamEngine\Domain\PollOption;
use Throwable;

final readonly class PollRepository
{
    public function __construct(
        private PdoDatabase $db
    ) {
    }

    /**
     * Uses index: feed_polls_feed_id_unique (feed_id)
     */
    public function findByFeedId(int $feedId): ?Poll
    {
        $row = $this->db->fetchOne('SELECT * FROM feed_polls WHERE feed_id = ?', [$feedId]);

        return $row !== null ? Poll::fromRow($row, $this->findOptions((int) $row['id'])) : null;
    }

    /**
     * Uses index: PRIMARY(id)
     */
    public function findById(int $pollId): ?Poll
    {
        $row = $this->db->fetchOne('SELECT * FROM feed_polls WHERE id = ?', [$pollId]);

        return $row !== null ? Poll::fromRow($row, $this->findOptions($pollId)) : null;
    }

    /**
     * Uses index: feed_poll_options_poll_id_position_index (poll_id, position)
     *
     * @return PollOption[] ordered the way they were created / are meant to
     *     be displayed
     */
    private function findOptions(int $pollId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM feed_poll_options WHERE poll_id = ? ORDER BY position ASC, id ASC',
            [$pollId]
        );

        return array_map(static fn (array $row): PollOption => PollOption::fromRow($row), $rows);
    }

    /**
     * Creates a poll and its options in one transaction. $optionTexts is
     * assumed already validated by Service\PollService (trimmed, non-empty,
     * at least two, question/length limits checked) - this layer just
     * persists them in the given order via their array position.
     *
     * Uses index: feed_polls_feed_id_unique (feed_id) - the INSERT itself;
     * Service\PollService::createPoll() already checked no poll exists for
     * this feed yet, but the unique constraint is the actual guard against
     * a race between that check and this insert.
     *
     * @param string[] $optionTexts
     */
    public function create(
        int $feedId,
        string $question,
        array $optionTexts,
        int $maxChoices,
        bool $allowRevote,
        string $resultsVisibility,
        ?int $closesAt
    ): Poll {
        $this->db->begin();

        try {
            $this->db->execute(
                '
                INSERT INTO feed_polls
                    (feed_id, question, max_choices, allow_revote, results_visibility, closes_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, UNIX_TIMESTAMP(), UNIX_TIMESTAMP())
                ',
                [$feedId, $question, $maxChoices, $allowRevote ? 1 : 0, $resultsVisibility, $closesAt]
            );

            $pollId = $this->db->lastInsertId();

            foreach (array_values($optionTexts) as $position => $text) {
                $this->db->execute(
                    'INSERT INTO feed_poll_options (poll_id, text, position) VALUES (?, ?, ?)',
                    [$pollId, $text, $position]
                );
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw new RuntimeException("Unable to create poll. {$exception->getMessage()}");
        }

        $poll = $this->findById($pollId);

        if ($poll === null) {
            // Can't happen outside of something else hard-deleting the row
            // between our own commit and this read - RuntimeException
            // rather than a nullable return keeps this method's signature
            // a plain, always-present Poll like every other create()-style
            // method in this codebase.
            throw new RuntimeException('Poll vanished immediately after being created');
        }

        return $poll;
    }

    /**
     * Uses index: feed_poll_votes_poll_id_user_id_index (poll_id, user_id)
     *
     * @return int[] option ids the user has voted for, empty if they haven't
     *     voted at all
     */
    public function findUserVotes(int $pollId, int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT option_id FROM feed_poll_votes WHERE poll_id = ? AND user_id = ?',
            [$pollId, $userId]
        );

        return array_map(static fn (array $row): int => (int) $row['option_id'], $rows);
    }

    /**
     * Uses index: feed_poll_votes_poll_id_user_id_index (poll_id, user_id)
     */
    public function hasVoted(int $pollId, int $userId): bool
    {
        $row = $this->db->fetchOne(
            'SELECT 1 FROM feed_poll_votes WHERE poll_id = ? AND user_id = ? LIMIT 1',
            [$pollId, $userId]
        );

        return $row !== null;
    }

    /**
     * Casts (or replaces) one user's vote and keeps
     * feed_poll_options.votes_count/feed_polls.voters_count in sync in the
     * same transaction - same shape as FeedRatingRepository::submit().
     * Service\PollService is responsible for every business rule (poll
     * still open, revote actually allowed, option ids belong to this poll,
     * selection within max_choices) before calling this; this layer just
     * applies the already-validated write.
     *
     * A revote replaces the user's entire previous selection - their old
     * vote rows are deleted (and those options' votes_count decremented)
     * before the new ones are inserted, not merged with it, matching how a
     * fresh "select up to N options, submit" form naturally behaves.
     * voters_count only increments on a user's first-ever vote on this
     * poll; a revote leaves it untouched since they were already counted.
     *
     * Uses index: feed_poll_votes_poll_id_user_id_index (poll_id, user_id) -
     * both the FOR UPDATE row lock (preventing two concurrent votes/revotes
     * from the same user racing on the increment/decrement below) and the
     * revote's own DELETE.
     * Uses index: PRIMARY(id) on feed_poll_options/feed_polls for the count
     * updates.
     *
     * @param int[] $optionIds
     */
    public function vote(int $pollId, int $userId, array $optionIds): void
    {
        $this->db->begin();

        try {
            $existingOptionIds = array_map(
                static fn (array $row): int => (int) $row['option_id'],
                $this->db->fetchAll(
                    'SELECT option_id FROM feed_poll_votes WHERE poll_id = ? AND user_id = ? FOR UPDATE',
                    [$pollId, $userId]
                )
            );

            $isFirstVote = $existingOptionIds === [];

            if (! $isFirstVote) {
                $this->db->execute(
                    'DELETE FROM feed_poll_votes WHERE poll_id = ? AND user_id = ?',
                    [$pollId, $userId]
                );
                $this->adjustOptionVotes($existingOptionIds, -1);
            }

            foreach ($optionIds as $optionId) {
                $this->db->execute(
                    '
                    INSERT INTO feed_poll_votes (poll_id, option_id, user_id, created_at)
                    VALUES (?, ?, ?, UNIX_TIMESTAMP())
                    ',
                    [$pollId, $optionId, $userId]
                );
            }
            $this->adjustOptionVotes($optionIds, 1);

            if ($isFirstVote) {
                $this->db->execute(
                    'UPDATE feed_polls SET voters_count = voters_count + 1 WHERE id = ?',
                    [$pollId]
                );
            }

            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->rollback();
            throw new RuntimeException("Unable to submit poll vote. {$exception->getMessage()}");
        }
    }

    /**
     * Hard-deletes a poll. feed_poll_options/feed_poll_votes both cascade off
     * feed_polls.id (see migrations/20260912000000_initial.sql's own
     * foreign keys), so this one statement is the whole cleanup - no explicit
     * child deletes and no transaction needed, same as
     * FeedRepository::delete() relies on the feeds table's own cascades.
     *
     * Every business rule (who may delete it, and the "not once anyone has
     * voted" guard specifically) lives in Service\PollService::deletePoll();
     * this layer just applies the already-validated write, same division as
     * vote() above.
     *
     * Uses index: PRIMARY(id)
     */
    public function delete(int $pollId): void
    {
        $this->db->execute('DELETE FROM feed_polls WHERE id = ?', [$pollId]);
    }

    /**
     * @param int[] $optionIds
     */
    private function adjustOptionVotes(array $optionIds, int $delta): void
    {
        foreach ($optionIds as $optionId) {
            $this->db->execute(
                'UPDATE feed_poll_options SET votes_count = votes_count + ? WHERE id = ?',
                [$delta, $optionId]
            );
        }
    }
}

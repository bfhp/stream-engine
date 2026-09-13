<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Poll;
use StreamEngine\Domain\PollOption;
use StreamEngine\Domain\User;
use StreamEngine\Repository\PollRepository;

/**
 * Generic feed-attached polls - per docs/MODULE_CONTRACT.md's "useful to
 * more than one module" test, same posture as FeedService's own
 * rating/favorite methods, just kept in its own Service rather than bolted
 * onto the already-1700-line FeedService: a poll's own vocabulary (options,
 * choices, closing, results visibility) is large enough to earn its own
 * file, the way TermService/AccessService already sit alongside FeedService
 * instead of inside it. Provided to ControllerFactory by StreamEngine.php
 * exactly like those.
 *
 * Depends on FeedService (not FeedRepository/AccessService directly) so
 * every feed-access rule (ACL-aware lookups, canEditFeed()'s owner/admin/
 * moderator logic) is asked of the one place that already owns it, instead
 * of duplicating it here.
 */
class PollService
{
    private const int MIN_OPTIONS = 2;

    private const int MAX_OPTIONS = 20;

    private const int MAX_QUESTION_LENGTH = 255;

    private const int MAX_OPTION_TEXT_LENGTH = 255;

    public function __construct(
        private readonly PollRepository $repository,
        private readonly FeedService $feedService,
        private readonly TranslationManager $tm,
    ) {
    }

    /**
     * The poll attached to a feed, if any - null is a normal "this feed has
     * no poll" answer, not an error. Access to the poll is gated on access
     * to the feed it belongs to (a poll on a private/members-only feed
     * shouldn't be readable by someone who can't see that feed at all).
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     */
    public function getPollForFeed(int $feedId, User $user): ?Poll
    {
        $this->feedService->getFeedById($feedId, $user);

        return $this->repository->findByFeedId($feedId);
    }

    /**
     * Attaches a new poll to a feed. Only the feed's own owner (or an admin/
     * moderator, per FeedService::canEditFeed()) may do this - same rule as
     * editing the feed itself, since a poll is part of that feed's content.
     * A feed may only ever have one poll (feed_polls.feed_id is unique;
     * this check plus that constraint together close the race between two
     * concurrent creates).
     *
     * @param string[] $options raw option texts, in display order
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function createPoll(
        int $feedId,
        string $question,
        array $options,
        int $maxChoices,
        bool $allowRevote,
        string $resultsVisibility,
        ?int $closesAt,
        User $user
    ): Poll {
        if ($user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        $feed = $this->feedService->getFeedById($feedId, $user);

        if (! $this->feedService->canEditFeed($feed, $user)) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        if ($this->repository->findByFeedId($feedId) !== null) {
            throw new ValidationException($this->tm->trans('poll.already_exists'));
        }

        $question = trim($question);
        if ($question === '' || mb_strlen($question) > self::MAX_QUESTION_LENGTH) {
            throw new ValidationException($this->tm->trans('poll.question_invalid'));
        }

        $options = array_values(array_filter(
            array_map(static fn (string $option): string => trim($option), $options),
            static fn (string $option): bool => $option !== ''
        ));

        if (count($options) < self::MIN_OPTIONS) {
            throw new ValidationException($this->tm->trans('poll.not_enough_options', ['min' => self::MIN_OPTIONS]));
        }

        if (count($options) > self::MAX_OPTIONS) {
            throw new ValidationException($this->tm->trans('poll.too_many_options', ['max' => self::MAX_OPTIONS]));
        }

        foreach ($options as $option) {
            if (mb_strlen($option) > self::MAX_OPTION_TEXT_LENGTH) {
                throw new ValidationException($this->tm->trans('poll.option_too_long'));
            }
        }

        if (count(array_unique($options)) !== count($options)) {
            throw new ValidationException($this->tm->trans('poll.duplicate_options'));
        }

        if ($maxChoices < 1) {
            throw new ValidationException($this->tm->trans('poll.max_choices_invalid'));
        }

        if ($maxChoices > count($options)) {
            throw new ValidationException($this->tm->trans('poll.max_choices_exceeds_options'));
        }

        if (! in_array($resultsVisibility, Poll::VISIBILITIES, true)) {
            throw new ValidationException($this->tm->trans('poll.results_visibility_invalid'));
        }

        if ($closesAt !== null && $closesAt <= time()) {
            throw new ValidationException($this->tm->trans('poll.closes_at_in_past'));
        }

        if ($resultsVisibility === Poll::VISIBILITY_AFTER_CLOSE && $closesAt === null) {
            throw new ValidationException($this->tm->trans('poll.after_close_requires_end_date'));
        }

        return $this->repository->create(
            feedId: $feedId,
            question: $question,
            optionTexts: $options,
            maxChoices: $maxChoices,
            allowRevote: $allowRevote,
            resultsVisibility: $resultsVisibility,
            closesAt: $closesAt,
        );
    }

    /**
     * Detaches a feed's poll entirely. Same permission rule as createPoll()
     * (the feed's own owner/admin/moderator, per FeedService::canEditFeed()) -
     * a poll is part of its feed's content, so whoever may edit that feed may
     * remove its poll.
     *
     * Refuses once anyone has actually voted (`voters_count > 0`), for
     * everyone including admins: deleting a poll cascades its
     * feed_poll_options/feed_poll_votes rows away (see PollRepository::
     * delete()), so allowing it after the fact would silently discard real
     * votes with no undo. This is the guard that makes "editing" a poll safe
     * to express as delete-then-recreate (Modules\Forums\ForumsController::
     * replaceTopicPoll() is the first caller) - an untouched poll can still
     * be reworded, a poll people have answered is frozen. Deleting the feed
     * itself still takes the poll with it via the feed_polls.feed_id cascade;
     * that's a deliberate difference (you're discarding the whole discussion,
     * not quietly rewriting the question people already answered).
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function deletePoll(int $pollId, User $user): void
    {
        if ($user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        $poll = $this->repository->findById($pollId);
        if ($poll === null) {
            throw new ValidationException($this->tm->trans('poll.not_found'), 404, 'not_found');
        }

        $feed = $this->feedService->getFeedById($poll->feedId, $user);

        if (! $this->feedService->canEditFeed($feed, $user)) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        if ($poll->votersCount > 0) {
            throw new ValidationException($this->tm->trans('poll.has_votes'));
        }

        $this->repository->delete($pollId);
    }

    /**
     * Casts (or, if the poll allows it, replaces) the current user's vote.
     * Access to the poll is gated on access to its feed, same reasoning as
     * getPollForFeed().
     *
     * @param int[] $optionIds
     *
     * @throws ForbiddenException
     * @throws NotFoundException
     * @throws ValidationException
     */
    public function vote(int $pollId, array $optionIds, User $user): Poll
    {
        if ($user->isGuest()) {
            throw new ForbiddenException($this->tm->trans('feed.forbidden'));
        }

        $poll = $this->repository->findById($pollId);
        if ($poll === null) {
            throw new ValidationException($this->tm->trans('poll.not_found'), 404, 'not_found');
        }

        // Throws NotFoundException/ForbiddenException if the underlying
        // feed is gone or the viewer can't access it - same ACL rule
        // getPollForFeed() enforces for reads.
        $this->feedService->getFeedById($poll->feedId, $user);

        if ($poll->isClosed()) {
            throw new ValidationException($this->tm->trans('poll.closed'));
        }

        $hasVoted = $this->repository->hasVoted($pollId, $user->id);
        if ($hasVoted && ! $poll->allowRevote) {
            throw new ValidationException($this->tm->trans('poll.revote_not_allowed'));
        }

        $optionIds = array_values(array_unique(array_map('intval', $optionIds)));

        if ($optionIds === []) {
            throw new ValidationException($this->tm->trans('poll.no_option_selected'));
        }

        if (count($optionIds) > $poll->maxChoices) {
            throw new ValidationException(
                $this->tm->trans('poll.too_many_options_selected', ['max' => $poll->maxChoices])
            );
        }

        $validOptionIds = array_map(static fn (PollOption $option): int => $option->id, $poll->options);
        foreach ($optionIds as $optionId) {
            if (! in_array($optionId, $validOptionIds, true)) {
                throw new ValidationException($this->tm->trans('poll.invalid_option'));
            }
        }

        $this->repository->vote($pollId, $user->id, $optionIds);

        return $this->repository->findById($pollId) ?? $poll;
    }

    /**
     * The current user's own selection for a poll, if any - used to
     * pre-select a vote form's state. Guests never have votes.
     *
     * @return int[]
     */
    public function getUserVotes(int $pollId, User $user): array
    {
        if ($user->isGuest()) {
            return [];
        }

        return $this->repository->findUserVotes($pollId, $user->id);
    }

    /**
     * Whether the current viewer is allowed to see a poll's results right
     * now, per its own resultsVisibility rule (Poll::canSeeResults()) -
     * guests can never have voted, so 'after_vote' is always false for
     * them, same as everywhere else in this codebase that gates on
     * "has this user voted".
     */
    public function canSeeResults(Poll $poll, User $user): bool
    {
        $hasVoted = ! $user->isGuest() && $this->repository->hasVoted($poll->id, $user->id);

        return $poll->canSeeResults($hasVoted);
    }
}

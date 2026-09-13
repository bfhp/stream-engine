<?php

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\TestCase;
use StreamEngine\Domain\Poll;
use StreamEngine\Domain\PollOption;

final class PollTest extends TestCase
{
    private function makePoll(array $overrides = []): Poll
    {
        return Poll::fromRow(array_merge([
            'id' => 1,
            'feed_id' => 10,
            'question' => 'Best language?',
            'max_choices' => 1,
            'allow_revote' => 0,
            'results_visibility' => Poll::VISIBILITY_ALWAYS,
            'closes_at' => null,
            'voters_count' => 0,
            'created_at' => 1000,
            'updated_at' => 1000,
        ], $overrides), [
            new PollOption(id: 1, pollId: 1, text: 'PHP', position: 0, votesCount: 3),
            new PollOption(id: 2, pollId: 1, text: 'Rust', position: 1, votesCount: 5),
        ]);
    }

    public function testAnOpenEndedPollIsNeverClosed(): void
    {
        $poll = $this->makePoll(['closes_at' => null]);

        $this->assertFalse($poll->isClosed(now: time() + 1_000_000));
    }

    public function testAPollClosesOnceItsEndDateHasPassed(): void
    {
        $poll = $this->makePoll(['closes_at' => 1000]);

        $this->assertFalse($poll->isClosed(now: 999));
        $this->assertTrue($poll->isClosed(now: 1000));
        $this->assertTrue($poll->isClosed(now: 1001));
    }

    public function testIsMultipleChoiceReflectsMaxChoices(): void
    {
        $this->assertFalse($this->makePoll(['max_choices' => 1])->isMultipleChoice());
        $this->assertTrue($this->makePoll(['max_choices' => 2])->isMultipleChoice());
    }

    public function testResultsAreAlwaysVisibleUnderTheAlwaysPolicy(): void
    {
        $poll = $this->makePoll(['results_visibility' => Poll::VISIBILITY_ALWAYS]);

        $this->assertTrue($poll->canSeeResults(viewerHasVoted: false));
        $this->assertTrue($poll->canSeeResults(viewerHasVoted: true));
    }

    public function testResultsRequireAVoteUnderTheAfterVotePolicy(): void
    {
        $poll = $this->makePoll(['results_visibility' => Poll::VISIBILITY_AFTER_VOTE]);

        $this->assertFalse($poll->canSeeResults(viewerHasVoted: false));
        $this->assertTrue($poll->canSeeResults(viewerHasVoted: true));
    }

    public function testResultsRequireClosureUnderTheAfterClosePolicy(): void
    {
        $poll = $this->makePoll(['results_visibility' => Poll::VISIBILITY_AFTER_CLOSE, 'closes_at' => 1000]);

        $this->assertFalse($poll->canSeeResults(viewerHasVoted: true, now: 999));
        $this->assertTrue($poll->canSeeResults(viewerHasVoted: false, now: 1000));
    }

    public function testFromRowKeepsOptionsInGivenOrder(): void
    {
        $poll = $this->makePoll();

        $this->assertCount(2, $poll->options);
        $this->assertSame('PHP', $poll->options[0]->text);
        $this->assertSame(3, $poll->options[0]->votesCount);
        $this->assertSame('Rust', $poll->options[1]->text);
    }
}

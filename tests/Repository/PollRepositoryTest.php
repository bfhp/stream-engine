<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\Poll;
use StreamEngine\Repository\PollRepository;

final class PollRepositoryTest extends TestCase
{
    private function pollRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 55,
            'feed_id' => 10,
            'question' => 'Best language?',
            'max_choices' => 1,
            'allow_revote' => 0,
            'results_visibility' => 'always',
            'closes_at' => null,
            'voters_count' => 0,
            'created_at' => 1000,
            'updated_at' => 1000,
        ], $overrides);
    }

    private function optionRows(): array
    {
        return [
            ['id' => 1, 'poll_id' => 55, 'text' => 'PHP', 'position' => 0, 'votes_count' => 0],
            ['id' => 2, 'poll_id' => 55, 'text' => 'Rust', 'position' => 1, 'votes_count' => 0],
        ];
    }

    public function testFindByFeedIdReturnsNullWhenNoPollExists(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchOne')->willReturn(null);
        $db->expects($this->never())->method('fetchAll');

        $repository = new PollRepository($db);

        $this->assertNull($repository->findByFeedId(10));
    }

    public function testFindByFeedIdReturnsPollWithOrderedOptions(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'feed_polls')
                    && str_contains($sql, 'feed_id = ?')),
                [10]
            )
            ->willReturn($this->pollRow());

        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'feed_poll_options')
                    && str_contains($sql, 'ORDER BY position ASC')),
                [55]
            )
            ->willReturn($this->optionRows());

        $repository = new PollRepository($db);
        $poll = $repository->findByFeedId(10);

        $this->assertInstanceOf(Poll::class, $poll);
        $this->assertSame(55, $poll->id);
        $this->assertCount(2, $poll->options);
        $this->assertSame('PHP', $poll->options[0]->text);
        $this->assertSame('Rust', $poll->options[1]->text);
    }

    public function testCreateInsertsPollAndOptionsInATransactionAndReturnsHydratedPoll(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $executed = [];

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollback');
        $db->expects($this->once())->method('lastInsertId')->willReturn(55);

        $db
            ->expects($this->exactly(3))
            ->method('execute')
            ->willReturnCallback(static function (string $sql, array $params) use (&$executed): int {
                $executed[] = [$sql, $params];

                return 1;
            });

        $db->expects($this->once())->method('fetchOne')->willReturn($this->pollRow());
        $db->expects($this->once())->method('fetchAll')->willReturn($this->optionRows());

        $repository = new PollRepository($db);
        $poll = $repository->create(
            feedId: 10,
            question: 'Best language?',
            optionTexts: ['PHP', 'Rust'],
            maxChoices: 1,
            allowRevote: false,
            resultsVisibility: 'always',
            closesAt: null,
        );

        $this->assertStringContainsString('INSERT INTO feed_polls', $executed[0][0]);
        $this->assertSame([10, 'Best language?', 1, 0, 'always', null], $executed[0][1]);

        $this->assertStringContainsString('INSERT INTO feed_poll_options', $executed[1][0]);
        $this->assertSame([55, 'PHP', 0], $executed[1][1]);
        $this->assertSame([55, 'Rust', 1], $executed[2][1]);

        $this->assertInstanceOf(Poll::class, $poll);
        $this->assertSame(55, $poll->id);
        $this->assertCount(2, $poll->options);
    }

    public function testCreateRollsBackOnFailure(): void
    {
        $db = $this->createMock(PdoDatabase::class);

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('execute')->willThrowException(new RuntimeException('db down'));
        $db->expects($this->once())->method('rollback');
        $db->expects($this->never())->method('commit');

        $repository = new PollRepository($db);

        $this->expectException(RuntimeException::class);
        $repository->create(
            feedId: 10,
            question: 'Q',
            optionTexts: ['A', 'B'],
            maxChoices: 1,
            allowRevote: false,
            resultsVisibility: 'always',
            closesAt: null,
        );
    }

    public function testFindUserVotesReturnsOptionIds(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with($this->anything(), [55, 7])
            ->willReturn([['option_id' => '1'], ['option_id' => '2']]);

        $repository = new PollRepository($db);

        $this->assertSame([1, 2], $repository->findUserVotes(55, 7));
    }

    public function testHasVotedReturnsFalseWhenNoRowExists(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchOne')->willReturn(null);

        $repository = new PollRepository($db);

        $this->assertFalse($repository->hasVoted(55, 7));
    }

    public function testHasVotedReturnsTrueWhenRowExists(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchOne')->willReturn(['1' => 1]);

        $repository = new PollRepository($db);

        $this->assertTrue($repository->hasVoted(55, 7));
    }

    public function testVoteInsertsFirstVoteAndIncrementsVotersCount(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $executed = [];

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollback');

        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'FOR UPDATE')),
                [55, 7]
            )
            ->willReturn([]);

        $db
            ->method('execute')
            ->willReturnCallback(static function (string $sql, array $params) use (&$executed): int {
                $executed[] = [$sql, $params];

                return 1;
            });

        $repository = new PollRepository($db);
        $repository->vote(55, 7, [1, 2]);

        // No revote delete for a first-time vote.
        $this->assertFalse(
            (bool) array_filter($executed, static fn (array $e): bool => str_contains($e[0], 'DELETE'))
        );

        $inserts = array_values(array_filter(
            $executed,
            static fn (array $e): bool => str_contains($e[0], 'INSERT INTO feed_poll_votes')
        ));
        $this->assertCount(2, $inserts);
        $this->assertSame([55, 1, 7], $inserts[0][1]);
        $this->assertSame([55, 2, 7], $inserts[1][1]);

        $countUpdates = array_values(array_filter(
            $executed,
            static fn (array $e): bool => str_contains($e[0], 'feed_poll_options SET votes_count = votes_count + ?')
        ));
        $this->assertCount(2, $countUpdates);
        $this->assertSame([1, 1], $countUpdates[0][1]);
        $this->assertSame([1, 2], $countUpdates[1][1]);

        $votersUpdate = array_values(array_filter(
            $executed,
            static fn (array $e): bool => str_contains($e[0], 'voters_count = voters_count + 1')
        ));
        $this->assertCount(1, $votersUpdate);
        $this->assertSame([55], $votersUpdate[0][1]);
    }

    public function testVoteReplacesExistingSelectionOnRevoteWithoutTouchingVotersCount(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $executed = [];

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');

        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn([['option_id' => '1']]);

        $db
            ->method('execute')
            ->willReturnCallback(static function (string $sql, array $params) use (&$executed): int {
                $executed[] = [$sql, $params];

                return 1;
            });

        $repository = new PollRepository($db);
        $repository->vote(55, 7, [2]);

        $deletes = array_values(array_filter(
            $executed,
            static fn (array $e): bool => str_contains($e[0], 'DELETE FROM feed_poll_votes')
        ));
        $this->assertCount(1, $deletes);
        $this->assertSame([55, 7], $deletes[0][1]);

        $votersUpdate = array_filter(
            $executed,
            static fn (array $e): bool => str_contains($e[0], 'voters_count')
        );
        $this->assertSame([], $votersUpdate);
    }

    public function testVoteRollsBackOnFailure(): void
    {
        $db = $this->createMock(PdoDatabase::class);

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('fetchAll')->willReturn([]);
        $db->expects($this->once())->method('execute')->willThrowException(new RuntimeException('db down'));
        $db->expects($this->once())->method('rollback');
        $db->expects($this->never())->method('commit');

        $repository = new PollRepository($db);

        $this->expectException(RuntimeException::class);
        $repository->vote(55, 7, [1]);
    }
}

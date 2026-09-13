<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Poll;
use StreamEngine\Domain\User;
use StreamEngine\Repository\PollRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\PollService;

final class PollServiceTest extends TestCase
{
    private function guest(): User
    {
        return new User(id: 0, email: '', role: AccessService::ROLE_USER);
    }

    private function user(int $id = 7): User
    {
        return new User(id: $id, email: 'user@example.com', role: AccessService::ROLE_USER);
    }

    private function makeFeed(int $id = 10): Feed
    {
        return Feed::fromRow([
            'id' => $id,
            'parent_id' => null,
            'owner_id' => 7,
            'type' => 'forum-post',
            'slug' => 'topic-'.$id,
            'title' => 'Topic',
            'content' => '',
            'description' => null,
            'image_url' => null,
            'container_id' => null,
            'visibility' => 'public',
            'position' => 0,
            'created_at' => 1000,
        ]);
    }

    private function pollRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 55,
            'feed_id' => 10,
            'question' => 'Best language?',
            'max_choices' => 1,
            'allow_revote' => 0,
            'results_visibility' => Poll::VISIBILITY_ALWAYS,
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

    private function makeService(PdoDatabase $pollDb, FeedService $feedService): PollService
    {
        return new PollService(
            new PollRepository($pollDb),
            $feedService,
            new TranslationManager('ru', 'en'),
        );
    }

    // -- createPoll ----------------------------------------------------

    public function testCreatePollThrowsForbiddenExceptionForGuest(): void
    {
        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->never())->method('getFeedById');

        $pollDb = $this->createMock(PdoDatabase::class);
        $pollDb->expects($this->never())->method('fetchOne');

        $service = $this->makeService($pollDb, $feedService);

        $this->expectException(ForbiddenException::class);
        $service->createPoll(10, 'Q?', ['A', 'B'], 1, false, Poll::VISIBILITY_ALWAYS, null, $this->guest());
    }

    public function testCreatePollThrowsForbiddenExceptionWhenUserCannotEditFeed(): void
    {
        $feed = $this->makeFeed();

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($feed);
        $feedService->method('canEditFeed')->willReturn(false);

        $pollDb = $this->createMock(PdoDatabase::class);
        $pollDb->expects($this->never())->method('fetchOne');

        $service = $this->makeService($pollDb, $feedService);

        $this->expectException(ForbiddenException::class);
        $service->createPoll(10, 'Q?', ['A', 'B'], 1, false, Poll::VISIBILITY_ALWAYS, null, $this->user());
    }

    public function testCreatePollThrowsValidationExceptionWhenFeedAlreadyHasPoll(): void
    {
        $feed = $this->makeFeed();

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($feed);
        $feedService->method('canEditFeed')->willReturn(true);

        $pollDb = $this->createStub(PdoDatabase::class);
        $pollDb->method('fetchOne')->willReturn($this->pollRow());
        $pollDb->method('fetchAll')->willReturn($this->optionRows());

        $service = $this->makeService($pollDb, $feedService);

        $this->expectException(ValidationException::class);
        $service->createPoll(10, 'Q?', ['A', 'B'], 1, false, Poll::VISIBILITY_ALWAYS, null, $this->user());
    }

    public function testCreatePollThrowsValidationExceptionForTooFewOptions(): void
    {
        $feed = $this->makeFeed();

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($feed);
        $feedService->method('canEditFeed')->willReturn(true);

        $pollDb = $this->createStub(PdoDatabase::class);
        $pollDb->method('fetchOne')->willReturn(null);

        $service = $this->makeService($pollDb, $feedService);

        $this->expectException(ValidationException::class);
        $service->createPoll(10, 'Q?', ['Only one'], 1, false, Poll::VISIBILITY_ALWAYS, null, $this->user());
    }

    public function testCreatePollThrowsValidationExceptionWhenMaxChoicesExceedsOptionCount(): void
    {
        $feed = $this->makeFeed();

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($feed);
        $feedService->method('canEditFeed')->willReturn(true);

        $pollDb = $this->createStub(PdoDatabase::class);
        $pollDb->method('fetchOne')->willReturn(null);

        $service = $this->makeService($pollDb, $feedService);

        $this->expectException(ValidationException::class);
        $service->createPoll(10, 'Q?', ['A', 'B'], 5, false, Poll::VISIBILITY_ALWAYS, null, $this->user());
    }

    public function testCreatePollThrowsValidationExceptionWhenAfterCloseVisibilityHasNoEndDate(): void
    {
        $feed = $this->makeFeed();

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($feed);
        $feedService->method('canEditFeed')->willReturn(true);

        $pollDb = $this->createStub(PdoDatabase::class);
        $pollDb->method('fetchOne')->willReturn(null);

        $service = $this->makeService($pollDb, $feedService);

        $this->expectException(ValidationException::class);
        $service->createPoll(10, 'Q?', ['A', 'B'], 1, false, Poll::VISIBILITY_AFTER_CLOSE, null, $this->user());
    }

    public function testCreatePollTrimsAndFiltersOptionsBeforePersisting(): void
    {
        $feed = $this->makeFeed();

        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($feed);
        $feedService->method('canEditFeed')->willReturn(true);

        $executed = [];

        $pollDb = $this->createStub(PdoDatabase::class);
        $pollDb->method('fetchOne')->willReturnOnConsecutiveCalls(null, $this->pollRow());
        $pollDb->method('fetchAll')->willReturn($this->optionRows());
        $pollDb->method('lastInsertId')->willReturn(55);
        $pollDb
            ->method('execute')
            ->willReturnCallback(static function (string $sql, array $params) use (&$executed): int {
                $executed[] = [$sql, $params];

                return 1;
            });

        $service = $this->makeService($pollDb, $feedService);

        $poll = $service->createPoll(
            10,
            '  Best language?  ',
            [' PHP ', '', 'Rust', '   '],
            1,
            false,
            Poll::VISIBILITY_ALWAYS,
            null,
            $this->user()
        );

        $this->assertInstanceOf(Poll::class, $poll);

        $insertPoll = $executed[0];
        $this->assertStringContainsString('INSERT INTO feed_polls', $insertPoll[0]);
        $this->assertSame('Best language?', $insertPoll[1][1]);

        $optionInserts = array_values(array_filter(
            $executed,
            static fn (array $e): bool => str_contains($e[0], 'INSERT INTO feed_poll_options')
        ));
        $this->assertCount(2, $optionInserts);
        $this->assertSame('PHP', $optionInserts[0][1][1]);
        $this->assertSame('Rust', $optionInserts[1][1][1]);
    }

    // -- vote ------------------------------------------------------------

    public function testVoteThrowsForbiddenExceptionForGuest(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $pollDb = $this->createMock(PdoDatabase::class);
        $pollDb->expects($this->never())->method('fetchOne');

        $service = $this->makeService($pollDb, $feedService);

        $this->expectException(ForbiddenException::class);
        $service->vote(55, [1], $this->guest());
    }

    public function testVoteThrowsValidationExceptionWhenPollDoesNotExist(): void
    {
        $feedService = $this->createMock(FeedService::class);
        $feedService->expects($this->never())->method('getFeedById');

        $pollDb = $this->createStub(PdoDatabase::class);
        $pollDb->method('fetchOne')->willReturn(null);

        $service = $this->makeService($pollDb, $feedService);

        $this->expectException(ValidationException::class);
        $service->vote(55, [1], $this->user());
    }

    public function testVoteThrowsValidationExceptionWhenPollIsClosed(): void
    {
        $feed = $this->makeFeed();
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($feed);

        $pollDb = $this->createStub(PdoDatabase::class);
        $pollDb->method('fetchOne')->willReturn($this->pollRow(['closes_at' => time() - 10]));
        $pollDb->method('fetchAll')->willReturn($this->optionRows());

        $service = $this->makeService($pollDb, $feedService);

        $this->expectException(ValidationException::class);
        $service->vote(55, [1], $this->user());
    }

    public function testVoteThrowsValidationExceptionWhenRevoteNotAllowedButUserAlreadyVoted(): void
    {
        $feed = $this->makeFeed();
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($feed);

        $pollDb = $this->createStub(PdoDatabase::class);
        $pollDb->method('fetchOne')->willReturnCallback(
            static fn (string $sql, array $params) => str_contains($sql, 'feed_poll_votes')
                ? ['1' => 1]
                : ['id' => 55, 'feed_id' => 10, 'question' => 'Q?', 'max_choices' => 1, 'allow_revote' => 0,
                    'results_visibility' => Poll::VISIBILITY_ALWAYS, 'closes_at' => null, 'voters_count' => 1,
                    'created_at' => 1000, 'updated_at' => 1000]
        );
        $pollDb->method('fetchAll')->willReturn($this->optionRows());

        $service = $this->makeService($pollDb, $feedService);

        $this->expectException(ValidationException::class);
        $service->vote(55, [1], $this->user());
    }

    public function testVoteThrowsValidationExceptionWhenTooManyOptionsSelected(): void
    {
        $feed = $this->makeFeed();
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($feed);

        $pollDb = $this->createStub(PdoDatabase::class);
        $pollDb->method('fetchOne')->willReturnCallback(
            fn (string $sql) => str_contains($sql, 'feed_poll_votes') ? null : $this->pollRow()
        );
        $pollDb->method('fetchAll')->willReturn($this->optionRows());

        $service = $this->makeService($pollDb, $feedService);

        // Poll's own max_choices is 1 (see pollRow()'s default), selecting
        // both options should be rejected.
        $this->expectException(ValidationException::class);
        $service->vote(55, [1, 2], $this->user());
    }

    public function testVoteThrowsValidationExceptionForAnOptionThatDoesNotBelongToThePoll(): void
    {
        $feed = $this->makeFeed();
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($feed);

        $pollDb = $this->createStub(PdoDatabase::class);
        $pollDb->method('fetchOne')->willReturnCallback(
            fn (string $sql) => str_contains($sql, 'feed_poll_votes') ? null : $this->pollRow()
        );
        $pollDb->method('fetchAll')->willReturn($this->optionRows());

        $service = $this->makeService($pollDb, $feedService);

        $this->expectException(ValidationException::class);
        $service->vote(55, [999], $this->user());
    }

    public function testVoteDelegatesToRepositoryAndReturnsRefreshedPoll(): void
    {
        $feed = $this->makeFeed();
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getFeedById')->willReturn($feed);

        $executed = [];

        $pollDb = $this->createStub(PdoDatabase::class);
        $pollDb->method('fetchOne')->willReturnCallback(
            fn (string $sql) => str_contains($sql, 'feed_poll_votes') ? null : $this->pollRow()
        );
        $pollDb->method('fetchAll')->willReturnCallback(
            fn (string $sql, array $params) => str_contains($sql, 'FOR UPDATE')
                ? []
                : $this->optionRows()
        );
        $pollDb
            ->method('execute')
            ->willReturnCallback(static function (string $sql, array $params) use (&$executed): int {
                $executed[] = [$sql, $params];

                return 1;
            });

        $service = $this->makeService($pollDb, $feedService);

        $poll = $service->vote(55, [1], $this->user(7));

        $this->assertInstanceOf(Poll::class, $poll);

        $voteInserts = array_values(array_filter(
            $executed,
            static fn (array $e): bool => str_contains($e[0], 'INSERT INTO feed_poll_votes')
        ));
        $this->assertCount(1, $voteInserts);
        $this->assertSame([55, 1, 7], $voteInserts[0][1]);
    }

    // -- canSeeResults -----------------------------------------------------

    public function testCanSeeResultsIsFalseForAGuestUnderAfterVoteVisibility(): void
    {
        $feedService = $this->createStub(FeedService::class);
        $pollDb = $this->createMock(PdoDatabase::class);
        $pollDb->expects($this->never())->method('fetchOne');

        $poll = Poll::fromRow($this->pollRow(['results_visibility' => Poll::VISIBILITY_AFTER_VOTE]));

        $service = $this->makeService($pollDb, $feedService);

        $this->assertFalse($service->canSeeResults($poll, $this->guest()));
    }
}

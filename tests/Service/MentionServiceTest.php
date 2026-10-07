<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\Page;
use StreamEngine\Repository\MentionRepository;
use StreamEngine\Service\MentionService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

final class MentionServiceTest extends TestCase
{
    private function service(?PdoDatabase $db = null): MentionService
    {
        return new MentionService(new MentionRepository($db ?? $this->createStub(PdoDatabase::class)));
    }

    public function testParserUsesUsernameBoundariesAndSkipsEscapesAndQuotedMarkup(): void
    {
        $tokens = $this->service()->extractTokens(
            'Hi @Alice, @bob-test! mail@example.com https://host/@url '
            .'\\@escaped <a href="/@linked">@linked</a> <code>@coded</code> '
            .'<blockquote>@quoted</blockquote> and @Alice again',
        );

        self::assertSame([
            'alice' => 'Alice',
            'bob-test' => 'bob-test',
        ], $tokens);
    }

    public function testSynchronizationResolvesAllUnknownUsernamesInOneBatchQuery(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->exactly(2))
            ->method('fetchAll')
            ->willReturnCallback(function (string $sql, array $params): array {
                if (str_contains($sql, 'FROM mentions')) {
                    self::assertSame([91], $params);
                    return [[
                        'user_id' => 7,
                        'username_snapshot' => 'renamed-user',
                        'active' => 1,
                        'current_username' => 'current-user',
                    ]];
                }

                self::assertStringContainsString('username IN (?, ?)', $sql);
                self::assertSame(['Alice', 'bob'], $params);
                return [
                    ['id' => 10, 'username' => 'alice'],
                    ['id' => 11, 'username' => 'Bob'],
                ];
            });

        $writes = [];
        $db->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (string $sql, array $params) use (&$writes): int {
                $writes[] = [$sql, $params];
                return 1;
            });

        $newIds = $this->service($db)->synchronizeFeed(
            91,
            '@Alice @alice @bob @renamed-user',
        );

        self::assertSame([10, 11], $newIds);
        self::assertStringContainsString('INSERT INTO mentions (feed_id', $writes[0][0]);
        self::assertSame([91, 7, 'renamed-user', 91, 10, 'Alice', 91, 11, 'bob'], $writes[0][1]);
        self::assertStringContainsString('user_id NOT IN (?, ?, ?)', $writes[1][0]);
    }

    public function testMoreThanTwentyDistinctMentionsIsRejectedBeforeLookup(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('fetchAll');

        $this->expectException(\StreamEngine\Core\Exceptions\ValidationException::class);
        $this->service($db)->validate(implode(' ', array_map(
            static fn (int $id): string => '@user'.$id,
            range(100, 120),
        )));
    }

    public function testRenderCreatesSafeProfileLinkAndKeepsEscapedMentionLiteral(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchAll')->willReturn([[
            'user_id' => 10,
            'username_snapshot' => 'Alice',
            'active' => 1,
            'current_username' => 'alice-now',
        ]]);
        $page = Page::api(1, 0, 'users/{username}', ['GET'], 'user.show');
        $urls = new UrlGenerator(
            new PageTree([$page]),
            new FakeFeedRepository([]),
            new ArrayCache(),
        );
        $service = new MentionService(new MentionRepository($db), $urls);

        $rendered = $service->renderFeed(
            91,
            '\\@Alice and @Alice &lt;img src=x onerror=alert(1)&gt;',
        );

        self::assertStringStartsWith('@Alice and ', $rendered);
        self::assertStringContainsString('<a class="mention" href="/users/alice-now/">@Alice</a>', $rendered);
        self::assertStringNotContainsString('<img', $rendered);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $rendered);
    }

    public function testRenderWithoutAtSignDoesNotQueryMentions(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('fetchAll');

        self::assertSame('Plain text', $this->service($db)->renderFeed(91, 'Plain text'));
    }

    public function testEditDeactivatesRemovedMentionsWithoutAnotherUsernameLookup(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchAll')->willReturn([[
            'user_id' => 10,
            'username_snapshot' => 'Alice',
            'active' => 1,
            'current_username' => 'alice',
        ]]);
        $db->expects($this->once())
            ->method('execute')
            ->with($this->stringContains('active = 0'), [91]);

        $newIds = $this->service($db)->synchronizeFeed(91, 'No mentions anymore');

        self::assertSame([], $newIds);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\ConversationRepository;
use StreamEngine\Repository\MessageRepository;
use StreamEngine\Repository\ParticipantRepository;
use StreamEngine\Repository\UploadRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\MessageService;

/**
 * `getConversation()` - opening one conversation.
 *
 * The authorization boundary of the messenger's read path, and the reason to
 * test it first: the only thing standing between a visitor and any private
 * conversation on the site is that `getByIdForUser()` joins
 * `conversation_participants` and returns nothing when the viewer is not in it.
 * `/api/v1/conversations/<any id>` is a GET a logged-in stranger can make.
 *
 * Two subtler things it also decides.
 *
 * **A missing conversation and somebody else's answer identically.** Both are
 * `ForbiddenException`, so the endpoint cannot be used to discover which
 * conversation ids exist.
 *
 * **`unread_count` is clamped at zero.** It is derived arithmetic -
 * `last_message_id - last_read_message_id` - and the subtrahend can legitimately
 * end up ahead of the minuend when the last message is deleted after somebody
 * read it. Without `max(..., 0)` the badge would show a negative number.
 */
final class MessageServiceConversationDetailTest extends TestCase
{
    /**
     * @param array<string, mixed>|null  $conversation what getByIdForUser sees
     * @param list<int>                  $participantIds
     * @param list<array<string, mixed>> $users
     * @param list<int>                  $onlineUserIds
     */
    private function makeService(
        ?array $conversation,
        array $participantIds = [],
        array $users = [],
        array $onlineUserIds = []
    ): MessageService {
        $db = $this->createStub(PdoDatabase::class);

        $db->method('fetchOne')->willReturnCallback(
            static fn (string $sql, array $params = []): ?array =>
                str_contains($sql, 'FROM conversations c') ? $conversation : null
        );

        // `user_sessions` before `users`: the presence query mentions both.
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql) use ($participantIds, $users, $onlineUserIds): array {
                if (str_contains($sql, 'user_sessions')) {
                    return array_map(static fn (int $id): array => ['user_id' => (string) $id], $onlineUserIds);
                }

                if (str_contains($sql, 'FROM users')) {
                    return $users;
                }

                if (str_contains($sql, 'FROM conversation_participants')) {
                    return array_map(static fn (int $id): array => ['user_id' => (string) $id], $participantIds);
                }

                return [];
            }
        );

        return new MessageService(
            $db,
            new MessageRepository($db),
            new ParticipantRepository($db),
            new ConversationRepository($db),
            new UserRepository($db),
            new UserSessionRepository($db),
            new UploadRepository($db)
        );
    }

    /** A row as `getByIdForUser()` returns it - the join has already gated it. */
    private function conversationRow(array $overrides = []): array
    {
        return array_merge([
            'id' => '11',
            'title' => null,
            'direct_key' => '3:7',
            'last_message_id' => '99',
            'last_read_message_id' => '97',
        ], $overrides);
    }

    private function userRow(int $id, string $nick, string $avatar = '', bool $hidePresence = false): array
    {
        return [
            'id' => (string) $id,
            'nick' => $nick,
            'avatar_url' => $avatar,
            'created_at' => '1600000000',
            'signature' => '',
            'hide_presence' => $hidePresence ? '1' : '0',
        ];
    }

    /* ===============================
       The authorization boundary
    =============================== */

    /**
     * The whole read-side guard. `getByIdForUser()` answers null both for a
     * conversation that does not exist and for one the viewer is not a
     * participant of - and this turns either into the same refusal, so the
     * endpoint cannot be used to enumerate conversation ids.
     */
    public function testAConversationTheViewerIsNotInIsRefused(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->makeService(null)->getConversation(11, 7);
    }

    public function testNothingIsLookedUpOnceAccessIsRefused(): void
    {
        // The refusal is the first thing, before participants, users or
        // presence - so a stranger's probe costs one query, not four.
        $queries = 0;
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(null);
        $db->method('fetchAll')->willReturnCallback(function () use (&$queries): array {
            $queries++;

            return [];
        });

        $service = new MessageService(
            $db,
            new MessageRepository($db),
            new ParticipantRepository($db),
            new ConversationRepository($db),
            new UserRepository($db),
            new UserSessionRepository($db),
            new UploadRepository($db)
        );

        try {
            $service->getConversation(11, 7);
            self::fail('a non-participant must be refused');
        } catch (ForbiddenException) {
            self::assertSame(0, $queries);
        }
    }

    /* ===============================
       A direct conversation
    =============================== */

    public function testADirectConversationIsTitledAfterTheOtherPerson(): void
    {
        // Viewer 7, key '3:7' - so the other person is 3.
        $detail = $this->makeService(
            $this->conversationRow(),
            participantIds: [3, 7],
            users: [$this->userRow(3, 'Пётр'), $this->userRow(7, 'Антон')]
        )->getConversation(11, 7);

        self::assertFalse($detail['is_group']);
        self::assertSame(3, $detail['other_user_id']);
        self::assertSame('Пётр', $detail['title']);
    }

    public function testTheSameConversationIsTitledDifferentlyForEachSide(): void
    {
        $users = [$this->userRow(3, 'Пётр'), $this->userRow(7, 'Антон')];

        self::assertSame(
            'Антон',
            $this->makeService($this->conversationRow(), [3, 7], $users)->getConversation(11, 3)['title']
        );
    }

    public function testAPartnerWithNoUserRowFallsBackToTheirId(): void
    {
        $detail = $this->makeService($this->conversationRow(), participantIds: [3, 7])->getConversation(11, 7);

        self::assertSame('#3', $detail['title']);
    }

    public function testThePartnersPresenceIsReported(): void
    {
        $detail = $this->makeService(
            $this->conversationRow(),
            participantIds: [3, 7],
            users: [$this->userRow(3, 'Пётр')],
            onlineUserIds: [3]
        )->getConversation(11, 7);

        self::assertTrue($detail['other_user_online']);
    }

    public function testHiddenPartnerPresenceIsNotReported(): void
    {
        $detail = $this->makeService(
            $this->conversationRow(),
            participantIds: [3, 7],
            users: [$this->userRow(3, 'Пётр', hidePresence: true)],
            onlineUserIds: [3]
        )->getConversation(11, 7);

        self::assertFalse($detail['other_user_online']);
        self::assertSame([false, false], array_column($detail['participants'], 'online'));
    }

    public function testAnOfflinePartnerIsFalseRatherThanNull(): void
    {
        // null means "group, question does not apply" - the chat header draws
        // the dot on that distinction.
        $detail = $this->makeService(
            $this->conversationRow(),
            participantIds: [3, 7],
            users: [$this->userRow(3, 'Пётр')]
        )->getConversation(11, 7);

        self::assertFalse($detail['other_user_online']);
    }

    /* ===============================
       A group conversation
    =============================== */

    public function testAGroupIsTitledFromItsOwnTitle(): void
    {
        $detail = $this->makeService(
            $this->conversationRow(['direct_key' => null, 'title' => 'Разработка']),
            participantIds: [3, 7, 9]
        )->getConversation(11, 7);

        self::assertTrue($detail['is_group']);
        self::assertSame('Разработка', $detail['title']);
        self::assertNull($detail['other_user_id']);
        self::assertNull($detail['other_user_online']);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function blankTitleProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace only' => ['   '],
        ];
    }

    #[DataProvider('blankTitleProvider')]
    public function testAGroupWithNoUsableTitleGetsTheGenericOne(?string $title): void
    {
        $detail = $this->makeService(
            $this->conversationRow(['direct_key' => null, 'title' => $title]),
            participantIds: [3, 7]
        )->getConversation(11, 7);

        self::assertSame('Групповой чат', $detail['title']);
    }

    /* ===============================
       The participant list
    =============================== */

    public function testEveryParticipantIsListedWithTheirPresence(): void
    {
        $detail = $this->makeService(
            $this->conversationRow(['direct_key' => null, 'title' => 'Разработка']),
            participantIds: [3, 7, 9],
            users: [
                $this->userRow(3, 'Пётр', '/uploads/p.png'),
                $this->userRow(7, 'Антон'),
                $this->userRow(9, 'Мария'),
            ],
            onlineUserIds: [9]
        )->getConversation(11, 7);

        self::assertCount(3, $detail['participants']);
        self::assertSame([3, 7, 9], array_column($detail['participants'], 'id'));
        self::assertSame([false, false, true], array_column($detail['participants'], 'online'));
        self::assertSame('/uploads/p.png', $detail['participants'][0]['avatarUrl']);
    }

    /**
     * A participant whose account is gone still occupies a row in the drawer -
     * they wrote some of the messages above, so removing them from the list
     * would leave those attributed to nobody.
     */
    public function testAParticipantWithNoUserRowGetsAPlaceholder(): void
    {
        $detail = $this->makeService(
            $this->conversationRow(['direct_key' => null, 'title' => 'Разработка']),
            participantIds: [3, 42],
            users: [$this->userRow(3, 'Пётр')]
        )->getConversation(11, 7);

        self::assertSame(
            ['id' => 42, 'displayName' => '#42', 'avatarUrl' => '', 'online' => false],
            $detail['participants'][1]
        );
    }

    public function testTheViewerIsInTheirOwnParticipantList(): void
    {
        // The client needs it: the drawer shows everybody, and the read-state
        // ticks are matched against these ids.
        $detail = $this->makeService(
            $this->conversationRow(),
            participantIds: [3, 7],
            users: [$this->userRow(3, 'Пётр'), $this->userRow(7, 'Антон')]
        )->getConversation(11, 7);

        self::assertContains(7, array_column($detail['participants'], 'id'));
    }

    /* ===============================
       The unread arithmetic
    =============================== */

    /**
     * @return array<string, array{?string, ?string, int}>
     */
    public static function unreadProvider(): array
    {
        return [
            'two behind' => ['99', '97', 2],
            'fully caught up' => ['99', '99', 0],
            'never read anything' => ['99', null, 99],
            'nothing to read' => [null, null, 0],
            // The clamp. `last_read_message_id` can end up ahead of
            // `last_message_id` when the last message is deleted after
            // somebody read it - and a badge showing "-3" is worse than one
            // showing nothing.
            'read further than the last message' => ['96', '99', 0],
        ];
    }

    #[DataProvider('unreadProvider')]
    public function testTheUnreadCountIsClampedAtZero(?string $lastMessageId, ?string $lastReadId, int $expected): void
    {
        $detail = $this->makeService(
            $this->conversationRow([
                'last_message_id' => $lastMessageId,
                'last_read_message_id' => $lastReadId,
            ]),
            participantIds: [3, 7]
        )->getConversation(11, 7);

        self::assertSame($expected, $detail['unread_count']);
    }

    public function testTheTwoMessageIdsAreReportedAsIntegers(): void
    {
        // The client compares message ids to these to decide which ticks to
        // draw, so a string would compare wrong on the boundary.
        $detail = $this->makeService($this->conversationRow(), participantIds: [3, 7])->getConversation(11, 7);

        self::assertSame(99, $detail['last_message_id']);
        self::assertSame(97, $detail['last_read_message_id']);
        self::assertSame(11, $detail['id']);
    }

    public function testAConversationWithNoMessagesReportsZeros(): void
    {
        $detail = $this->makeService(
            $this->conversationRow([
                'direct_key' => null,
                'title' => 'Новая',
                'last_message_id' => null,
                'last_read_message_id' => null,
            ]),
            participantIds: [3, 7]
        )->getConversation(11, 7);

        self::assertSame(0, $detail['last_message_id']);
        self::assertSame(0, $detail['last_read_message_id']);
        self::assertSame(0, $detail['unread_count']);
    }
}

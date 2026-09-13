<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\ConversationRepository;
use StreamEngine\Repository\MessageRepository;
use StreamEngine\Repository\ParticipantRepository;
use StreamEngine\Repository\UploadRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\MessageService;

/**
 * `getUserConversationsWithMeta()` - the messenger's conversation list.
 *
 * The single largest untested method in the service (42 lines) and the one the
 * whole messenger opens onto. Everything it does is *per-viewer*: the same
 * conversation row renders differently for each participant, and every one of
 * those differences is computed here rather than in the client.
 *
 * Three of them are worth being sure about.
 *
 * **Whose name is on a direct chat.** The title, avatar and online dot all
 * come from `otherUserIdFromDirectKey()`. Backwards, and every chat in the
 * list is labelled with the reader's own name and face.
 *
 * **`is_own`.** Resolved here so the page never needs the viewer's id in its
 * markup. `messenger-global.ts` uses it to decide whether to raise a toast -
 * so wrong, and you get notified about your own messages.
 *
 * **Groups deliberately have no presence.** `other_user_id`, `other_user_avatar`
 * and `other_user_online` are all null for a group, and the online lookup is
 * only asked about direct partners - a "3 online" summary for groups is a fine
 * future addition, and until then querying for it would be work nobody reads.
 *
 * A routing double rather than `FakePdoDatabase`: this reads three different
 * shapes (conversations with their computed unread count, users by id, live
 * sessions) and the fake models none of them.
 */
final class MessageServiceConversationListTest extends TestCase
{
    /** @var list<array{string, array}> every fetchAll the service made */
    private array $queries = [];

    /**
     * @param list<array<string, mixed>> $conversations
     * @param list<array<string, mixed>> $users
     * @param list<int>                  $onlineUserIds
     */
    private function makeService(array $conversations, array $users = [], array $onlineUserIds = []): MessageService
    {
        $db = $this->createStub(PdoDatabase::class);

        // Routed by SQL substring. `user_sessions` is matched before `users`
        // on purpose - the presence query mentions both.
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, array $params = []) use ($conversations, $users, $onlineUserIds): array {
                $this->queries[] = [$sql, $params];

                if (str_contains($sql, 'user_sessions')) {
                    return array_map(static fn (int $id): array => ['user_id' => (string) $id], $onlineUserIds);
                }

                if (str_contains($sql, 'FROM users')) {
                    return $users;
                }

                if (str_contains($sql, 'FROM conversations')) {
                    return $conversations;
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

    /**
     * One row as `ConversationRepository::getListWithMeta()` returns it.
     *
     * `direct_key` null is what makes a conversation a group; the two
     * participant ids smallest-first is what makes it a direct chat.
     */
    private function conversationRow(array $overrides = []): array
    {
        return array_merge([
            'id' => '11',
            'title' => null,
            'direct_key' => '3:7',
            'last_message_id' => '99',
            'last_message_at' => '1700000000',
            'last_message_text' => 'Привет',
            'last_message_user_id' => '3',
            'last_message_created_at' => '1700000000',
            'unread_count' => '2',
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

    /** The SQL of every query the service made, for order/absence assertions. */
    private function sqls(): string
    {
        return implode("\n---\n", array_column($this->queries, 0));
    }

    /* ===============================
       A direct conversation
    =============================== */

    public function testADirectChatIsTitledAfterTheOtherPerson(): void
    {
        // Viewer is 7; the key is '3:7', so the other person is 3.
        $service = $this->makeService(
            [$this->conversationRow()],
            [$this->userRow(3, 'Пётр', '/uploads/petr.png')]
        );

        $list = $service->getUserConversationsWithMeta(7);

        self::assertFalse($list[0]['is_group']);
        self::assertSame(3, $list[0]['other_user_id']);
        self::assertSame('Пётр', $list[0]['title']);
        self::assertSame('/uploads/petr.png', $list[0]['other_user_avatar']);
    }

    /**
     * The same row seen from the other side. This is the assertion that would
     * fail if `otherUserIdFromDirectKey()` were ever reversed - and the symptom
     * in production would be every conversation showing the reader's own name.
     */
    public function testTheSameRowIsTitledDifferentlyForEachParticipant(): void
    {
        $rows = [$this->conversationRow()];
        $users = [$this->userRow(3, 'Пётр'), $this->userRow(7, 'Антон')];

        self::assertSame('Пётр', $this->makeService($rows, $users)->getUserConversationsWithMeta(7)[0]['title']);
        self::assertSame('Антон', $this->makeService($rows, $users)->getUserConversationsWithMeta(3)[0]['title']);
    }

    public function testAPartnerWithNoUserRowFallsBackToTheirId(): void
    {
        // A deactivated account: findByIds() returns only rows that exist, so
        // the list must not render a blank title.
        $service = $this->makeService([$this->conversationRow()], []);

        $list = $service->getUserConversationsWithMeta(7);

        self::assertSame('#3', $list[0]['title']);
        self::assertSame('', $list[0]['other_user_avatar']);
    }

    public function testAPartnerWithALiveSessionIsOnline(): void
    {
        $service = $this->makeService(
            [$this->conversationRow()],
            [$this->userRow(3, 'Пётр')],
            onlineUserIds: [3]
        );

        self::assertTrue($service->getUserConversationsWithMeta(7)[0]['other_user_online']);
    }

    public function testAPartnerWhoHidesPresenceIsOfflineEvenWithALiveSession(): void
    {
        $service = $this->makeService(
            [$this->conversationRow()],
            [$this->userRow(3, 'Пётр', hidePresence: true)],
            onlineUserIds: [3]
        );

        self::assertFalse($service->getUserConversationsWithMeta(7)[0]['other_user_online']);
    }

    public function testAPartnerWithNoLiveSessionIsOffline(): void
    {
        // false, not null - null is reserved for "this is a group, the question
        // does not apply", and the client renders the dot on that distinction.
        $service = $this->makeService([$this->conversationRow()], [$this->userRow(3, 'Пётр')]);

        self::assertFalse($service->getUserConversationsWithMeta(7)[0]['other_user_online']);
    }

    /* ===============================
       A group conversation
    =============================== */

    public function testAGroupIsTitledFromItsOwnTitle(): void
    {
        $service = $this->makeService([
            $this->conversationRow(['direct_key' => null, 'title' => 'Разработка']),
        ]);

        $list = $service->getUserConversationsWithMeta(7);

        self::assertTrue($list[0]['is_group']);
        self::assertSame('Разработка', $list[0]['title']);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function blankGroupTitleProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace only' => ['   '],
        ];
    }

    /**
     * A group whose title was never set, or set to spaces, still needs
     * something in the list - an empty row reads as a rendering bug.
     *
     */
    #[DataProvider('blankGroupTitleProvider')]
    public function testAGroupWithNoUsableTitleGetsTheGenericOne(?string $title): void
    {
        $service = $this->makeService([
            $this->conversationRow(['direct_key' => null, 'title' => $title]),
        ]);

        self::assertSame('Групповой чат', $service->getUserConversationsWithMeta(7)[0]['title']);
    }

    public function testAGroupCarriesNoPartnerFields(): void
    {
        $service = $this->makeService([
            $this->conversationRow(['direct_key' => null, 'title' => 'Разработка']),
        ]);

        $list = $service->getUserConversationsWithMeta(7);

        // All three null rather than absent: the client tests them, and a
        // group has no single "other person" to describe.
        self::assertNull($list[0]['other_user_id']);
        self::assertNull($list[0]['other_user_avatar']);
        self::assertNull($list[0]['other_user_online']);
    }

    /**
     * Presence is asked about direct partners only. A group's members are not
     * in the online query at all - which is both the documented decision and
     * the difference between one small `IN (...)` and one the size of every
     * group the viewer belongs to.
     */
    public function testAGroupsMembersAreNotAskedAboutOnlineStatus(): void
    {
        $service = $this->makeService([
            $this->conversationRow(['direct_key' => null, 'title' => 'Разработка', 'last_message_user_id' => '5']),
        ]);

        $service->getUserConversationsWithMeta(7);

        // The sender is still looked up - the list shows who wrote last - but
        // no presence query is made, because there were no direct partners.
        self::assertStringContainsString('FROM users', $this->sqls());
        self::assertStringNotContainsString('user_sessions', $this->sqls());
    }

    /* ===============================
       The last message
    =============================== */

    public function testAMessageTheViewerWroteIsMarkedAsTheirOwn(): void
    {
        // Viewer 3 wrote message 99 (last_message_user_id is 3).
        $service = $this->makeService([$this->conversationRow()], [$this->userRow(7, 'Антон')]);

        $last = $service->getUserConversationsWithMeta(3)[0]['last_message'];

        self::assertTrue($last['is_own']);
        // 'Вы' rather than the reader's own name - this is a list of chats,
        // and "Антон: привет" in your own chat reads as somebody else.
        self::assertSame('Вы', $last['user_name']);
    }

    public function testAMessageSomebodyElseWroteIsNotMarkedAsOwn(): void
    {
        $service = $this->makeService([$this->conversationRow()], [$this->userRow(3, 'Пётр')]);

        $last = $service->getUserConversationsWithMeta(7)[0]['last_message'];

        self::assertFalse($last['is_own']);
        self::assertSame('Пётр', $last['user_name']);
    }

    public function testASenderWithNoUserRowFallsBackToTheirId(): void
    {
        $service = $this->makeService(
            [$this->conversationRow(['last_message_user_id' => '42'])],
            [$this->userRow(3, 'Пётр')]
        );

        self::assertSame('#42', $service->getUserConversationsWithMeta(7)[0]['last_message']['user_name']);
    }

    public function testTheLastMessageIsShapedForTheList(): void
    {
        $service = $this->makeService([$this->conversationRow()], [$this->userRow(3, 'Пётр')]);

        $last = $service->getUserConversationsWithMeta(7)[0]['last_message'];

        // Integers, not the strings PDO handed over - the client compares ids.
        self::assertSame(99, $last['id']);
        self::assertSame(3, $last['user_id']);
        self::assertSame(1700000000, $last['created_at']);
        self::assertSame('Привет', $last['text']);
    }

    public function testAConversationWithNoMessagesHasNoLastMessage(): void
    {
        // A group created a moment ago. null rather than an empty shape, so
        // the client can render "нет сообщений" instead of a blank line.
        $service = $this->makeService([
            $this->conversationRow([
                'direct_key' => null,
                'title' => 'Новая',
                'last_message_id' => null,
                'last_message_user_id' => null,
                'last_message_text' => null,
                'last_message_created_at' => null,
            ]),
        ]);

        self::assertNull($service->getUserConversationsWithMeta(7)[0]['last_message']);
    }

    /* ===============================
       The rest of the row
    =============================== */

    public function testTheUnreadCountIsAnInteger(): void
    {
        $service = $this->makeService(
            [$this->conversationRow(['unread_count' => '7'])],
            [$this->userRow(3, 'Пётр')]
        );

        // The client adds these up for the global badge, so a string here
        // would concatenate rather than sum.
        self::assertSame(7, $service->getUserConversationsWithMeta(7)[0]['unread_count']);
        self::assertSame(11, $service->getUserConversationsWithMeta(7)[0]['id']);
    }

    public function testAnEmptyInboxIsAnEmptyList(): void
    {
        $service = $this->makeService([]);

        $list = $service->getUserConversationsWithMeta(7);

        self::assertSame([], $list);
        // And neither lookup is made for nobody: findByIds() and
        // getOnlineUserIds() both short-circuit on an empty id list.
        self::assertStringNotContainsString('FROM users', $this->sqls());
        self::assertStringNotContainsString('user_sessions', $this->sqls());
    }

    public function testTheOrderTheRepositoryGaveIsKept(): void
    {
        // The repository sorts by last_message_at DESC; array_map preserves
        // that, and the list is rendered top to bottom as given.
        $service = $this->makeService([
            $this->conversationRow(['id' => '11', 'direct_key' => null, 'title' => 'Раз']),
            $this->conversationRow(['id' => '12', 'direct_key' => null, 'title' => 'Два']),
            $this->conversationRow(['id' => '13', 'direct_key' => null, 'title' => 'Три']),
        ]);

        self::assertSame(
            ['Раз', 'Два', 'Три'],
            array_column($service->getUserConversationsWithMeta(7), 'title')
        );
    }

    /**
     * Both a direct partner and a message sender end up in the same user
     * lookup, so a list of twenty conversations is one query rather than forty.
     */
    public function testEverybodyNeededIsLookedUpInOneQuery(): void
    {
        $service = $this->makeService(
            [
                $this->conversationRow(['id' => '11', 'direct_key' => '3:7', 'last_message_user_id' => '3']),
                $this->conversationRow(['id' => '12', 'direct_key' => '7:9', 'last_message_user_id' => '5']),
            ],
            [$this->userRow(3, 'Пётр'), $this->userRow(9, 'Мария'), $this->userRow(5, 'Иван')]
        );

        $service->getUserConversationsWithMeta(7);

        $userQueries = array_filter(
            $this->queries,
            static fn (array $q): bool => str_contains($q[0], 'FROM users')
        );

        self::assertCount(1, $userQueries);

        // The partners (3, 9) and the senders (3, 5), deduplicated. Sorted
        // here only to compare: findByIds() preserves the order it was given,
        // which is partner-then-sender row by row.
        $ids = array_map('intval', reset($userQueries)[1]);
        sort($ids);

        self::assertSame([3, 5, 9], $ids);
    }
}

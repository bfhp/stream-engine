<?php

declare(strict_types=1);

namespace Tests\Service;

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
 * `getMessagesWithMeta()` - the payload behind the open conversation's poll.
 *
 * The busiest read in the application: an open messenger tab asks for this
 * every few seconds, and it answers seven separate questions in one round trip
 * precisely so it does not have to be seven polls.
 *
 * The one that is easy to miss is the second list. `getAfter()` returns only
 * messages *newer* than what the client already has - so an edit or a deletion
 * of an older message would never reach a tab that is already open. `edited`
 * and `deleted_ids` are how it does: a separate lookback query, split by
 * whether the row has a `deleted_at`. Break that split and one participant's
 * correction silently never appears on the other's screen.
 *
 * Routing this needed more care than the other two reads - it makes two
 * `messages` queries and three `conversation_participants` ones, so the double
 * below keys on the clause that distinguishes each rather than on the table.
 */
final class MessageServicePollTest extends TestCase
{
    /** @var list<array{string, array}> */
    private array $queries = [];

    /**
     * @param list<array<string, mixed>> $after     what getAfter() returns
     * @param list<array<string, mixed>> $changed   what getRecentlyChanged() returns
     * @param list<int>                  $typingIds
     * @param array<int, int>            $readStates
     * @param list<int>                  $participantIds
     * @param list<int>                  $onlineIds
     * @param list<array<string, mixed>> $users
     */
    private function makeService(
        bool $isParticipant = true,
        array $after = [],
        array $changed = [],
        array $typingIds = [],
        array $readStates = [],
        array $participantIds = [],
        array $onlineIds = [],
        array $users = []
    ): MessageService {
        $db = $this->createStub(PdoDatabase::class);

        $db->method('fetchOne')->willReturnCallback(
            function (string $sql, array $params = []) use ($isParticipant): ?array {
                $this->queries[] = [$sql, $params];

                // isParticipant() is the only fetchOne on this path.
                return $isParticipant ? ['1' => '1'] : null;
            }
        );

        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, array $params = []) use (
                $after,
                $changed,
                $typingIds,
                $readStates,
                $participantIds,
                $onlineIds,
                $users
            ): array {
                $this->queries[] = [$sql, $params];

                // Keyed on the distinguishing clause, not the table: two of
                // these read `messages` and three read
                // `conversation_participants`.
                if (str_contains($sql, 'AND m.id > ?')) {
                    return $after;
                }

                if (str_contains($sql, 'm.deleted_at IS NOT NULL')) {
                    return $changed;
                }

                if (str_contains($sql, 'last_typing_at')) {
                    return array_map(static fn (int $id): array => ['user_id' => (string) $id], $typingIds);
                }

                if (str_contains($sql, 'last_read_message_id')) {
                    return array_map(
                        static fn (int $id, int $read): array => [
                            'user_id' => (string) $id,
                            'last_read_message_id' => (string) $read,
                        ],
                        array_keys($readStates),
                        array_values($readStates)
                    );
                }

                if (str_contains($sql, 'user_sessions')) {
                    return array_map(static fn (int $id): array => ['user_id' => (string) $id], $onlineIds);
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

    /** A row as the display query returns it, joins included. */
    private function messageRow(array $overrides = []): array
    {
        return array_merge([
            'id' => '12',
            'user_id' => '7',
            'text' => 'Привет',
            'created_at' => '1700000000',
            'updated_at' => null,
            'deleted_at' => null,
            'reply_to_message_id' => null,
            'reply_user_id' => null,
            'reply_text' => null,
            'attachment_id' => null,
            'attachment_path' => null,
            'attachment_mime' => null,
            'attachment_size' => null,
            'attachment_original_name' => null,
        ], $overrides);
    }

    private function userRow(int $id, bool $hidePresence = false): array
    {
        return [
            'id' => (string) $id,
            'nick' => 'User '.$id,
            'avatar_url' => '',
            'created_at' => '1600000000',
            'signature' => '',
            'hide_presence' => $hidePresence ? '1' : '0',
        ];
    }

    private function sqls(): string
    {
        return implode("\n---\n", array_column($this->queries, 0));
    }

    /* ===============================
       The guard
    =============================== */

    /**
     * The read guard on the busiest endpoint in the app. Without it, a
     * logged-in stranger polling `/api/v1/conversations/<id>/messages` would
     * receive somebody else's conversation a few seconds at a time.
     */
    public function testANonParticipantIsRefused(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->makeService(isParticipant: false)->getMessagesWithMeta(11, 7, 0);
    }

    public function testNothingElseIsQueriedOnceAccessIsRefused(): void
    {
        try {
            $this->makeService(isParticipant: false)->getMessagesWithMeta(11, 7, 0);
            self::fail('a non-participant must be refused');
        } catch (ForbiddenException) {
            // Exactly one query - the membership check itself. A poll from a
            // stranger must not cost six.
            self::assertCount(1, $this->queries);
        }
    }

    /* ===============================
       New messages
    =============================== */

    public function testNewMessagesComeBackMapped(): void
    {
        $service = $this->makeService(after: [
            $this->messageRow(['id' => '12', 'text' => 'Первое']),
            $this->messageRow(['id' => '13', 'text' => 'Второе']),
        ]);

        $payload = $service->getMessagesWithMeta(11, 7, 11);

        self::assertSame([12, 13], array_column($payload['messages'], 'id'));
        self::assertSame('Первое', $payload['messages'][0]['text']);
        // Mapped, not raw rows - `edited` is derived, so its presence is what
        // proves mapMessage() ran.
        self::assertArrayHasKey('edited', $payload['messages'][0]);
    }

    public function testTheClientsCursorIsPassedToTheQuery(): void
    {
        $this->makeService()->getMessagesWithMeta(11, 7, 42);

        $afterQuery = array_values(array_filter(
            $this->queries,
            static fn (array $q): bool => str_contains($q[0], 'AND m.id > ?')
        ));

        // conversation, afterId, limit - the cursor is what stops every poll
        // re-sending the whole conversation.
        self::assertSame([11, 42, 50], $afterQuery[0][1]);
    }

    public function testAQuietConversationStillAnswers(): void
    {
        // Nothing new, but the poll must still return the other six keys -
        // read receipts and presence change without any new message.
        $payload = $this->makeService()->getMessagesWithMeta(11, 7, 99);

        self::assertSame([], $payload['messages']);
        self::assertSame([], $payload['edited']);
        self::assertSame([], $payload['deleted_ids']);
        self::assertArrayHasKey('read_states', $payload);
        self::assertArrayHasKey('online_user_ids', $payload);
        self::assertArrayHasKey('server_time_ms', $payload);
    }

    /* ===============================
       Edits and deletions of older messages
    =============================== */

    /**
     * The mechanism this whole second query exists for: `getAfter()` will never
     * return message 5 again once the client has it, so an edit to message 5
     * reaches the open tab only through here.
     */
    public function testAnEditedOlderMessageComesBackSeparately(): void
    {
        $service = $this->makeService(changed: [
            $this->messageRow(['id' => '5', 'text' => 'Исправленный', 'updated_at' => '1700000100']),
        ]);

        $payload = $service->getMessagesWithMeta(11, 7, 99);

        self::assertSame([], $payload['messages']);
        self::assertCount(1, $payload['edited']);
        self::assertSame(5, $payload['edited'][0]['id']);
        self::assertSame('Исправленный', $payload['edited'][0]['text']);
        self::assertTrue($payload['edited'][0]['edited']);
    }

    public function testADeletedMessageComesBackAsAnIdOnly(): void
    {
        // Only the id: the client removes the bubble, and sending the text of
        // a deleted message back would defeat deleting it.
        $service = $this->makeService(changed: [
            $this->messageRow(['id' => '5', 'text' => 'Удалённый', 'deleted_at' => '1700000100']),
        ]);

        $payload = $service->getMessagesWithMeta(11, 7, 99);

        self::assertSame([5], $payload['deleted_ids']);
        self::assertSame([], $payload['edited']);
    }

    public function testTheTwoAreSplitByWhetherTheRowIsDeleted(): void
    {
        $service = $this->makeService(changed: [
            $this->messageRow(['id' => '5', 'updated_at' => '1700000100']),
            $this->messageRow(['id' => '6', 'deleted_at' => '1700000100']),
            $this->messageRow(['id' => '7', 'updated_at' => '1700000200']),
            // Deleted *and* previously edited - deletion wins, because there
            // is nothing to show.
            $this->messageRow(['id' => '8', 'updated_at' => '1700000100', 'deleted_at' => '1700000300']),
        ]);

        $payload = $service->getMessagesWithMeta(11, 7, 99);

        self::assertSame([5, 7], array_column($payload['edited'], 'id'));
        self::assertSame([6, 8], $payload['deleted_ids']);
    }

    /* ===============================
       Typing, read state, presence
    =============================== */

    public function testTypingUsersComeBackAsIntegers(): void
    {
        $payload = $this->makeService(typingIds: [3, 9])->getMessagesWithMeta(11, 7, 0);

        self::assertSame([3, 9], $payload['typing']);
    }

    public function testTheViewerIsExcludedFromTheTypingQuery(): void
    {
        // Excluded in SQL, not filtered afterwards - so "вы печатаете" never
        // appears on your own screen.
        $this->makeService()->getMessagesWithMeta(11, 7, 0);

        $typingQuery = array_values(array_filter(
            $this->queries,
            static fn (array $q): bool => str_contains($q[0], 'last_typing_at')
        ));

        self::assertSame([11, 7], $typingQuery[0][1]);
    }

    public function testReadStatesArePassedThroughPerParticipant(): void
    {
        // Per-participant last_read_message_id, not a per-message read log -
        // the client compares each message's id against these to draw ticks.
        $payload = $this->makeService(readStates: [3 => 12, 7 => 10])->getMessagesWithMeta(11, 7, 0);

        self::assertSame([3 => 12, 7 => 10], $payload['read_states']);
    }

    public function testPresenceComesBackAsAListOfIdsRatherThanAMap(): void
    {
        // array_keys() of the repository's id => true map: the client iterates
        // it, and a map keyed by id would serialise as an object.
        $payload = $this->makeService(
            participantIds: [3, 7, 9],
            onlineIds: [3, 9]
        )->getMessagesWithMeta(11, 7, 0);

        self::assertSame([3, 9], $payload['online_user_ids']);
    }

    public function testHiddenParticipantsAreExcludedFromPresencePoll(): void
    {
        $payload = $this->makeService(
            participantIds: [3, 7, 9],
            onlineIds: [3, 9],
            users: [
                $this->userRow(3, hidePresence: true),
                $this->userRow(9),
            ]
        )->getMessagesWithMeta(11, 7, 0);

        self::assertSame([9], $payload['online_user_ids']);
    }

    public function testPresenceIsAskedAboutThisConversationsParticipants(): void
    {
        // Riding the same poll rather than a dedicated presence one - so the
        // chat header's dot refreshes without a second request.
        $this->makeService(participantIds: [3, 7, 9])->getMessagesWithMeta(11, 7, 0);

        self::assertStringContainsString('user_sessions', $this->sqls());
    }

    public function testNobodyOnlineIsAnEmptyList(): void
    {
        $payload = $this->makeService(participantIds: [3, 7])->getMessagesWithMeta(11, 7, 0);

        self::assertSame([], $payload['online_user_ids']);
    }

    /* ===============================
       The clock
    =============================== */

    public function testTheServerClockIsReportedInMilliseconds(): void
    {
        $before = (int) (microtime(true) * 1000);
        $payload = $this->makeService()->getMessagesWithMeta(11, 7, 0);
        $after = (int) (microtime(true) * 1000);

        // Milliseconds, not seconds: the client uses it to correct its own
        // clock when labelling "только что", and a seconds value would be off
        // by a factor of a thousand rather than visibly wrong.
        self::assertGreaterThanOrEqual($before, $payload['server_time_ms']);
        self::assertLessThanOrEqual($after, $payload['server_time_ms']);
    }
}

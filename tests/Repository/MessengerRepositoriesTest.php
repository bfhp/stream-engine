<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\ConversationRepository;
use StreamEngine\Repository\MessageRepository;
use StreamEngine\Repository\ParticipantRepository;

/**
 * The messenger's three repositories, which had no tests between them.
 *
 * They are thin SQL wrappers, so most of what matters is *in the statement*:
 * soft-delete visibility, the read-state high-water mark, the typing TTL. Those
 * rules are load-bearing and invisible - getting one wrong doesn't throw, it
 * quietly shows a deleted message, or lets a read receipt move backwards - so
 * they are asserted against the SQL and its bound parameters. Where there is
 * real PHP logic (hydration, the empty-input guard, the ?? 0 default) the
 * behaviour is asserted directly.
 *
 * Grouped in one file because the three are one subsystem and several of the
 * rules span them: MessageRepository soft-deletes, and ConversationRepository's
 * cache refresh is what stops the deleted row being shown as a preview.
 */
final class MessengerRepositoriesTest extends TestCase
{
    /** @var list<array{0: string, 1: array}> */
    private array $writes = [];

    /** @var list<array{0: string, 1: array}> */
    private array $reads = [];

    /** @param array<string, mixed>|list<array<string, mixed>>|null $result */
    private function db(mixed $result = null): PdoDatabase
    {
        $db = $this->createStub(PdoDatabase::class);

        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []): int {
                $this->writes[] = [$sql, $params];

                return 1;
            }
        );

        $db->method('fetchOne')->willReturnCallback(
            function (string $sql, array $params = []) use ($result): ?array {
                $this->reads[] = [$sql, $params];

                return is_array($result) && $result !== [] && ! array_is_list($result)
                    ? $result
                    : null;
            }
        );

        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, array $params = []) use ($result): array {
                $this->reads[] = [$sql, $params];

                return is_array($result) && ($result === [] || array_is_list($result)) ? $result : [];
            }
        );

        $db->method('lastInsertId')->willReturn(99);

        return $db;
    }

    /**
     * @param list<array{0: string, 1: array}> $calls
     * @return array{0: string, 1: array}
     */
    private function only(array $calls): array
    {
        $this->assertCount(1, $calls, 'expected exactly one statement');

        return $calls[0];
    }

    /* ===============================
       MessageRepository
    =============================== */

    /**
     * Deletion is soft, and that is the whole propagation mechanism: the row
     * stays so getRecentlyChanged() can tell participants who already rendered
     * the message that it's gone.
     */
    public function testDeleteIsSoft(): void
    {
        (new MessageRepository($this->db()))->delete(7);

        [$sql, $params] = $this->only($this->writes);

        $this->assertStringContainsString('UPDATE messages', $sql);
        $this->assertStringContainsString('deleted_at = UNIX_TIMESTAMP()', $sql);
        $this->assertStringNotContainsString('DELETE', $sql);
        $this->assertSame([7], $params);
    }

    /**
     * The other half of soft delete: findById() still finds the row, and
     * returns `deleted_at` so callers can tell. MessageService::delete() relies
     * on exactly this to answer "already deleted" as NotFound rather than
     * silently succeeding twice.
     */
    public function testFindByIdSelectsDeletedAtAndDoesNotFilterOnIt(): void
    {
        (new MessageRepository($this->db()))->findById(7);

        [$sql, $params] = $this->only($this->reads);

        $this->assertStringContainsString('deleted_at', $sql);
        $this->assertStringNotContainsString('deleted_at IS NULL', $sql);
        $this->assertSame([7], $params);
    }

    public function testGetAfterHidesDeletedMessagesAndPagesById(): void
    {
        (new MessageRepository($this->db()))->getAfter(5, afterId: 30, limit: 50);

        [$sql, $params] = $this->only($this->reads);

        // Never surface a deleted message as new to someone who hasn't seen it.
        $this->assertStringContainsString('m.deleted_at IS NULL', $sql);
        $this->assertStringContainsString('m.id > ?', $sql);
        $this->assertStringContainsString('ORDER BY m.id', $sql);
        $this->assertSame([5, 30, 50], $params);
    }

    /**
     * The mirror image of getAfter(), and the reason both exist: this one has
     * to *include* deleted rows, since a delete reaching an already-open client
     * is the only thing it is for.
     */
    public function testGetRecentlyChangedIncludesDeletedRowsWithinTheWindow(): void
    {
        (new MessageRepository($this->db()))->getRecentlyChanged(5, lookbackSeconds: 20);

        [$sql, $params] = $this->only($this->reads);

        $this->assertStringContainsString('m.deleted_at IS NOT NULL', $sql);
        $this->assertStringContainsString('m.updated_at IS NOT NULL', $sql);
        // The window is bound twice, once per arm.
        $this->assertSame([5, 20, 20], $params);
    }

    public function testInsertReturnsTheNewId(): void
    {
        $id = (new MessageRepository($this->db()))->insert(5, 7, 'привет', null, null);

        [, $params] = $this->only($this->writes);

        $this->assertSame(99, $id);
        // Nulls are passed through rather than omitted - the column list is
        // fixed, so a dropped reply or attachment has to arrive as NULL.
        $this->assertSame([5, 7, 'привет', null, null], $params);
    }

    public function testUpdateTextStampsUpdatedAtSoTheChangeCanPropagate(): void
    {
        (new MessageRepository($this->db()))->updateText(7, 'новый текст');

        [$sql, $params] = $this->only($this->writes);

        // Without updated_at, getRecentlyChanged() would never see the edit and
        // other participants would keep the old text until they reloaded.
        $this->assertStringContainsString('updated_at = UNIX_TIMESTAMP()', $sql);
        $this->assertSame(['новый текст', 7], $params);
    }

    /* ===============================
       ParticipantRepository
    =============================== */

    public function testAddUsersIgnoreDoesNothingForAnEmptyList(): void
    {
        (new ParticipantRepository($this->db()))->addUsersIgnore(5, []);

        // Not merely harmless: `VALUES` with nothing after it is a syntax error.
        $this->assertSame([], $this->writes);
    }

    public function testAddUsersIgnoreBuildsOnePairPerUserAndToleratesDuplicates(): void
    {
        (new ParticipantRepository($this->db()))->addUsersIgnore(5, [7, '8']);

        [$sql, $params] = $this->only($this->writes);

        // INSERT IGNORE, so adding someone already in the conversation is a
        // no-op rather than a duplicate-key 500 - which is what makes
        // createOrGetDirect()'s retry-after-rollback safe.
        $this->assertStringContainsString('INSERT IGNORE', $sql);
        $this->assertStringContainsString('VALUES (?, ?),(?, ?)', $sql);
        // Ids are cast, since they arrive from a JSON payload as strings.
        $this->assertSame([5, 7, 5, 8], $params);
    }

    /**
     * A read receipt is a high-water mark. GREATEST(IFNULL(...)) is what stops
     * it moving backwards - which two polls arriving out of order would
     * otherwise do, un-reading messages the sender had already been told were
     * read.
     */
    public function testMarkAsReadNeverMovesTheMarkerBackwards(): void
    {
        (new ParticipantRepository($this->db()))->markAsRead(5, 7, 42);

        [$sql, $params] = $this->only($this->writes);

        $this->assertStringContainsString('GREATEST(IFNULL(last_read_message_id, 0), ?)', $sql);
        $this->assertSame([42, 5, 7], $params);
    }

    public function testGetReadStatesKeysByUserAndDefaultsToZero(): void
    {
        $db = $this->db([
            ['user_id' => '7', 'last_read_message_id' => '42'],
            // Never read anything - NULL in the column, 0 to the caller, which
            // computeReadStatus() then compares as "has read nothing".
            ['user_id' => '8', 'last_read_message_id' => null],
        ]);

        $this->assertSame(
            [7 => 42, 8 => 0],
            (new ParticipantRepository($db))->getReadStates(5)
        );
    }

    public function testGetUserIdsHydratesToInts(): void
    {
        $db = $this->db([['user_id' => '7'], ['user_id' => '8']]);

        $this->assertSame([7, 8], (new ParticipantRepository($db))->getUserIds(5));
    }

    public function testIsParticipantIsTrueOnlyWhenARowComesBack(): void
    {
        // Any non-empty assoc row will do - isParticipant() only asks whether
        // fetchOne() returned something, since the query selects a literal 1.
        $present = new ParticipantRepository($this->db(['found' => 1]));
        $this->assertTrue($present->isParticipant(5, 7));

        $this->reads = [];

        $absent = new ParticipantRepository($this->db());
        $this->assertFalse($absent->isParticipant(5, 99));
    }

    /**
     * Typing is a 5-second TTL rather than a start/stop protocol - nothing has
     * to send "stopped typing", and a client that vanishes mid-word stops
     * showing as typing on its own.
     */
    public function testGetTypingUsersExcludesTheCallerAndExpiresAfterFiveSeconds(): void
    {
        (new ParticipantRepository($this->db()))->getTypingUsers(5, excludeUserId: 7);

        [$sql, $params] = $this->only($this->reads);

        // You are always "typing" to yourself; showing it back would be noise.
        $this->assertStringContainsString('user_id != ?', $sql);
        $this->assertStringContainsString('last_typing_at > (UNIX_TIMESTAMP() - 5)', $sql);
        $this->assertSame([5, 7], $params);
    }

    public function testRemoveUserTargetsOneMembershipRow(): void
    {
        (new ParticipantRepository($this->db()))->removeUser(5, 7);

        [$sql, $params] = $this->only($this->writes);

        // Both halves of the key, so leaving one conversation can't remove you
        // from the others.
        $this->assertStringContainsString('conversation_id = ? AND user_id = ?', $sql);
        $this->assertSame([5, 7], $params);
    }

    public function testUpdateTypingStampsTheCallersOwnRow(): void
    {
        (new ParticipantRepository($this->db()))->updateTyping(5, 7);

        [$sql, $params] = $this->only($this->writes);

        $this->assertStringContainsString('last_typing_at = UNIX_TIMESTAMP()', $sql);
        $this->assertSame([5, 7], $params);
    }

    /* ===============================
       ConversationRepository
    =============================== */

    public function testCreateGroupStoresItsTitleAndReturnsTheNewId(): void
    {
        $id = (new ConversationRepository($this->db()))->createGroup('Наш чат');

        [, $params] = $this->only($this->writes);

        $this->assertSame(99, $id);
        $this->assertSame(['Наш чат'], $params);
    }

    public function testCreateGroupAcceptsNoTitle(): void
    {
        // Untitled is ordinary - MessageService::getConversation() falls back to
        // "Групповой чат" for display rather than requiring one at creation.
        (new ConversationRepository($this->db()))->createGroup(null);

        [, $params] = $this->only($this->writes);

        $this->assertSame([null], $params);
    }

    /**
     * The cheap path, taken on every send: point the cache straight at the
     * message just inserted. refreshLastMessage() below is the expensive
     * recompute, only needed when a message disappears.
     */
    public function testUpdateCachePointsAtTheMessageJustSent(): void
    {
        (new ConversationRepository($this->db()))->updateCache(5, 42);

        [$sql, $params] = $this->only($this->writes);

        $this->assertStringContainsString('last_message_id = ?', $sql);
        $this->assertStringContainsString('last_message_at = UNIX_TIMESTAMP()', $sql);
        $this->assertSame([42, 5], $params);
    }

    /**
     * The vanished-preview guard. When the cached last message is deleted, the
     * conversation list would keep showing it forever - so the cache is
     * recomputed from the newest *non-deleted* message.
     */
    public function testRefreshLastMessageIgnoresDeletedMessages(): void
    {
        $db = $this->db(['id' => '41', 'created_at' => '1700000000']);

        (new ConversationRepository($db))->refreshLastMessage(5);

        [$readSql] = $this->only($this->reads);
        $this->assertStringContainsString('deleted_at IS NULL', $readSql);
        $this->assertStringContainsString('ORDER BY id DESC', $readSql);

        [$writeSql, $writeParams] = $this->only($this->writes);
        $this->assertStringContainsString('UPDATE conversations', $writeSql);
        $this->assertSame(['41', '1700000000', 5], $writeParams);
    }

    public function testRefreshLastMessageClearsTheCacheWhenNothingIsLeft(): void
    {
        // Every message in the conversation deleted - the preview must become
        // empty rather than keep pointing at a row nobody can see.
        (new ConversationRepository($this->db()))->refreshLastMessage(5);

        [, $params] = $this->only($this->writes);

        $this->assertSame([null, 0, 5], $params);
    }

    public function testFindByDirectKeyReturnsAnIntOrNull(): void
    {
        $found = new ConversationRepository($this->db(['id' => '11']));
        $this->assertSame(11, $found->findByDirectKey('2:5'));

        $this->reads = [];

        $missing = new ConversationRepository($this->db());
        $this->assertNull($missing->findByDirectKey('2:5'));
    }

    public function testCreateDirectStoresTheKeyAndReturnsTheNewId(): void
    {
        $id = (new ConversationRepository($this->db()))->createDirect('2:5');

        [$sql, $params] = $this->only($this->writes);

        $this->assertSame(99, $id);
        $this->assertStringContainsString('direct_key', $sql);
        $this->assertSame(['2:5'], $params);
    }

    /**
     * getMeta() is what tells addParticipants()/removeParticipant() that a
     * conversation is a direct one and therefore has a fixed membership, so
     * `direct_key` has to come back.
     */
    public function testGetMetaSelectsTheDirectKey(): void
    {
        (new ConversationRepository($this->db()))->getMeta(5);

        [$sql, $params] = $this->only($this->reads);

        $this->assertStringContainsString('direct_key', $sql);
        $this->assertSame([5], $params);
    }

    /**
     * The access primitive under MessageService::getConversation(): the join to
     * conversation_participants *is* the permission check, so a non-participant
     * gets no row rather than a row they then have to be refused.
     */
    public function testGetByIdForUserJoinsOnParticipation(): void
    {
        (new ConversationRepository($this->db()))->getByIdForUser(5, 7);

        [$sql, $params] = $this->only($this->reads);

        $this->assertStringContainsString('JOIN conversation_participants', $sql);
        $this->assertStringContainsString('cp.user_id = ?', $sql);
        $this->assertSame([5, 7], $params);
    }
}

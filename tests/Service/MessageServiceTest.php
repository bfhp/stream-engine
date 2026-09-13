<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\User;
use StreamEngine\Repository\ConversationRepository;
use StreamEngine\Repository\MessageRepository;
use StreamEngine\Repository\ParticipantRepository;
use StreamEngine\Repository\UploadRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\MessageService;
use Tests\Support\FakePdoDatabase;

/**
 * MessageService/every repository involved here are `final`, so (same as
 * UserService/AuthService elsewhere in this test suite - see
 * ControllerFactoryTest's own comment) they can't be doubled with
 * createStub()/createMock(). A real instance is built around a
 * PdoDatabase double instead, same technique ControllerFactoryTest already
 * uses.
 *
 * Covers notify() specifically - the reserved "system" account's own
 * wrapper over createOrGetDirect()/send() (see MessageService's own
 * docblock for why that's just a regular conversation/message rather than
 * a separate subsystem). Most of these tests use FakePdoDatabase (see its
 * own docblock) - the same in-memory fake FriendServiceTest/
 * UsersControllerTest already use for this messenger stack - since it lets
 * us assert on what actually happened (which conversation/messages exist)
 * rather than which SQL-ish strings were passed in.
 * testNotifyWrapsUnderlyingFailuresInARuntimeException() is the exception:
 * it only needs *some* PdoDatabase that throws on the first query, and
 * FakePdoDatabase is `final` (can't be subclassed to inject that failure),
 * so it stays on a plain createStub().
 */
final class MessageServiceTest extends TestCase
{
    /** Message text long enough to survive the purifier untouched. */
    private const TEXT = 'привет';

    private function makeService(PdoDatabase $db): MessageService
    {
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
     * A PdoDatabase that answers the three lookups this service's guards turn
     * on - "is this user a participant", "what does this message row look like",
     * "who owns this upload" - and records every write.
     *
     * Routed by SQL substring, the same way UsersControllerTest's own
     * makeRoutingDb() does. Deliberately not FakePdoDatabase: that fake models
     * messages as {conversation_id, user_id, text} with no id, created_at or
     * deleted_at, so it cannot express "a message sent 901 seconds ago" or "a
     * soft-deleted message" - which is most of what needs asserting here.
     * Extending it is only worthwhile if a future service test needs realistic
     * repository state; for these guards, canned rows are both enough and
     * clearer about what each test assumes. Remaining coverage work is tracked
     * in docs/TODO.md.
     *
     * @param list<int> $participantIds who is in conversation 5
     * @param array<int, array<string, mixed>> $messageRows message id => row
     * @param array<int, int|null> $uploadOwners upload id => owning user id
     * @param list<array{string, array}> $writes filled in with every execute()
     */
    private function makeRoutingDb(
        array $participantIds = [7],
        array $messageRows = [],
        array $uploadOwners = [],
        array &$writes = []
    ): PdoDatabase {
        $db = $this->createStub(PdoDatabase::class);

        $db->method('fetchOne')->willReturnCallback(
            function (string $sql, array $params) use ($participantIds, $messageRows, $uploadOwners): ?array {
                if (str_contains($sql, 'FROM conversation_participants')) {
                    return in_array((int) $params[1], $participantIds, true) ? ['1' => 1] : null;
                }

                // getConversation()'s access check is this join rather than
                // isParticipant() - no row means no access. Handled explicitly
                // so the negative case can't pass merely because the router
                // didn't recognise the query.
                if (str_contains($sql, 'JOIN conversation_participants')) {
                    return in_array((int) $params[1], $participantIds, true)
                        ? [
                            'id' => (int) $params[0],
                            'title' => null,
                            'direct_key' => '7:8',
                            'last_message_id' => null,
                            'last_read_message_id' => null,
                        ]
                        : null;
                }

                if (str_contains($sql, 'FROM messages')) {
                    return $messageRows[(int) $params[0]] ?? null;
                }

                if (str_contains($sql, 'FROM uploads')) {
                    $id = (int) $params[0];

                    return array_key_exists($id, $uploadOwners)
                        ? [
                            'id' => $id,
                            'user_id' => $uploadOwners[$id],
                            'path' => 'a.png',
                            'mime' => 'image/png',
                            'size' => 1,
                            'original_name' => 'a.png',
                            'created_at' => 0,
                        ]
                        : null;
                }

                return null;
            }
        );

        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []) use (&$writes): int {
                $writes[] = [$sql, $params];

                return 1;
            }
        );

        $db->method('lastInsertId')->willReturn(42);

        return $db;
    }

    /** A message row as MessageRepository::findById() selects it. */
    private function messageRow(
        int $id,
        int $userId,
        ?int $ageSeconds = 0,
        ?int $deletedAt = null,
        int $conversationId = 5
    ): array {
        return [
            'id' => $id,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'created_at' => time() - (int) $ageSeconds,
            'deleted_at' => $deletedAt,
        ];
    }

    /** @param list<array{string, array}> $writes */
    private function wrote(array $writes, string $needle): bool
    {
        foreach ($writes as [$sql, $_params]) {
            if (str_contains($sql, $needle)) {
                return true;
            }
        }

        return false;
    }

    /* ===============================
       Access control
    =============================== */

    /**
     * Everything that touches a conversation asks isParticipant() first. This is
     * the layer that keeps private messages private, and none of it was covered.
     *
     * @param callable(MessageService): void $call
     */
    #[DataProvider('participantGatedCallProvider')]
    public function testNonParticipantIsRefused(callable $call): void
    {
        // Conversation 5 contains user 7; user 99 is the outsider.
        $service = $this->makeService($this->makeRoutingDb(participantIds: [7]));

        $this->expectException(ForbiddenException::class);

        $call($service);
    }

    /** @return array<string, array{callable(MessageService): void}> */
    public static function participantGatedCallProvider(): array
    {
        return [
            'send' => [fn (MessageService $s) => $s->send(5, 99, self::TEXT)],
            'read the conversation' => [fn (MessageService $s) => $s->getConversation(5, 99)],
            'read messages' => [fn (MessageService $s) => $s->getMessagesWithMeta(5, 99, 0)],
            'mark as read' => [fn (MessageService $s) => $s->markAsRead(5, 99, 1)],
            'typing' => [fn (MessageService $s) => $s->typing(5, 99)],
            'add participants' => [fn (MessageService $s) => $s->addParticipants(5, 99, [8])],
            'remove a participant' => [fn (MessageService $s) => $s->removeParticipant(5, 99, 7)],
        ];
    }

    public function testSendDoesNotWriteAnythingForANonParticipant(): void
    {
        $writes = [];
        $service = $this->makeService(
            $this->makeRoutingDb(participantIds: [7], writes: $writes)
        );

        try {
            $service->send(5, 99, self::TEXT);
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException) {
            // The guard has to come before the INSERT, not merely alongside it.
            $this->assertSame([], $writes);
        }
    }

    public function testDeleteRefusesSomeoneElsesMessage(): void
    {
        $writes = [];
        $service = $this->makeService($this->makeRoutingDb(
            participantIds: [7, 99],
            messageRows: [1 => $this->messageRow(1, userId: 7)],
            writes: $writes
        ));

        try {
            // 99 is in the conversation - being a participant is not enough.
            $service->delete(1, 99);
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException $e) {
            // The message matters: the participant check throws the same
            // exception type, so without this the test would pass even if
            // ownership were never consulted.
            $this->assertSame("Cannot delete other user's message", $e->getMessage());
            $this->assertSame([], $writes);
        }
    }

    public function testEditRefusesSomeoneElsesMessage(): void
    {
        $writes = [];
        $service = $this->makeService($this->makeRoutingDb(
            participantIds: [7, 99],
            messageRows: [1 => $this->messageRow(1, userId: 7)],
            writes: $writes
        ));

        try {
            $service->edit(1, 99, 'подменённый текст');
            $this->fail('expected ForbiddenException');
        } catch (ForbiddenException $e) {
            $this->assertSame("Cannot edit other user's message", $e->getMessage());
            $this->assertSame([], $writes);
        }
    }

    #[DataProvider('missingMessageProvider')]
    public function testDeleteAndEditTreatAMissingOrDeletedMessageAsNotFound(
        string $method,
        array $rows
    ): void {
        $service = $this->makeService($this->makeRoutingDb(
            participantIds: [7],
            messageRows: $rows
        ));

        $this->expectException(NotFoundException::class);

        $method === 'delete'
            ? $service->delete(1, 7)
            : $service->edit(1, 7, 'новый текст');
    }

    /** @return array<string, array{string, array<int, array<string, mixed>>}> */
    public static function missingMessageProvider(): array
    {
        return [
            // Deleting twice must not look like success - the row is still there,
            // soft-deleted, so `!$message` alone wouldn't catch it.
            'delete: no such message' => ['delete', []],
            'delete: already deleted' => ['delete', [1 => ['id' => 1, 'conversation_id' => 5, 'user_id' => 7, 'created_at' => 0, 'deleted_at' => 123]]],
            'edit: no such message' => ['edit', []],
            'edit: already deleted' => ['edit', [1 => ['id' => 1, 'conversation_id' => 5, 'user_id' => 7, 'created_at' => 0, 'deleted_at' => 123]]],
        ];
    }

    /* ===============================
       Edit window
    =============================== */

    /**
     * created_at is a unix timestamp, not a date-time string, so the comparison
     * has to stay numeric - passing it through strtotime() yields false/0 and
     * makes every edit look expired. That was a real bug, fixed without a
     * regression test; this is the test.
     */
    #[DataProvider('editWindowProvider')]
    public function testEditWindowBoundary(int $ageSeconds, bool $allowed): void
    {
        $writes = [];
        $service = $this->makeService($this->makeRoutingDb(
            participantIds: [7],
            messageRows: [1 => $this->messageRow(1, userId: 7, ageSeconds: $ageSeconds)],
            writes: $writes
        ));

        if (!$allowed) {
            $this->expectException(ForbiddenException::class);
            $service->edit(1, 7, 'новый текст');

            return;
        }

        $service->edit(1, 7, 'новый текст');

        $this->assertTrue($this->wrote($writes, 'UPDATE messages'));
    }

    /** @return array<string, array{int, bool}> */
    public static function editWindowProvider(): array
    {
        return [
            'just sent' => [0, true],
            '899s old - inside' => [899, true],
            '901s old - outside' => [901, false],
            '2h old' => [7200, false],
            // Exactly 900 is deliberately absent. The comparison is
            // `created_at < time() - 900`, so 900 is the inclusive edge - but
            // the row's created_at and the check call time() independently, so a
            // clock tick between them would flip that one case and only that
            // one. 899 and 901 survive a tick; 900 wouldn't.
        ];
    }

    /* ===============================
       send(): reply and attachment hardening
    =============================== */

    /**
     * Returns the params of the INSERT that send() performed.
     *
     * @param list<array{string, array}> $writes
     * @return array{0:int,1:int,2:string,3:?int,4:?int}
     */
    private function insertedMessage(array $writes): array
    {
        foreach ($writes as [$sql, $params]) {
            if (str_contains($sql, 'INSERT INTO messages')) {
                return $params;
            }
        }

        $this->fail('no message was inserted');
    }

    /**
     * A bad reply target is dropped, not rejected: it may simply have raced with
     * a deletion, and failing the whole send would lose the message the visitor
     * actually typed. Spoofing one is the other half - pointing at a message in
     * someone else's conversation must not quote it into this one.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    #[DataProvider('badReplyTargetProvider')]
    public function testSendDropsAnUnusableReplyTarget(array $rows, int $replyTo): void
    {
        $writes = [];
        $service = $this->makeService($this->makeRoutingDb(
            participantIds: [7],
            messageRows: $rows,
            writes: $writes
        ));

        $service->send(5, 7, self::TEXT, replyToMessageId: $replyTo);

        $this->assertNull($this->insertedMessage($writes)[3]);
    }

    /** @return array<string, array{array<int, array<string, mixed>>, int}> */
    public static function badReplyTargetProvider(): array
    {
        return [
            'no such message' => [[], 999],
            'a message in another conversation' => [
                [9 => ['id' => 9, 'conversation_id' => 6, 'user_id' => 7, 'created_at' => 0, 'deleted_at' => null]],
                9,
            ],
            'a soft-deleted message' => [
                [9 => ['id' => 9, 'conversation_id' => 5, 'user_id' => 7, 'created_at' => 0, 'deleted_at' => 123]],
                9,
            ],
        ];
    }

    public function testSendKeepsAValidReplyTarget(): void
    {
        $writes = [];
        $service = $this->makeService($this->makeRoutingDb(
            participantIds: [7],
            messageRows: [9 => $this->messageRow(9, userId: 8)],
            writes: $writes
        ));

        $service->send(5, 7, self::TEXT, replyToMessageId: 9);

        // The other side of the tests above: without this, an implementation
        // that dropped every reply would pass all of them.
        $this->assertSame(9, $this->insertedMessage($writes)[3]);
    }

    public function testSendDropsAnAttachmentOwnedBySomeoneElse(): void
    {
        $writes = [];
        $service = $this->makeService($this->makeRoutingDb(
            participantIds: [7],
            uploadOwners: [3 => 99], // upload 3 belongs to user 99
            writes: $writes
        ));

        $service->send(5, 7, self::TEXT, attachmentUploadId: 3);

        $this->assertNull($this->insertedMessage($writes)[4]);
    }

    public function testSendKeepsTheSendersOwnAttachment(): void
    {
        $writes = [];
        $service = $this->makeService($this->makeRoutingDb(
            participantIds: [7],
            uploadOwners: [3 => 7],
            writes: $writes
        ));

        $service->send(5, 7, self::TEXT, attachmentUploadId: 3);

        $this->assertSame(3, $this->insertedMessage($writes)[4]);
    }

    /**
     * The second empty check, the one that is easy to read as redundant: text
     * alone is empty *and* the attachment that justified it has just been
     * dropped, so there is nothing left to send. Without it the row would be an
     * empty message with no attachment.
     */
    public function testSendRefusesWhenTheOnlyAttachmentWasDropped(): void
    {
        $writes = [];
        $service = $this->makeService($this->makeRoutingDb(
            participantIds: [7],
            uploadOwners: [3 => 99],
            writes: $writes
        ));

        try {
            $service->send(5, 7, '', attachmentUploadId: 3);
            $this->fail('expected ValidationException');
        } catch (ValidationException) {
            $this->assertSame([], $writes);
        }
    }

    public function testSendAcceptsAnAttachmentWithNoText(): void
    {
        $writes = [];
        $service = $this->makeService($this->makeRoutingDb(
            participantIds: [7],
            uploadOwners: [3 => 7],
            writes: $writes
        ));

        // A photo with no caption is a legitimate message.
        $service->send(5, 7, '   ', attachmentUploadId: 3);

        $inserted = $this->insertedMessage($writes);
        $this->assertSame('', $inserted[2]);
        $this->assertSame(3, $inserted[4]);
    }

    public function testSendRefusesAnEmptyMessage(): void
    {
        $writes = [];
        $service = $this->makeService($this->makeRoutingDb(participantIds: [7], writes: $writes));

        $this->expectException(ValidationException::class);

        $service->send(5, 7, "  \n\t ");
    }

    /* ===============================
       Sanitisation
    =============================== */

    /**
     * A message is one line of plain text plus, deliberately, a real link - the
     * purifier is configured down to `a[href]` with http(s) only. Everything
     * else is *stripped*, not escaped, which is what lets the client render
     * message text as HTML at all.
     *
     * Asserted through send() rather than by reaching for the private
     * sanitizeText(): the storage path is the thing that matters, and this is
     * the only way text reaches it.
     */
    /**
     * Exact output only where it is unambiguous - plain text, and tags that are
     * removed outright. The anchor cases below assert properties instead: which
     * bytes HTMLPurifier emits for a surviving link is its business (attribute
     * order, URI normalisation), and pinning them would make this a test of the
     * library rather than of the configuration.
     */
    #[DataProvider('strippedProvider')]
    public function testSendStripsEverythingButLinks(string $input, string $expected): void
    {
        $writes = [];
        $service = $this->makeService($this->makeRoutingDb(participantIds: [7], writes: $writes));

        $service->send(5, 7, $input);

        $this->assertSame($expected, $this->insertedMessage($writes)[2]);
    }

    /** @return array<string, array{string, string}> */
    public static function strippedProvider(): array
    {
        return [
            'plain text is untouched' => ['привет', 'привет'],
            // Removed with its contents, not escaped into visible text.
            'a script tag goes, content and all' => ['<script>alert(1)</script>привет', 'привет'],
            'an image goes' => ['<img src="x" onerror="evil()">текст', 'текст'],
            // Only a[href] is allowed, so other tags are unwrapped: the text
            // survives, the markup does not.
            'bold is unwrapped' => ['<b>жирный</b>', 'жирный'],
            'a div is unwrapped' => ['<div>абзац</div>', 'абзац'],
        ];
    }

    public function testSendKeepsAnHttpsLinkButNothingElseOnIt(): void
    {
        $writes = [];
        $service = $this->makeService($this->makeRoutingDb(participantIds: [7], writes: $writes));

        $service->send(
            5,
            7,
            '<a href="https://x.test/a?b=1" onclick="evil()" style="position:fixed" target="_top">клик</a>'
        );

        $text = $this->insertedMessage($writes)[2];

        // The link itself is the point - product intent is that a message can
        // carry a real one (see FriendService's system notifications).
        $this->assertStringContainsString('https://x.test/a?b=1', $text);
        $this->assertStringContainsString('клик', $text);

        // Everything hung off it is not. target/rel are set client-side instead
        // of being trusted from storage - see messages.ts's hardenMessageLinks().
        $this->assertStringNotContainsString('onclick', $text);
        $this->assertStringNotContainsString('style', $text);
        $this->assertStringNotContainsString('target', $text);
    }

    #[DataProvider('disallowedSchemeProvider')]
    public function testSendStripsDisallowedUriSchemes(string $input, string $scheme): void
    {
        $writes = [];
        $service = $this->makeService($this->makeRoutingDb(participantIds: [7], writes: $writes));

        $service->send(5, 7, $input);

        // Only http(s) are allowed. The anchor may or may not survive without
        // its href; the scheme must not survive at all.
        $this->assertStringNotContainsString($scheme, $this->insertedMessage($writes)[2]);
    }

    /** @return array<string, array{string, string}> */
    public static function disallowedSchemeProvider(): array
    {
        return [
            'javascript:' => ['<a href="javascript:alert(1)">клик</a>', 'javascript:'],
            'data:' => ['<a href="data:text/html;base64,PHNjcmlwdD4=">клик</a>', 'data:'],
        ];
    }

    /* ===============================
       Direct conversations
    =============================== */

    /**
     * direct_key is "min:max", so the pair identifies one conversation whichever
     * way round the two users are named. Getting this wrong would give every
     * pair two conversations, each side seeing half the history.
     */
    public function testDirectConversationIsTheSameWhicheverWayRoundThePairIsGiven(): void
    {
        $db = new FakePdoDatabase();
        $service = $this->makeService($db);

        $first = $service->createOrGetDirect(2, 5);
        $second = $service->createOrGetDirect(5, 2);

        $this->assertSame($first, $second);
        $this->assertSame([2, 5], $db->conversationParticipants($first));
    }

    public function testDirectConversationWithYourselfIsRefused(): void
    {
        $service = $this->makeService(new FakePdoDatabase());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot create conversation with yourself');

        $service->createOrGetDirect(7, 7);
    }

    public function testNotifySendsMessageThroughAnExistingDirectConversation(): void
    {
        $db = new FakePdoDatabase();
        $service = $this->makeService($db);

        // The first call creates the direct conversation; the second must
        // reuse the same one (createOrGetDirect() looks it up by
        // direct_key) rather than creating a duplicate - that's the
        // "existing conversation" behaviour worth locking down.
        $service->notify(2, 'Добро пожаловать на сайт!');
        $service->notify(2, 'Второе сообщение');

        self::assertCount(2, $db->messages);
        $conversationId = $db->messages[0]['conversation_id'];
        self::assertSame($conversationId, $db->messages[1]['conversation_id']);
        self::assertSame(
            [User::SYSTEM_USER_ID, 2],
            $db->conversationParticipants($conversationId)
        );
    }

    public function testNotifyWrapsUnderlyingFailuresInARuntimeException(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willThrowException(new RuntimeException('db down'));

        $service = $this->makeService($db);

        // notify() catches Throwable and rethrows as its own
        // RuntimeException (see its own source) rather than swallowing the
        // failure - so callers (e.g. registration) do propagate a broken
        // notification as a failure of their own operation, the same way
        // the registration email already does.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("MessageService::notify failed for user 2: db down");

        $service->notify(2, 'Добро пожаловать на сайт!');
    }

    public function testNotifyIsANoOpWhenRecipientIsTheSystemUserItself(): void
    {
        $db = new FakePdoDatabase();
        $service = $this->makeService($db);

        $service->notify(User::SYSTEM_USER_ID, 'should never be sent');

        // notify() returns before touching the messenger at all - nothing
        // should have been created (FakePdoDatabase can't assert "no method
        // was called" the way a mock can, but it can assert on the state
        // that would exist if it had been).
        self::assertSame([], $db->messages);
    }

    /* =====================================================================
       mapMessage() - the wire format

       Every message the messenger renders goes through this, and none of it
       was covered. It is pure: one database row in, one array for
       json_encode() out. Two things in it are load-bearing beyond the shape.

       `edited` is derived from `updated_at !== null` rather than stored, so a
       message that was never edited must not carry the "изменено" label - and
       a message that was must, because a silently rewritten message in a
       private conversation is the one thing a reader cannot check for
       themselves.

       The attachment `url` is built by concatenation onto `/uploads/`, which
       is worth pinning because the path comes from the database and nothing
       here escapes or validates it.
    ===================================================================== */

    /**
     * One row as the *display* query returns it, joins included.
     *
     * Deliberately not the existing `messageRow()` above: that one is the
     * shape the ownership and edit-window guards look up
     * (id/conversation_id/user_id/created_at/deleted_at), which has none of
     * the reply or attachment columns mapMessage() reads. Two shapes, two
     * names.
     */
    private function mappableRow(array $overrides = []): array
    {
        return array_merge([
            'id' => '12',
            'user_id' => '7',
            'text' => 'Привет',
            'created_at' => '1700000000',
            'updated_at' => null,
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

    private function mapped(array $overrides = []): array
    {
        $method = (new \ReflectionClass(MessageService::class))->getMethod('mapMessage');

        return $method->invoke($this->makeService(new FakePdoDatabase()), $this->mappableRow($overrides));
    }

    public function testAMessageIsMappedWithItsIdentityAsIntegers(): void
    {
        $message = $this->mapped();

        // The row comes back from PDO as strings; the client compares ids
        // numerically when it dedupes overlapping polls.
        self::assertSame(12, $message['id']);
        self::assertSame(7, $message['user_id']);
        self::assertSame(1700000000, $message['created_at']);
        self::assertSame('Привет', $message['text']);
    }

    public function testAnUneditedMessageIsNotMarkedEdited(): void
    {
        self::assertFalse($this->mapped()['edited']);
    }

    public function testAnEditedMessageIsMarkedEdited(): void
    {
        // Any non-null updated_at, whatever its value - the flag is about
        // whether the row was ever touched, not when.
        self::assertTrue($this->mapped(['updated_at' => '1700000100'])['edited']);
    }

    public function testAMessageWithNoReplyHasNoReplyBlock(): void
    {
        // null rather than an empty array: the client tests truthiness to
        // decide whether to render the quoted block at all.
        self::assertNull($this->mapped()['reply']);
    }

    public function testAReplyCarriesTheQuotedMessage(): void
    {
        $reply = $this->mapped([
            'reply_to_message_id' => '9',
            'reply_user_id' => '3',
            'reply_text' => 'Исходное',
        ])['reply'];

        self::assertSame(['id' => 9, 'user_id' => 3, 'text' => 'Исходное'], $reply);
    }

    public function testAMessageWithNoAttachmentHasNoAttachmentBlock(): void
    {
        self::assertNull($this->mapped()['attachment']);
    }

    public function testAnAttachmentUrlIsBuiltFromItsStoredPath(): void
    {
        $attachment = $this->mapped([
            'attachment_id' => '5',
            'attachment_path' => '2026/08/abc123.png',
            'attachment_mime' => 'image/png',
            'attachment_size' => '20480',
            'attachment_original_name' => 'снимок экрана.png',
        ])['attachment'];

        self::assertSame([
            'id' => 5,
            'url' => '/uploads/2026/08/abc123.png',
            'mime' => 'image/png',
            'size' => 20480,
            'original_name' => 'снимок экрана.png',
        ], $attachment);
    }

    /**
     * The original name is passed through untouched - it is the uploader's own
     * string. Safe because it reaches the page as a JSON value that the client
     * writes with `textContent`, and worth recording so that stops being an
     * accident: rendering it as HTML anywhere would be an XSS in a private
     * conversation.
     */
    public function testTheOriginalNameIsNotSanitisedHere(): void
    {
        $attachment = $this->mapped([
            'attachment_id' => '5',
            'attachment_path' => 'x.png',
            'attachment_mime' => 'image/png',
            'attachment_size' => '1',
            'attachment_original_name' => '<script>alert(1)</script>.png',
        ])['attachment'];

        self::assertSame('<script>alert(1)</script>.png', $attachment['original_name']);
    }

    /* =====================================================================
       otherUserIdFromDirectKey() - who the other person is
    ===================================================================== */

    private function otherUser(string $directKey, int $userId): int
    {
        $method = (new \ReflectionClass(MessageService::class))->getMethod('otherUserIdFromDirectKey');

        return $method->invoke($this->makeService(new FakePdoDatabase()), $directKey, $userId);
    }

    /**
     * A direct conversation's key is the two participant ids, smallest first,
     * joined by a colon - which is what makes the pair unique in the database.
     * This reads the *other* id out of it, and the answer decides whose name
     * and avatar the conversation list shows. Getting it backwards labels every
     * chat with the reader's own name.
     */
    #[DataProvider('directKeyProvider')]
    public function testTheOtherParticipantIsReadOutOfTheKey(string $key, int $viewer, int $expected): void
    {
        self::assertSame($expected, $this->otherUser($key, $viewer));
    }

    /** @return array<string, array{string, int, int}> */
    public static function directKeyProvider(): array
    {
        return [
            'viewer is the lower id' => ['3:7', 3, 7],
            'viewer is the higher id' => ['3:7', 7, 3],
            'two-digit ids' => ['12:97', 97, 12],
            // A conversation with oneself - the key has the same id twice, and
            // either branch answers correctly.
            'a note to self' => ['5:5', 5, 5],
        ];
    }

    /**
     * A viewer who is in neither half gets the *first* id back rather than an
     * error. Unreachable through the service, which only ever asks about
     * conversations the user is a participant of - recorded because the
     * fallback is silent, so if that ever stopped being true the messenger
     * would label a conversation with a stranger's name instead of failing.
     */
    public function testAViewerWhoIsNotInTheKeyGetsTheFirstId(): void
    {
        self::assertSame(3, $this->otherUser('3:7', 99));
    }
}

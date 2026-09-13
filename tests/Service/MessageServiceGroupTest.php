<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\Exceptions\ForbiddenException;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\ConversationRepository;
use StreamEngine\Repository\MessageRepository;
use StreamEngine\Repository\ParticipantRepository;
use StreamEngine\Repository\UploadRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\MessageService;

/**
 * The three group mutations - creating one, adding people, removing them.
 *
 * The guard that matters most is the one that reads like a formality:
 * **neither `addParticipants()` nor `removeParticipant()` will touch a direct
 * conversation.** A direct chat is two people and its whole history; quietly
 * adding a third would hand them every message ever sent in it, retroactively.
 * `direct_key !== null` is the only thing stopping that.
 *
 * `createGroup()` is the transaction. It inserts the conversation, then its
 * participants, and a failure between the two would leave a row nobody is in -
 * invisible in every list, reachable by nobody, and impossible to delete
 * through the interface.
 *
 * One thing pinned as a decision rather than approved: in a group, **any
 * participant may remove any other**. There is no owner or moderator check
 * here - see `testAnyParticipantCanRemoveAnyOther`.
 */
final class MessageServiceGroupTest extends TestCase
{
    /** @var list<array{string, array}> every execute() the service made */
    private array $writes = [];

    /**
     * @param list<int>                 $participantIds who isParticipant() says yes for
     * @param array<string, mixed>|null $meta           what getMeta() returns
     */
    private function makeService(
        array $participantIds = [],
        ?array $meta = ['id' => '11', 'direct_key' => null]
    ): MessageService {
        $db = $this->createStub(PdoDatabase::class);

        $db->method('fetchOne')->willReturnCallback(
            static function (string $sql, array $params = []) use ($participantIds, $meta): ?array {
                if (str_contains($sql, 'SELECT 1')) {
                    // isParticipant($conversationId, $userId) - params[1] is
                    // whose membership is being asked about.
                    return in_array((int) ($params[1] ?? 0), $participantIds, true) ? ['1' => '1'] : null;
                }

                if (str_contains($sql, 'direct_key')) {
                    return $meta;
                }

                return null;
            }
        );

        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []): int {
                $this->writes[] = [$sql, $params];

                return 1;
            }
        );

        $db->method('lastInsertId')->willReturn(11);

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
     * The user ids in the participants INSERT.
     *
     * `addUsersIgnore()` binds a (conversation_id, user_id) pair per person,
     * so the conversation id - 11, what lastInsertId() answers - is dropped to
     * leave the people.
     *
     * @return list<int>
     */
    private function addedUserIds(): array
    {
        return array_values(array_filter(
            array_map('intval', $this->writesTo('conversation_participants')[0][1]),
            static fn (int $v): bool => $v !== 11
        ));
    }

    /** @return list<array{string, array}> the writes matching a table */
    private function writesTo(string $needle): array
    {
        return array_values(array_filter(
            $this->writes,
            static fn (array $w): bool => str_contains($w[0], $needle)
        ));
    }

    /* ===============================
       Creating a group
    =============================== */

    public function testCreatingAGroupReturnsItsNewId(): void
    {
        self::assertSame(11, $this->makeService()->createGroup(7, [3, 9], 'Разработка'));
    }

    public function testTheCreatorIsAlwaysAParticipant(): void
    {
        // Even though they are not in the list they passed - a group its own
        // creator cannot see would be created and immediately lost.
        $this->makeService()->createGroup(7, [3, 9], 'Разработка');

        $insert = $this->writesTo('conversation_participants')[0];

        self::assertContains(7, array_map('intval', $insert[1]));
    }

    public function testTheCreatorIsNotAddedTwiceIfTheyAreInTheListAlready(): void
    {
        // Two `array_unique` calls, one before appending the owner and one
        // after - the second is what makes this idempotent. A duplicate row
        // would be refused by the INSERT IGNORE anyway, but the pair count is
        // what the group's member list is built from.
        $this->makeService()->createGroup(7, [3, 7, 9], 'Разработка');

        // addUsersIgnore() binds (conversation_id, user_id) pairs, so the
        // conversation's own id appears once per participant - filtered out
        // here to leave the people.
        $ids = $this->addedUserIds();

        self::assertSame([3, 7, 9], array_values(array_unique($ids)));
        self::assertCount(3, $ids, 'each participant appears once');
    }

    public function testDuplicatesInTheRequestCollapse(): void
    {
        $this->makeService()->createGroup(7, [3, 3, 3], 'Разработка');

        self::assertSame([3, 7], array_values(array_unique($this->addedUserIds())));
    }

    public function testAGroupWithNoOtherPeopleIsStillCreated(): void
    {
        // A group of one is a legitimate starting point - the creator adds
        // people afterwards - so this must not be refused or left empty.
        $this->makeService()->createGroup(7, [], null);

        self::assertNotSame([], $this->writesTo('conversation_participants'));
    }

    /**
     * The transaction, and the reason there is one. If the participant insert
     * fails after the conversation row is in, the result is a conversation
     * nobody belongs to: absent from every list, reachable by nobody, and with
     * no interface to delete it.
     */
    public function testAFailedParticipantInsertRollsBackTheConversation(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $calls = [];

        $db->method('execute')->willReturnCallback(
            static function (string $sql) use (&$calls): int {
                $calls[] = 'execute';

                if (str_contains($sql, 'conversation_participants')) {
                    throw new RuntimeException('deadlock');
                }

                return 1;
            }
        );
        $db->method('lastInsertId')->willReturn(11);
        $db->method('begin')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'begin';
        });
        $db->method('commit')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'commit';
        });
        $db->method('rollback')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'rollback';
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
            $service->createGroup(7, [3], 'Разработка');
            self::fail('the failure must not be swallowed');
        } catch (RuntimeException) {
            // Rolled back, not committed - and the original exception is
            // rethrown rather than turned into a success with a missing group.
            self::assertContains('rollback', $calls);
            self::assertNotContains('commit', $calls);
            self::assertSame('begin', $calls[0]);
        }
    }

    /* ===============================
       Adding people
    =============================== */

    public function testOnlyAParticipantMayAddPeople(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->makeService(participantIds: [3, 9])->addParticipants(11, 7, [5]);
    }

    public function testMembershipIsCheckedBeforeAnythingIsLoaded(): void
    {
        // Refused before getMeta() - so a stranger poking at conversation ids
        // learns nothing about whether they exist.
        try {
            $this->makeService(participantIds: [])->addParticipants(11, 7, [5]);
            self::fail('a non-participant must be refused');
        } catch (ForbiddenException) {
            self::assertSame([], $this->writes);
        }
    }

    public function testAMissingConversationIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->makeService(participantIds: [7], meta: null)->addParticipants(11, 7, [5]);
    }

    /**
     * The guard this file exists for. A direct conversation is two people and
     * everything they have ever said to each other; adding a third would give
     * them all of it, and there is no interface anywhere that expects a direct
     * chat to have three participants.
     */
    public function testNobodyCanBeAddedToADirectConversation(): void
    {
        $service = $this->makeService(
            participantIds: [7],
            meta: ['id' => '11', 'direct_key' => '3:7']
        );

        try {
            $service->addParticipants(11, 7, [5]);
            self::fail('a direct conversation must not gain a third participant');
        } catch (ForbiddenException) {
            self::assertSame([], $this->writes);
        }
    }

    public function testPeopleAreAddedToAGroup(): void
    {
        $this->makeService(participantIds: [7])->addParticipants(11, 7, [3, 9]);

        $insert = $this->writesTo('conversation_participants')[0];

        self::assertStringContainsString('INSERT IGNORE', $insert[0]);
        self::assertContains(3, array_map('intval', $insert[1]));
        self::assertContains(9, array_map('intval', $insert[1]));
    }

    public function testTheActorIsFilteredOutOfTheirOwnRequest(): void
    {
        // They are already in it; INSERT IGNORE would refuse the row anyway,
        // but filtering means a request naming only yourself writes nothing at
        // all rather than issuing a no-op INSERT.
        $this->makeService(participantIds: [7])->addParticipants(11, 7, [7]);

        self::assertSame([], $this->writes);
    }

    public function testAnEmptyRequestWritesNothing(): void
    {
        $this->makeService(participantIds: [7])->addParticipants(11, 7, []);

        self::assertSame([], $this->writes);
    }

    /* ===============================
       Removing people
    =============================== */

    public function testOnlyAParticipantMayRemovePeople(): void
    {
        $this->expectException(ForbiddenException::class);

        $this->makeService(participantIds: [3, 9])->removeParticipant(11, 7, 3);
    }

    public function testARemovalFromAMissingConversationIsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->makeService(participantIds: [7], meta: null)->removeParticipant(11, 7, 3);
    }

    /**
     * The other half of the direct-conversation guard. Removing one of the two
     * would leave a chat with a single participant - which no list, header or
     * title in the messenger knows how to render.
     */
    public function testNobodyCanBeRemovedFromADirectConversation(): void
    {
        $service = $this->makeService(
            participantIds: [3, 7],
            meta: ['id' => '11', 'direct_key' => '3:7']
        );

        try {
            $service->removeParticipant(11, 7, 3);
            self::fail('a direct conversation must not lose a participant');
        } catch (ForbiddenException) {
            self::assertSame([], $this->writes);
        }
    }

    public function testRemovingSomebodyWhoIsNotThereIsAQuietNoOp(): void
    {
        // Idempotent rather than an error: two moderators clicking at once, or
        // a retried request, must not produce a failure the second time.
        $this->makeService(participantIds: [7])->removeParticipant(11, 7, 3);

        self::assertSame([], $this->writes);
    }

    public function testAParticipantIsRemoved(): void
    {
        $this->makeService(participantIds: [3, 7])->removeParticipant(11, 7, 3);

        $delete = $this->writesTo('DELETE FROM conversation_participants')[0];

        // Scoped by both columns: without the conversation_id this would
        // remove them from every group they are in.
        self::assertSame([11, 3], array_map('intval', $delete[1]));
    }

    /**
     * Any participant may remove any other - there is no owner or moderator
     * check on this path. Recorded as it stands rather than approved: for a
     * private group of people who chose each other it is defensible, and for a
     * larger one it means the newest member can empty the room. Adding a role
     * check would be a behaviour change, so it belongs in a decision rather
     * than in a test that quietly assumes the current answer is right.
     */
    public function testAnyParticipantCanRemoveAnyOther(): void
    {
        // 9 is not the creator and has no special role; 3 is removed anyway.
        $this->makeService(participantIds: [3, 7, 9])->removeParticipant(11, 9, 3);

        self::assertNotSame([], $this->writesTo('DELETE FROM conversation_participants'));
    }

    public function testAParticipantCanRemoveThemselves(): void
    {
        // Which is how leaving a group works - there is no separate "leave"
        // endpoint.
        $this->makeService(participantIds: [3, 7])->removeParticipant(11, 7, 7);

        $delete = $this->writesTo('DELETE FROM conversation_participants')[0];

        self::assertSame([11, 7], array_map('intval', $delete[1]));
    }
}

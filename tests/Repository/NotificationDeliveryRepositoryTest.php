<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\Notification;
use StreamEngine\Repository\NotificationDeliveryRepository;

final class NotificationDeliveryRepositoryTest extends TestCase
{
    public function testCreatePersistsDeliveryAndReturnsItsId(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('INSERT INTO notification_deliveries'),
                [
                    7,
                    'forum.reply',
                    'messenger',
                    'instant',
                    '{"topicId":42,"title":"Новый ответ"}',
                    'Новый ответ',
                    'forum.reply:99:7',
                    1700000000,
                ]
            )
            ->willReturn(1);
        $db->expects($this->once())->method('lastInsertId')->willReturn(73);

        $id = (new NotificationDeliveryRepository($db))->create(
            new Notification(
                recipientUserId: 7,
                type: 'forum.reply',
                messengerText: 'Новый ответ',
                payload: ['topicId' => 42, 'title' => 'Новый ответ'],
                deduplicationKey: 'forum.reply:99:7',
            ),
            channel: 'messenger',
            delivery: 'instant',
            scheduledAt: 1700000000,
        );

        self::assertSame(73, $id);
    }

    public function testCreateReturnsNullForDuplicateDelivery(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('execute')->willReturn(0);
        $db->expects($this->never())->method('lastInsertId');

        $id = (new NotificationDeliveryRepository($db))->create(
            new Notification(7, 'forum.reply', 'Новый ответ', deduplicationKey: 'same-key'),
            'messenger',
            'instant',
            1700000000,
        );

        self::assertNull($id);
    }

    public function testMarkSentUpdatesClaimedDeliveries(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->logicalAnd(
                    $this->stringContains("status = 'sent'"),
                    $this->stringContains('attempt_count = attempt_count + 1'),
                ),
                [73]
            );

        (new NotificationDeliveryRepository($db))->markSent([73]);
    }

    public function testMarkFailedStoresTruncatedErrorAndRetries(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $error = str_repeat('я', 1100);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains("'failed', 'pending'"),
                $this->callback(static fn (array $params): bool => $params[4] === 73
                    && mb_strlen($params[3]) === 1000)
            );

        (new NotificationDeliveryRepository($db))->markFailed([73], $error);
    }

    public function testClaimDueReservesAndMapsDeliveries(): void
    {
        $writes = [];
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->exactly(2))->method('execute')->willReturnCallback(
            function (string $sql, array $params) use (&$writes): int {
                $writes[] = [$sql, $params];

                return count($writes) === 2 ? 1 : 0;
            }
        );
        $db->expects($this->once())->method('fetchAll')->willReturn([
            [
                'id' => '73',
                'recipient_user_id' => '7',
                'notification_type' => 'forum.reply',
                'delivery' => 'instant',
                'payload' => '{"topicId":42}',
                'message_text' => 'Новый ответ',
                'scheduled_at' => '1700000000',
                'email' => 'user@example.com',
            ],
        ]);

        $deliveries = (new NotificationDeliveryRepository($db))->claimDue('email', 'instant', 100);

        self::assertSame([
            [
                'id' => 73,
                'recipientUserId' => 7,
                'notificationType' => 'forum.reply',
                'delivery' => 'instant',
                'payload' => ['topicId' => 42],
                'messageText' => 'Новый ответ',
                'scheduledAt' => 1700000000,
                'email' => 'user@example.com',
            ],
        ], $deliveries);
        self::assertStringContainsString("status = 'pending'", $writes[0][0]);
        self::assertSame(['email', 900], $writes[0][1]);
        self::assertStringContainsString("status = 'processing'", $writes[1][0]);
        self::assertStringContainsString('LIMIT 100', $writes[1][0]);
        self::assertSame(['email', 'instant'], array_slice($writes[1][1], 1));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $writes[1][1][0]);
    }

    public function testClaimDueSkipsFetchWhenQueueIsEmpty(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->exactly(2))->method('execute')->willReturn(0);
        $db->expects($this->never())->method('fetchAll');

        self::assertSame([], (new NotificationDeliveryRepository($db))->claimDue('email', 'daily', 500));
    }

    public function testMarkSentCompletesWholeDigest(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('execute')->with(
            $this->logicalAnd(
                $this->stringContains("status = 'sent'"),
                $this->stringContains('id IN (?, ?)'),
                $this->stringContains("status = 'processing'"),
            ),
            [73, 74]
        );

        (new NotificationDeliveryRepository($db))->markSent([73, 74]);
    }

    public function testMarkFailedRetriesThenStopsAfterThreeAttempts(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('execute')->with(
            $this->logicalAnd(
                $this->stringContains("IF(attempt_count >= ?, 'failed', 'pending')"),
                $this->stringContains('UNIX_TIMESTAMP() + ?'),
            ),
            [2, 2, 300, 'SMTP unavailable', 73]
        );

        (new NotificationDeliveryRepository($db))->markFailed([73], 'SMTP unavailable');
    }

    public function testCancelPendingEmailForUserLeavesOtherDeliveriesAlone(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('execute')->with(
            $this->logicalAnd(
                $this->stringContains("channel = 'email'"),
                $this->stringContains("status IN ('pending', 'processing')"),
                $this->stringContains("status = 'cancelled'"),
            ),
            [7]
        );

        (new NotificationDeliveryRepository($db))->cancelPendingEmailForUser(7);
    }
}

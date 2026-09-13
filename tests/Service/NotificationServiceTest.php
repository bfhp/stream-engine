<?php

declare(strict_types=1);

namespace Tests\Service;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\Notification;
use StreamEngine\Domain\User;
use StreamEngine\Repository\ConversationRepository;
use StreamEngine\Repository\MessageRepository;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\ParticipantRepository;
use StreamEngine\Repository\UploadRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use Tests\Support\FakePdoDatabase;

final class NotificationServiceTest extends TestCase
{
    public static function unreadDigestStates(): array
    {
        return [
            'unread' => [3, 'daily', true],
            'already read' => [0, 'daily', false],
            'disabled' => [3, 'off', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreadDigestStates')]
    public function testUnreadDigestRechecksCountAndPreferencesBeforeSending(int $count, string $mode, bool $send): void
    {
        $preferences = $this->createStub(PdoDatabase::class);
        $preferences->method('fetchAll')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'SELECT p.user_id')
                ? []
                : [['channel' => 'email', 'delivery' => $mode]],
        );
        $preferences->method('fetchOne')->willReturn(['token' => str_repeat('a', 43)]);
        $writes = [];
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['unread_count' => $count]);
        $db->method('execute')->willReturnCallback(static function (string $sql, array $params) use (&$writes): int {
            $writes[] = $sql;
            if (str_contains($sql, "SET status = 'processing'")) {
                return $params[1] === 'email' && $params[2] === 'daily' ? 1 : 0;
            }

            return 1;
        });
        $db->method('fetchAll')->willReturn([
            $this->emailRow(91, 7, 'a@example.test', 'message.unread_digest', 'stale count'),
        ]);
        $mail = $this->createMock(MailService::class);
        $mail->expects($send ? $this->once() : $this->never())->method('send')->willReturnCallback(
            static function (string $to, string $subject, string $template, array $context): void {
                self::assertSame('notifications/digest', $template);
                self::assertSame('Непрочитанных личных сообщений: 3.', $context['notifications'][0]['text']);
            },
        );
        $service = new NotificationService(
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($db),
            new NotificationPreferenceRepository($preferences),
            new UserRepository($this->createStub(PdoDatabase::class)),
            $mail,
        );

        $service->processQueue();

        self::assertStringContainsString($send ? "SET status = 'sent'" : "SET status = 'cancelled'", implode("\n", $writes));
    }

    public function testUnreadDigestQueuesAtUserTimeWithStableDeduplicationKey(): void
    {
        $preferences = $this->createStub(PdoDatabase::class);
        $preferences->method('fetchAll')->willReturnCallback(
            static fn (string $sql, array $params): array => $params[0] === 0 ? [['user_id' => 7]] : [],
        );
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturn(['unread_count' => 2]);
        $queued = [];
        $db->method('execute')->willReturnCallback(static function (string $sql, array $params) use (&$queued): int {
            if (str_contains($sql, 'INSERT INTO notification_deliveries')) {
                $queued[] = $params;
                return 1;
            }

            return 0;
        });
        $users = $this->createStub(PdoDatabase::class);
        $users->method('fetchOne')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'digest_delivery_time')
                ? ['digest_delivery_time' => '08:45:00']
                : ['timezone' => 'Europe/Nicosia'],
        );
        $mail = $this->createMock(MailService::class);
        $mail->expects($this->never())->method('send');
        $service = new NotificationService(
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($db),
            new NotificationPreferenceRepository($preferences),
            new UserRepository($users),
            $mail,
        );
        $service->processQueue();
        $service->processQueue();

        self::assertCount(2, $queued);
        self::assertSame([7, 'message.unread_digest', 'email', 'daily'], array_slice($queued[0], 0, 4));
        self::assertSame($queued[0][6], $queued[1][6]);
        self::assertSame('08:45:00', (new DateTimeImmutable('@'.$queued[0][7]))->setTimezone(new DateTimeZone('Europe/Nicosia'))->format('H:i:s'));
    }

    public function testFriendPostNotificationFansOutOncePerRecipient(): void
    {
        $preferenceDb = $this->createStub(PdoDatabase::class);
        $preferenceDb->method('fetchAll')->willReturn([
            ['channel' => 'messenger', 'delivery' => 'off'],
            ['channel' => 'email', 'delivery' => 'instant'],
        ]);

        $deliveries = [];
        $deliveryDb = $this->createStub(PdoDatabase::class);
        $deliveryDb->method('execute')->willReturnCallback(
            static function (string $sql, array $params) use (&$deliveries): int {
                if (str_contains($sql, 'INSERT INTO notification_deliveries')) {
                    $deliveries[] = $params;
                }

                return 1;
            }
        );

        $service = new NotificationService(
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($deliveryDb),
            new NotificationPreferenceRepository($preferenceDb),
            new UserRepository($this->createStub(PdoDatabase::class)),
            $this->mailStub(),
        );
        $service->notifyFriendsAboutPost(
            $this->feed(50, 10, 7, 'blog-post', 'Новая запись', 'https://example.test/post'),
            new User(7, 'author@example.test', AccessService::ROLE_USER, nick: 'Анна'),
            [8, 8, 7, 0, 9],
        );

        self::assertSame([[8, 'friend.post'], [9, 'friend.post']], array_map(
            static fn (array $params): array => [$params[0], $params[1]],
            $deliveries,
        ));
        self::assertSame('friend.post:50:8', $deliveries[0][6]);
        self::assertSame('https://example.test/post', json_decode($deliveries[0][4], true)['contentUrl']);
    }

    public function testFriendPostNotificationsAreOffByDefault(): void
    {
        $deliveryDb = $this->createMock(PdoDatabase::class);
        $deliveryDb->expects($this->never())->method('execute');

        $service = new NotificationService(
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($deliveryDb),
            new NotificationPreferenceRepository($this->createStub(PdoDatabase::class)),
            new UserRepository($this->createStub(PdoDatabase::class)),
            $this->mailStub(),
        );

        $service->notifyFriendsAboutPost(
            $this->feed(50, 10, 7, 'blog-post', 'Новая запись'),
            new User(7, 'author@example.test', AccessService::ROLE_USER),
            [8],
        );
    }

    public function testCommunityPostNotificationFansOutOncePerMember(): void
    {
        $preferenceDb = $this->createStub(PdoDatabase::class);
        $preferenceDb->method('fetchAll')->willReturn([
            ['channel' => 'messenger', 'delivery' => 'off'],
            ['channel' => 'email', 'delivery' => 'instant'],
        ]);

        $deliveries = [];
        $deliveryDb = $this->createStub(PdoDatabase::class);
        $deliveryDb->method('execute')->willReturnCallback(
            static function (string $sql, array $params) use (&$deliveries): int {
                if (str_contains($sql, 'INSERT INTO notification_deliveries')) {
                    $deliveries[] = $params;
                }

                return 1;
            }
        );

        $service = new NotificationService(
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($deliveryDb),
            new NotificationPreferenceRepository($preferenceDb),
            new UserRepository($this->createStub(PdoDatabase::class)),
            $this->mailStub(),
        );
        $service->notifyCommunityMembersAboutPost(
            $this->feed(50, 40, 7, 'blog-post', 'Новая запись', 'https://example.test/community/post'),
            $this->feed(40, null, 10, 'community', 'Музыка', 'https://example.test/community'),
            new User(7, 'author@example.test', AccessService::ROLE_USER, nick: 'Анна'),
            [8, 8, 7, 0, 9],
        );

        self::assertSame([[8, 'community.post'], [9, 'community.post']], array_map(
            static fn (array $params): array => [$params[0], $params[1]],
            $deliveries,
        ));
        self::assertSame('community.post:50:8', $deliveries[0][6]);
        $payload = json_decode($deliveries[0][4], true);
        self::assertSame(40, $payload['communityId']);
        self::assertSame('https://example.test/community/post', $payload['contentUrl']);
    }

    public function testCommunityPostNotificationsAreOffByDefault(): void
    {
        $deliveryDb = $this->createMock(PdoDatabase::class);
        $deliveryDb->expects($this->never())->method('execute');

        $service = new NotificationService(
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($deliveryDb),
            new NotificationPreferenceRepository($this->createStub(PdoDatabase::class)),
            new UserRepository($this->createStub(PdoDatabase::class)),
            $this->mailStub(),
        );

        $service->notifyCommunityMembersAboutPost(
            $this->feed(50, 40, 7, 'blog-post', 'Новая запись'),
            $this->feed(40, null, 10, 'community', 'Музыка'),
            new User(7, 'author@example.test', AccessService::ROLE_USER),
            [8],
        );
    }

    public function testCommentNotificationTargetsReplyAuthorAndPostAuthorWithoutDuplicates(): void
    {
        $preferenceDb = $this->createStub(PdoDatabase::class);
        $preferenceDb->method('fetchAll')->willReturn([
            ['channel' => 'messenger', 'delivery' => 'off'],
            ['channel' => 'email', 'delivery' => 'instant'],
        ]);

        $deliveries = [];
        $deliveryDb = $this->createStub(PdoDatabase::class);
        $deliveryDb->method('execute')->willReturnCallback(
            static function (string $sql, array $params) use (&$deliveries): int {
                if (str_contains($sql, 'INSERT INTO notification_deliveries')) {
                    $deliveries[] = $params;
                }

                return 1;
            }
        );

        $service = new NotificationService(
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($deliveryDb),
            new NotificationPreferenceRepository($preferenceDb),
            new UserRepository($this->createStub(PdoDatabase::class)),
            $this->mailStub(),
        );

        $root = $this->feed(50, null, 7, 'blog-post', 'Запись', 'https://example.test/post');
        $parent = $this->feed(60, 50, 8, 'comment');
        $comment = $this->feed(70, 60, 9, 'comment');

        $service->notifyCommentCreated(
            $comment,
            $parent,
            $root,
            new User(9, 'author@example.test', AccessService::ROLE_USER, nick: 'Анна'),
        );

        self::assertSame([[8, 'comment.reply'], [7, 'content.comment']], array_map(
            static fn (array $params): array => [$params[0], $params[1]],
            $deliveries,
        ));
        self::assertSame('comment.reply:70:8', $deliveries[0][6]);
        self::assertSame('content.comment:70:7', $deliveries[1][6]);
        self::assertSame('https://example.test/post#comments', json_decode($deliveries[0][4], true)['contentUrl']);
    }

    public function testCommentNotificationDoesNotNotifySameRecipientTwiceOrNotifyAuthor(): void
    {
        $preferenceDb = $this->createStub(PdoDatabase::class);
        $preferenceDb->method('fetchAll')->willReturn([
            ['channel' => 'messenger', 'delivery' => 'off'],
            ['channel' => 'email', 'delivery' => 'instant'],
        ]);

        $deliveries = [];
        $deliveryDb = $this->createStub(PdoDatabase::class);
        $deliveryDb->method('execute')->willReturnCallback(
            static function (string $sql, array $params) use (&$deliveries): int {
                if (str_contains($sql, 'INSERT INTO notification_deliveries')) {
                    $deliveries[] = $params;
                }

                return 1;
            }
        );

        $service = new NotificationService(
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($deliveryDb),
            new NotificationPreferenceRepository($preferenceDb),
            new UserRepository($this->createStub(PdoDatabase::class)),
            $this->mailStub(),
        );

        $root = $this->feed(50, null, 7, 'blog-post', 'Запись');
        $service->notifyCommentCreated(
            $this->feed(70, 60, 9, 'comment'),
            $this->feed(60, 50, 7, 'comment'),
            $root,
            new User(9, 'author@example.test', AccessService::ROLE_USER),
        );
        $service->notifyCommentCreated(
            $this->feed(71, 50, 7, 'comment'),
            $root,
            $root,
            new User(7, 'owner@example.test', AccessService::ROLE_USER),
        );

        self::assertCount(1, $deliveries);
        self::assertSame([7, 'comment.reply'], [$deliveries[0][0], $deliveries[0][1]]);
    }

    public function testNotifyQueuesMessengerWithoutSendingDuringRequest(): void
    {
        $db = new FakePdoDatabase();
        $messages = new MessageService(
            $db,
            new MessageRepository($db),
            new ParticipantRepository($db),
            new ConversationRepository($db),
            new UserRepository($db),
            new UserSessionRepository($db),
            new UploadRepository($db),
        );

        $deliveryDb = $this->createMock(PdoDatabase::class);
        $deliveryDb
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('INSERT INTO notification_deliveries'),
                $this->callback(static fn (array $params): bool => $params[2] === 'messenger'
                    && $params[3] === 'instant'),
            )
            ->willReturn(1);
        $deliveryDb->expects($this->once())->method('lastInsertId')->willReturn(73);

        $service = new NotificationService(
            $messages,
            new NotificationDeliveryRepository($deliveryDb),
            new NotificationPreferenceRepository($this->createStub(PdoDatabase::class)),
            new UserRepository($this->createStub(PdoDatabase::class)),
            $this->mailStub(),
        );
        $service->notify(new Notification(
            recipientUserId: 2,
            type: 'forum.reply',
            messengerText: 'Новый ответ',
            payload: ['topicId' => 42],
            deduplicationKey: 'forum.reply:99:2',
        ));

        self::assertSame([], $db->messages);
    }

    public function testNotifyDoesNotRedeliverDuplicate(): void
    {
        $deliveryDb = $this->createMock(PdoDatabase::class);
        $deliveryDb->expects($this->once())->method('execute')->willReturn(0);
        $deliveryDb->expects($this->never())->method('lastInsertId');

        $messages = (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor();
        $service = new NotificationService(
            $messages,
            new NotificationDeliveryRepository($deliveryDb),
            new NotificationPreferenceRepository($this->createStub(PdoDatabase::class)),
            new UserRepository($this->createStub(PdoDatabase::class)),
            $this->mailStub(),
        );

        $service->notify(new Notification(
            recipientUserId: 2,
            type: 'forum.reply',
            messengerText: 'Уже отправлено',
            deduplicationKey: 'forum.reply:99:2',
        ));

        self::assertTrue(true);
    }

    public function testMessengerWorkerReturnsFailedSendToRetryFlow(): void
    {
        $claimToken = null;
        $failureParams = null;
        $deliveryDb = $this->createStub(PdoDatabase::class);
        $deliveryDb->method('execute')->willReturnCallback(
            function (string $sql, array $params = []) use (&$claimToken, &$failureParams): int {
                if (str_contains($sql, "SET status = 'processing'")) {
                    if ($params[1] === 'messenger') {
                        $claimToken = $params[0];

                        return 1;
                    }

                    return 0;
                }

                if (str_contains($sql, "'failed', 'pending'")) {
                    $failureParams = $params;
                }

                return 0;
            }
        );
        $deliveryDb->method('fetchAll')->willReturnCallback(
            function (string $sql, array $params) use (&$claimToken): array {
                return $params[0] === $claimToken
                    ? [$this->emailRow(74, 2, 'user@example.com', 'forum.reply', 'Новый ответ')]
                    : [];
            }
        );

        $messageDb = $this->createStub(PdoDatabase::class);
        $messageDb->method('fetchOne')->willThrowException(new RuntimeException('db down'));
        $messages = new MessageService(
            $messageDb,
            new MessageRepository($messageDb),
            new ParticipantRepository($messageDb),
            new ConversationRepository($messageDb),
            new UserRepository($messageDb),
            new UserSessionRepository($messageDb),
            new UploadRepository($messageDb),
        );

        $service = new NotificationService(
            $messages,
            new NotificationDeliveryRepository($deliveryDb),
            new NotificationPreferenceRepository($this->createStub(PdoDatabase::class)),
            new UserRepository($this->createStub(PdoDatabase::class)),
            $this->mailStub(),
        );

        $service->processQueue();

        self::assertSame([2, 2, 300, 'MessageService::notify failed for user 2: db down', 74], $failureParams);
    }

    public function testMessengerWorkerSendsAndMarksDeliveryComplete(): void
    {
        $claimToken = null;
        $sentIds = null;
        $deliveryDb = $this->createStub(PdoDatabase::class);
        $deliveryDb->method('execute')->willReturnCallback(
            function (string $sql, array $params = []) use (&$claimToken, &$sentIds): int {
                if (str_contains($sql, "SET status = 'processing'")) {
                    if ($params[1] === 'messenger') {
                        $claimToken = $params[0];

                        return 1;
                    }

                    return 0;
                }

                if (str_contains($sql, "SET status = 'sent'")) {
                    $sentIds = $params;
                }

                return 0;
            }
        );
        $deliveryDb->method('fetchAll')->willReturnCallback(
            function (string $sql, array $params) use (&$claimToken): array {
                return $params[0] === $claimToken
                    ? [$this->emailRow(74, 2, 'user@example.com', 'forum.reply', 'Новый ответ')]
                    : [];
            }
        );

        $messageDb = new FakePdoDatabase();
        $messages = new MessageService(
            $messageDb,
            new MessageRepository($messageDb),
            new ParticipantRepository($messageDb),
            new ConversationRepository($messageDb),
            new UserRepository($messageDb),
            new UserSessionRepository($messageDb),
            new UploadRepository($messageDb),
        );
        $service = new NotificationService(
            $messages,
            new NotificationDeliveryRepository($deliveryDb),
            new NotificationPreferenceRepository($this->createStub(PdoDatabase::class)),
            new UserRepository($this->createStub(PdoDatabase::class)),
            $this->mailStub(),
        );

        $service->processQueue();

        self::assertSame('Новый ответ', $messageDb->messages[0]['text']);
        self::assertSame([74], $sentIds);
    }

    public function testPreferencesCanDisableMessengerAndQueueInstantEmail(): void
    {
        $preferenceDb = $this->createMock(PdoDatabase::class);
        $preferenceDb
            ->expects($this->once())
            ->method('fetchAll')
            ->willReturn([
                ['channel' => 'messenger', 'delivery' => 'off'],
                ['channel' => 'email', 'delivery' => 'instant'],
            ]);

        $deliveryDb = $this->createMock(PdoDatabase::class);
        $deliveryDb
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('INSERT INTO notification_deliveries'),
                $this->callback(static fn (array $params): bool => $params[2] === 'email'
                    && $params[3] === 'instant')
            )
            ->willReturn(1);
        $deliveryDb->expects($this->once())->method('lastInsertId')->willReturn(75);

        $messages = (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor();
        $service = new NotificationService(
            $messages,
            new NotificationDeliveryRepository($deliveryDb),
            new NotificationPreferenceRepository($preferenceDb),
            new UserRepository($this->createStub(PdoDatabase::class)),
            $this->mailStub(),
        );

        $service->notify(new Notification(2, 'forum.reply', 'Новый ответ'));
    }

    public function testDailyEmailIsScheduledInUserTimezone(): void
    {
        $preferenceDb = $this->createStub(PdoDatabase::class);
        $preferenceDb->method('fetchAll')->willReturn([
            ['channel' => 'messenger', 'delivery' => 'off'],
            ['channel' => 'email', 'delivery' => 'daily'],
        ]);

        $scheduledAt = null;
        $deliveryDb = $this->createMock(PdoDatabase::class);
        $deliveryDb
            ->expects($this->once())
            ->method('execute')
            ->willReturnCallback(static function (string $sql, array $params) use (&$scheduledAt): int {
                $scheduledAt = $params[7];

                return 1;
            });
        $deliveryDb->expects($this->once())->method('lastInsertId')->willReturn(76);

        $userDb = $this->createMock(PdoDatabase::class);
        $userDb
            ->expects($this->exactly(2))
            ->method('fetchOne')
            ->willReturnCallback(static fn (string $sql): array => str_contains($sql, 'digest_delivery_time')
                ? ['digest_delivery_time' => '09:30:00']
                : ['timezone' => 'Europe/Nicosia']);

        $messages = (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor();
        $service = new NotificationService(
            $messages,
            new NotificationDeliveryRepository($deliveryDb),
            new NotificationPreferenceRepository($preferenceDb),
            new UserRepository($userDb),
            $this->mailStub(),
        );

        $before = time();
        $service->notify(new Notification(2, 'forum.reply', 'Новый ответ'));

        self::assertIsInt($scheduledAt);
        self::assertGreaterThan($before, $scheduledAt);
        $localScheduled = (new DateTimeImmutable('@'.$scheduledAt))->setTimezone(new DateTimeZone('Europe/Nicosia'));
        self::assertSame('09:30:00', $localScheduled->format('H:i:s'));
    }

    public function testProcessQueueSendsInstantEmailAndGroupsDailyByRecipient(): void
    {
        $claimedByToken = [];
        $sentGroups = [];
        $rows = [
            'instant' => [
                $this->emailRow(1, 7, 'a@example.com', 'forum.reply', 'Ответ A'),
                $this->emailRow(2, 8, 'b@example.com', 'friend.request', 'Запрос B'),
            ],
            'daily' => [
                $this->emailRow(3, 7, 'a@example.com', 'forum.reply', 'Ответ C'),
                $this->emailRow(4, 7, 'a@example.com', 'friend.mutual', 'Друг D'),
                $this->emailRow(5, 8, 'b@example.com', 'custom.event', 'Событие E'),
            ],
        ];

        $db = $this->createStub(PdoDatabase::class);
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []) use (&$claimedByToken, &$sentGroups, $rows): int {
                if (str_contains($sql, "SET status = 'processing'")) {
                    if ($params[1] !== 'email') {
                        return 0;
                    }

                    $claimedByToken[$params[0]] = $params[2];

                    return count($rows[$params[2]]);
                }

                if (str_contains($sql, "SET status = 'sent'")) {
                    $sentGroups[] = $params;
                }

                return 0;
            }
        );
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql, array $params) use ($rows, &$claimedByToken): array {
                return $rows[$claimedByToken[$params[0]]];
            }
        );

        $messages = [];
        $mail = $this->createMock(MailService::class);
        $mail->expects($this->exactly(4))->method('send')->willReturnCallback(
            function (
                string $to,
                string $subject,
                string $template,
                array $context,
                ?string $unsubscribeUrl,
            ) use (&$messages): void {
                $messages[] = compact('to', 'subject', 'template', 'context', 'unsubscribeUrl');
            }
        );

        $this->notificationService($db, $mail)->processQueue();

        self::assertSame([[1], [2], [3, 4], [5]], $sentGroups);
        self::assertSame(
            ['notifications/instant', 'notifications/instant', 'notifications/digest', 'notifications/digest'],
            array_column($messages, 'template')
        );
        self::assertSame('Новый ответ в теме', $messages[0]['subject']);
        self::assertSame(['a@example.com', 'b@example.com', 'a@example.com', 'b@example.com'], array_column($messages, 'to'));
        self::assertSame('/unsubscribe?token='.str_repeat('a', 43), $messages[0]['unsubscribeUrl']);
        self::assertCount(2, $messages[2]['context']['notifications']);
        self::assertCount(1, $messages[3]['context']['notifications']);
    }

    public function testProcessQueueReturnsFailedEmailToRetryFlow(): void
    {
        $claimToken = null;
        $failureParams = null;
        $db = $this->createStub(PdoDatabase::class);
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []) use (&$claimToken, &$failureParams): int {
                if (str_contains($sql, "SET status = 'processing'")) {
                    if ($params[1] === 'email' && $params[2] === 'instant') {
                        $claimToken = $params[0];

                        return 1;
                    }

                    return 0;
                }

                if (str_contains($sql, "'failed', 'pending'")) {
                    $failureParams = $params;
                }

                return 0;
            }
        );
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, array $params) use (&$claimToken): array {
                return $params[0] === $claimToken
                    ? [$this->emailRow(9, 7, 'a@example.com', 'forum.reply', 'Ответ')]
                    : [];
            }
        );

        $mail = $this->createMock(MailService::class);
        $mail->expects($this->once())->method('send')->willThrowException(new RuntimeException('SMTP unavailable'));

        $this->notificationService($db, $mail)->processQueue();

        self::assertSame([2, 2, 300, 'SMTP unavailable', 9], $failureParams);
    }

    public function testProcessQueueSanitizesEmailMessageAndRejectsUnsafeLink(): void
    {
        $claimToken = null;
        $db = $this->createStub(PdoDatabase::class);
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []) use (&$claimToken): int {
                if (str_contains($sql, "SET status = 'processing'")
                    && $params[1] === 'email' && $params[2] === 'instant') {
                    $claimToken = $params[0];

                    return 1;
                }

                return 0;
            }
        );
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, array $params) use (&$claimToken): array {
                return $params[0] === $claimToken
                    ? [$this->emailRow(
                        10,
                        7,
                        'a@example.com',
                        'forum.reply',
                        'Ответ <strong>готов</strong>',
                        '{"topicUrl":"javascript:alert(1)"}',
                    )]
                    : [];
            }
        );

        $context = null;
        $mail = $this->createMock(MailService::class);
        $mail->expects($this->once())->method('send')->willReturnCallback(
            static function (string $to, string $subject, string $template, array $mailContext) use (&$context): void {
                $context = $mailContext;
            }
        );

        $this->notificationService($db, $mail)->processQueue();

        self::assertSame('Ответ готов', $context['notification']['text']);
        self::assertNull($context['notification']['url']);
    }

    public function testUnsubscribeDisablesEmailAndCancelsQueuedDeliveries(): void
    {
        $token = str_repeat('a', 43);
        $preferenceWrites = [];
        $preferenceDb = $this->createStub(PdoDatabase::class);
        $preferenceDb->method('fetchOne')->willReturn(['user_id' => '7']);
        $preferenceDb->method('execute')->willReturnCallback(
            function (string $sql, array $params) use (&$preferenceWrites): int {
                $preferenceWrites[] = [$sql, $params];

                return 1;
            }
        );

        $deliveryWrites = [];
        $deliveryDb = $this->createStub(PdoDatabase::class);
        $deliveryDb->method('execute')->willReturnCallback(
            function (string $sql, array $params) use (&$deliveryWrites): int {
                $deliveryWrites[] = [$sql, $params];

                return 1;
            }
        );

        $service = new NotificationService(
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($deliveryDb),
            new NotificationPreferenceRepository($preferenceDb),
            new UserRepository($this->createStub(PdoDatabase::class)),
            $this->mailStub(),
        );

        self::assertTrue($service->unsubscribeFromEmail($token));
        self::assertSame([7, '*', 'email', 'off'], $preferenceWrites[0][1]);
        self::assertSame([7], $deliveryWrites[0][1]);
        self::assertStringContainsString("status = 'cancelled'", $deliveryWrites[0][0]);
    }

    private function notificationService(PdoDatabase $db, MailService $mail): NotificationService
    {
        $preferenceDb = $this->createStub(PdoDatabase::class);
        $preferenceDb->method('fetchOne')->willReturn(['token' => str_repeat('a', 43)]);

        return new NotificationService(
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($db),
            new NotificationPreferenceRepository($preferenceDb),
            new UserRepository($this->createStub(PdoDatabase::class)),
            $mail,
        );
    }

    private function mailStub(): MailService
    {
        return (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor();
    }

    private function feed(
        int $id,
        ?int $parentId,
        int $ownerId,
        string $type,
        ?string $title = null,
        ?string $canonicalUrl = null,
    ): Feed {
        return new Feed(
            id: $id,
            parentId: $parentId,
            ownerId: $ownerId,
            type: $type,
            slug: null,
            title: $title,
            description: null,
            imageUrl: null,
            content: '',
            containerId: null,
            visibility: 'public',
            position: 0,
            createdAt: time(),
            relevance: null,
            canonicalUrl: $canonicalUrl,
        );
    }

    /** @return array<string, string> */
    private function emailRow(
        int $id,
        int $userId,
        string $email,
        string $type,
        string $text,
        string $payload = '{}',
    ): array {
        return [
            'id' => (string) $id,
            'recipient_user_id' => (string) $userId,
            'notification_type' => $type,
            'delivery' => 'instant',
            'payload' => $payload,
            'message_text' => $text,
            'scheduled_at' => '1700000000',
            'email' => $email,
        ];
    }
}

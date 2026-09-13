<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\NotificationPreferenceRepository;

final class NotificationPreferenceRepositoryTest extends TestCase
{
    public function testFindForUserAndTypeReturnsPreferencesByChannel(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->stringContains('FROM notification_preferences'),
                [7, 'forum.reply']
            )
            ->willReturn([
                ['channel' => 'messenger', 'delivery' => 'instant'],
                ['channel' => 'email', 'delivery' => 'daily'],
            ]);

        self::assertSame(
            [
                'messenger' => ['delivery' => 'instant'],
                'email' => ['delivery' => 'daily'],
            ],
            (new NotificationPreferenceRepository($db))->findForUserAndType(7, 'forum.reply')
        );
    }

    public function testSaveUpsertsPreference(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with(
                $this->stringContains('ON DUPLICATE KEY UPDATE'),
                [7, 'forum.reply', 'email', 'daily']
            );

        (new NotificationPreferenceRepository($db))->save(7, 'forum.reply', 'email', 'daily');
    }

    public function testGlobalUnsubscribeOverridesTypePreference(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturn([
            [
                'notification_type' => 'forum.reply',
                'channel' => 'email',
                'delivery' => 'daily',
            ],
            [
                'notification_type' => '*',
                'channel' => 'email',
                'delivery' => 'off',
            ],
        ]);

        self::assertSame(
            ['delivery' => 'off'],
            (new NotificationPreferenceRepository($db))->findForUserAndType(7, 'forum.reply')['email']
        );
    }

    public function testCreatesUrlSafeUnsubscribeToken(): void
    {
        $insertedToken = null;
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchOne')->willReturn(null);
        $db->expects($this->once())->method('execute')->willReturnCallback(
            static function (string $sql, array $params) use (&$insertedToken): int {
                $insertedToken = $params[1];

                return 1;
            }
        );

        $token = (new NotificationPreferenceRepository($db))->getOrCreateEmailUnsubscribeToken(7);

        self::assertSame($insertedToken, $token);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
    }

    public function testFindsUserByValidUnsubscribeToken(): void
    {
        $token = str_repeat('a', 43);
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('fetchOne')->with(
            $this->stringContains('notification_unsubscribe_tokens'),
            [$token]
        )->willReturn(['user_id' => '7']);

        self::assertSame(
            7,
            (new NotificationPreferenceRepository($db))->findUserIdByEmailUnsubscribeToken($token)
        );
    }

    public function testRejectsMalformedUnsubscribeTokenWithoutQuery(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('fetchOne');

        self::assertNull(
            (new NotificationPreferenceRepository($db))->findUserIdByEmailUnsubscribeToken('../invalid')
        );
    }

    public function testUnsubscribeStoresGlobalEmailOptOut(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('execute')->with(
            $this->stringContains('INSERT INTO notification_preferences'),
            [7, '*', 'email', 'off']
        );

        (new NotificationPreferenceRepository($db))->unsubscribeUserFromEmail(7);
    }

    public function testSaveForUserReplacesGlobalOptOutAndWritesChannelsInTransaction(): void
    {
        $writes = [];
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollback');
        $db->expects($this->exactly(4))->method('execute')->willReturnCallback(
            static function (string $sql, array $params) use (&$writes): int {
                $writes[] = [$sql, $params];

                return 1;
            }
        );

        (new NotificationPreferenceRepository($db))->saveForUser(7, [
            'forum.reply' => [
                'messenger' => 'instant',
                'email' => 'daily',
            ],
        ], '08:30');

        self::assertSame(['08:30', 7], $writes[3][1]);
        self::assertStringContainsString("notification_type = '*'", $writes[0][0]);
        self::assertSame([7, 'forum.reply', 'messenger', 'instant'], $writes[1][1]);
        self::assertSame([7, 'forum.reply', 'email', 'daily'], $writes[2][1]);
    }
    public function testDigestTimeUpdateFailureRollsBackChannelChanges(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->once())->method('begin');
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollback');
        $db->method('execute')->willReturnCallback(static function (string $sql): int {
            if (str_contains($sql, 'UPDATE users')) {
                throw new \RuntimeException('write failed');
            }

            return 1;
        });

        $this->expectException(\RuntimeException::class);
        (new NotificationPreferenceRepository($db))->saveForUser(
            7,
            ['forum.reply' => ['messenger' => 'instant', 'email' => 'daily']],
            '08:30',
        );
    }

}

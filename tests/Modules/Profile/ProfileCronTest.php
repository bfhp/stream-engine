<?php

declare(strict_types=1);

namespace Tests\Modules\Profile;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StreamEngine\Core\Cron\CronRegistry;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Modules\Profile\ProfileController;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use UnhandledMatchError;

final class ProfileCronTest extends TestCase
{
    public function testRegistersNotificationDeliveryCron(): void
    {
        $registry = new CronRegistry();

        ProfileController::registerCron($registry);

        self::assertSame([
            'notifications:deliveries' => [
                'task' => 'notifications:deliveries',
                'controller' => 'Profile',
                'interval' => 60,
            ],
        ], $registry->all());
    }

    public function testDispatchesNotificationDeliveryCron(): void
    {
        $queueClaims = 0;
        $db = $this->createStub(PdoDatabase::class);
        $db->method('execute')->willReturnCallback(
            function (string $sql) use (&$queueClaims): int {
                if (str_contains($sql, "SET status = 'processing'")) {
                    ++$queueClaims;
                }

                return 0;
            }
        );
        $notifications = new NotificationService(
            (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
            new NotificationDeliveryRepository($db),
            new NotificationPreferenceRepository($db),
            new UserRepository($db),
            (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
        );

        $reflection = new ReflectionClass(ProfileController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('notifications')->setValue($controller, $notifications);

        $controller->runCron('notifications:deliveries');

        self::assertSame(3, $queueClaims);
    }

    public function testUnknownCronTaskFailsLoudly(): void
    {
        $controller = (new ReflectionClass(ProfileController::class))->newInstanceWithoutConstructor();

        $this->expectException(UnhandledMatchError::class);

        $controller->runCron('notifications:unknown');
    }
}

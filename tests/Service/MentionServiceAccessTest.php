<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Config;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Repository\MentionRepository;
use StreamEngine\Repository\MessageRepository;
use StreamEngine\Repository\ParticipantRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\MentionService;

final class MentionServiceAccessTest extends TestCase
{
    /** @return iterable<string, array{bool,bool}> */
    public static function messageAccessCases(): iterable
    {
        yield 'recipient remains a participant' => [true, true];
        yield 'recipient left the conversation' => [false, false];
    }

    #[DataProvider('messageAccessCases')]
    public function testMessageDeliveryRequiresCurrentConversationMembership(
        bool $isParticipant,
        bool $expected,
    ): void {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->exactly(4))
            ->method('fetchOne')
            ->willReturnCallback(static function (string $sql) use ($isParticipant): ?array {
                if (str_contains($sql, 'FROM mentions')) {
                    return ['1' => 1];
                }
                if (str_contains($sql, 'FROM users')) {
                    return [
                        'id' => 7,
                        'email' => 'recipient@example.test',
                        'role' => 'user',
                        'nick' => 'Recipient',
                        'username' => 'recipient',
                    ];
                }
                if (str_contains($sql, 'FROM messages')) {
                    return [
                        'id' => 55,
                        'conversation_id' => 12,
                        'user_id' => 4,
                        'created_at' => 1_700_000_000,
                        'deleted_at' => null,
                    ];
                }
                if (str_contains($sql, 'FROM conversation_participants')) {
                    return $isParticipant ? ['1' => 1] : null;
                }

                self::fail('Unexpected query: '.$sql);
            });

        $service = new MentionService(new MentionRepository($db));
        $service->configureAccess(
            $this->createStub(FeedService::class),
            new MessageRepository($db),
            new ParticipantRepository($db),
            new UserRepository($db),
            new TranslationManager('en'),
            new Config([]),
        );

        self::assertSame($expected, $service->canDeliverMessage(55, 7));
    }
}

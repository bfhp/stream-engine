<?php

declare(strict_types=1);

namespace Tests\Core\Installation;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Installation\InstallationState;

final class InstallationStateTest extends TestCase
{
    public function testStateMovesThroughInstallingFailedAndReadyStates(): void
    {
        $started = InstallationState::start('1.2.3');
        self::assertSame(InstallationState::STATUS_INSTALLING, $started->status);
        self::assertSame('preflight', $started->stage);

        $schema = $started->advanceTo('schema');
        self::assertSame($started->startedAt, $schema->startedAt);
        self::assertSame('schema', $schema->stage);

        $failed = $schema->fail('schema_failed');
        self::assertSame(InstallationState::STATUS_FAILED, $failed->status);
        self::assertSame('schema_failed', $failed->errorCode);

        $ready = $schema->complete();
        self::assertSame(InstallationState::STATUS_READY, $ready->status);
        self::assertSame('ready', $ready->stage);
        self::assertNotNull($ready->completedAt);

        self::assertEquals($ready, InstallationState::fromArray($ready->toArray()));
    }

    public function testInvalidStateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid installation status');

        new InstallationState('unknown', '1.0.0', 'preflight', '2026-01-01T00:00:00+00:00');
    }
}

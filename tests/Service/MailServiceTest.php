<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use StreamEngine\Core\Config;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Repository\SettingsRepository;
use StreamEngine\Service\MailService;
use StreamEngine\Service\SettingsService;

final class MailServiceTest extends TestCase
{
    public function testBuildsAbsoluteUnsubscribeUrlForEmailHeadersAndBody(): void
    {
        $mail = new MailService(
            new Config(['SITE_URL' => 'https://example.test/']),
            new SettingsService(new SettingsRepository($this->createStub(PdoDatabase::class))),
            new TranslationManager('ru', 'ru'),
            __DIR__.'/../../views/email',
        );
        $method = new ReflectionMethod(MailService::class, 'absoluteUnsubscribeUrl');

        self::assertSame(
            'https://example.test/unsubscribe?token=abc',
            $method->invoke($mail, '/unsubscribe?token=abc')
        );
        self::assertSame(
            'https://other.test/unsubscribe',
            $method->invoke($mail, 'https://other.test/unsubscribe')
        );
    }

    public function testRelativeUnsubscribeUrlRequiresCanonicalSiteUrl(): void
    {
        $mail = new MailService(
            new Config([]),
            new SettingsService(new SettingsRepository($this->createStub(PdoDatabase::class))),
            new TranslationManager('ru', 'ru'),
            __DIR__.'/../../views/email',
        );
        $method = new ReflectionMethod(MailService::class, 'absoluteUnsubscribeUrl');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Canonical site URL is not configured');

        $method->invoke($mail, '/unsubscribe?token=abc');
    }
}

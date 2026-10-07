<?php

declare(strict_types=1);

namespace Tests\Modules\Users;

use Closure;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Config;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Modules\Users\RegistrationCaptcha;
use StreamEngine\Repository\SettingsRepository;
use StreamEngine\Service\SettingsService;

final class RegistrationCaptchaTest extends TestCase
{
    public function testDisabledCaptchaSucceedsWithoutARequest(): void
    {
        $called = false;
        $captcha = $this->makeCaptcha([], static function () use (&$called): array {
            $called = true;

            return [];
        });

        self::assertTrue($captcha->verify(''));
        self::assertFalse($called);
    }

    public function testTurnstileSendsExpectedPayloadAndChecksActionAndHostname(): void
    {
        $request = null;
        $captcha = $this->makeCaptcha(
            $this->settings('turnstile'),
            static function (string $endpoint, array $payload) use (&$request): array {
                $request = [$endpoint, $payload];

                return ['success' => true, 'action' => 'registration', 'hostname' => 'example.test'];
            },
        );

        self::assertTrue($captcha->verify('token', '192.0.2.4'));
        self::assertSame('https://challenges.cloudflare.com/turnstile/v0/siteverify', $request[0]);
        self::assertSame([
            'secret' => 'secret-key',
            'response' => 'token',
            'remoteip' => '192.0.2.4',
        ], $request[1]);
    }

    public function testTurnstileRejectsFailureWrongActionAndWrongHostname(): void
    {
        foreach ([
            ['success' => false],
            ['success' => true, 'action' => 'login', 'hostname' => 'example.test'],
            ['success' => true, 'action' => 'registration', 'hostname' => 'other.test'],
        ] as $result) {
            $captcha = $this->makeCaptcha(
                $this->settings('turnstile'),
                static fn (): array => $result,
            );

            self::assertFalse($captcha->verify('token'));
        }
    }

    public function testHcaptchaSendsSiteKeyAndAcceptsSuccessfulResponse(): void
    {
        $request = null;
        $captcha = $this->makeCaptcha(
            $this->settings('hcaptcha'),
            static function (string $endpoint, array $payload) use (&$request): array {
                $request = [$endpoint, $payload];

                return ['success' => true];
            },
        );

        self::assertTrue($captcha->verify('token'));
        self::assertSame('https://api.hcaptcha.com/siteverify', $request[0]);
        self::assertSame('site-key', $request[1]['sitekey']);
    }

    public function testEnabledCaptchaRejectsMissingTokenAndIncompleteConfiguration(): void
    {
        $called = false;
        $request = static function () use (&$called): array {
            $called = true;

            return ['success' => true];
        };

        self::assertFalse($this->makeCaptcha($this->settings('turnstile'), $request)->verify(''));
        self::assertFalse($this->makeCaptcha([
            SettingsService::REGISTRATION_CAPTCHA_PROVIDER_KEY => ['hcaptcha', 1],
        ], $request)->verify('token'));
        self::assertFalse($called);
    }

    /** @param array<string, array{string, int}> $settings */
    private function makeCaptcha(array $settings, Closure $request): RegistrationCaptcha
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturn(array_map(
            static fn (string $key, array $value): array => [
                'setting_key' => $key,
                'setting_value' => $value[0],
                'updated_at' => $value[1],
            ],
            array_keys($settings),
            array_values($settings),
        ));

        return new RegistrationCaptcha(
            new SettingsService(new SettingsRepository($db)),
            new Config(['SITE_URL' => 'https://example.test']),
            $request,
        );
    }

    /** @return array<string, array{string, int}> */
    private function settings(string $provider): array
    {
        return [
            SettingsService::REGISTRATION_CAPTCHA_PROVIDER_KEY => [$provider, 1],
            SettingsService::REGISTRATION_CAPTCHA_SITE_KEY => ['site-key', 1],
            SettingsService::REGISTRATION_CAPTCHA_SECRET_KEY => ['secret-key', 1],
        ];
    }
}

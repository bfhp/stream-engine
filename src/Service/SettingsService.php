<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use StreamEngine\Repository\SettingsRepository;

final class SettingsService
{
    public const array CRON_MODES = ['os', 'web', 'off'];
    public const string SITE_ICON_KEY = 'site_icon';
    public const string SITE_ICON_SVG_KEY = 'site_icon_svg';
    public const string HEADER_LOGO_KEY = 'header_logo';
    public const string REGISTRATION_HONEYPOT_FIELD_KEY = 'registration.honeypot_field';
    public const string DEFAULT_REGISTRATION_HONEYPOT_FIELD = 'contact_reference';
    public const string REGISTRATION_MODE_KEY = 'registration.mode';
    public const array REGISTRATION_MODES = ['closed', 'email', 'open'];
    public const string REGISTRATION_CAPTCHA_PROVIDER_KEY = 'registration.captcha.provider';
    public const string REGISTRATION_CAPTCHA_SITE_KEY = 'registration.captcha.site_key';
    public const string REGISTRATION_CAPTCHA_SECRET_KEY = 'registration.captcha.secret_key';
    public const array REGISTRATION_CAPTCHA_PROVIDERS = ['none', 'turnstile', 'hcaptcha'];

    /**
     * @var array<string, string>
     */
    private array $data = [];

    private int $lastModified = 0;

    private bool $loaded = false;

    public function __construct(
        private readonly SettingsRepository $repository,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureLoaded();

        return $this->data[$key] ?? $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        $this->ensureLoaded();

        return $this->data[$key] ?? $default;
    }

    public function getInt(string $key, int $default = 0): int
    {
        $this->ensureLoaded();

        return (int) ($this->data[$key] ?? $default);
    }

    public function lastModified(): int
    {
        $this->ensureLoaded();

        return $this->lastModified;
    }

    public function cronMode(): string
    {
        $mode = $this->getString('cron.mode', 'os');

        return in_array($mode, self::CRON_MODES, true) ? $mode : 'off';
    }

    public function registrationHoneypotField(): string
    {
        $field = $this->getString(
            self::REGISTRATION_HONEYPOT_FIELD_KEY,
            self::DEFAULT_REGISTRATION_HONEYPOT_FIELD,
        );

        return self::isValidRegistrationHoneypotField($field)
            ? $field
            : self::DEFAULT_REGISTRATION_HONEYPOT_FIELD;
    }

    public function registrationMode(): string
    {
        $mode = $this->getString(self::REGISTRATION_MODE_KEY, 'email');

        return in_array($mode, self::REGISTRATION_MODES, true) ? $mode : 'closed';
    }

    /** @return array{provider:string, siteKey:string, secret:string} */
    public function registrationCaptcha(): array
    {
        $provider = $this->getString(self::REGISTRATION_CAPTCHA_PROVIDER_KEY, 'none');
        if (! in_array($provider, self::REGISTRATION_CAPTCHA_PROVIDERS, true)) {
            $provider = 'none';
        }

        return [
            'provider' => $provider,
            'siteKey' => $this->getString(self::REGISTRATION_CAPTCHA_SITE_KEY),
            'secret' => $this->getString(self::REGISTRATION_CAPTCHA_SECRET_KEY),
        ];
    }

    public static function isValidRegistrationHoneypotField(string $field): bool
    {
        return preg_match('/\A[A-Za-z][A-Za-z0-9_]{2,63}\z/', $field) === 1
            && ! in_array($field, ['email', 'password'], true);
    }

    /**
     * @return array{svg:?string, ico:string, apple:string}
     */
    public function siteIcons(): array
    {
        $base = $this->getString(self::SITE_ICON_KEY);
        if (preg_match('~\A/uploads/site-icons/favicon-[a-f0-9]{16}\z~', $base) !== 1) {
            return [
                'svg' => '/favicon.svg',
                'ico' => '/favicon.ico',
                'apple' => '/apple-touch-icon.png',
            ];
        }

        $svg = $this->getString(self::SITE_ICON_SVG_KEY);
        if ($svg !== $base.'.svg') {
            $svg = null;
        }

        return [
            'svg' => $svg,
            'ico' => $base.'.ico',
            'apple' => $base.'.png',
        ];
    }

    public function headerLogo(): ?string
    {
        $url = $this->getString(self::HEADER_LOGO_KEY);

        return preg_match(
            '~\A/uploads/site-brand/header-logo-[a-f0-9]{16}\.(?:gif|png|webp)\z~',
            $url,
        ) === 1 ? $url : null;
    }

    public function set(string $key, string $value): void
    {
        $this->repository->set($key, $value);
        $this->reload();
    }

    /** @param array<string, string> $settings */
    public function setMany(array $settings): void
    {
        $this->repository->setMany($settings);
        $this->reload();
    }

    private function ensureLoaded(): void
    {
        if (! $this->loaded) {
            $this->reload();
        }
    }

    private function reload(): void
    {
        $settings = $this->repository->load();
        $this->data = $settings['data'];
        $this->lastModified = $settings['lastModified'];
        $this->loaded = true;
    }
}

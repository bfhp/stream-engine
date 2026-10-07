<?php

declare(strict_types=1);

namespace StreamEngine\Modules\Users;

use Closure;
use StreamEngine\Core\Config;
use StreamEngine\Service\SettingsService;

final class RegistrationCaptcha
{
    private const array ENDPOINTS = [
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'hcaptcha' => 'https://api.hcaptcha.com/siteverify',
    ];

    /** @var Closure(string, array<string, string>): ?array<string, mixed> */
    private readonly Closure $request;

    /** @param null|Closure(string, array<string, string>): ?array<string, mixed> $request */
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Config $config,
        ?Closure $request = null,
    ) {
        $this->request = $request ?? $this->post(...);
    }

    public function verify(string $token, string $remoteIp = ''): bool
    {
        $captcha = $this->settings->registrationCaptcha();
        $provider = $captcha['provider'];
        if ($provider === 'none') {
            return true;
        }
        if ($token === '' || strlen($token) > 4096 || $captcha['siteKey'] === '' || $captcha['secret'] === '') {
            return false;
        }

        $payload = [
            'secret' => $captcha['secret'],
            'response' => $token,
        ];
        if ($remoteIp !== '') {
            $payload['remoteip'] = $remoteIp;
        }
        if ($provider === 'hcaptcha') {
            $payload['sitekey'] = $captcha['siteKey'];
        }

        $endpoint = self::ENDPOINTS[$provider] ?? null;
        $result = $endpoint === null ? null : ($this->request)($endpoint, $payload);
        if (($result['success'] ?? false) !== true) {
            return false;
        }

        if ($provider === 'turnstile' && ($result['action'] ?? '') !== 'registration') {
            return false;
        }

        $expectedHostname = parse_url($this->config->siteUrl() ?? '', PHP_URL_HOST);
        return $provider !== 'turnstile'
            || ! is_string($expectedHostname)
            || $expectedHostname === ''
            || hash_equals($expectedHostname, (string) ($result['hostname'] ?? ''));
    }

    /** @param array<string, string> $payload @return ?array<string, mixed> */
    private function post(string $endpoint, array $payload): ?array
    {
        $curl = curl_init($endpoint);
        if ($curl === false) {
            return null;
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (! is_string($body) || $status < 200 || $status >= 300) {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }
}

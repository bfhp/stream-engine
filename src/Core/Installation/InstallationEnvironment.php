<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use InvalidArgumentException;

final readonly class InstallationEnvironment
{
    public string $appEnvironment;
    public string $siteUrl;
    public string $dbHost;
    public int $dbPort;
    public string $dbName;
    public string $dbUsername;
    public string $dbPassword;

    public function __construct(
        string $appEnvironment,
        string $siteUrl,
        string $dbHost,
        int|string $dbPort,
        string $dbName,
        string $dbUsername,
        string $dbPassword,
    ) {
        $this->appEnvironment = self::normalizeAppEnvironment($appEnvironment);
        $this->siteUrl = self::normalizeSiteUrl($siteUrl);
        $this->dbHost = self::normalizeDbHost($dbHost);
        $this->dbPort = self::normalizeDbPort($dbPort);
        $this->dbName = self::normalizeDbName($dbName);
        $this->dbUsername = self::normalizeDbUsername($dbUsername);
        $this->dbPassword = self::normalizeDbPassword($dbPassword);
    }

    public static function normalizeAppEnvironment(string $value): string
    {
        $value = strtolower(trim($value));
        if (! in_array($value, ['prod', 'dev', 'test'], true)) {
            throw new InvalidArgumentException('Application environment must be "prod", "dev" or "test".');
        }

        return $value;
    }

    public static function normalizeSiteUrl(string $value): string
    {
        self::assertSingleLineUtf8($value, 'Site URL');
        $value = rtrim(trim($value), '/');
        $parts = parse_url($value);
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! isset($parts['host'])
            || isset($parts['user'], $parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Site URL must be an absolute HTTP or HTTPS URL without credentials, query or fragment.');
        }

        return $value;
    }

    public static function normalizeDbHost(string $value): string
    {
        self::assertSingleLineUtf8($value, 'Database host');
        $value = trim($value);
        if ($value === '' || strlen($value) > 255 || preg_match('/\s/', $value) === 1) {
            throw new InvalidArgumentException('Database host must contain between 1 and 255 characters without whitespace.');
        }

        return $value;
    }

    public static function normalizeDbPort(int|string $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) {
            throw new InvalidArgumentException('Database port must be between 1 and 65535.');
        }

        return (int) $value;
    }

    public static function normalizeDbName(string $value): string
    {
        self::assertSingleLineUtf8($value, 'Database name');
        $value = trim($value);
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) !== 1) {
            throw new InvalidArgumentException('Database name may contain only letters, numbers, underscores and hyphens.');
        }

        return $value;
    }

    public static function normalizeDbUsername(string $value): string
    {
        self::assertSingleLineUtf8($value, 'Database username');
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 128) {
            throw new InvalidArgumentException('Database username must contain between 1 and 128 characters.');
        }

        return $value;
    }

    public static function normalizeDbPassword(string $value): string
    {
        self::assertSingleLineUtf8($value, 'Database password');
        if (mb_strlen($value) > 1024) {
            throw new InvalidArgumentException('Database password must not exceed 1024 characters.');
        }

        return $value;
    }

    /** @param array<string, mixed> $current @return array<string, string> */
    public function values(array $current): array
    {
        return [
            'APP_ENV' => $this->appEnvironment,
            'APP_SECRET' => self::existingSecret($current, 'APP_SECRET'),
            'SITE_URL' => $this->siteUrl,
            'DB_HOST' => $this->dbHost,
            'DB_PORT' => (string) $this->dbPort,
            'DB_NAME' => $this->dbName,
            'DB_USERNAME' => $this->dbUsername,
            'DB_PASSWORD' => $this->dbPassword,
            'CRON_KEY' => self::existingSecret($current, 'CRON_KEY'),
            'CRON_MODE' => is_string($current['CRON_MODE'] ?? null) && $current['CRON_MODE'] !== ''
                ? $current['CRON_MODE']
                : ($this->appEnvironment === 'prod' ? 'os' : 'web'),
        ];
    }

    /** @param array<string, mixed> $current */
    private static function existingSecret(array $current, string $key): string
    {
        $value = $current[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : bin2hex(random_bytes(32));
    }

    private static function assertSingleLineUtf8(string $value, string $field): void
    {
        if (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\n") || str_contains($value, "\r") || str_contains($value, "\0")) {
            throw new InvalidArgumentException($field.' must be valid single-line UTF-8.');
        }
    }
}

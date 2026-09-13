<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use InvalidArgumentException;

final readonly class InstallationConfig
{
    public const int MIN_PASSWORD_LENGTH = 10;

    public string $siteName;
    public string $locale;
    public string $adminEmail;
    public string $adminName;
    public string $adminPassword;

    public function __construct(
        string $siteName,
        string $locale,
        string $adminEmail,
        string $adminName,
        string $adminPassword,
    ) {
        $this->siteName = self::normalizeSiteName($siteName);
        $this->locale = self::normalizeLocale($locale);
        $this->adminEmail = self::normalizeAdminEmail($adminEmail);
        $this->adminName = self::normalizeAdminName($adminName);
        $this->adminPassword = self::normalizeAdminPassword($adminPassword);
    }

    public static function normalizeSiteName(string $value): string
    {
        self::assertUtf8($value, 'site name');
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 150) {
            throw new InvalidArgumentException('Site name must contain between 1 and 150 characters.');
        }

        return $value;
    }

    public static function normalizeLocale(string $value): string
    {
        self::assertUtf8($value, 'locale');
        $value = trim(strtolower($value));

        if (preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $value) !== 1) {
            throw new InvalidArgumentException('Locale must be a language code such as "en" or "ru".');
        }

        return $value;
    }

    public static function normalizeAdminEmail(string $value): string
    {
        self::assertUtf8($value, 'administrator email');
        $value = trim(mb_strtolower($value));

        if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Administrator email is invalid.');
        }
        if ($value === Installer::SYSTEM_EMAIL) {
            throw new InvalidArgumentException('The system account email is reserved.');
        }

        return $value;
    }

    public static function normalizeAdminName(string $value): string
    {
        self::assertUtf8($value, 'administrator name');
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > 50) {
            throw new InvalidArgumentException('Administrator name must contain between 1 and 50 characters.');
        }

        return $value;
    }

    public static function normalizeAdminPassword(string $value): string
    {
        self::assertUtf8($value, 'administrator password');

        if (mb_strlen($value) < self::MIN_PASSWORD_LENGTH || mb_strlen($value) > 255) {
            throw new InvalidArgumentException(sprintf(
                'Administrator password must contain between %d and 255 characters.',
                self::MIN_PASSWORD_LENGTH,
            ));
        }

        return $value;
    }

    private static function assertUtf8(string $value, string $field): void
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException(sprintf(
                'The %s is not valid UTF-8. Check the terminal locale and try again.',
                $field,
            ));
        }
    }
}

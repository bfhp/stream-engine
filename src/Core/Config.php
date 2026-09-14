<?php

declare(strict_types=1);

namespace StreamEngine\Core;

final readonly class Config
{
    public function __construct(
        private array $env,
    ) {

    }

    // ===== DB =====

    public function dbName(): string
    {
        return $this->env['DB_NAME'] ?? 'default';
    }

    public function dbHost(): string
    {
        return $this->env['DB_HOST'] ?? 'localhost';
    }

    public function dbPort(): int
    {
        return (int) ($this->env['DB_PORT'] ?? 3306);
    }

    public function dbUserName(): string
    {
        return $this->env['DB_USERNAME'] ?? '';
    }

    public function dbPassword(): string
    {
        return $this->env['DB_PASSWORD'] ?? '';
    }

    // ===== MEMCACHE =====

    public function cacheHost(): string
    {
        return $this->env['MEMCACHED_HOST'] ?? '127.0.0.1';
    }

    public function cachePort(): int
    {
        return (int) ($this->env['MEMCACHED_PORT'] ?? 11211);
    }

    public function cachePrefix(): string
    {
        return 'stream_engine_'.($this->env['CACHE_PREFIX'] ?? 'default');
    }

    // ===== UPLOADS =====

    public function uploadsDir(): string
    {
        return $this->env['UPLOADS_DIR'] ?? 'storage/uploads';
    }

    public function tempDir(): string
    {
        $dir = trim((string) ($this->env['TMP_DIR'] ?? ini_get('upload_tmp_dir')));

        return $dir !== '' ? $dir : sys_get_temp_dir();
    }

    public function twigCacheDir(): string
    {
        $dir = trim((string) ($this->env['TWIG_CACHE_DIR'] ?? ''));

        return $dir !== '' ? $dir : rtrim($this->tempDir(), '/').'/twig/';
    }

    public function themeDir(): ?string
    {
        $dir = trim((string) ($this->env['THEME_DIR'] ?? ''));

        return $dir !== '' ? $dir : null;
    }

    // ===== OBJECT STORAGE =====

    public function objectStorageEndpoint(): string
    {
        return rtrim((string) ($this->env['OBJECT_STORAGE_ENDPOINT'] ?? ''), '/');
    }

    public function objectStorageBucket(): string
    {
        return (string) ($this->env['OBJECT_STORAGE_BUCKET'] ?? '');
    }

    public function objectStorageRegion(): string
    {
        return (string) ($this->env['OBJECT_STORAGE_REGION'] ?? 'us-east-1');
    }

    public function objectStorageAccessKey(): string
    {
        return (string) ($this->env['OBJECT_STORAGE_ACCESS_KEY'] ?? '');
    }

    public function objectStorageSecretKey(): string
    {
        return (string) ($this->env['OBJECT_STORAGE_SECRET_KEY'] ?? '');
    }

    public function objectStoragePublicBaseUrl(): string
    {
        return rtrim((string) ($this->env['OBJECT_STORAGE_PUBLIC_BASE_URL'] ?? ''), '/');
    }

    // ===== SECRETS =====

    /**
     * General-purpose signing key for values the server hands a client and
     * later has to recognize as its own - today only the anonymous visit
     * cookie (UserService::recordGuestPresence()), which is signed so a
     * forged one is discarded before it can insert a row.
     *
     * Falls back to CRON_KEY, which every deployment already sets and keeps
     * secret, so this doesn't become another thing to configure before the
     * site works. Set APP_SECRET explicitly to keep the two apart.
     *
     * If neither is set the return is empty and anything signed with it is
     * effectively unsigned - callers should treat the signature as an abuse
     * speed bump, not an authentication boundary.
     */
    public function appSecret(): string
    {
        return (string) ($this->env['APP_SECRET'] ?? $this->env['CRON_KEY'] ?? '');
    }

    public function siteUrl(): string
    {
        return rtrim((string) ($this->env['SITE_URL'] ?? 'https://localhost'), '/');
    }

    // ===== CRON =====
    public function cronKey(): string
    {
        return $this->env['CRON_KEY'] ?? '';
    }
    public function cronMode(): string
    {
        return $this->env['CRON_MODE'] ?? 'web';
    }

    // ===== SMTP =====

    public function smtpHost(): string
    {
        return $this->env['SMTP_HOST'] ?? 'localhost';
    }

    public function smtpPort(): int
    {
        return (int) ($this->env['SMTP_PORT'] ?? 587);
    }

    public function smtpUsername(): string
    {
        return $this->env['SMTP_USERNAME'] ?? '';
    }

    public function smtpPassword(): string
    {
        return $this->env['SMTP_PASSWORD'] ?? '';
    }

    public function smtpEncryption(): string
    {
        return $this->env['SMTP_ENCRYPTION'] ?? 'tls';
    }

    public function smtpFrom(): string
    {
        return $this->env['SMTP_FROM'] ?? 'noreply@localhost';
    }
}

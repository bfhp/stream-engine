<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Config;

/**
 * Config is a plain array with 25 accessors over it, so what is worth testing
 * is not the reads but the **defaults** - they are what a deployment gets when
 * it forgets an environment variable, and several of them decide whether a
 * security control is on.
 */
final class ConfigTest extends TestCase
{
    /* ===============================
       Secrets and cron
    =============================== */

    /**
     * An unset cron key must be empty rather than anything guessable, and the
     * cron endpoint has to treat empty as "refuse everything" - which it now
     * does (APIController::handleCronRequest). Both halves are needed: an empty
     * key with a `hash_equals($configured, $given)` check and no emptiness
     * guard would open cron to anyone sending `?key=`.
     */
    public function testAnUnsetCronKeyIsEmpty(): void
    {
        $this->assertSame('', (new Config([]))->cronKey());
    }

    public function testCronIsOffUnlessAModeIsConfigured(): void
    {
        // 'no-cron' is the safe default: no web request triggers a background
        // process unless a deployment asked for it.
        $this->assertSame('no-cron', (new Config([]))->cronMode());
        $this->assertSame('web', (new Config(['CRON_MODE' => 'web']))->cronMode());
    }

    /**
     * appSecret() falls back to CRON_KEY so a fresh deployment signs *something*
     * without a second variable to set - and to the empty string if neither is
     * present, at which point anything it signs is effectively unsigned.
     *
     * Pinned so nobody starts treating the signature as an authentication
     * boundary: today its only user is the anonymous visit cookie, where it is
     * an abuse speed bump.
     */
    #[DataProvider('appSecretProvider')]
    public function testAppSecretFallsBackThroughCronKeyToEmpty(array $env, string $expected): void
    {
        $this->assertSame($expected, (new Config($env))->appSecret());
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function appSecretProvider(): array
    {
        return [
            'explicit' => [['APP_SECRET' => 'a-real-secret'], 'a-real-secret'],
            'falls back to the cron key' => [['CRON_KEY' => 'cron-secret'], 'cron-secret'],
            // Explicit wins, which is what "set APP_SECRET to keep the two
            // apart" depends on.
            'explicit beats the fallback' => [
                ['APP_SECRET' => 'a-real-secret', 'CRON_KEY' => 'cron-secret'],
                'a-real-secret',
            ],
            'neither' => [[], ''],
        ];
    }

    /**
     * `??` only skips *missing* keys, so an empty string in the environment is
     * a configured value and passes straight through - `CRON_KEY=` in a .env is
     * indistinguishable from not setting it at all. Written down because it is
     * the shape a half-finished deployment actually has.
     */
    public function testAnEmptyEnvironmentValueIsNotTreatedAsUnset(): void
    {
        $config = new Config(['CRON_KEY' => '', 'CRON_MODE' => '']);

        $this->assertSame('', $config->cronKey());
        $this->assertSame('', $config->cronMode());
    }

    public function testSiteUrlIsNormalizedAndHasSafeLocalDefault(): void
    {
        self::assertSame('https://example.test', (new Config(['SITE_URL' => 'https://example.test///']))->siteUrl());
        self::assertSame('https://localhost', (new Config([]))->siteUrl());
    }

    /* ===============================
       Object storage
    =============================== */

    /**
     * Both URL settings are rtrim'd, because they are concatenated with paths
     * that begin with `/` - a trailing slash would produce `//uploads/...`,
     * which S3 treats as a distinct (empty-named) key prefix.
     */
    public function testStorageUrlsLoseTheirTrailingSlash(): void
    {
        $config = new Config([
            'OBJECT_STORAGE_ENDPOINT' => 'https://storage.example.com/',
            'OBJECT_STORAGE_PUBLIC_BASE_URL' => 'https://cdn.example.com/files///',
        ]);

        $this->assertSame('https://storage.example.com', $config->objectStorageEndpoint());
        $this->assertSame('https://cdn.example.com/files', $config->objectStoragePublicBaseUrl());
    }

    public function testUnconfiguredStorageIsEmptyRatherThanPartiallyGuessed(): void
    {
        $config = new Config([]);

        // Credentials and bucket have no sensible default, so they are empty
        // and the storage client fails to configure rather than silently
        // pointing somewhere.
        $this->assertSame('', $config->objectStorageEndpoint());
        $this->assertSame('', $config->objectStorageBucket());
        $this->assertSame('', $config->objectStorageAccessKey());
        $this->assertSame('', $config->objectStorageSecretKey());
        $this->assertSame('', $config->objectStoragePublicBaseUrl());

        // The one exception: region has a real default because S3 signing
        // requires *some* region even against a non-AWS endpoint.
        $this->assertSame('us-east-1', $config->objectStorageRegion());
    }

    /* ===============================
       Directories
    =============================== */

    public function testAConfiguredUploadsDirIsUsedVerbatim(): void
    {
        $config = new Config(['UPLOADS_DIR' => '/srv/uploads']);

        // No realpath() on the configured value - the directory may not exist
        // yet at boot.
        $this->assertSame('/srv/uploads', $config->uploadsDir());
    }

    public function testTheUploadsDirDefaultsToStorageUploads(): void
    {
        $this->assertSame('storage/uploads', (new Config([]))->uploadsDir());
    }

    public function testThemeDirIsOptional(): void
    {
        $this->assertNull((new Config([]))->themeDir());
        $this->assertNull((new Config(['THEME_DIR' => '   ']))->themeDir());
        $this->assertSame('/srv/site/theme', (new Config(['THEME_DIR' => ' /srv/site/theme ']))->themeDir());
    }

    public function testTempDirFallsBackToSystemTempWhenUploadTmpDirIsEmpty(): void
    {
        $config = new Config([]);

        $this->assertSame(sys_get_temp_dir(), $config->tempDir());
    }

    public function testAConfiguredTempDirWins(): void
    {
        $this->assertSame('/var/tmp/se', (new Config(['TMP_DIR' => '/var/tmp/se']))->tempDir());
    }

    /**
     * Whitespace-only is treated as unset. Worth pinning because the value ends
     * up as a path prefix: a directory named " " would be created rather than
     * the fallback used.
     */
    public function testAWhitespaceOnlyTempDirIsTreatedAsUnset(): void
    {
        $this->assertSame(sys_get_temp_dir(), (new Config(['TMP_DIR' => "  \t "]))->tempDir());
    }

    /* ===============================
       Ports, and the strings that become them
    =============================== */

    /**
     * Environment values arrive as strings and these three are cast, so a
     * quoted port from a .env still reaches the driver as an int.
     */
    #[DataProvider('portProvider')]
    public function testPortsAreCastToIntegers(string $key, string $value, int $expected): void
    {
        $config = new Config([$key => $value]);

        $ports = [
            'DB_PORT' => $config->dbPort(),
            'MEMCACHED_PORT' => $config->cachePort(),
            'SMTP_PORT' => $config->smtpPort(),
        ];

        $this->assertSame($expected, $ports[$key]);
    }

    /** @return array<string, array{string, string, int}> */
    public static function portProvider(): array
    {
        return [
            'db port' => ['DB_PORT', '3307', 3307],
            'cache port' => ['MEMCACHED_PORT', '11212', 11212],
            'smtp port' => ['SMTP_PORT', '2525', 2525],
            // (int) '' is 0, so a blank port is a connection failure rather
            // than the default - the same "empty is configured" trap as above.
            'a blank port becomes zero, not the default' => ['DB_PORT', '', 0],
        ];
    }

    public function testDefaultPortsAreTheStandardOnes(): void
    {
        $config = new Config([]);

        $this->assertSame(3306, $config->dbPort());
        $this->assertSame(11211, $config->cachePort());
        $this->assertSame(587, $config->smtpPort());
    }

    /* ===============================
       Cache namespacing
    =============================== */

    /**
     * The prefix is what keeps two sites on one memcached from reading each
     * other's entries, so the fixed part must always be there and the
     * configured part must actually change it.
     */
    public function testTheCachePrefixIsAlwaysNamespaced(): void
    {
        $this->assertSame('stream_engine_default', (new Config([]))->cachePrefix());
        $this->assertSame('stream_engine_site2', (new Config(['CACHE_PREFIX' => 'site2']))->cachePrefix());
    }

    /* ===============================
       The rest of the defaults
    =============================== */

    public function testConnectionDefaultsAreLocalAndUnauthenticated(): void
    {
        $config = new Config([]);

        // A blank username/password rather than a guessed one: the connection
        // fails loudly instead of trying root.
        $this->assertSame('default', $config->dbName());
        $this->assertSame('localhost', $config->dbHost());
        $this->assertSame('', $config->dbUserName());
        $this->assertSame('', $config->dbPassword());
        $this->assertSame('127.0.0.1', $config->cacheHost());
    }

    public function testMailDefaultsAreEncryptedAndNonDeliverable(): void
    {
        $config = new Config([]);

        // tls by default - an unconfigured deployment must not fall back to
        // sending credentials in the clear.
        $this->assertSame('tls', $config->smtpEncryption());
        $this->assertSame('localhost', $config->smtpHost());
        $this->assertSame('', $config->smtpUsername());
        $this->assertSame('', $config->smtpPassword());
        $this->assertSame('noreply@localhost', $config->smtpFrom());
    }
}

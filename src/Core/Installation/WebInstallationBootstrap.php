<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use RuntimeException;
use Throwable;

final readonly class WebInstallationBootstrap
{
    private const string CLAIM_COOKIE = 'stream_engine_install_claim';

    /** @param array<string, mixed> $environment */
    public function __construct(
        private string $projectRoot,
        private array $environment,
    ) {
    }

    /**
     * Handles an uninstalled site and returns true. A ready installation
     * returns false so the normal application bootstrap can continue.
     */
    public function handle(): bool
    {
        $states = new InstallationStateStore($this->projectRoot.'/storage/installation.json');
        $tokens = new InstallationTokenStore($this->projectRoot.'/storage/installation-token');
        $claims = new InstallationClaimStore($this->projectRoot.'/storage/installation-claim.json');

        try {
            $state = $states->load();
        } catch (Throwable $e) {
            return $this->error(
                'Could not read installation state: '.$e->getMessage(),
                'Stream Engine installation state is unavailable. Check the PHP error log.',
            );
        }

        if ($state?->status === InstallationState::STATUS_READY) {
            $this->cleanUpAccessFiles($tokens, $claims);

            return false;
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($path !== '/install') {
            header('Location: /install', true, 302);

            return true;
        }

        try {
            $installationToken = $tokens->load();
            $csrfToken = $this->csrfToken();
            $browserAuthorized = $installationToken !== null || $this->authorizeBrowser($claims);
        } catch (Throwable $e) {
            return $this->error(
                'Could not prepare web installation: '.$e->getMessage(),
                'Stream Engine web installation is unavailable. Check the PHP error log.',
            );
        }

        $installer = new WebInstaller(
            installationToken: $installationToken,
            languagesDirectory: InstallerFactory::engineRoot().'/src/Lang',
            install: function (
                InstallationConfig $config,
                InstallationEnvironment $environment,
            ) use ($tokens, $claims): InstallationState {
                $state = (new ConfiguredInstaller($this->projectRoot, $this->environment))
                    ->install($config, $environment);
                $tokens->delete();
                $claims->delete();

                return $state;
            },
            browserAuthorized: $browserAuthorized,
            environmentDefaults: $this->environmentDefaults(),
            preflightReport: (new InstallationPreflight($this->projectRoot, $this->environment))->system(),
        );

        $response = $installer->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_POST, $csrfToken);
        $this->send($response);

        return true;
    }

    private function csrfToken(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE && ! session_start()) {
            throw new RuntimeException('Could not start the installation session.');
        }
        if (! isset($_SESSION['installation_csrf']) || ! is_string($_SESSION['installation_csrf'])) {
            $_SESSION['installation_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['installation_csrf'];
    }

    private function authorizeBrowser(InstallationClaimStore $claims): bool
    {
        $claimCookie = $_COOKIE[self::CLAIM_COOKIE] ?? null;
        $claimCookie = is_string($claimCookie) ? $claimCookie : null;
        if ($claims->owns($claimCookie)) {
            return true;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return false;
        }

        $claim = $claims->acquire();
        if ($claim === null) {
            return false;
        }

        setcookie(self::CLAIM_COOKIE, $claim, [
            'expires' => time() + InstallationClaimStore::TTL,
            'path' => '/install',
            'secure' => $this->isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        return true;
    }

    private function isSecureRequest(): bool
    {
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /** @return array<string, string> */
    private function environmentDefaults(): array
    {
        $siteUrl = $this->environment['SITE_URL'] ?? null;
        if (! is_string($siteUrl) || $siteUrl === '') {
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $siteUrl = is_string($host) && $host !== ''
                ? ($this->isSecureRequest() ? 'https://' : 'http://').$host
                : '';
        }

        return [
            'app_environment' => self::environmentValue($this->environment, 'APP_ENV', 'prod'),
            'site_url' => $siteUrl,
            'db_host' => self::environmentValue($this->environment, 'DB_HOST', 'localhost'),
            'db_port' => self::environmentValue($this->environment, 'DB_PORT', '3306'),
            'db_name' => self::environmentValue($this->environment, 'DB_NAME'),
            'db_username' => self::environmentValue($this->environment, 'DB_USERNAME'),
        ];
    }

    /** @param array<string, mixed> $environment */
    private static function environmentValue(array $environment, string $key, string $default = ''): string
    {
        $value = $environment[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    private function cleanUpAccessFiles(
        InstallationTokenStore $tokens,
        InstallationClaimStore $claims,
    ): void {
        try {
            $tokens->delete();
            $claims->delete();
        } catch (Throwable $e) {
            error_log('Could not clean up web installation access: '.$e->getMessage());
        }
    }

    private function send(WebInstallationResponse $response): void
    {
        http_response_code($response->status);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; form-action \'self\'; base-uri \'none\'; frame-ancestors \'none\'');
        header('X-Content-Type-Options: nosniff');
        foreach ($response->headers as $name => $value) {
            header($name.': '.$value);
        }
        echo $response->body;
    }

    private function error(string $logMessage, string $publicMessage): bool
    {
        error_log($logMessage);
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo $publicMessage."\n";

        return true;
    }
}

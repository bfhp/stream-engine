<?php

declare(strict_types=1);

namespace StreamEngine\Core\Installation;

use InvalidArgumentException;
use Throwable;

final readonly class WebInstaller
{
    /**
     * @param callable(InstallationConfig, InstallationEnvironment): InstallationState $install
     * @param null|callable(string): void $log
     * @param array<string, string|int> $environmentDefaults
     */
    public function __construct(
        private ?string $installationToken,
        private string $languagesDirectory,
        private mixed $install,
        private mixed $log = null,
        private bool $browserAuthorized = true,
        private array $environmentDefaults = [],
        private ?InstallationPreflightReport $preflightReport = null,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function handle(string $method, array $input, string $csrfToken): WebInstallationResponse
    {
        if (! $this->browserAuthorized) {
            return new WebInstallationResponse(409, $this->claimedPage());
        }

        if ($method === 'GET') {
            return new WebInstallationResponse(200, $this->form($csrfToken, $this->values($input), []));
        }
        if ($method !== 'POST') {
            return new WebInstallationResponse(405, '', ['Allow' => 'GET, POST']);
        }

        [$config, $environment, $errors, $values] = $this->validate($input, $csrfToken);
        if ($config === null || $environment === null) {
            return new WebInstallationResponse(422, $this->form($csrfToken, $values, $errors));
        }

        try {
            ($this->install)($config, $environment);
        } catch (InstallationPreflightException $e) {
            return new WebInstallationResponse(
                422,
                $this->form(
                    $csrfToken,
                    $values,
                    ['form' => 'Fix the failed preflight checks and try again.'],
                    $e->report,
                ),
            );
        } catch (Throwable $e) {
            $message = 'Web installation failed: '.$e->getMessage();
            if ($this->log !== null) {
                ($this->log)($message);
            } else {
                error_log($message);
            }

            return new WebInstallationResponse(
                503,
                $this->form($csrfToken, $values, [
                    'form' => 'Installation failed. Check the PHP error log and try again.',
                ]),
            );
        }

        return new WebInstallationResponse(303, '', ['Location' => '/']);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{?InstallationConfig, ?InstallationEnvironment, array<string, string>, array<string, string>}
     */
    private function validate(array $input, string $csrfToken): array
    {
        $values = $this->values($input);
        $errors = [];

        if (! hash_equals($csrfToken, $this->string($input['csrf_token'] ?? null))) {
            $errors['form'] = 'The form expired. Reload the page and try again.';
        }
        if ($this->installationToken !== null
            && ! hash_equals($this->installationToken, $this->string($input['installation_token'] ?? null))) {
            $errors['installation_token'] = 'The installation token is invalid.';
        }
        if ($this->string($input['admin_password'] ?? null) !== $this->string($input['admin_password_confirmation'] ?? null)) {
            $errors['admin_password'] = 'Administrator passwords do not match.';
        }

        $validators = [
            'site_name' => InstallationConfig::normalizeSiteName(...),
            'locale' => function (string $value): string {
                $locale = InstallationConfig::normalizeLocale($value);
                if (! is_file(rtrim($this->languagesDirectory, '/').'/'.$locale.'.php')) {
                    throw new InvalidArgumentException(sprintf('Locale "%s" is not available.', $locale));
                }

                return $locale;
            },
            'admin_email' => InstallationConfig::normalizeAdminEmail(...),
            'admin_name' => InstallationConfig::normalizeAdminName(...),
            'admin_password' => InstallationConfig::normalizeAdminPassword(...),
            'app_environment' => InstallationEnvironment::normalizeAppEnvironment(...),
            'site_url' => InstallationEnvironment::normalizeSiteUrl(...),
            'db_host' => InstallationEnvironment::normalizeDbHost(...),
            'db_port' => InstallationEnvironment::normalizeDbPort(...),
            'db_name' => InstallationEnvironment::normalizeDbName(...),
            'db_username' => InstallationEnvironment::normalizeDbUsername(...),
            'db_password' => InstallationEnvironment::normalizeDbPassword(...),
        ];

        $normalized = [];
        foreach ($validators as $field => $validator) {
            try {
                $value = array_key_exists($field, $values)
                    ? $values[$field]
                    : $this->string($input[$field] ?? null);
                $normalized[$field] = $validator($value);
            } catch (InvalidArgumentException $e) {
                $errors[$field] = $e->getMessage();
            }
        }

        if ($errors !== []) {
            return [null, null, $errors, $values];
        }

        return [new InstallationConfig(
            siteName: $normalized['site_name'],
            locale: $normalized['locale'],
            adminEmail: $normalized['admin_email'],
            adminName: $normalized['admin_name'],
            adminPassword: $normalized['admin_password'],
        ), new InstallationEnvironment(
            appEnvironment: $normalized['app_environment'],
            siteUrl: $normalized['site_url'],
            dbHost: $normalized['db_host'],
            dbPort: $normalized['db_port'],
            dbName: $normalized['db_name'],
            dbUsername: $normalized['db_username'],
            dbPassword: $normalized['db_password'],
        ), [], $values];
    }

    /** @param array<string, mixed> $input @return array<string, string> */
    private function values(array $input): array
    {
        return [
            'site_name' => $this->string($input['site_name'] ?? 'Stream Engine'),
            'locale' => $this->string($input['locale'] ?? 'ru'),
            'admin_email' => $this->string($input['admin_email'] ?? ''),
            'admin_name' => $this->string($input['admin_name'] ?? 'Administrator'),
            'app_environment' => $this->string($this->environmentDefaults['app_environment'] ?? 'prod'),
            'site_url' => $this->string($input['site_url'] ?? ($this->environmentDefaults['site_url'] ?? '')),
            'db_host' => $this->string($input['db_host'] ?? ($this->environmentDefaults['db_host'] ?? 'localhost')),
            'db_port' => $this->string($input['db_port'] ?? ($this->environmentDefaults['db_port'] ?? '3306')),
            'db_name' => $this->string($input['db_name'] ?? ($this->environmentDefaults['db_name'] ?? '')),
            'db_username' => $this->string($input['db_username'] ?? ($this->environmentDefaults['db_username'] ?? '')),
        ];
    }

    /** @param array<string, string> $values @param array<string, string> $errors */
    private function form(
        string $csrfToken,
        array $values,
        array $errors,
        ?InstallationPreflightReport $preflightReport = null,
    ): string {
        $error = static fn (string $field): string => isset($errors[$field])
            ? '<p class="error">'.self::escape($errors[$field]).'</p>'
            : '';
        $tokenField = $this->installationToken !== null
            ? '<label>Installation token<input type="password" name="installation_token" autocomplete="off" required></label><p class="hint">Copy the token from <code>storage/installation-token</code> on the server.</p>'.$error('installation_token')
            : '<p class="hint">This browser owns the one-hour installation session.</p>';

        return $this->layout('Install Stream Engine', sprintf(
            '<main><h1>Install Stream Engine</h1><p class="intro">Connect this site to its new database and create the first administrator.</p>%s%s<form method="post" action="/install" novalidate><input type="hidden" name="csrf_token" value="%s">%s<h2>Environment</h2><label>Site URL<input type="url" name="site_url" value="%s" required></label>%s<div class="row"><label>Database host<input name="db_host" value="%s" required></label><label>Database port<input type="number" name="db_port" value="%s" min="1" max="65535" required></label></div>%s%s<label>Database name<input name="db_name" value="%s" required></label>%s<label>Database username<input name="db_username" value="%s" required></label>%s<label>Database password<input type="password" name="db_password" autocomplete="new-password"></label>%s<h2>Site</h2><label>Site name<input name="site_name" value="%s" maxlength="150" required></label>%s<label>Locale<input name="locale" value="%s" maxlength="5" required></label>%s<h2>Administrator</h2><label>Administrator email<input type="email" name="admin_email" value="%s" required></label>%s<label>Administrator name<input name="admin_name" value="%s" maxlength="50" required></label>%s<label>Administrator password<input type="password" name="admin_password" minlength="%d" maxlength="255" autocomplete="new-password" required></label><p class="hint">10–255 characters; spaces, Unicode and special characters are allowed.</p>%s<label>Repeat administrator password<input type="password" name="admin_password_confirmation" minlength="%d" maxlength="255" autocomplete="new-password" required></label><button type="submit">Install Stream Engine</button></form></main>',
            $error('form'),
            $this->preflight($preflightReport ?? $this->preflightReport),
            self::escape($csrfToken),
            $tokenField,
            self::escape($values['site_url']),
            $error('site_url'),
            self::escape($values['db_host']),
            self::escape($values['db_port']),
            $error('db_host'),
            $error('db_port'),
            self::escape($values['db_name']),
            $error('db_name'),
            self::escape($values['db_username']),
            $error('db_username'),
            $error('db_password'),
            self::escape($values['site_name']),
            $error('site_name'),
            self::escape($values['locale']),
            $error('locale'),
            self::escape($values['admin_email']),
            $error('admin_email'),
            self::escape($values['admin_name']),
            $error('admin_name'),
            InstallationConfig::MIN_PASSWORD_LENGTH,
            $error('admin_password'),
            InstallationConfig::MIN_PASSWORD_LENGTH,
        ));
    }

    private function preflight(?InstallationPreflightReport $report): string
    {
        if ($report === null) {
            return '';
        }

        $items = '';
        foreach ($report->checks as $check) {
            $status = $check->passed ? 'Passed' : 'Failed';
            $items .= sprintf(
                '<li class="check %s"><strong>%s: %s</strong><span>%s</span></li>',
                $check->passed ? 'passed' : 'failed',
                $status,
                self::escape($check->label),
                self::escape($check->message),
            );
        }

        return '<section class="preflight"><h2>Preflight checks</h2><ul>'.$items.'</ul></section>';
    }

    private function claimedPage(): string
    {
        return $this->layout(
            'Installation already opened',
            '<main><h1>Installation already opened</h1><p>Another browser owns the current installation session. Continue there, wait one hour, or remove <code>storage/installation-claim.json</code> on the server to start again.</p></main>',
        );
    }

    private function layout(string $title, string $content): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.self::escape($title).'</title><style>html{color-scheme:light dark;font:16px/1.5 system-ui,sans-serif}body{margin:0;background:#f3f5f7;color:#17202a}main{box-sizing:border-box;max-width:42rem;margin:5vh auto;padding:2rem;border-radius:1rem;background:#fff;box-shadow:0 1rem 3rem #17202a18}h1{margin-top:0}h2{margin-top:2rem}.intro,.hint{color:#5d6d7e}.hint{font-size:.875rem;margin-top:-.5rem}.row{display:grid;grid-template-columns:2fr 1fr;gap:1rem}label{display:grid;gap:.35rem;margin:1rem 0;font-weight:600}input{box-sizing:border-box;width:100%;padding:.75rem;border:1px solid #aeb6bf;border-radius:.5rem;background:#fff;color:#17202a;font:inherit}button{margin-top:1rem;padding:.8rem 1.2rem;border:0;border-radius:.5rem;background:#1769e0;color:#fff;font:inherit;font-weight:700;cursor:pointer}.error{padding:.65rem .8rem;border-radius:.4rem;background:#fdecea;color:#a61b1b}.preflight ul{padding:0;list-style:none}.check{display:grid;gap:.15rem;margin:.5rem 0;padding:.65rem .8rem;border-radius:.4rem}.check span{font-size:.875rem}.passed{background:#eaf7ee;color:#176b34}.failed{background:#fdecea;color:#a61b1b}@media(max-width:36rem){.row{grid-template-columns:1fr}}@media(prefers-color-scheme:dark){body{background:#111820;color:#eef2f5}main{background:#1c2732}input{background:#111820;color:#eef2f5;border-color:#506070}.intro,.hint{color:#aebbc7}.passed{background:#183c26;color:#a7e7bd}.failed{background:#471f22;color:#ffc2c2}}</style></head><body>'.$content.'</body></html>';
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

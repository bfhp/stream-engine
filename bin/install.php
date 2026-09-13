<?php

declare(strict_types=1);

use StreamEngine\Core\Installation\InstallationConfig;
use StreamEngine\Core\Installation\InstallationEnvironment;
use StreamEngine\Core\Installation\ConfiguredInstaller;
use StreamEngine\Core\Installation\InstallerFactory;

require __DIR__.'/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__.'/..')->safeLoad();

try {
    $options = getopt('', [
        'site-name:',
        'locale:',
        'admin-email:',
        'admin-name:',
        'admin-password:',
        'admin-password-file:',
        'app-env:',
        'site-url:',
        'db-host:',
        'db-port:',
        'db-name:',
        'db-username:',
        'db-password:',
        'no-interaction',
    ]);
    if ($options === false) {
        throw new RuntimeException('Could not parse command-line options.');
    }

    $nonInteractive = array_key_exists('no-interaction', $options);
    $root = dirname(__DIR__);
    $engineRoot = InstallerFactory::engineRoot();
    $environmentConfig = installationEnvironment($options, $nonInteractive, $_ENV);
    $installConfig = installationConfig($options, $nonInteractive, $engineRoot.'/src/Lang');
    $state = (new ConfiguredInstaller($root, $_ENV))->install($installConfig, $environmentConfig);

    echo sprintf("Stream Engine %s is installed and ready.\n", $state->release);
    echo sprintf("Administrator: %s\n", $installConfig->adminEmail);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}

/**
 * @param array<string, mixed> $options
 * @param array<string, mixed> $current
 */
function installationEnvironment(array $options, bool $nonInteractive, array $current): InstallationEnvironment
{
    return new InstallationEnvironment(
        appEnvironment: validatedOptionOrPrompt(
            $options,
            'app-env',
            'Application environment',
            environmentDefault($current, 'APP_ENV', 'prod'),
            $nonInteractive,
            InstallationEnvironment::normalizeAppEnvironment(...),
        ),
        siteUrl: validatedOptionOrPrompt(
            $options,
            'site-url',
            'Site URL',
            environmentDefault($current, 'SITE_URL', 'http://localhost'),
            $nonInteractive,
            InstallationEnvironment::normalizeSiteUrl(...),
        ),
        dbHost: validatedOptionOrPrompt(
            $options,
            'db-host',
            'Database host',
            environmentDefault($current, 'DB_HOST', 'localhost'),
            $nonInteractive,
            InstallationEnvironment::normalizeDbHost(...),
        ),
        dbPort: validatedOptionOrPrompt(
            $options,
            'db-port',
            'Database port',
            environmentDefault($current, 'DB_PORT', '3306'),
            $nonInteractive,
            static fn (string $value): string => (string) InstallationEnvironment::normalizeDbPort($value),
        ),
        dbName: validatedOptionOrPrompt(
            $options,
            'db-name',
            'Database name',
            environmentDefault($current, 'DB_NAME'),
            $nonInteractive,
            InstallationEnvironment::normalizeDbName(...),
        ),
        dbUsername: validatedOptionOrPrompt(
            $options,
            'db-username',
            'Database username',
            environmentDefault($current, 'DB_USERNAME'),
            $nonInteractive,
            InstallationEnvironment::normalizeDbUsername(...),
        ),
        dbPassword: databasePassword($options, $nonInteractive, $current),
    );
}

/** @param array<string, mixed> $current */
function environmentDefault(array $current, string $key, ?string $fallback = null): ?string
{
    $value = $current[$key] ?? null;

    return is_string($value) ? $value : $fallback;
}

/**
 * @param array<string, mixed> $options
 */
function installationConfig(array $options, bool $nonInteractive, string $languagesDirectory): InstallationConfig
{
    return new InstallationConfig(
        siteName: validatedOptionOrPrompt(
            $options,
            'site-name',
            'Site name',
            'Stream Engine',
            $nonInteractive,
            InstallationConfig::normalizeSiteName(...),
        ),
        locale: validatedOptionOrPrompt(
            $options,
            'locale',
            'Locale',
            'ru',
            $nonInteractive,
            static function (string $value) use ($languagesDirectory): string {
                $locale = InstallationConfig::normalizeLocale($value);
                if (! is_file(rtrim($languagesDirectory, '/').'/'.$locale.'.php')) {
                    throw new InvalidArgumentException(sprintf('Locale "%s" is not available.', $locale));
                }

                return $locale;
            },
        ),
        adminEmail: validatedOptionOrPrompt(
            $options,
            'admin-email',
            'Administrator email',
            null,
            $nonInteractive,
            InstallationConfig::normalizeAdminEmail(...),
        ),
        adminName: validatedOptionOrPrompt(
            $options,
            'admin-name',
            'Administrator name',
            'Administrator',
            $nonInteractive,
            InstallationConfig::normalizeAdminName(...),
        ),
        adminPassword: adminPassword($options, $nonInteractive),
    );
}

/**
 * @param array<string, mixed> $options
 * @param callable(string): string $validator
 */
function validatedOptionOrPrompt(
    array $options,
    string $option,
    string $label,
    ?string $default,
    bool $nonInteractive,
    callable $validator,
): string {
    $useOption = true;

    while (true) {
        $value = optionOrPrompt($useOption ? $options : [], $option, $label, $default, $nonInteractive);

        try {
            return $validator($value);
        } catch (InvalidArgumentException $e) {
            if ($nonInteractive) {
                throw $e;
            }

            fwrite(STDERR, "Invalid input: {$e->getMessage()}\nPlease enter {$label} again.\n\n");
            $useOption = false;
        }
    }
}

/** @param array<string, mixed> $options */
function optionOrPrompt(
    array $options,
    string $option,
    string $label,
    ?string $default,
    bool $nonInteractive,
): string {
    if (isset($options[$option]) && is_string($options[$option])) {
        return $options[$option];
    }
    if ($nonInteractive) {
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException(sprintf('Missing required option --%s.', $option));
    }

    $suffix = $default !== null ? ' ['.$default.']' : '';
    fwrite(STDOUT, $label.$suffix.': ');
    $value = fgets(STDIN);
    if ($value === false) {
        throw new RuntimeException(sprintf('Could not read %s.', strtolower($label)));
    }
    $value = trim($value);

    return $value !== '' ? $value : ($default ?? '');
}

/** @param array<string, mixed> $options */
function adminPassword(array $options, bool $nonInteractive): string
{
    $useConfiguredPassword = true;

    while (true) {
        $optionPassword = $useConfiguredPassword ? ($options['admin-password'] ?? null) : null;
        $passwordFile = $useConfiguredPassword ? ($options['admin-password-file'] ?? getenv('CMS_ADMIN_PASSWORD_FILE')) : null;
        if (is_string($optionPassword)) {
            $password = $optionPassword;
        } elseif (is_string($passwordFile) && $passwordFile !== '') {
            $password = file_get_contents($passwordFile);
            if ($password === false) {
                throw new RuntimeException(sprintf('Could not read administrator password file "%s".', $passwordFile));
            }
            $password = rtrim($password, "\r\n");
        } else {
            $password = $useConfiguredPassword ? getenv('CMS_ADMIN_PASSWORD') : false;
            if (! is_string($password) || $password === '') {
                if ($nonInteractive) {
                    throw new RuntimeException('Set CMS_ADMIN_PASSWORD_FILE or CMS_ADMIN_PASSWORD in non-interactive mode.');
                }

                fwrite(STDOUT, "Administrator password requirements: 10-255 characters; spaces, Unicode and special characters are allowed.\n");
                $password = hiddenPrompt('Administrator password: ');
                $confirmation = hiddenPrompt('Repeat administrator password: ');
                if (! hash_equals($password, $confirmation)) {
                    $error = new InvalidArgumentException('Administrator passwords do not match.');
                }
            }
        }

        try {
            if (isset($error)) {
                throw $error;
            }

            return InstallationConfig::normalizeAdminPassword($password);
        } catch (InvalidArgumentException $e) {
            if ($nonInteractive) {
                throw $e;
            }

            fwrite(STDERR, "Invalid input: {$e->getMessage()}\nPlease enter Administrator password again.\n\n");
            $useConfiguredPassword = false;
            unset($error);
        }
    }
}

/** @param array<string, mixed> $options @param array<string, mixed> $current */
function databasePassword(array $options, bool $nonInteractive, array $current): string
{
    if (array_key_exists('db-password', $options) && is_string($options['db-password'])) {
        return InstallationEnvironment::normalizeDbPassword($options['db-password']);
    }

    $configured = $current['DB_PASSWORD'] ?? null;
    if (is_string($configured)) {
        return InstallationEnvironment::normalizeDbPassword($configured);
    }
    if ($nonInteractive) {
        throw new RuntimeException('Missing required option --db-password. Use --db-password= for an empty password.');
    }

    fwrite(STDOUT, "Database password (input hidden; may be empty).\n");

    return InstallationEnvironment::normalizeDbPassword(hiddenPrompt('Database password: '));
}

function hiddenPrompt(string $label): string
{
    fwrite(STDOUT, $label);
    $stty = shell_exec('stty -g 2>/dev/null');
    if (is_string($stty) && trim($stty) !== '') {
        shell_exec('stty -echo 2>/dev/null');
    }

    try {
        $value = fgets(STDIN);
    } finally {
        if (is_string($stty) && trim($stty) !== '') {
            shell_exec('stty '.escapeshellarg(trim($stty)).' 2>/dev/null');
            fwrite(STDOUT, "\n");
        }
    }

    if ($value === false) {
        throw new RuntimeException('Could not read administrator password.');
    }

    return rtrim($value, "\r\n");
}

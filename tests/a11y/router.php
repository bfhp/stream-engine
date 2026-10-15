<?php

declare(strict_types=1);

/*
 * Router for PHP's built-in web server, used by the accessibility scan in CI:
 *
 *   php -S 127.0.0.1:8080 -t public tests/a11y/router.php
 *
 * It mirrors the nginx development configuration: existing files below
 * public/ are served as they are and every other request goes to the front
 * controller. It is not intended for production use.
 */

$projectRoot = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (is_string($path) && $path !== '/' && ! str_contains($path, '..')
    && is_file($projectRoot.'/public'.rawurldecode($path))) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $projectRoot.'/public/index.php';

require $projectRoot.'/public/index.php';

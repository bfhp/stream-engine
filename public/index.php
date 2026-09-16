<?php

declare(strict_types=1);

use StreamEngine\Core\Installation\WebInstallationBootstrap;
use StreamEngine\StreamEngine;

require __DIR__.'/../vendor/autoload.php';

try {
    Dotenv\Dotenv::createImmutable(__DIR__.'/..')->safeLoad();

    if ((new WebInstallationBootstrap(dirname(__DIR__), $_ENV))->handle()) {
        exit;
    }

    (new StreamEngine())->handleRequest();
} catch (Throwable $e) {
    error_log(sprintf(
        'Application bootstrap failed: %s: %s in %s:%d',
        $e::class,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
    ));

    if (! headers_sent()) {
        http_response_code(500);
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $expectsJson = is_string($path) && ($path === '/api' || str_starts_with($path, '/api/'));

    if ($expectsJson) {
        if (! headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'error' => 'Internal server error.',
            'code' => 'internal_error',
        ]);

        exit;
    }

    if (! headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
    echo <<<'HTML'
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>500 — Internal server error</title></head>
        <body><main><h1>500</h1><p>Internal server error.</p></main></body>
        </html>
        HTML;
}

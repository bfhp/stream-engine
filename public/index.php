<?php

declare(strict_types=1);

use StreamEngine\Core\Exceptions\ValidationException;
use StreamEngine\Core\Installation\WebInstallationBootstrap;
use StreamEngine\StreamEngine;

require __DIR__.'/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__. '/..')->safeLoad();

if ((new WebInstallationBootstrap(dirname(__DIR__), $_ENV))->handle()) {
    exit;
}

$engine = new StreamEngine();
try {
    $engine->handleRequest();
} catch (ValidationException $e) {
    http_response_code($e->getHttpCode());
    echo json_encode([
        'error' => [
            'code' => $e->getCodeName(),
            'message' => $e->getMessage()
        ]
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => [
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
}

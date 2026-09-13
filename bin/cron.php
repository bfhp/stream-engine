<?php

declare(strict_types=1);

use StreamEngine\StreamEngine;

const CRON_PROCESS = true;

$autoload = realpath($GLOBALS['_composer_autoload_path'] ?? __DIR__.'/../vendor/autoload.php');
if ($autoload === false) {
    throw new RuntimeException('Composer autoloader not found. Run cron through vendor/bin/cron.php.');
}

require $autoload;

$root = dirname($autoload, 2);
Dotenv\Dotenv::createImmutable($root)->load();

$lockFile = $root.'/storage/cron.lock';

$fp = fopen($lockFile, 'c');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    exit;
}
ftruncate($fp, 0);
fwrite($fp, (string) getmypid());

ini_set('log_errors', '1');
ini_set('error_log', $root.'/storage/cron-error.log');

ini_set('max_execution_time', '0');
set_time_limit(0);

try {
    $engine = new StreamEngine();
    $engine->runCron();
} catch (Throwable $e) {
    error_log($e->getMessage());
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}

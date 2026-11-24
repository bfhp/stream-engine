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

// No options: one scheduler tick, which starts a worker per due task.
// --task=<name>: a manual run of that task, ignoring its interval.
// --task=<name> --scheduled: the worker a tick started.
$options = getopt('', ['task:', 'scheduled']);
$task = $options['task'] ?? null;
if ($task !== null && (! is_string($task) || $task === '')) {
    throw new InvalidArgumentException('The --task option requires a task name.');
}
$scheduled = array_key_exists('scheduled', $options);
if ($scheduled && $task === null) {
    throw new InvalidArgumentException('The --scheduled option requires --task.');
}
if ($task !== null) {
    // A worker never starts other workers (CronTrigger::spawnScheduledTask()).
    define('CRON_WORKER', true);
}

$fp = null;
if ($task === null) {
    $lockFile = $root.'/storage/cron.lock';
    $fp = fopen($lockFile, 'c');
    if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
        exit;
    }
    ftruncate($fp, 0);
    fwrite($fp, (string) getmypid());
}

ini_set('log_errors', '1');
ini_set('error_log', $root.'/storage/cron-error.log');

ini_set('max_execution_time', '0');
set_time_limit(0);

try {
    $engine = new StreamEngine();
    if ($task === null) {
        $engine->runCron();
    } elseif ($scheduled) {
        $engine->runScheduledCronTask($task);
    } else {
        $engine->runCronTask($task);
    }
} finally {
    if (is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

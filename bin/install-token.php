<?php

declare(strict_types=1);

use StreamEngine\Core\Installation\InstallationState;
use StreamEngine\Core\Installation\InstallationStateStore;
use StreamEngine\Core\Installation\InstallationTokenStore;

require __DIR__.'/../vendor/autoload.php';

try {
    $root = dirname(__DIR__);
    $state = (new InstallationStateStore($root.'/storage/installation.json'))->load();
    if ($state?->status === InstallationState::STATUS_READY) {
        throw new RuntimeException('Stream Engine is already installed.');
    }

    $token = (new InstallationTokenStore(
        $root.'/storage/installation-token',
        static function (): void {
        },
    ))->create();

    echo "Protected web installation is enabled.\n";
    echo "Installation token: {$token}\n";
    echo "Open /install and enter this token.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);
    exit(1);
}

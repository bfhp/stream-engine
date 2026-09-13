<?php

declare(strict_types=1);

namespace StreamEngine;

use Composer\Script\Event;

class ComposerScripts
{
    public static function checkSystemDeps(Event $event): void
    {
        $io = $event->getIO();

        if (!self::isDocker()) {
            $io->write('<comment>Skipping system deps check (not in Docker)</comment>');
            return;
        }

        if (! self::swetestExists()) {
            $io->writeError('<error>swetest not installed.</error>');
            $io->writeError('Install it with: <info>sudo apt install swetest</info>');
            exit(1);
        }

        $io->write('<info>swetest: OK</info>');

        if (! self::pandocExists()) {
            $io->writeError('<error>pandoc not installed.</error>');
            $io->writeError('Install it with: <info>sudo apt install pandoc</info>');
            exit(1);
        }

        $io->write('<info>pandoc: OK</info>');
    }

    private static function swetestExists(): bool
    {
        return self::commandExists('swetest');
    }

    private static function pandocExists(): bool
    {
        return self::commandExists('pandoc');
    }

    private static function commandExists(string $command): bool
    {
        $result = shell_exec('command -v '.escapeshellarg($command).' 2>/dev/null');

        return ! empty($result);
    }

    private static function isDocker(): bool
    {
        return file_exists('/.dockerenv');
    }
}

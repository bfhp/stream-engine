<?php

declare(strict_types=1);

namespace StreamEngine\Core\Cron;

class CronRegistry
{
    private array $tasks = [];

    public function add(
        string $task,
        string $controller,
        int $interval
    ): void {
        $this->tasks[$task] = [
            'task' => $task,
            'controller' => $controller,
            'interval' => $interval
        ];
    }

    public function all(): array
    {
        return $this->tasks;
    }
}

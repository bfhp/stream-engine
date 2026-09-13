<?php

declare(strict_types=1);

namespace StreamEngine\Domain;

class Upload
{
    public function __construct(
        public int $id,
        public ?int $userId,
        public string $path,
        public string $mime,
        public int $size,
        public string $originalName,
        public int $createdAt
    ) {
    }
}

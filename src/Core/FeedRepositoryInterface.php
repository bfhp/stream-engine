<?php

declare(strict_types=1);

namespace StreamEngine\Core;

interface FeedRepositoryInterface
{
    public function hydrateTree(array $ids): array;
}

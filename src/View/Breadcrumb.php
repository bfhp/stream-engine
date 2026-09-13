<?php

declare(strict_types=1);

namespace StreamEngine\View;

final class Breadcrumb
{
    public string $url;
    public function __construct(
        public string $title,
        public string $slug,
    ) {

    }
}

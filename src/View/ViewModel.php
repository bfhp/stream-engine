<?php

declare(strict_types=1);

namespace StreamEngine\View;

use StreamEngine\Domain\Page;

final class ViewModel
{
    public function __construct(
        public string $template,
        public array $data
    ) {
    }

    public static function fromPage(Page $page, string $template, array $data = []): self
    {
        return new self($template, array_replace([
            'title' => $page->pageName,
            'shareButtons' => $page->settings->shareButtons ?? false,
        ], $data));
    }
}

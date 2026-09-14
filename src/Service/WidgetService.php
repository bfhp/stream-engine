<?php

declare(strict_types=1);

namespace StreamEngine\Service;

final readonly class WidgetService
{
    /**
     * @var list<string>
     */
    public const array PLACEMENTS = [
        'after_header',
        'before_content',
        'after_content',
        'sidebar_top',
        'sidebar_bottom',
        'footer_legal',
        'footer_contacts',
    ];

    public function __construct(
        private SettingsService $settings,
    ) {
    }

    /**
     * @return array{after_header: string, before_content: string, after_content: string, sidebar_top: string, sidebar_bottom: string, footer_legal: string, footer_contacts: string}
     */
    public function placements(): array
    {
        $placements = [];

        foreach (self::PLACEMENTS as $placement) {
            $placements[$placement] = trim($this->settings->getString('widgets.'.$placement));
        }

        /** @var array{after_header: string, before_content: string, after_content: string, sidebar_top: string, sidebar_bottom: string, footer_legal: string, footer_contacts: string} $placements */
        return $placements;
    }
}

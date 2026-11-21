<?php

declare(strict_types=1);

namespace StreamEngine\Service;

final readonly class WidgetService
{
    /**
     * @var list<string>
     */
    public const array PLACEMENTS = [
        'head',
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
     * Widget HTML by placement; placements in $hidden come back empty, so the
     * themes render nothing for them.
     *
     * @param list<string> $hidden
     * @return array{head: string, after_header: string, before_content: string, after_content: string, sidebar_top: string, sidebar_bottom: string, footer_legal: string, footer_contacts: string}
     */
    public function placements(array $hidden = []): array
    {
        $placements = [];

        foreach (self::PLACEMENTS as $placement) {
            $placements[$placement] = in_array($placement, $hidden, true)
                ? ''
                : trim($this->settings->getString('widgets.'.$placement));
        }

        /** @var array{head: string, after_header: string, before_content: string, after_content: string, sidebar_top: string, sidebar_bottom: string, footer_legal: string, footer_contacts: string} $placements */
        return $placements;
    }
}

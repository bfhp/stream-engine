<?php

declare(strict_types=1);

namespace StreamEngine\View;

use StreamEngine\Core\Assets;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig helpers for built browser assets (Core\Assets):
 *
 * - `asset_import_map(admin = false)`: the import map for the page locale's
 *   translations; first in `<head>`, before any module script or preload;
 * - `asset_tags(entries)`: stylesheets, module preloads and scripts of Vite
 *   entries, e.g. `asset_tags(['site', 'forums'])`;
 * - `asset_styles(entries)`: their stylesheets only.
 */
final class AssetsExtension extends AbstractExtension
{
    public function __construct(
        private readonly Assets $assets,
        private readonly string $locale,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'asset_import_map',
                fn (bool $admin = false): string => $this->assets->importMap($this->locale, $admin),
                ['is_safe' => ['html']],
            ),
            new TwigFunction(
                'asset_tags',
                fn (array $entries): string => $this->assets->tags(array_values($entries)),
                ['is_safe' => ['html']],
            ),
            new TwigFunction(
                'asset_styles',
                fn (array $entries): string => $this->assets->styles(array_values($entries)),
                ['is_safe' => ['html']],
            ),
        ];
    }
}

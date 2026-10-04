<?php

declare(strict_types=1);

namespace Tests\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ColorModeTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $this->twig = new Environment(new FilesystemLoader(__DIR__.'/../../views/themes/default'));
    }

    public function testSystemModeInstallsAnOperatingSystemPreferenceListener(): void
    {
        $html = $this->twig->render('partials/color-mode.twig', [
            'theme' => [
                'themeColor' => '#123456',
                'values' => ['color_mode' => 'system'],
            ],
        ]);

        self::assertStringContainsString("matchMedia('(prefers-color-scheme: dark)')", $html);
        self::assertStringContainsString("media.addEventListener?.('change', apply)", $html);
        self::assertStringContainsString("root.dataset.colorMode = mode", $html);
        self::assertStringContainsString("root.dataset.bsTheme = mode", $html);
        self::assertStringContainsString("'#123456'", $html);
    }

    public function testFixedModesDoNotInstallTheSystemListener(): void
    {
        foreach (['light', 'dark'] as $mode) {
            $html = $this->twig->render('partials/color-mode.twig', [
                'theme' => ['values' => ['color_mode' => $mode]],
            ]);

            self::assertSame('', trim($html));
        }
    }
}

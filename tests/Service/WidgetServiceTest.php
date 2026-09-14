<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\SettingsRepository;
use StreamEngine\Service\SettingsService;
use StreamEngine\Service\WidgetService;

final class WidgetServiceTest extends TestCase
{
    /**
     * @param array<string, string> $settings
     */
    private function makeService(array $settings): WidgetService
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturn(array_map(
            static fn (string $key, string $value): array => [
                'setting_key' => $key,
                'setting_value' => $value,
                'updated_at' => 1_800_000_000,
            ],
            array_keys($settings),
            $settings,
        ));

        return new WidgetService(new SettingsService(new SettingsRepository($db)));
    }

    public function testWidgetsExposeKnownPlacements(): void
    {
        $widgets = $this->makeService([
            'widgets.after_header' => " \n<div>top</div>\n",
            'widgets.before_content' => '<div>before</div>',
            'widgets.after_content' => '<div>after</div>',
            'widgets.sidebar_top' => '<div>side top</div>',
            'widgets.sidebar_bottom' => '<div>side bottom</div>',
            'widgets.footer_legal' => '<div>legal</div>',
            'widgets.footer_contacts' => '<div>contacts</div>',
            'widgets.unknown' => '<div>ignored</div>',
        ])->placements();

        $this->assertSame('<div>top</div>', $widgets['after_header']);
        $this->assertSame('<div>before</div>', $widgets['before_content']);
        $this->assertSame('<div>after</div>', $widgets['after_content']);
        $this->assertSame('<div>side top</div>', $widgets['sidebar_top']);
        $this->assertSame('<div>side bottom</div>', $widgets['sidebar_bottom']);
        $this->assertSame('<div>legal</div>', $widgets['footer_legal']);
        $this->assertSame('<div>contacts</div>', $widgets['footer_contacts']);
        $this->assertArrayNotHasKey('unknown', $widgets);
    }

    public function testMissingWidgetsAreEmptyStrings(): void
    {
        $widgets = $this->makeService([])->placements();

        $this->assertSame('', $widgets['after_header']);
        $this->assertSame('', $widgets['before_content']);
        $this->assertSame('', $widgets['after_content']);
        $this->assertSame('', $widgets['sidebar_top']);
        $this->assertSame('', $widgets['sidebar_bottom']);
        $this->assertSame('', $widgets['footer_legal']);
        $this->assertSame('', $widgets['footer_contacts']);
    }
}

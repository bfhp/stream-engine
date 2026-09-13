<?php

declare(strict_types=1);

namespace Tests\View;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Domain\Page;
use StreamEngine\View\ViewModel;

final class ViewModelTest extends TestCase
{
    #[DataProvider('pageSettings')]
    public function testFromPageProvidesDefaults(?object $settings, bool $expected): void
    {
        $page = Page::api(1, 0, 'example', ['GET'], settings: $settings);
        $page->pageName = 'Page title';

        $view = ViewModel::fromPage($page, 'example.twig');

        $this->assertSame('example.twig', $view->template);
        $this->assertSame(['title' => 'Page title', 'shareButtons' => $expected], $view->data);
    }

    public static function pageSettings(): iterable
    {
        yield 'no settings' => [null, false];
        yield 'missing flag' => [(object) [], false];
        yield 'enabled' => [(object) ['shareButtons' => true], true];
        yield 'disabled' => [(object) ['shareButtons' => false], false];
    }

    public function testExplicitDataOverridesDefaultsIncludingNullAndFalse(): void
    {
        $page = Page::api(1, 0, 'example', ['GET'], settings: (object) ['shareButtons' => true]);
        $page->pageName = 'Page title';
        $data = ['title' => null, 'shareButtons' => false, 'description' => 'Description'];

        $view = ViewModel::fromPage($page, 'example.twig', $data);

        $this->assertSame($data, $view->data);
        $this->assertSame('Page title', $page->pageName);
        $this->assertTrue($page->settings->shareButtons);
    }

    public function testConstructorStillSupportsViewsWithoutPage(): void
    {
        $view = new ViewModel('error.twig', ['title' => 'Error']);

        $this->assertSame('error.twig', $view->template);
        $this->assertSame(['title' => 'Error'], $view->data);
    }
}

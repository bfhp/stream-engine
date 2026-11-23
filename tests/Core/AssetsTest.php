<?php

declare(strict_types=1);

namespace Tests\Core;

use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StreamEngine\Core\Assets;

final class AssetsTest extends TestCase
{
    private static function assets(): Assets
    {
        return new Assets(dirname(__DIR__).'/Support/assets-manifest.php');
    }

    public function testTagsListStylesheetsThenPreloadsThenScriptsEachOnce(): void
    {
        $html = self::assets()->tags(['base', 'site', 'users']);

        self::assertSame(
            '<link rel="stylesheet" href="/assets/css/base-B1a2c3d4.css">'."\n"
            .'<link rel="stylesheet" href="/assets/css/site-A6f7b8c9.css">'."\n"
            .'<link rel="stylesheet" href="/assets/css/custom-content-B7a8c9d0.css">'."\n"
            .'<link rel="modulepreload" href="/assets/js/chunks/i18n-C8b9d0e1.js">'."\n"
            .'<link rel="modulepreload" href="/assets/js/chunks/uploads-F1e2a3b4.js">'."\n"
            .'<script type="module" src="/assets/js/site-F5e6a7b8.js"></script>'."\n"
            .'<script type="module" src="/assets/js/users-E0d1f2a3.js"></script>'."\n",
            $html,
        );
    }

    public function testCssOnlyEntriesAddNoScript(): void
    {
        self::assertStringNotContainsString('<script', self::assets()->tags(['base', 'bootstrap']));
    }

    public function testStylesLeaveScriptsAndPreloadsOut(): void
    {
        $html = self::assets()->styles(['base', 'site']);

        self::assertStringContainsString('/assets/css/site-A6f7b8c9.css', $html);
        self::assertStringNotContainsString('modulepreload', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testImportMapPointsTheCatalogsAtTheLocaleFiles(): void
    {
        self::assertSame(
            '<script type="importmap">{"imports":{"stream-engine-translations":"/assets/js/i18n/ru-Cc33Dd44.js"}}</script>'."\n",
            self::assets()->importMap('ru'),
        );
        self::assertStringContainsString(
            '"stream-engine-translations/admin":"/assets/js/i18n/ru-admin-Ii99Jj00.js"',
            self::assets()->importMap('ru', true),
        );
    }

    public function testTranslationFilesFallBackToTheBaseLocaleThenEnglish(): void
    {
        $assets = self::assets();

        self::assertSame('js/i18n/pt-br-Ee55Ff66.js', $assets->translationFile('site', 'pt_BR'));
        self::assertSame('js/i18n/ru-Cc33Dd44.js', $assets->translationFile('site', 'ru-RU'));
        self::assertSame('js/i18n/en-Aa11Bb22.js', $assets->translationFile('site', 'de'));
        self::assertSame('js/i18n/en-admin-Gg77Hh88.js', $assets->translationFile('admin', 'pt-br'));
    }

    public function testAnUnknownEntryIsAnError(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Unknown asset entry 'nope'");

        self::assets()->tags(['site', 'nope']);
    }

    public function testAMissingBuildSaysSo(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('npm run build');

        (new Assets(sys_get_temp_dir().'/no-such-build/manifest.php'))->tags(['site']);
    }
}

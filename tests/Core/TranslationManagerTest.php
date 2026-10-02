<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use StreamEngine\Core\TranslationManager;

final class TranslationManagerTest extends TestCase
{
    public function testDirectionFollowsTheLocaleScript(): void
    {
        $this->assertSame('rtl', (new TranslationManager('ar', 'en'))->direction());
        $this->assertTrue(TranslationManager::isRtlLocale('fa-IR'));
        $this->assertTrue(TranslationManager::isRtlLocale('HE'));
        $this->assertSame('ltr', (new TranslationManager('ru', 'en'))->direction());
        $this->assertFalse(TranslationManager::isRtlLocale('pt-br'));
        $this->assertFalse(TranslationManager::isRtlLocale(''));
    }

    public function testReturnsLoadedTranslationForExistingKey(): void
    {
        $tm = new TranslationManager('ru', 'ru');

        $this->assertSame('только что', $tm->trans('time.just_now'));
    }

    public function testFallsBackToKeyWhenTranslationIsMissing(): void
    {
        $tm = new TranslationManager('ru', 'ru');

        $this->assertSame('missing.key', $tm->trans('missing.key'));
    }

    public function testReplacesTemplateParameters(): void
    {
        $tm = new TranslationManager('ru', 'ru');

        $this->assertSame(
            'Длина пароля должна быть минимум 10 символов',
            $tm->trans('user.password_too_short', ['min' => 10])
        );
    }

    public function testLoadsFallbackLocaleWhenPrimaryLocaleIsMissing(): void
    {
        $tm = new TranslationManager('zz', 'ru');

        $this->assertSame('только что', $tm->trans('time.just_now'));
        $this->assertSame('zz', $tm->getLocale());
    }

    public function testGetAllReturnsLoadedMessages(): void
    {
        $tm = new TranslationManager('ru', 'ru');
        $all = $tm->getAll();

        $this->assertArrayHasKey('time.just_now', $all);
        $this->assertArrayHasKey('feed.not_found', $all);
    }

    public function testGetForJsFiltersByPrefix(): void
    {
        $tm = new TranslationManager('ru', 'ru');

        $forBrowser = $tm->getForJS();
        $this->assertNotEmpty($forBrowser);
        $this->assertSame('Показать ещё', $forBrowser['js.common.load_more']);
        foreach (array_keys($forBrowser) as $key) {
            $this->assertStringStartsWith('js.', $key);
        }

        $forJs = $tm->getForJS('feed.');

        // Every returned key must carry the requested prefix, and nothing
        // outside it (e.g. 'time.*', 'user.*') should leak in.
        foreach (array_keys($forJs) as $key) {
            $this->assertStringStartsWith('feed.', $key);
        }
        $this->assertArrayNotHasKey('time.just_now', $forJs);

        // Spot-check a couple of known values are passed through unchanged
        // rather than pinning the entire feed.* set, which would make this
        // test fail every time a new feed.* translation key is added.
        $this->assertSame('Feed not found', $forJs['feed.not_found']);
        $this->assertSame('Оценка должна быть от 1 до 5', $forJs['feed.rating_invalid']);

        // The filter should be exhaustive: every feed.* key in the full
        // catalog must show up here too.
        $allFeedKeys = array_filter(
            array_keys($tm->getAll()),
            static fn (string $key): bool => str_starts_with($key, 'feed.')
        );
        $this->assertCount(count($allFeedKeys), $forJs);
    }

    public function testEverySupportedLocaleContainsTheCompleteAdminCatalog(): void
    {
        $english = new TranslationManager('en', 'en');
        $adminKeys = array_values(array_filter(
            array_keys($english->getAll()),
            static fn (string $key): bool => str_starts_with($key, 'js.admin.')
                || str_starts_with($key, 'admin.error.')
                || str_starts_with($key, 'admin.page_action.')
        ));

        $this->assertSame(['ar', 'de', 'en', 'es', 'fr', 'it', 'pt-br', 'ru'], TranslationManager::availableLocales());
        foreach (TranslationManager::availableLocales() as $locale) {
            $messages = (new TranslationManager($locale, $locale))->getAll();
            foreach ($adminKeys as $key) {
                $this->assertArrayHasKey($key, $messages, "Missing $key in $locale");
            }
        }

        $this->assertSame('Dashboard', $english->trans('js.admin.dashboard.title'));
        $this->assertSame(
            'Панель управления',
            (new TranslationManager('ru', 'ru'))->trans('js.admin.dashboard.title')
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\TranslationManager;

/**
 * CLDR plural selection on the server, and the catalogs it reads.
 *
 * `tests/Support/plural-rules.json` is the contract between the two runtimes:
 * this test asserts PHP intl against it, `tests/js/plural.test.ts` asserts the
 * browser's `Intl.PluralRules` against the same file. A count is rendered here
 * on the first page load and in the browser on every update after it, so if
 * an ICU upgrade on either side moves a rule, one of the two suites goes red
 * instead of a number silently changing its grammar on refresh.
 */
final class PluralRulesTest extends TestCase
{
    /** @return array<string, array{categories: list<string>, samples: array<string, string>}> */
    private static function rules(): array
    {
        $rules = json_decode(
            (string) file_get_contents(dirname(__DIR__).'/Support/plural-rules.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return array_filter($rules, static fn (string $key): bool => !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }

    private static function formatter(string $locale): Formatter
    {
        return new Formatter(new TranslationManager($locale, 'en'), $locale);
    }

    /** @return array<string, array{string}> */
    public static function localeProvider(): array
    {
        $provided = [];
        foreach (TranslationManager::availableLocales() as $locale) {
            $provided[$locale] = [$locale];
        }

        return $provided;
    }

    public function testFixtureCoversEveryShippedLocale(): void
    {
        $this->assertSame(
            TranslationManager::availableLocales(),
            array_keys(self::rules()),
        );
    }

    #[DataProvider('localeProvider')]
    public function testCategoriesMatchTheSharedFixture(string $locale): void
    {
        $formatter = self::formatter($locale);

        foreach (self::rules()[$locale]['samples'] as $n => $expected) {
            $number = str_contains((string) $n, '.') ? (float) $n : (int) $n;
            $this->assertSame($expected, $formatter->pluralCategory($number), "$locale, n = $n");
        }
    }

    /**
     * Every plural value in every catalog is keyed by CLDR category, has a
     * form for each category the locale uses, and has nothing else.
     */
    #[DataProvider('localeProvider')]
    public function testCatalogPluralsCoverTheLocaleCategories(string $locale): void
    {
        $expected = self::rules()[$locale]['categories'];
        $messages = require dirname(__DIR__, 2)."/src/Lang/$locale.php";
        $plurals = array_filter($messages, 'is_array');

        $this->assertNotEmpty($plurals);
        foreach ($plurals as $key => $forms) {
            $this->assertFalse(array_is_list($forms), "$key in $locale is positional; key it by CLDR category");
            $this->assertSame($expected, array_keys($forms), "$key in $locale");
            foreach ($forms as $form) {
                $this->assertIsString($form);
                $this->assertNotSame('', trim($form), "$key in $locale has an empty form");
            }
        }
    }

    public function testEnglishNoLongerUsesTheSingularForTwentyOne(): void
    {
        $english = self::formatter('en');

        $this->assertSame('post', $english->transChoice('forums.post_unit', 1));
        $this->assertSame('posts', $english->transChoice('forums.post_unit', 21));
        $this->assertSame('posts', $english->transChoice('forums.post_unit', 101));
        $this->assertSame('posts', $english->transChoice('forums.post_unit', 0));
    }

    public function testArabicUsesAllSixCategories(): void
    {
        $arabic = self::formatter('ar');
        $forms = ['zero' => 'z', 'one' => 'o', 'two' => 't', 'few' => 'f', 'many' => 'm', 'other' => 'x'];

        $this->assertSame('z', $arabic->selectPluralForm($forms, 0));
        $this->assertSame('o', $arabic->selectPluralForm($forms, 1));
        $this->assertSame('t', $arabic->selectPluralForm($forms, 2));
        $this->assertSame('f', $arabic->selectPluralForm($forms, 3));
        $this->assertSame('m', $arabic->selectPluralForm($forms, 11));
        $this->assertSame('x', $arabic->selectPluralForm($forms, 100));
        $this->assertSame('f', $arabic->selectPluralForm($forms, 103));
    }

    public function testFractionsUseTheOtherCategory(): void
    {
        $russian = self::formatter('ru');

        $this->assertSame('other', $russian->pluralCategory(1.5));
        $this->assertSame('часа', $russian->transChoice('time.hour', 1.5));
        $this->assertSame('few', $russian->pluralCategory(2.0));
    }

    public function testMissingCategoryFallsBackToOther(): void
    {
        $russian = self::formatter('ru');

        $this->assertSame('x', $russian->selectPluralForm(['one' => 'o', 'other' => 'x'], 5));
        $this->assertNull($russian->selectPluralForm(['one' => 'o'], 5));
        $this->assertNull($russian->selectPluralForm([], 5));
    }

    public function testNonInflectingUnitsAreReturnedAsIs(): void
    {
        // ru.php keeps file-size units as abbreviations: 'js.common.byte' => 'Б'.
        $this->assertSame('КБ', self::formatter('ru')->transChoice('js.common.kilobyte', 5));
        $this->assertSame('missing.key', self::formatter('ru')->transChoice('missing.key', 5));
    }

    /**
     * Catalogs written before keyed forms (`[one, few, many]` or
     * `[one, other]`) keep working: each CLDR category maps to a position.
     */
    public function testLegacyPositionalFormsStillWork(): void
    {
        $this->assertSame('b', self::formatter('en')->selectPluralForm(['a', 'b'], 21));
        $this->assertSame('c', self::formatter('en')->selectPluralForm(['a', 'b', 'c'], 21));
        $this->assertSame('a', self::formatter('ru')->selectPluralForm(['a', 'b', 'c'], 21));
        $this->assertSame('b', self::formatter('ru')->selectPluralForm(['a', 'b', 'c'], 1.5));
        $this->assertSame('a', self::formatter('ar')->selectPluralForm(['a', 'b', 'c'], 100));
        $this->assertSame('a', self::formatter('ja')->selectPluralForm(['a'], 7));
    }

    /**
     * The table `legacyPluralIndex()` in `assets-src/shared/plural.ts`
     * asserts too.
     */
    public function testLegacyIndexTable(): void
    {
        $cases = [
            ['en', 'one', 3, 0], ['en', 'other', 3, 2], ['en', 'other', 2, 1],
            ['ru', 'few', 3, 1], ['ru', 'many', 3, 2], ['ru', 'other', 3, 1],
            ['pl', 'other', 3, 1], ['fr', 'many', 3, 2], ['fr', 'many', 2, 1],
            ['ar', 'zero', 3, 1], ['ar', 'two', 3, 1], ['ar', 'few', 3, 1],
            ['ar', 'many', 3, 2], ['ar', 'other', 3, 0], ['ar', 'many', 2, 1],
            ['pt-br', 'other', 3, 2], ['ja', 'other', 1, 0],
        ];

        foreach ($cases as [$locale, $category, $length, $index]) {
            $this->assertSame(
                $index,
                Formatter::legacyPluralIndex($locale, $category, $length),
                "$locale $category of $length",
            );
        }
    }
}

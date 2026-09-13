<?php

declare(strict_types=1);

namespace Tests\Core;

use DateTimeImmutable;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\TranslationManager;

final class FormatterTest extends TestCase
{
    private Formatter $formatter;
    private TranslationManager $tm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tm = new TranslationManager('ru', 'en');
        $this->formatter = new Formatter(
            $this->tm,
            'ru'
        );
    }

    public function testDateFormatsRussianDate(): void
    {
        $date = new DateTimeImmutable('2026-04-23 14:30:00 UTC');
        $formatted = $this->formatter->date($date);
        $formattedWithYearWord = $this->formatter->date($date, true);

        $this->assertMatchesRegularExpression('/23 .* 2026/u', $formatted);
        $this->assertStringStartsWith($formatted, $formattedWithYearWord);
        $this->assertGreaterThan(strlen($formatted), strlen($formattedWithYearWord));
    }

    public function testDatetimeContainsDateAndTime(): void
    {
        $date = new DateTimeImmutable('2026-04-23 14:30:00 UTC');
        $formatted = $this->formatter->datetime($date);

        $this->assertMatchesRegularExpression('/23 .* 2026/u', $formatted);
        $this->assertMatchesRegularExpression('/14:30/', $formatted);
    }

    public function testRelativeFormatsRecentIntervals(): void
    {
        $this->assertSame(
            $this->tm->trans('time.just_now'),
            $this->formatter->relative(new DateTimeImmutable('-30 seconds'))
        );

        $this->assertSame(
            $this->tm->trans('time.minute_ago'),
            $this->formatter->relative(new DateTimeImmutable('-90 seconds'))
        );

        $this->assertSame(
            $this->tm->trans('time.minutes_ago', ['count' => 5, 'unit' => $this->tm->getAll()['time.minute'][2]]),
            $this->formatter->relative(new DateTimeImmutable('-5 minutes'))
        );

        $this->assertSame(
            $this->tm->trans('time.hour_ago'),
            $this->formatter->relative(new DateTimeImmutable('-90 minutes'))
        );

        $this->assertSame(
            $this->tm->trans('time.hours_ago', ['count' => 3, 'unit' => $this->tm->getAll()['time.hour'][1]]),
            $this->formatter->relative(new DateTimeImmutable('-3 hours'))
        );

        $this->assertSame(
            $this->tm->trans('time.days_ago', ['count' => 2, 'unit' => $this->tm->getAll()['time.day'][1]]),
            $this->formatter->relative(new DateTimeImmutable('-2 days'))
        );
    }

    public function testRelativeFallsBackToLongDateForOldDates(): void
    {
        $date = new DateTimeImmutable('-40 days');

        $this->assertSame(
            $this->formatter->date($date, true),
            $this->formatter->relative($date)
        );
    }

    public function testPluralChoosesCorrectRussianForm(): void
    {
        [$one, $few, $many] = $this->tm->getAll()['time.minute'];

        $this->assertSame($one, $this->formatter->plural(1, $one, $few, $many));
        $this->assertSame($few, $this->formatter->plural(2, $one, $few, $many));
        $this->assertSame($many, $this->formatter->plural(5, $one, $few, $many));
        $this->assertSame($many, $this->formatter->plural(11, $one, $few, $many));
        $this->assertSame($one, $this->formatter->plural(21, $one, $few, $many));
    }

    /**
     * The same table `tests/js/plural.test.ts` asserts against
     * `assets-src/shared/plural.ts`.
     *
     * The two have to agree: a count is rendered here on the first page load
     * and by the front end on every update after it, so a divergence shows up
     * as a number that changes its grammar when the page refreshes rather than
     * as anything that fails. The front end had four implementations of this
     * rule until they were collapsed into one; three of them skipped `abs()`
     * and disagreed with this method on negatives.
     *
     * @param 'one'|'few'|'many' $expected
     */
    #[DataProvider('pluralFormProvider')]
    public function testPluralAgreesWithTheFrontEndTable(int $n, string $expected): void
    {
        $this->assertSame(
            $expected,
            $this->formatter->plural($n, 'one', 'few', 'many'),
            'n = '.$n
        );
    }

    /** @return array<string, array{int, string}> */
    public static function pluralFormProvider(): array
    {
        $cases = [
            0 => 'many', 1 => 'one', 2 => 'few', 4 => 'few', 5 => 'many',
            10 => 'many',
            // The teens: many despite ending in 1-4, and the whole reason the
            // rule needs writing down.
            11 => 'many', 12 => 'many', 14 => 'many', 15 => 'many',
            20 => 'many', 21 => 'one', 22 => 'few', 25 => 'many',
            100 => 'many', 101 => 'one', 102 => 'few', 111 => 'many', 114 => 'many',
            1000 => 'many', 1001 => 'one', 1002 => 'few',
            // abs() first, so a negative reads like its magnitude. This is the
            // half the JavaScript copies used to get wrong.
            -1 => 'one', -2 => 'few', -5 => 'many', -11 => 'many', -21 => 'one',
        ];

        $provided = [];
        foreach ($cases as $n => $expected) {
            $provided[(string) $n] = [$n, $expected];
        }

        return $provided;
    }

    public function testFormatReplacesTemplateParameters(): void
    {
        $this->assertSame(
            'Hello, World!',
            $this->formatter->format('Hello, {name}!', ['name' => 'World'])
        );
    }

    public function testFormatReplacesMultipleTemplateParameters(): void
    {
        $this->assertSame(
            'Alice sent 3 messages to Bob',
            $this->formatter->format(
                '{from} sent {count} messages to {to}',
                ['from' => 'Alice', 'count' => 3, 'to' => 'Bob']
            )
        );
    }

    public function testFormatLeavesUnknownPlaceholderUntouched(): void
    {
        $this->assertSame(
            'Hello, {name}!',
            $this->formatter->format('Hello, {name}!')
        );
    }

    public function testSlugifyTransliteratesCyrillicToBareAlphanumericByDefault(): void
    {
        $this->assertSame('ivanpetrov', Formatter::slugify('Иван Петров'));
    }

    public function testSlugifyKeepsAlreadyLatinInputAlphanumericByDefault(): void
    {
        $this->assertSame('johndoe99', Formatter::slugify('John_Doe 99'));
    }

    public function testSlugifyReturnsEmptyStringWhenNothingIsAlphanumeric(): void
    {
        $this->assertSame('', Formatter::slugify('★彡 ~*~'));
    }

    public function testSlugifyHyphenatesWordsWhenSeparatorIsGiven(): void
    {
        $this->assertSame('ivan-petrov', Formatter::slugify('Иван Петров', '-'));
    }

    public function testSlugifyTrimsLeadingAndTrailingSeparator(): void
    {
        $this->assertSame('my-post-title', Formatter::slugify('  My Post Title!!  ', '-'));
    }

    public function testSlugifyCollapsesConsecutiveDisallowedCharactersIntoOneSeparator(): void
    {
        $this->assertSame('a-b', Formatter::slugify('a --- b', '-'));
    }

    /**
     * The transliteration table. The two implementations that used to exist
     * differed on `й` and `ц` only; the table that survived takes `й` from the
     * library (`j`) and `ц` from the older Formatter (`ts`), and `х` was
     * changed from `h` to `kh` at the same time. Those last two are the
     * conventional English renderings - `Цой` reads as `tsoj` rather than
     * `coj`, `Хохлов` as `khokhlov` rather than `hohlov` - which is the point,
     * since these end up in URLs people read and type.
     *
     * Note what fixing the order uncovered: `'й' => 'j'` had never once
     * fired, because both callers folded diacritics *before* transliterating
     * and NFD decomposes `й` into `и` plus a breve. So `Михайлович` came out
     * `mihailovich` while the table claimed `mihajlovich`, and nothing
     * reconciled them. (Both of those are the old spelling of `х` too - the
     * same name is `mikhajlovich` now.)
     *
     * Slugs written before these changes keep whatever they were given - they
     * live in a column, and nothing recomputes one to build a URL - so a name
     * with `й`, `ц` or `х` slugged from today on will not match the shape of
     * one slugged last year. Accepted deliberately: the alternative was
     * keeping a table with dead entries and an unconventional `х`.
     */
    #[DataProvider('translitProvider')]
    public function testCyrillicIsTransliteratedConsistently(string $input, string $expected): void
    {
        $this->assertSame($expected, Formatter::slugify($input, '-'));
    }

    /** @return array<string, array{string, string}> */
    public static function translitProvider(): array
    {
        return [
            'й is j' => ['Йога', 'joga'],
            'ц is ts' => ['Цой', 'tsoj'],
            'х is kh' => ['Хохлов', 'khokhlov'],
            'both ц and я, mid-word' => ['Формация цивилизации', 'formatsiya-tsivilizatsii'],
            'щ is sch' => ['Щедрин', 'schedrin'],
            'ж is zh' => ['Жуков', 'zhukov'],
            'ю and я are two letters each' => ['Юля', 'yulya'],
            'ё is е' => ['Ёлка', 'elka'],
            // The soft and hard signs map to nothing at all, so no hyphen
            // appears where the word had none.
            'ъ and ь disappear' => ['Объезд большой', 'obezd-bolshoj'],
            // The one that proves the order: `й` here survived to reach the
            // table. Folding first turned it into `и`, and this said
            // `mikhailovich`.
            'a full name' => ['Кандыба Виктор Михайлович', 'kandyba-viktor-mikhajlovich'],
        ];
    }

    /**
     * Accented latin folds instead of vanishing. Before the fold was wired in,
     * the `[^a-z0-9]` pass deleted `ü` outright and `Müller` came out
     * `m-ller` - a real problem for a library of translated books.
     */
    #[DataProvider('diacriticProvider')]
    public function testAccentedLatinFoldsRatherThanDisappearing(string $input, string $expected): void
    {
        $this->assertSame($expected, Formatter::slugify($input, '-'));
    }

    /** @return array<string, array{string, string}> */
    public static function diacriticProvider(): array
    {
        return [
            'decomposable' => ['Müller', 'muller'],
            'two of them' => ['Kübler-Ross', 'kubler-ross'],
            'acute' => ['Café', 'cafe'],
            // No combining form, so only the table can reach these.
            'slashed o' => ['Søren', 'soren'],
            'crossed l' => ['Łódź', 'lodz'],
            'dotless i' => ['Işık', 'isik'],
        ];
    }

    /* ---------------------------------------------------------------------
       unicodeSlug() - the term variant, which keeps its letters
    --------------------------------------------------------------------- */

    public function testUnicodeSlugKeepsCyrillic(): void
    {
        $this->assertSame('фантастика', Formatter::unicodeSlug('Фантастика'));
    }

    public function testUnicodeSlugStillCollapsesPunctuation(): void
    {
        $this->assertSame('sci-fi-fantasy', Formatter::unicodeSlug('Sci-Fi & Fantasy!'));
    }

    public function testUnicodeSlugKeepsNonAsciiDigits(): void
    {
        // \p{N} is Unicode-wide, so an Arabic-Indic numeral is a number.
        $this->assertSame('том-٣', Formatter::unicodeSlug('Том ٣'));
    }

    /* ---------------------------------------------------------------------
       The shared tail: length and fallback
    --------------------------------------------------------------------- */

    public function testTheLengthCutIsFollowedByAnotherTrim(): void
    {
        // Without the second trim this is `abc-`, a slug with a dangling
        // separator - which is what FeedTermRepository used to produce.
        $this->assertSame('abc', Formatter::slugify('abc def', '-', 4));
    }

    public function testNothingSluggableGivesTheFallback(): void
    {
        // No letters and no digits in either script.
        $this->assertSame('term', Formatter::unicodeSlug('★ ~*~', '-', null, 'term'));
        $this->assertSame('item', Formatter::slugify('★ ~*~', '-', null, 'item'));
    }

    /**
     * Where the two part company: `彡` is `\p{L}`, so unicodeSlug keeps it
     * while slugify - which allows only `[a-z0-9]` - does not. Found by
     * writing the test above with `★彡 ~*~`, borrowed from the slugify case,
     * and getting `彡` back instead of the fallback.
     *
     * Worth pinning rather than just fixing: it means a term named in any
     * script gets a usable slug, which is the whole point of the unicode
     * variant, and it means the fallback is rarer there than for slugify.
     */
    public function testUnicodeSlugKeepsLettersFromAnyScript(): void
    {
        $this->assertSame('彡', Formatter::unicodeSlug('★彡 ~*~', '-', null, 'term'));
        $this->assertSame('日本語', Formatter::unicodeSlug('日本語'));

        // The transliterating one has nothing to map them to, so they go.
        $this->assertSame('item', Formatter::slugify('★彡 ~*~', '-', null, 'item'));
    }

    public function testWithNoFallbackAnUnsluggableValueIsEmpty(): void
    {
        // The default, kept for the username case, where the caller decides.
        $this->assertSame('', Formatter::slugify('★彡 ~*~', '-'));
    }

    /* ===============================
       decodeLegacyText
    =============================== */

    /**
     * The legacy CMS stored free text entity-encoded, inconsistently: forum and
     * blog titles single-encoded, quiz questions sometimes double. Twig escapes
     * again on output, so an undecoded value renders as a literal `&quot;`.
     *
     * Every caller is a WP-import `cleanOrNull()` over a `title`/`header`/
     * question column, and every one of those columns is rendered escaped.
     * That is the safety argument for decoding at all, and it is worth stating
     * because the function is a *de*-escaper: applied to a value later printed
     * with `|raw`, it turns stored `&lt;script&gt;` back into live markup.
     */
    public function testASingleEncodedTitleIsDecodedOnce(): void
    {
        $this->assertSame(
            'Что есть...слово "Зикр"...',
            Formatter::decodeLegacyText('Что есть...слово &quot;Зикр&quot;...')
        );
    }

    /**
     * The reason for the loop. Here the `&` of `&quot;` was itself escaped, so
     * one html_entity_decode() call leaves `&quot;` behind - which is exactly
     * what shows up on the page.
     */
    public function testADoubleEncodedValueIsDecodedTwice(): void
    {
        $this->assertSame(
            '"Творчество"',
            Formatter::decodeLegacyText('&amp;quot;Творчество&amp;quot;')
        );
    }

    public function testPlainTextIsReturnedUnchanged(): void
    {
        $value = 'Обычный заголовок без сущностей';

        $this->assertSame($value, Formatter::decodeLegacyText($value));
    }

    /**
     * A bare ampersand is not an entity and must survive - the loop stops when
     * a pass changes nothing, so "AT&T" does not slowly erode.
     */
    public function testABareAmpersandIsLeftAlone(): void
    {
        $this->assertSame('AT&T и Ко', Formatter::decodeLegacyText('AT&T и Ко'));
    }

    /**
     * ENT_QUOTES, so both quote characters decode - `&#39;` in particular,
     * which is what the legacy dump used for apostrophes.
     */
    public function testBothQuoteStylesAndNumericEntitiesDecode(): void
    {
        $this->assertSame(
            '"D\'Artagnan" & Co',
            Formatter::decodeLegacyText('&quot;D&#39;Artagnan&quot; &amp; Co')
        );
    }

    /**
     * The cap. Each pass strips one `amp;`, so a value encoded more times than
     * the budget comes back *still encoded* rather than looping - a visible
     * wrong title, which is the right failure for an import: it is noticed and
     * fixed in the data, and it cannot hang the run.
     */
    public function testDeeplyNestedEncodingStopsAtTheCapStillEncoded(): void
    {
        $value = '&amp;amp;amp;amp;amp;quot;X';

        $this->assertSame('&quot;X', Formatter::decodeLegacyText($value));
    }

    public function testTheCapIsConfigurableAndCountsPasses(): void
    {
        $value = '&amp;quot;Творчество&amp;quot;';

        // One pass strips one layer.
        $this->assertSame('&quot;Творчество&quot;', Formatter::decodeLegacyText($value, 1));
        // Two is what a genuine double-encoded value needs.
        $this->assertSame('"Творчество"', Formatter::decodeLegacyText($value, 2));
    }

    /**
     * Zero passes is a no-op rather than an error - the loop condition is
     * checked first. Worth pinning because it means the function can be
     * disabled at a call site by passing 0, and because an off-by-one that made
     * it decode once anyway would be invisible on single-encoded input.
     */
    public function testAZeroBudgetDecodesNothing(): void
    {
        $this->assertSame(
            '&quot;Зикр&quot;',
            Formatter::decodeLegacyText('&quot;Зикр&quot;', 0)
        );
    }

    public function testAnEmptyStringSurvivesTheLoop(): void
    {
        $this->assertSame('', Formatter::decodeLegacyText(''));
    }

    /**
     * It is a decoder, not a sanitizer: stored `&lt;script&gt;` comes back as
     * live markup. Safe only because every caller feeds a column that Twig
     * escapes on output - asserted here so that "let's reuse this for the
     * content column" runs into a test that says why not.
     */
    public function testItUndoesEscapingRatherThanSanitizing(): void
    {
        $this->assertSame(
            '<script>alert(1)</script>',
            Formatter::decodeLegacyText('&lt;script&gt;alert(1)&lt;/script&gt;')
        );
    }

    /* =====================================================================
       json() - how this application writes JSON to a client

       Lifted here from a Core\Json of its own. Small enough to look
       pointless and worth pinning anyway: the flags are a property of the
       whole API surface, applied at eighty call sites through this one
       function, and two of the three fail *silently* if they are ever
       dropped. A response three times larger than it needs to be looks
       identical to a correct one, and so does a response never written.
    ===================================================================== */

    /* ===============================
       Unicode
    =============================== */

    public function testCyrillicGoesOutAsItself(): void
    {
        // PHP's default escapes every non-ASCII character to a six-byte
        // \uXXXX sequence, so this value would go out as 36 bytes instead of
        // 12 - on a site where nearly every payload is Russian.
        $this->assertSame('{"city":"Москва"}', Formatter::json(['city' => 'Москва']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonAsciiProvider(): array
    {
        return [
            'cyrillic' => ['Москва'],
            'ё' => ['Ёлка'],
            'em dash' => ['текст — ещё текст'],
            'nbsp' => ["10\u{00A0}книг"],
            'emoji, i.e. a surrogate pair' => ['книга 📖'],
            'mixed' => ['Book «Мастер» (2-е изд.)'],
        ];
    }

    #[DataProvider('nonAsciiProvider')]
    public function testNothingIsEscapedAndEverythingSurvivesTheRoundTrip(string $value): void
    {
        $encoded = Formatter::json(['v' => $value]);

        $this->assertStringNotContainsString('\u', $encoded, 'nothing should be escaped');
        // The point of not escaping is size, not meaning - the value has to
        // come back identical either way.
        $this->assertSame($value, json_decode($encoded, true)['v']);
    }

    /* ===============================
       Slashes
    =============================== */

    public function testUrlsAreNotFullOfBackslashes(): void
    {
        // The default writes `https:\/\/example.com\/books\/7`, a habit from
        // embedding JSON inside <script> blocks. These are application/json
        // responses read by fetch().
        $this->assertSame(
            '{"url":"https://example.com/books/7"}',
            Formatter::json(['url' => 'https://example.com/books/7'])
        );
    }

    /* ===============================
       Failure
    =============================== */

    /**
     * The flag that changes behaviour rather than bytes.
     *
     * Without it `json_encode()` answers `false` for malformed UTF-8, and
     * `echo false` prints nothing - so the client gets HTTP 200 with an empty
     * body and nothing anywhere records that a response was lost. With it the
     * same input raises, and Core\ApiErrorResponse turns that into a logged
     * 500.
     */
    public function testMalformedUtf8RaisesRatherThanVanishing(): void
    {
        $this->expectException(JsonException::class);

        // A lone continuation byte: valid as bytes, not valid as UTF-8.
        Formatter::json(['text' => "\xB1\x31"]);
    }

    public function testInfinityRaisesToo(): void
    {
        // INF and NAN have no JSON representation. Reachable from a computed
        // average or a division somewhere upstream.
        $this->expectException(JsonException::class);

        Formatter::json(['score' => INF]);
    }

    public function testTheReturnTypeIsAStringRatherThanStringOrFalse(): void
    {
        // The practical consequence of throwing: callers can stop wondering
        // what `echo` does with `false`.
        $this->assertIsString(Formatter::json(['ok' => true]));
    }

    /* ===============================
       Ordinary values
    =============================== */

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function shapeProvider(): array
    {
        return [
            'empty list' => [[], '[]'],
            'list' => [[1, 2, 3], '[1,2,3]'],
            'map' => [['a' => 1], '{"a":1}'],
            'null' => [null, 'null'],
            'nested' => [['a' => ['b' => 'Москва']], '{"a":{"b":"Москва"}}'],
            // A list with a gap is an object, same as PHP's own rule - worth
            // pinning because a client expecting an array gets `{"1":"b"}`.
            'sparse list' => [[1 => 'b'], '{"1":"b"}'],
        ];
    }

    #[DataProvider('shapeProvider')]
    public function testTheShapeIsUnchangedFromPlainJsonEncode(mixed $value, string $expected): void
    {
        $this->assertSame($expected, Formatter::json($value));
    }

    public function testExtraFlagsAreAddedRatherThanReplacing(): void
    {
        // The three defaults are not negotiable per call; a caller that wants
        // pretty printing gets it *and* keeps them.
        $encoded = Formatter::json(['city' => 'Москва'], JSON_PRETTY_PRINT);

        $this->assertStringContainsString("\n", $encoded);
        $this->assertStringContainsString('Москва', $encoded);
    }
}

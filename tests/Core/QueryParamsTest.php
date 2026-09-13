<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\QueryParams;

/**
 * The query string as a value.
 *
 * What it replaced was `filter_input(INPUT_GET, …)`, which reads the SAPI's
 * own copy of the request rather than `$_GET`. Under the CLI SAPI PHPUnit
 * runs on there is no request, so it answered `null` for everything and no
 * test could make it answer otherwise - which is why three test files had
 * taken to declaring their own `filter_input()` inside the controller's
 * namespace, and why several branches were simply unreachable.
 *
 * The property worth holding onto here is **totality**: every accessor
 * returns its declared type for every possible input, including the two that
 * used to need a guard at each call site - an absent key, and a key holding
 * an array, which `?tag[]=x` makes trivial to send.
 */
final class QueryParamsTest extends TestCase
{
    /* ===============================
       string()
    =============================== */

    public function testAPresentValueComesBackAsWritten(): void
    {
        $query = new QueryParams(['q' => '  булгаков  ']);

        // No trimming, no casing, no filtering: string() is the raw read.
        $this->assertSame('  булгаков  ', $query->string('q'));
    }

    public function testAnAbsentKeyIsTheDefault(): void
    {
        $query = new QueryParams([]);

        $this->assertSame('', $query->string('q'));
        $this->assertSame('all', $query->string('type', 'all'));
    }

    /**
     * `?tag[]=article` is a request any browser can be made to send, and
     * `(string) ['article']` is an "Array to string conversion" warning followed
     * by the literal word `Array` - which then went into an SQL parameter or
     * a page title. Treating it as absent is what filter_input() did and the
     * only reading that cannot hand a caller the wrong type.
     */
    public function testAnArrayIsTreatedAsAbsentRatherThanStringified(): void
    {
        $query = new QueryParams(['tag' => ['article', 'esoteric']]);

        $this->assertSame('', $query->string('tag'));
        $this->assertSame('fallback', $query->string('tag', 'fallback'));
    }

    public function testAnEmptyValueIsAValueRatherThanAnAbsence(): void
    {
        // `?q=` is different from no `q` at all only if you look at has();
        // string() collapses them, which is what every caller wants.
        $query = new QueryParams(['q' => '']);

        $this->assertSame('', $query->string('q'));
        $this->assertSame('', $query->string('q', 'default'));
        $this->assertTrue($query->has('q'));
    }

    public function testHasDistinguishesAnEmptyValueFromAMissingKey(): void
    {
        $query = new QueryParams(['a' => '', 'b' => 'x']);

        $this->assertTrue($query->has('a'));
        $this->assertTrue($query->has('b'));
        $this->assertFalse($query->has('c'));
    }

    /* ===============================
       trimmed()
    =============================== */

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function trimmedProvider(): array
    {
        return [
            'plain' => ['булгаков', 'булгаков'],
            'surrounding spaces' => ['  булгаков  ', 'булгаков'],
            'tabs and newlines' => ["\t булгаков \n", 'булгаков'],
            'whitespace only' => ["  \t ", ''],
            'empty' => ['', ''],
            'an array' => [['булгаков'], ''],
            // Inner whitespace survives - collapsing it here would silently
            // rewrite a phrase query.
            'inner whitespace' => ['мастер   и   маргарита', 'мастер   и   маргарита'],
        ];
    }

    #[DataProvider('trimmedProvider')]
    public function testTrimmedRemovesOnlyTheEdges(mixed $raw, string $expected): void
    {
        $this->assertSame($expected, (new QueryParams(['q' => $raw]))->trimmed('q'));
    }

    public function testTrimmedOnAnAbsentKeyIsTheDefault(): void
    {
        $this->assertSame('', (new QueryParams([]))->trimmed('q'));
        $this->assertSame('all', (new QueryParams([]))->trimmed('q', 'all'));
    }

    /* ===============================
       int()
    =============================== */

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function intProvider(): array
    {
        return [
            'a number' => ['42', 42],
            'negative' => ['-3', -3],
            'surrounding space' => [' 42 ', 42],
            'already an int' => [42, 42],
            // filter_var's FILTER_VALIDATE_INT, so these are all refusals
            // rather than silent casts - `(int) "12abc"` would be 12.
            'trailing junk' => ['12abc', 7],
            'fractional' => ['3.5', 7],
            'not a number' => ['abc', 7],
            'empty' => ['', 7],
            'an array' => [['1'], 7],
            'a hex string' => ['0x1A', 7],
        ];
    }

    #[DataProvider('intProvider')]
    public function testIntValidatesRatherThanCasts(mixed $raw, int $expected): void
    {
        $this->assertSame($expected, (new QueryParams(['n' => $raw]))->int('n', 7));
    }

    public function testIntOnAnAbsentKeyIsTheDefault(): void
    {
        $this->assertSame(0, (new QueryParams([]))->int('page'));
        $this->assertSame(1, (new QueryParams([]))->int('page', 1));
    }

    /**
     * The one dearticleerate behaviour change from what this replaced. The old
     * idiom was `(int) (filter_input(…, FILTER_VALIDATE_INT) ?: $default)`,
     * and `?:` treats a valid **0** as a failure - so `?child_limit=0` asked
     * for no children and was given three. Zero is a number here; the two
     * callers that need a floor already clamp with max().
     */
    public function testZeroIsANumberAndNotAMissingValue(): void
    {
        $this->assertSame(0, (new QueryParams(['child_limit' => '0']))->int('child_limit', 3));
    }

    /* ===============================
       boolOrNull()
    =============================== */

    /**
     * @return array<string, array{mixed, ?bool}>
     */
    public static function boolProvider(): array
    {
        return [
            '1' => ['1', true],
            'true' => ['true', true],
            'on' => ['on', true],
            'yes' => ['yes', true],
            '0' => ['0', false],
            'false' => ['false', false],
            'off' => ['off', false],
            'no' => ['no', false],
            'empty' => ['', false],
            // Neither: the parameter was there but said something else.
            'gibberish' => ['maybe', null],
            'an array' => [['1'], null],
        ];
    }

    #[DataProvider('boolProvider')]
    public function testBoolOrNullHasThreeAnswers(mixed $raw, ?bool $expected): void
    {
        $this->assertSame($expected, (new QueryParams(['flag' => $raw]))->boolOrNull('flag'));
    }

    /**
     * Three states rather than two, because "the visitor navigated here
     * themselves" and "they were redirected here and it said no" are
     * different pages - `ProfileController`'s `?registered=1` banner.
     */
    public function testAnAbsentFlagIsNeitherTrueNorFalse(): void
    {
        $this->assertNull((new QueryParams([]))->boolOrNull('registered'));
    }

    /* ===============================
       The bag itself
    =============================== */

    public function testAllHandsBackTheWholeQueryForCallersThatWantIt(): void
    {
        // UserService::normalizePublicUsersListFilters() and NumLife take the
        // whole array and validate it themselves.
        $params = ['q' => 'Але', 'sort' => 'name', 'page' => '2'];

        $this->assertSame($params, (new QueryParams($params))->all());
    }

    public function testAnEmptyBagIsTheDefault(): void
    {
        // What RequestContext gives cron and every test that does not care.
        $this->assertSame([], (new QueryParams())->all());
    }

    /**
     * A snapshot, not a live view. This is the one good property of
     * `filter_input()` worth keeping - what the request said cannot be
     * changed by code that runs later - and it is why tests have to set
     * `$_GET` *before* building the controller rather than after.
     */
    public function testFromGlobalsCopiesRatherThanAliases(): void
    {
        $_GET = ['q' => 'булгаков'];

        $query = QueryParams::fromGlobals();

        $_GET['q'] = 'пелевин';
        $_GET['extra'] = 'x';

        $this->assertSame('булгаков', $query->string('q'));
        $this->assertFalse($query->has('extra'));

        $_GET = [];
    }

    public function testFromGlobalsWithNoQueryStringIsEmpty(): void
    {
        $_GET = [];

        $this->assertSame([], QueryParams::fromGlobals()->all());
    }
}

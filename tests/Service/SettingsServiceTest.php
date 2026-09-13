<?php

declare(strict_types=1);

namespace Tests\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\SettingsRepository;
use StreamEngine\Service\SettingsService;

/**
 * A request-scoped cache in front of a full table scan.
 *
 * The repository loads *every* settings row in one unindexed query - deliberate
 * for a small key-value table - so the only thing keeping that from being an
 * N-queries-per-page problem is that ensureLoaded() runs once. Settings are
 * read from templates, from the header logic and from half the services, so
 * "once" is the whole design; a regression there is invisible in behaviour and
 * expensive in production.
 *
 * The other half is lastModified(), which becomes the page's `Last-Modified`
 * header - so a settings change has to move it, or edited settings sit behind
 * a 304 until something else invalidates the page.
 */
final class SettingsServiceTest extends TestCase
{
    /** @var list<string> every statement the service caused */
    private array $queries = [];

    /** @var list<array{0: string, 1: array}> */
    private array $writes = [];

    /** @var array<string, array{string, int}> key => [value, updatedAt] */
    private array $table = [];

    private function makeService(array $table = []): SettingsService
    {
        $this->table = $table;

        $db = $this->createStub(PdoDatabase::class);

        $db->method('fetchAll')->willReturnCallback(
            function (string $sql): array {
                $this->queries[] = $sql;

                $rows = [];
                foreach ($this->table as $key => [$value, $updatedAt]) {
                    $rows[] = [
                        'setting_key' => $key,
                        'setting_value' => $value,
                        'updated_at' => $updatedAt,
                    ];
                }

                return $rows;
            }
        );

        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []): int {
                $this->writes[] = [$sql, $params];

                // Mirror the upsert so a later read sees what was written.
                $this->table[$params[0]] = [$params[1], 1_700_000_500];

                return 1;
            }
        );

        return new SettingsService(new SettingsRepository($db));
    }

    /* ===============================
       Loading once
    =============================== */

    public function testTheTableIsLoadedOnceAcrossManyReads(): void
    {
        $service = $this->makeService([
            'site.title' => ['Stream', 1_700_000_000],
            'catalog.per_page' => ['20', 1_700_000_100],
        ]);

        $service->get('site.title');
        $service->getString('site.title');
        $service->getInt('catalog.per_page');
        $service->lastModified();
        $service->get('nothing.here');

        $this->assertCount(1, $this->queries);
    }

    /**
     * Nothing is read at construction - a page that never touches settings must
     * not pay for the table scan. (The DI container builds this eagerly.)
     */
    public function testNothingIsLoadedUntilTheFirstRead(): void
    {
        $this->makeService(['site.title' => ['Stream', 1_700_000_000]]);

        $this->assertSame([], $this->queries);
    }

    /**
     * A miss still counts as loaded. Without the flag, `loaded` would be
     * inferred from a non-empty $data and a site with no settings rows would
     * re-scan the table on every single lookup.
     */
    public function testAnEmptyTableIsStillOnlyLoadedOnce(): void
    {
        $service = $this->makeService([]);

        $service->get('a');
        $service->get('b');
        $service->get('c');

        $this->assertCount(1, $this->queries);
    }

    /* ===============================
       Writing
    =============================== */

    public function testASetIsVisibleToTheNextReadWithoutRestartingTheRequest(): void
    {
        $service = $this->makeService(['site.title' => ['Stream', 1_700_000_000]]);

        $this->assertSame('Stream', $service->get('site.title'));

        $service->set('site.title', 'Stream Engine');

        // Reloaded rather than patched in memory, so the value read back is
        // what the database actually holds - including any normalisation the
        // column applies.
        $this->assertSame('Stream Engine', $service->get('site.title'));
    }

    public function testASetForcesExactlyOneReload(): void
    {
        $service = $this->makeService(['site.title' => ['Stream', 1_700_000_000]]);

        $service->get('site.title');
        $service->set('site.title', 'Stream Engine');
        $service->get('site.title');
        $service->get('site.title');

        // One for the first read, one for the reload after the write - and not
        // a third, because the reload also marks it loaded.
        $this->assertCount(2, $this->queries);
        $this->assertCount(1, $this->writes);
    }

    /**
     * Writing before any read must not leave the cache unloaded - reload() is
     * what set() calls, not just an invalidation flag.
     */
    public function testAWriteBeforeAnyReadStillLeavesTheCacheLoaded(): void
    {
        $service = $this->makeService([]);

        $service->set('site.title', 'Stream Engine');
        $service->get('site.title');

        $this->assertCount(1, $this->queries);
        $this->assertSame('Stream Engine', $service->get('site.title'));
    }

    /* ===============================
       lastModified
    =============================== */

    /**
     * The newest row wins regardless of the order rows come back in - there is
     * no ORDER BY on the load query.
     */
    public function testLastModifiedIsTheNewestRow(): void
    {
        $service = $this->makeService([
            'a' => ['1', 1_700_000_300],
            'b' => ['2', 1_700_000_900],
            'c' => ['3', 1_700_000_100],
        ]);

        $this->assertSame(1_700_000_900, $service->lastModified());
    }

    public function testLastModifiedIsZeroWhenThereAreNoSettings(): void
    {
        // Zero, not the current time: a fresh site must not claim its pages
        // changed at boot.
        $this->assertSame(0, $this->makeService([])->lastModified());
    }

    /**
     * Editing a setting has to move the header, or the change hides behind a
     * 304 for every visitor with the page already cached.
     */
    public function testAWriteMovesLastModifiedForward(): void
    {
        $service = $this->makeService(['site.title' => ['Stream', 1_700_000_000]]);

        $before = $service->lastModified();

        $service->set('site.title', 'Stream Engine');

        $this->assertGreaterThan($before, $service->lastModified());
    }

    /* ===============================
       Reading
    =============================== */

    public function testAMissingKeyReturnsTheCallersDefault(): void
    {
        $service = $this->makeService([]);

        $this->assertNull($service->get('nope'));
        $this->assertSame('fallback', $service->get('nope', 'fallback'));
        $this->assertSame('fallback', $service->getString('nope', 'fallback'));
        $this->assertSame(7, $service->getInt('nope', 7));
    }

    public function testGetStringDefaultsToTheEmptyString(): void
    {
        $this->assertSame('', $this->makeService([])->getString('nope'));
    }

    /**
     * Settings are stored as strings, and getInt() is what every numeric one
     * goes through - page sizes, limits, feature counts.
     */
    #[DataProvider('intCastProvider')]
    public function testGetIntCastsTheStoredString(string $stored, int $expected): void
    {
        $service = $this->makeService(['n' => [$stored, 1_700_000_000]]);

        $this->assertSame($expected, $service->getInt('n'));
    }

    /** @return array<string, array{string, int}> */
    public static function intCastProvider(): array
    {
        return [
            'a plain number' => ['20', 20],
            'negative' => ['-3', -3],
            // The interesting half: a value that is *present but not numeric*
            // casts to 0 rather than falling back to the default. A limit
            // stored as 'many' therefore becomes 0, not the sensible default -
            // the callers that matter must not treat 0 as "no limit".
            'a word' => ['many', 0],
            'empty' => ['', 0],
            // Leading digits win, PHP-style.
            'a number with a suffix' => ['20 items', 20],
            'a float truncates' => ['2.9', 2],
        ];
    }

    /**
     * The default only applies to a *missing* key, so a stored empty string is
     * a configured value - the same trap Config has with `??`.
     */
    public function testAStoredEmptyStringIsAValueRatherThanAMissingKey(): void
    {
        $service = $this->makeService(['site.title' => ['', 1_700_000_000]]);

        $this->assertSame('', $service->getString('site.title', 'Stream'));
        $this->assertSame('', $service->get('site.title', 'Stream'));
    }
}

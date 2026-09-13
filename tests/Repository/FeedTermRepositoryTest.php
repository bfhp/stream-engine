<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Repository\FeedTermRepository;
use StreamEngine\Service\FeedService;

final class FeedTermRepositoryTest extends TestCase
{
    public function testFindByFeedIdsAndVocabularyGroupsTermsByFeedId(): void
    {
        $db = $this->createMock(PdoDatabase::class);

        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static fn (string $sql): bool => str_contains($sql, 'feed_term_links')
                    && str_contains($sql, 'ft.vocabulary = ?')),
                [10, 20, 'author']
            )
            ->willReturn([
                [
                    'feed_id' => 10,
                    'position' => 0,
                    'id' => 101,
                    'parent_id' => null,
                    'vocabulary' => 'author',
                    'name' => 'Михаил Булгаков',
                    'slug' => 'михаил-булгаков',
                ],
                [
                    'feed_id' => 10,
                    'position' => 1,
                    'id' => 102,
                    'parent_id' => 101,
                    'vocabulary' => 'author',
                    'name' => 'Соавтор',
                    'slug' => 'соавтор',
                ],
            ]);

        $repository = new FeedTermRepository($db);
        $result = $repository->findByFeedIdsAndVocabulary([10, 20, 10], 'author');

        $this->assertCount(2, $result[10]);
        $this->assertSame('Михаил Булгаков', $result[10][0]->name);
        $this->assertSame('Соавтор', $result[10][1]->name);
        $this->assertSame(101, $result[10][1]->parentId);
        $this->assertArrayNotHasKey(20, $result);
    }

    public function testReplaceForFeedDeletesOldLinksInsertsNewTermsInOrderAndCommits(): void
    {
        $db = $this->createMock(PdoDatabase::class);

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollback');

        $executeLog = [];
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []) use (&$executeLog): int {
                $executeLog[] = ['sql' => $sql, 'params' => $params];

                return 1;
            }
        );

        $idsByName = ['Fiction Books' => 501, 'Horror' => 502];
        $fetchOneLog = [];
        $db->method('fetchOne')->willReturnCallback(
            function (string $sql, array $params = []) use (&$fetchOneLog, $idsByName): ?array {
                $fetchOneLog[] = ['sql' => $sql, 'params' => $params];

                return ['id' => $idsByName[$params[1]]];
            }
        );

        $repository = new FeedTermRepository($db);
        $repository->replaceForFeed(10, 'genre', ['Fiction Books', 'Horror']);

        $this->assertCount(5, $executeLog);

        $this->assertStringContainsString('DELETE ftl', $executeLog[0]['sql']);
        $this->assertSame([10, 'genre'], $executeLog[0]['params']);

        $this->assertStringContainsString('INSERT IGNORE INTO feed_terms', $executeLog[1]['sql']);
        $this->assertSame(['genre', 'Fiction Books', 'fiction-books'], $executeLog[1]['params']);

        $this->assertStringContainsString('INSERT INTO feed_term_links', $executeLog[2]['sql']);
        $this->assertSame([10, 501, 0], $executeLog[2]['params']);

        $this->assertStringContainsString('INSERT IGNORE INTO feed_terms', $executeLog[3]['sql']);
        $this->assertSame(['genre', 'Horror', 'horror'], $executeLog[3]['params']);

        $this->assertStringContainsString('INSERT INTO feed_term_links', $executeLog[4]['sql']);
        $this->assertSame([10, 502, 1], $executeLog[4]['params']);

        $this->assertSame(
            [['genre', 'Fiction Books'], ['genre', 'Horror']],
            array_map(static fn (array $call): array => $call['params'], $fetchOneLog)
        );
    }

    public function testReplaceForFeedRollsBackAndRethrowsWhenAnExecuteCallFailsMidTransaction(): void
    {
        $db = $this->createMock(PdoDatabase::class);

        $db->expects($this->once())->method('begin');
        $db->expects($this->never())->method('commit');
        $db->expects($this->once())->method('rollback');

        $callCount = 0;
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []) use (&$callCount): int {
                $callCount++;

                // Calls: 1=DELETE, 2=INSERT IGNORE(Fiction), 3=link insert(Fiction),
                // 4=INSERT IGNORE(Horror) <- fails mid-transaction, after one term already succeeded.
                if ($callCount === 4) {
                    throw new \RuntimeException('Query failed');
                }

                return 1;
            }
        );
        $db->method('fetchOne')->willReturn(['id' => 501]);

        $repository = new FeedTermRepository($db);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Query failed');

        $repository->replaceForFeed(10, 'genre', ['Fiction', 'Horror']);
    }

    public function testReplaceForFeedSlugifiesNamesWithPunctuationSpacesAndCyrillicCharacters(): void
    {
        $db = $this->createMock(PdoDatabase::class);

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollback');

        $insertParams = [];
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []) use (&$insertParams): int {
                if (str_contains($sql, 'INSERT IGNORE INTO feed_terms')) {
                    $insertParams[] = $params;
                }

                return 1;
            }
        );
        $db->method('fetchOne')->willReturn(['id' => 1]);

        $repository = new FeedTermRepository($db);
        $repository->replaceForFeed(10, 'genre', ['Sci-Fi & Fantasy!', ' Михаил   Булгаков ']);

        $this->assertSame(['genre', 'Sci-Fi & Fantasy!', 'sci-fi-fantasy'], $insertParams[0]);
        $this->assertSame(['genre', 'Михаил Булгаков', 'михаил-булгаков'], $insertParams[1]);
    }

    public function testReplaceForFeedDeduplicatesNamesThatNormalizeToTheSameLowercaseValue(): void
    {
        $db = $this->createMock(PdoDatabase::class);

        $db->expects($this->once())->method('begin');
        $db->expects($this->once())->method('commit');
        $db->expects($this->never())->method('rollback');

        $executeLog = [];
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []) use (&$executeLog): int {
                $executeLog[] = ['sql' => $sql, 'params' => $params];

                return 1;
            }
        );
        $db->method('fetchOne')->willReturn(['id' => 1]);

        $repository = new FeedTermRepository($db);
        // "FANTASY" and "Fantasy" both normalize to the same lowercase key, so only the
        // last-seen casing survives and just one term/link pair is created for them.
        $repository->replaceForFeed(10, 'genre', ['FANTASY', 'Fantasy', 'Horror']);

        // 1 DELETE + (INSERT IGNORE + link insert) for each of the 2 deduplicated names.
        $this->assertCount(5, $executeLog);

        $insertIgnoreCalls = array_values(array_filter(
            $executeLog,
            static fn (array $call): bool => str_contains($call['sql'], 'INSERT IGNORE INTO feed_terms')
        ));

        $this->assertCount(2, $insertIgnoreCalls);
        $this->assertSame(['genre', 'Fantasy', 'fantasy'], $insertIgnoreCalls[0]['params']);
        $this->assertSame(['genre', 'Horror', 'horror'], $insertIgnoreCalls[1]['params']);
    }

    /* ===============================
       normalizeNames and slugify
    =============================== */

    /**
     * Both are private and reachable only through replaceForFeed(), so they are
     * driven through it and observed on the INSERT IGNORE parameters:
     * `[vocabulary, name, slug]`. The name is what normalizeNames() produced,
     * the slug is what slugify() made of it.
     *
     * @param list<string> $names
     * @return list<array{0: string, 1: string}> [name, slug] per created term
     */
    private function termsCreatedFor(array $names): array
    {
        $db = $this->createStub(PdoDatabase::class);

        $created = [];
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []) use (&$created): int {
                if (str_contains($sql, 'INSERT IGNORE INTO feed_terms')) {
                    $created[] = [$params[1], $params[2]];
                }

                return 1;
            }
        );
        $db->method('fetchOne')->willReturn(['id' => 1]);

        (new FeedTermRepository($db))->replaceForFeed(10, 'genre', $names);

        return $created;
    }

    /** @param list<string> $names */
    private function slugsFor(array $names): array
    {
        return array_column($this->termsCreatedFor($names), 1);
    }

    /**
     * The deliberate divergence from Formatter::slugify(), which transliterates
     * Cyrillic to Latin. Term slugs keep it, because a term URL is read by a
     * human and `/tags/фантастика/` is the readable form - while feed slugs go
     * through slugify() and come out `fantastika`.
     *
     * Pinned side by side so the difference reads as a decision. Neither is
     * wrong; having two and not knowing which one applies is. Both are now the
     * same function underneath - Formatter::unicodeSlug() and
     * Formatter::slugify() differ only in the character class - so this test
     * is what stops a future tidy-up from collapsing them into one.
     */
    public function testTermSlugsKeepCyrillicWhereFormatterTransliteratesIt(): void
    {
        $this->assertSame(['фантастика'], $this->slugsFor(['Фантастика']));

        // The other one, for contrast.
        $this->assertSame('fantastika', Formatter::slugify('Фантастика'));
    }


    /**
     * `[^\p{L}\p{N}]+` is a run, so any stretch of punctuation and spaces
     * becomes exactly one hyphen - not one per character.
     */
    #[DataProvider('slugProvider')]
    public function testSlugifyCollapsesEverythingThatIsNotALetterOrNumber(
        string $name,
        string $expected
    ): void {
        $this->assertSame([$expected], $this->slugsFor([$name]));
    }

    /** @return array<string, array{string, string}> */
    public static function slugProvider(): array
    {
        return [
            'a run of punctuation is one hyphen' => ['Sci-Fi & Fantasy!', 'sci-fi-fantasy'],
            'leading and trailing punctuation is trimmed' => ['!!!Хоррор!!!', 'хоррор'],
            'digits are letters enough' => ['PHP 8.3', 'php-8-3'],
            'underscores are not alphanumeric' => ['snake_case', 'snake-case'],
            'mixed scripts are both kept' => ['PHP и Go', 'php-и-go'],
            'an existing hyphen survives as one' => ['e-book', 'e-book'],
            // \p{N} is Unicode-wide, so non-ASCII digits count too.
            'arabic-indic digits are numbers' => ['Том ٣', 'том-٣'],
        ];
    }

    /**
     * A name made entirely of characters slugify strips would produce an empty
     * slug, which would collide with every other such term and make a URL of
     * `/tags//`. The fallback is a literal 'term'.
     *
     * Note it is a *shared* fallback: two all-punctuation names both become
     * `term`, so the slug column is not unique between them - which is fine
     * only because the row is identified by name, not slug (see below).
     */
    public function testANameWithNothingSluggableFallsBackToTerm(): void
    {
        $this->assertSame(['term', 'term'], $this->slugsFor(['!!!', '???']));
    }

    /**
     * Truncation is now followed by a second trim, so a slug cut at the length
     * limit no longer ends on a hyphen.
     *
     * This reverses what this test used to assert, and the reason it gave for
     * leaving the hyphen alone - "re-trimming would change existing slugs,
     * breaking links" - turns out not to hold. Slugs live in
     * `feed_terms.slug`; nothing recomputes one to build a URL (`UrlGenerator`
     * and breadcrumb builders both read the stored value),
     * and `findOrCreate()` is an `INSERT IGNORE` keyed on
     * `(vocabulary, parent_key, name)`, so an existing term's slug is never
     * rewritten. Only terms created from now on get the new shape, and no
     * existing URL moves.
     *
     * One consequence worth knowing about rather than fixing: an importer's
     * import builds `termSlugMapKey` from a recomputed slug, so for the few
     * pre-existing terms stored with a trailing hyphen its map will not match.
     * The `INSERT IGNORE` on the name still prevents a duplicate row.
     */
    public function testATruncatedSlugNoLongerEndsInAHyphen(): void
    {
        // 74 letters, a space, then one more letter: the cut lands exactly on
        // the hyphen the space became, which is the case that used to keep it.
        $name = str_repeat('a', FeedService::MAX_SLUG_LENGTH - 1).' b';

        $slug = $this->slugsFor([$name])[0];

        $this->assertSame(FeedService::MAX_SLUG_LENGTH - 1, mb_strlen($slug));
        $this->assertStringEndsNotWith('-', $slug);
    }

    public function testALongNameIsCutToTheColumnLimit(): void
    {
        $slug = $this->slugsFor([str_repeat('я', 200)])[0];

        // Characters, not bytes - mb_substr, on a column sized in characters.
        $this->assertSame(FeedService::MAX_SLUG_LENGTH, mb_strlen($slug));
    }

    /**
     * The name is stored as the author typed it, minus whitespace tidying -
     * only the slug is folded. So `Научная Фантастика` renders with its
     * capitals and links to `/tags/научная-фантастика/`.
     */
    public function testTheStoredNameKeepsItsCasingWhileTheSlugIsFolded(): void
    {
        $this->assertSame(
            [['Научная Фантастика', 'научная-фантастика']],
            $this->termsCreatedFor(['Научная Фантастика'])
        );
    }

    #[DataProvider('whitespaceProvider')]
    public function testNormalizeNamesTidiesWhitespaceBeforeAnythingElse(
        string $name,
        string $expected
    ): void {
        $this->assertSame($expected, $this->termsCreatedFor([$name])[0][0]);
    }

    /** @return array<string, array{string, string}> */
    public static function whitespaceProvider(): array
    {
        return [
            'surrounding space' => ['  Хоррор  ', 'Хоррор'],
            'a run of inner spaces collapses to one' => ['Научная    Фантастика', 'Научная Фантастика'],
            // Tabs and newlines from a pasted list collapse the same way
            // rather than becoming part of the name.
            'tabs and newlines' => ["Научная\t\nФантастика", 'Научная Фантастика'],
            // PHP's /u modifier turns on PCRE2_UCP as well as UTF-8, so \s is
            // Unicode-aware: a non-breaking space pasted out of a word
            // processor is normalised to an ordinary one, and the term matches
            // the one someone else typed by hand. Without UCP it would survive,
            // and the two would be different rows with the same visible name.
            'a non-breaking space is whitespace too' => ["Хоррор\u{00A0}2", 'Хоррор 2'],
            'a narrow no-break space as well' => ["Хоррор\u{202F}2", 'Хоррор 2'],
        ];
    }

    /**
     * Blank entries are dropped rather than turned into a `term` row - a tag
     * input that ends with a trailing comma is the ordinary way this happens,
     * and a phantom term on every such feed would be the result.
     */
    public function testBlankNamesAreDroppedEntirely(): void
    {
        $this->assertSame(
            [['Хоррор', 'хоррор']],
            $this->termsCreatedFor(['', '   ', "\n", 'Хоррор'])
        );
    }

    public function testAnAllBlankListCreatesNothingButStillClearsTheOldLinks(): void
    {
        $db = $this->createStub(PdoDatabase::class);

        $statements = [];
        $db->method('execute')->willReturnCallback(
            function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 1;
            }
        );
        $db->method('fetchOne')->willReturn(['id' => 1]);

        (new FeedTermRepository($db))->replaceForFeed(10, 'genre', ['', '  ']);

        // "Remove every tag from this feed" has to reach the DELETE - an early
        // return on an empty list would leave the old links in place.
        $this->assertCount(1, $statements);
        $this->assertStringContainsString('DELETE ftl', $statements[0]);
    }

    /* ===============================
       findOrCreate
    =============================== */

    /**
     * The row is identified by **name**, not by slug: INSERT IGNORE leans on
     * the unique index over (vocabulary, parent_key, name), and the SELECT that
     * follows looks the name up again.
     *
     * That means two different names that slugify identically are two terms
     * sharing one slug - and therefore one URL. Recorded because it is
     * surprising and because the fix (uniqueness on slug) would have to decide
     * which name wins, which is a data decision, not a code one.
     */
    public function testTwoNamesThatShareASlugBecomeTwoSeparateTerms(): void
    {
        $created = $this->termsCreatedFor(['Sci-Fi', 'Sci Fi']);

        $this->assertSame(
            [['Sci-Fi', 'sci-fi'], ['Sci Fi', 'sci-fi']],
            $created
        );
    }

    /**
     * The lookup is scoped to top-level terms (`parent_id IS NULL`) as well as
     * to the vocabulary, so a child term with the same name in the same
     * vocabulary is a different row and cannot be picked up by mistake.
     */
    public function testTheLookupIsScopedToTheVocabularyAndToTopLevelTerms(): void
    {
        $db = $this->createStub(PdoDatabase::class);
        $db->method('execute')->willReturn(1);

        $lookups = [];
        $db->method('fetchOne')->willReturnCallback(
            function (string $sql, array $params = []) use (&$lookups): array {
                $lookups[] = ['sql' => $sql, 'params' => $params];

                return ['id' => 501];
            }
        );

        (new FeedTermRepository($db))->replaceForFeed(10, 'genre', ['Хоррор']);

        $this->assertCount(1, $lookups);
        $this->assertStringContainsString('parent_id IS NULL', $lookups[0]['sql']);
        $this->assertStringContainsString('vocabulary = ?', $lookups[0]['sql']);
        // By name, not by slug.
        $this->assertSame(['genre', 'Хоррор'], $lookups[0]['params']);
    }

    /**
     * New terms are always created at the top level - `parent_id` is a literal
     * NULL in the INSERT. Nesting is an editorial action elsewhere, never a
     * side effect of tagging a feed.
     */
    public function testTaggingNeverCreatesAChildTerm(): void
    {
        $db = $this->createStub(PdoDatabase::class);

        $inserts = [];
        $db->method('execute')->willReturnCallback(
            function (string $sql) use (&$inserts): int {
                if (str_contains($sql, 'INSERT IGNORE INTO feed_terms')) {
                    $inserts[] = $sql;
                }

                return 1;
            }
        );
        $db->method('fetchOne')->willReturn(['id' => 1]);

        (new FeedTermRepository($db))->replaceForFeed(10, 'genre', ['Хоррор']);

        $this->assertStringContainsString('VALUES (NULL, ?, ?, ?', $inserts[0]);
    }

    /**
     * Link positions are the array index of the normalized list, so removing a
     * blank entry renumbers what follows - the third thing the author typed
     * becomes position 1 if the second was empty. That is what keeps the
     * rendered tag order gap-free.
     */
    public function testLinkPositionsFollowTheNormalizedOrderNotTheInputOrder(): void
    {
        $db = $this->createStub(PdoDatabase::class);

        $links = [];
        $db->method('execute')->willReturnCallback(
            function (string $sql, array $params = []) use (&$links): int {
                if (str_contains($sql, 'INSERT INTO feed_term_links')) {
                    $links[] = $params;
                }

                return 1;
            }
        );
        $db->method('fetchOne')->willReturn(['id' => 1]);

        (new FeedTermRepository($db))->replaceForFeed(10, 'genre', ['Первый', '', 'Второй']);

        $this->assertSame([[10, 1, 0], [10, 1, 1]], $links);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Domain\Feed;
use StreamEngine\Domain\User;
use StreamEngine\Repository\FeedRepository;
use Tests\Support\FeedAclFixture;
use Tests\Support\LegacyFeedAcl;
use Tests\Support\TypedSqliteDatabase;

/**
 * The ACL-filtered reads of FeedRepository return exactly the rows the
 * legacy predicate (LegacyFeedAcl) allows, for every user and feed type of
 * FeedAclFixture.
 *
 * This is the safety net for rewriting how the ACL is built (docs/TODO.md,
 * P1, approach A): the rules must not change, only the SQL that applies
 * them. A difference here is an access-control regression, not a test to
 * adjust.
 */
final class FeedAclEquivalenceTest extends TestCase
{
    private static ?FeedAclFixture $fixture = null;

    private static function fixture(): FeedAclFixture
    {
        return self::$fixture ??= FeedAclFixture::create();
    }

    protected function setUp(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for the ACL equivalence tests.');
        }
    }

    private function repository(): FeedRepository
    {
        return new FeedRepository(TypedSqliteDatabase::over(self::fixture()->pdo));
    }

    /**
     * Ids the legacy predicate allows, newest first (created_at, then id).
     *
     * @return list<int>
     */
    private function legacy(User $user, ?string $type = null): array
    {
        [$acl, $params] = (new LegacyFeedAcl())->condition($user);
        $typeFilter = $type === null ? '' : 'f.type = ? AND ';
        $statement = self::fixture()->pdo->prepare(
            "SELECT f.id FROM feeds f WHERE $typeFilter($acl) ORDER BY f.created_at DESC, f.id DESC"
        );
        TypedSqliteDatabase::bind($statement, $type === null ? $params : [$type, ...$params]);

        return array_map('intval', array_column($statement->fetchAll(), 'id'));
    }

    /**
     * @return list<int>
     */
    private function allIds(?string $type = null): array
    {
        $statement = self::fixture()->pdo->prepare('SELECT id FROM feeds'.($type === null ? '' : ' WHERE type = ?').' ORDER BY id');
        $statement->execute($type === null ? [] : [$type]);

        return array_map('intval', array_column($statement->fetchAll(), 'id'));
    }

    /**
     * @param list<Feed> $feeds
     * @return list<int>
     */
    private static function ids(array $feeds): array
    {
        return array_values(array_map(static fn (Feed $feed): int => $feed->id, $feeds));
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    /** @return array<string, array{string}> */
    public static function userProvider(): array
    {
        $users = [];
        foreach (array_keys(FeedAclFixture::users()) as $name) {
            $users[$name] = [$name];
        }

        return $users;
    }

    #[DataProvider('userProvider')]
    public function testTypedReadsReturnExactlyTheLegacyRows(string $name): void
    {
        $user = FeedAclFixture::users()[$name];
        $repository = $this->repository();

        foreach (self::fixture()->types() as $type) {
            $expected = $this->legacy($user, $type);
            $context = "$name, $type";

            $this->assertSame($expected, self::ids($repository->findByType($type, $user, 100000, 0, false)), "findByType: $context");
            $this->assertSame(count($expected), $repository->countByType($type, $user), "countByType: $context");

            $page = $repository->findByTypePage($type, $user, 100000);
            $this->assertSame($expected, self::ids($page['items']), "findByTypePage items: $context");
            $this->assertSame(count($expected), $page['total'], "findByTypePage total: $context");

            $byOffset = [];
            for ($offset = 0; $offset < count($expected) + 7; $offset += 7) {
                $byOffset = [...$byOffset, ...self::ids($repository->findByTypePage($type, $user, 7, $offset)['items'])];
            }
            $this->assertSame($expected, $byOffset, "findByTypePage by 7: $context");

            $byCursor = [];
            $cursor = null;
            do {
                $batch = $repository->findByTypeAfter($type, $user, 7, $cursor);
                $byCursor = [...$byCursor, ...self::ids($batch)];
                $last = $batch === [] ? null : $batch[array_key_last($batch)];
                $cursor = $last === null ? null : (object) ['createdAt' => $last->createdAt, 'id' => $last->id];
            } while ($batch !== [] && count($byCursor) <= count($expected));
            $this->assertSame($expected, $byCursor, "findByTypeAfter by 7: $context");

            $this->assertSame(self::sorted($expected), self::sorted(self::ids($repository->findByIds($this->allIds($type), $user, $type))), "findByIds: $context");
        }
    }

    #[DataProvider('userProvider')]
    public function testUntypedReadsReturnExactlyTheLegacyRows(string $name): void
    {
        $user = FeedAclFixture::users()[$name];
        $repository = $this->repository();
        $expected = self::sorted($this->legacy($user));

        $one = [];
        foreach ($this->allIds() as $id) {
            if ($repository->findById($id, $user) !== null) {
                $one[] = $id;
            }
        }
        $this->assertSame($expected, $one, "findById: $name");
        $this->assertSame($expected, self::sorted(self::ids($repository->findByIds($this->allIds(), $user))), "findByIds: $name");
        $this->assertSame($expected, self::sorted(self::ids($repository->list($user, limit: 100000))), "list: $name");
    }

    /**
     * The equivalence tests pass whichever predicate a query uses, so this
     * pins which one it is: ordinary types, comments and gallery photos take
     * their own rules only; other spellings of a type and untyped queries
     * choose the rules by each row's type.
     */
    public function testOrdinaryTypesUseTheCompactPredicate(): void
    {
        $member = FeedAclFixture::users()['member'];
        $sqlOf = function (callable $call): string {
            $captured = [];
            $db = $this->createStub(\StreamEngine\Core\PdoDatabase::class);
            $db->method('fetchOne')->willReturnCallback(static function (string $sql) use (&$captured): ?array {
                $captured[] = $sql;

                return null;
            });
            $db->method('fetchAll')->willReturnCallback(static function (string $sql) use (&$captured): array {
                $captured[] = $sql;

                return [];
            });
            $call(new FeedRepository($db));

            return $captured[0];
        };

        $compact = $sqlOf(fn (FeedRepository $r) => $r->countByType('lib-page', $member));
        $this->assertStringNotContainsString('acl_parent', $compact);
        $this->assertStringNotContainsString('gallery', $compact);
        $this->assertStringNotContainsString('EXISTS', $compact);
        $this->assertLessThan(30, substr_count(trim($compact), "\n") + 1, $compact);

        $comment = $sqlOf(fn (FeedRepository $r) => $r->countByType('comment', $member));
        $this->assertStringNotContainsString('acl_parent', $comment);
        $this->assertStringNotContainsString('EXISTS', $comment);
        $this->assertStringContainsString('JOIN feeds s ON s.id', $comment);

        $photo = $sqlOf(fn (FeedRepository $r) => $r->countByType('gallery-photo', $member));
        $this->assertStringNotContainsString('acl_parent', $photo);
        $this->assertStringNotContainsString('EXISTS', $photo);
        $this->assertStringContainsString("gs.name = 'gallery.state'", $photo);

        // Untyped queries and other spellings of a type choose the rules by
        // each row's type; none uses the old predicate any more.
        foreach ([
            'Gallery-Photo' => fn (FeedRepository $r) => $r->countByType('Gallery-Photo', $member),
            'COMMENT' => fn (FeedRepository $r) => $r->countByType('COMMENT', $member),
            'untyped' => fn (FeedRepository $r) => $r->findById(1, $member),
        ] as $label => $call) {
            $sql = $sqlOf($call);
            $this->assertStringContainsString("f.type = 'comment' AND", $sql, $label);
            $this->assertStringContainsString("f.type <> 'gallery-photo' OR", $sql, $label);
            $this->assertStringNotContainsString('acl_parent', $sql, $label);
            $this->assertStringNotContainsString('EXISTS', $sql, $label);
        }
    }

    /**
     * The fixture is only a safety net if it reaches every rule: each of these
     * rows is decided by one rule, so dropping that rule from a rewritten
     * query changes some test above.
     */
    public function testFixtureExercisesEveryRule(): void
    {
        $fixture = self::fixture();
        $users = FeedAclFixture::users();
        $visible = fn (string $user, string $row): bool => in_array($fixture->named[$row], $this->legacy($users[$user]), true);
        $owner = FeedAclFixture::OWNER;

        // Ownership, membership by role level, moderators.
        $this->assertTrue($visible('owner', "article private in none by $owner"));
        $this->assertFalse($visible('outsider', "article private in none by $owner"));
        $this->assertTrue($visible('container moderator', 'article private in private by '.FeedAclFixture::OUTSIDER));
        $this->assertFalse($visible('member', 'article private in private by '.FeedAclFixture::OUTSIDER));
        $this->assertTrue($visible('member', 'article members in members by '.FeedAclFixture::OUTSIDER));
        // A private container admits role level 2 and up only, whatever its content.
        $this->assertFalse($visible('member', 'article members in private by '.FeedAclFixture::OUTSIDER));
        $this->assertFalse($visible('low role', 'article members in members by '.FeedAclFixture::OUTSIDER));
        $this->assertTrue($visible('global moderator', "article private in private by $owner"));

        // Closed container (0.7.3): ownership does not open it.
        $this->assertFalse($visible('owner', "article private in members by $owner"));
        $this->assertFalse($visible('owner', "article public in members by $owner"));
        $this->assertTrue($visible('member', "article public in members by $owner"));

        // Comments and replies follow their publication (0.6.1), not their author.
        $this->assertTrue($visible('owner', 'comment on '.$fixture->named["article private in none by $owner"]));
        $this->assertFalse($visible('outsider', 'comment on '.$fixture->named["article private in none by $owner"]));
        $this->assertTrue($visible('owner', 'reply on '.$fixture->named["article private in none by $owner"]));
        $this->assertFalse($visible('outsider', 'reply on '.$fixture->named["article private in none by $owner"]));
        // A comment without a publication is visible to nobody but moderators.
        $this->assertFalse($visible('owner', 'orphan comment'));
        $this->assertTrue($visible('admin', 'orphan comment'));

        // Gallery photos (0.7.0): processing state, album, context.
        $this->assertTrue($visible('guest', 'photo ready in public album of public blog'));
        $this->assertFalse($visible('owner', 'photo processing in public album of public blog'));
        $this->assertFalse($visible('owner', 'photo  in public album of public blog'));
        $this->assertTrue($visible('admin', 'photo processing in public album of public blog'));
        $this->assertFalse($visible('guest', 'photo ready in private album of public blog'));
        $this->assertFalse($visible('owner', 'photo ready in public album of article'));
        $this->assertFalse($visible('guest', 'photo ready in public album of private blog'));
        $this->assertFalse($visible('guest', 'comment on photo processing in public album of public blog'));
        $this->assertTrue($visible('guest', 'comment on photo ready in public album of public blog'));
        // The photo's own container, apart from its album's context.
        $this->assertFalse($visible('guest', 'photo ready in public blog, members container'));
        $this->assertFalse($visible('owner', 'photo ready in public blog, members container'));
        $this->assertTrue($visible('member', 'photo ready in public blog, members container'));
    }
}

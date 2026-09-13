<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Domain\User;
use StreamEngine\Repository\FeedRepository;
use StreamEngine\Service\AccessService;

/**
 * Two things live here: the row-level access predicate every read funnels
 * through, and the self-contained lookups that don't touch it.
 *
 * The ACL half exists because FeedServiceTest - where most of this repository
 * is exercised - *mocks* the repository, so the predicate deciding who may see
 * which feed was asserted precisely nowhere. It is also the kind of code whose
 * failure mode is silence: a misordered bound parameter doesn't error, it
 * compares the wrong columns and returns rows the caller was never allowed to
 * see.
 *
 * The rest of the file covers the
 * newer, self-contained lookups added for the "continue reading" widget,
 * which don't touch baseSelect()/ACL and so don't need that setup.
 */
final class FeedRepositoryTest extends TestCase
{
    /* ===============================
       Row-level access control
    =============================== */

    private function user(int $id, string $role = AccessService::ROLE_USER): User
    {
        return new User(id: $id, email: 'user@example.com', role: $role);
    }

    /**
     * buildAclCondition() is private, and deliberately reached directly here:
     * it is the predicate deciding which feed rows a given user may see at all,
     * and every one of the 22 public methods that takes a User funnels through
     * it. Asserting it once, precisely, beats inferring it from 22 call sites.
     *
     * @return array{string, list<mixed>}
     */
    private function aclCondition(User $user): array
    {
        $method = new ReflectionMethod(FeedRepository::class, 'buildAclCondition');

        return $method->invoke(new FeedRepository($this->createStub(PdoDatabase::class)), $user);
    }

    public function testAdminsSeeEverythingAndBindNothing(): void
    {
        [$sql, $params] = $this->aclCondition($this->user(1, role: AccessService::ROLE_ADMIN));

        $this->assertSame('1=1', $sql);
        // No placeholders in the SQL means no params - and, just as important,
        // nothing appended to the caller's own list, which is what the ordering
        // tests below depend on.
        $this->assertSame([], $params);
    }

    public function testEveryoneElseGetsTheThreeArmsAndBindsTheirIdTwice(): void
    {
        [$sql, $params] = $this->aclCondition($this->user(7));

        // Own rows, public rows, or rows in a container they belong to.
        $this->assertStringContainsString('f.owner_id = ?', $sql);
        $this->assertStringContainsString("f.visibility = 'public'", $sql);
        $this->assertStringContainsString('FROM memberships m', $sql);
        $this->assertStringContainsString('m.user_id = ?', $sql);

        // Two placeholders, same id, in that order - the pair the ordering
        // tests below expect to find at the end of every query's params.
        $this->assertSame([7, 7], $params);
    }

    /**
     * A guest is user id 0, and the first arm is `f.owner_id = ?`. Nothing owns
     * a feed as 0 today - `User::SYSTEM_USER_ID` is 1 and real ids start above
     * it - so this is safe, but it is safe by accident rather than by
     * construction. Pinned so that a migration introducing an owner_id of 0
     * (or a NULL that MySQL coerces) fails here rather than quietly making
     * every such feed world-readable.
     */
    public function testGuestsBindZeroWhichMustNotOwnAnything(): void
    {
        [, $params] = $this->aclCondition($this->user(0, role: AccessService::ROLE_USER));

        $this->assertSame([0, 0], $params);
    }

    public function testGlobalModeratorDoesNotBypassContentVisibility(): void
    {
        [$sql, $params] = $this->aclCondition($this->user(7, AccessService::ROLE_MODERATOR));
        $this->assertNotSame('1=1', $sql);
        $this->assertSame([7, 7], $params);
    }

    /**
     * The invariant that matters, and the reason this is worth testing at all:
     * applyAcl() *appends* its two params, so for a non-admin they are the last
     * two of every query. Get the order wrong and nothing errors - the query
     * still runs, comparing an owner id against a slug, and returns rows the
     * caller was never allowed to see.
     *
     * @param callable(FeedRepository, User): mixed $call
     * @param list<mixed> $ownParams what the method binds before the ACL
     */
    #[DataProvider('aclOrderingProvider')]
    public function testAclParamsAreAppendedAfterEachMethodsOwn(callable $call, array $ownParams): void
    {
        $captured = [];
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturnCallback(
            function (string $_sql, array $params) use (&$captured): ?array {
                $captured = $params;

                return null;
            }
        );
        $db->method('fetchAll')->willReturnCallback(
            function (string $_sql, array $params) use (&$captured): array {
                $captured = $params;

                return [];
            }
        );

        $call(new FeedRepository($db), $this->user(7));

        $this->assertSame([...$ownParams, 7, 7], $captured);
    }

    /** @return array<string, array{callable, list<mixed>}> */
    public static function aclOrderingProvider(): array
    {
        $cursor = (object) ['created_at' => 1700000000, 'id' => 42];

        return [
            'findById' => [fn (FeedRepository $r, User $u) => $r->findById(90, $u), [90]],
            'findBySlug' => [fn (FeedRepository $r, User $u) => $r->findBySlug('x', $u), ['x']],
            'findByParent' => [fn (FeedRepository $r, User $u) => $r->findByParent(58, $u), [58]],
            'findByParentAndSlug' => [
                fn (FeedRepository $r, User $u) => $r->findByParentAndSlug(58, 'x', $u),
                [58, 'x'],
            ],
            // The parent-is-null branch binds one fewer param, so the ACL pair
            // shifts left with it.
            'findByParentAndSlug, no parent' => [
                fn (FeedRepository $r, User $u) => $r->findByParentAndSlug(null, 'x', $u),
                ['x'],
            ],
            'findByParentAndSlug, typed' => [
                fn (FeedRepository $r, User $u) => $r->findByParentAndSlug(58, 'x', $u, 'chapter'),
                [58, 'x', 'chapter'],
            ],
            'findCommentsPage' => [
                fn (FeedRepository $r, User $u) => $r->findCommentsPage(58, 10, null, $u),
                [58],
            ],
            // Three cursor params ahead of the ACL pair - the widest gap
            // between a method's own list and the appended one.
            'findCommentsPage, paged' => [
                fn (FeedRepository $r, User $u) => $r->findCommentsPage(58, 10, $cursor, $u),
                [58, 1700000000, 1700000000, 42],
            ],
        ];
    }

    /**
     * The same invariant, but exhaustive by construction rather than by a
     * hand-kept list.
     *
     * Every public method taking a `User` is discovered by reflection and
     * invoked with synthesised arguments; the assertion is positional, so it
     * holds regardless of how many params the method binds before or after the
     * ACL: find where the predicate's own `f.owner_id = ?` lands in the SQL,
     * count the placeholders ahead of it, and require the user id at exactly
     * those two slots.
     *
     * That formulation covers `search()` - which binds its cursor *after*
     * applyAcl() - with no special case, and it means a method added tomorrow
     * is covered without anybody remembering this file.
     *
     * @param list<mixed> $args
     */
    #[DataProvider('aclMethodProvider')]
    public function testEveryAclMethodBindsTheUserAtItsPredicatesPlaceholders(
        string $method,
        array $args
    ): void {
        $calls = [];

        $db = $this->createStub(PdoDatabase::class);
        $capture = function (string $sql, array $params = []) use (&$calls) {
            $calls[] = [$sql, $params];

            return [];
        };
        $db->method('fetchAll')->willReturnCallback($capture);
        $db->method('fetchOne')->willReturnCallback(
            static function (string $sql, array $params = []) use (&$calls): ?array {
                $calls[] = [$sql, $params];

                return null;
            }
        );

        $user = $this->user(7);
        (new FeedRepository($db))->{$method}(...$this->fillUser($args, $user));

        $this->assertNotEmpty($calls, $method.' issued no query');

        foreach ($calls as [$sql, $params]) {
            // Placeholders and bound values have to match in number before the
            // positions below mean anything.
            $this->assertSame(
                substr_count($sql, '?'),
                count($params),
                $method.' binds a different number of params than it has placeholders'
            );

            // Anchored on the predicate's *second* line rather than its
            // `f.owner_id = ?` first one: four of these methods filter by
            // owner themselves, so that fragment is not unique and matching
            // it found the method's own placeholder instead of the ACL's.
            // Nothing binds between this line and the `?` above it, so the
            // ACL's pair starts one placeholder back.
            $offset = strpos($sql, "OR f.visibility = 'public'");
            $this->assertNotFalse($offset, $method.' does not apply the ACL predicate');

            $before = substr_count(substr($sql, 0, $offset), '?') - 1;

            $this->assertSame(
                [7, 7],
                [$params[$before] ?? null, $params[$before + 1] ?? null],
                $method.' binds the wrong values at its ACL placeholders'
            );
        }
    }

    /**
     * Every public method whose signature takes a `User`, with arguments
     * synthesised from the parameter types.
     *
     * The values are deliberately unlike the user id (7), so a method binding
     * one of its own arguments where the ACL pair belongs fails rather than
     * coincidentally matching.
     *
     * @return array<string, array{string, list<mixed>}>
     */
    public static function aclMethodProvider(): array
    {
        $reflection = new \ReflectionClass(FeedRepository::class);

        $cases = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || $method->getDeclaringClass()->getName() !== FeedRepository::class) {
                continue;
            }

            $takesUser = false;
            $args = [];
            $n = 0;

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';

                if ($name === User::class) {
                    $takesUser = true;
                    // Replaced with the real user by fillUser(); a marker is
                    // used so the provider stays a plain data array.
                    $args[] = '__user__';
                    continue;
                }

                $args[] = match ($name) {
                    'int' => 100 + $n++,
                    'string' => 'arg'.$n++,
                    'array' => [200 + $n++, 300 + $n++],
                    'bool' => false,
                    default => null,
                };
            }

            if (! $takesUser) {
                continue;
            }

            // findSibling interpolates two of its arguments straight into the
            // SQL, so they have to be the literals the caller passes.
            if ($method->getName() === 'findSibling') {
                $args[2] = '<';
                $args[3] = 'DESC';
            }

            $cases[$method->getName()] = [$method->getName(), $args];
        }

        // A guard against the discovery silently finding nothing - the whole
        // point is that the list is not maintained by hand.
        self::assertGreaterThan(20, count($cases));
        self::assertArrayHasKey('search', $cases);

        return $cases;
    }

    /** @param list<mixed> $args */
    private function fillUser(array $args, User $user): array
    {
        return array_map(
            static fn (mixed $arg): mixed => $arg === '__user__' ? $user : $arg,
            $args
        );
    }

    public function testAdminQueriesBindNoAclParamsAtAll(): void
    {
        $captured = null;
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchOne')->willReturnCallback(
            function (string $_sql, array $params) use (&$captured): ?array {
                $captured = $params;

                return null;
            }
        );

        (new FeedRepository($db))->findById(90, $this->user(1, role: AccessService::ROLE_ADMIN));

        // Not "[90, 1, 1]" - the admin branch contributes nothing, so a query
        // that still bound an id would mean the override wasn't taken.
        $this->assertSame([90], $captured);
    }

    /**
     * search() is the one method that binds *after* applyAcl() - the cursor
     * params are pushed on last - so it is the single place where the "ACL pair
     * is at the end" rule does not hold. Pinned separately so that fixing one
     * doesn't silently break the other.
     */
    public function testSearchBindsTheCursorAfterTheAclPair(): void
    {
        $captured = null;
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $_sql, array $params) use (&$captured): array {
                $captured = $params;

                return [];
            }
        );

        (new FeedRepository($db))->search(
            'книга',
            10,
            (object) ['priority' => 1, 'rank' => 2.5, 'id' => 58],
            $this->user(7)
        );

        // Ranking and eligibility parameters, then ACL, then the complete cursor.
        $this->assertSame(['книга', 'книга', 'книга', 'книга', 'книга', 'книга', 7, 7, 1, '2.500000', 58], $captured);
    }

    public function testListOrdersByPrimaryKey(): void
    {
        $capturedSql = null;
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            }
        );

        (new FeedRepository($db))->list($this->user(1, role: AccessService::ROLE_ADMIN));

        $this->assertStringContainsString('ORDER BY f.id DESC', (string) $capturedSql);
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>, list<mixed>}>
     */
    public static function indexedListProvider(): array
    {
        return [
            'id' => [
                ['id' => 90],
                ['f.id = ?', 'ORDER BY f.id DESC'],
                [90],
            ],
            'slug' => [
                ['slug' => 'book-one'],
                ['f.slug = ?', 'ORDER BY f.id DESC'],
                ['book-one'],
            ],
            'parent and type by position' => [
                ['parentId' => 58, 'type' => 'chapter'],
                ['f.parent_id = ?', 'AND f.type = ?', 'ORDER BY f.position'],
                [58, 'chapter'],
            ],
            'parent and type by created' => [
                ['parentId' => 58, 'type' => 'chapter', 'sort' => 'created_desc'],
                ['f.parent_id = ?', 'AND f.type = ?', 'ORDER BY f.created_at DESC, f.id DESC'],
                [58, 'chapter'],
            ],
            'parent' => [
                ['parentId' => 58],
                ['f.parent_id = ?', 'ORDER BY f.position'],
                [58],
            ],
            'owner' => [
                ['ownerId' => 7],
                ['f.owner_id = ?', 'ORDER BY f.id DESC'],
                [7],
            ],
            'title' => [
                ['title' => 'мастер'],
                ['f.title LIKE ?', 'ORDER BY f.created_at DESC, f.id DESC'],
                ['%мастер%'],
            ],
            'title and type' => [
                ['title' => 'мастер', 'type' => 'publication'],
                ['f.title LIKE ?', 'AND f.type = ?', 'ORDER BY f.created_at DESC, f.id DESC'],
                ['%мастер%', 'publication'],
            ],
            'type by created' => [
                ['type' => 'publication'],
                ['f.type = ?', 'ORDER BY f.created_at DESC, f.id DESC'],
                ['publication'],
            ],
            'type by rating' => [
                ['type' => 'publication', 'sort' => 'rating_desc'],
                ['f.type = ?', 'ORDER BY f.rating_avg DESC, f.id DESC'],
                ['publication'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @param list<string> $sqlFragments
     * @param list<mixed> $expectedParams
     */
    #[DataProvider('indexedListProvider')]
    public function testListBuildsOnlyIndexedConditions(array $args, array $sqlFragments, array $expectedParams): void
    {
        $capturedSql = null;
        $capturedParams = null;
        $db = $this->createStub(PdoDatabase::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, array $params) use (&$capturedSql, &$capturedParams): array {
                $capturedSql = $sql;
                $capturedParams = $params;

                return [];
            }
        );

        (new FeedRepository($db))->list($this->user(1, role: AccessService::ROLE_ADMIN), ...$args);

        foreach ($sqlFragments as $fragment) {
            $this->assertStringContainsString($fragment, (string) $capturedSql);
        }

        $this->assertSame($expectedParams, $capturedParams);
    }

    /** The cursor includes title priority, fixed-precision relevance, and id. */
    public function testSearchCursorComparesTheWholeSortTuple(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(
                    static fn (string $sql): bool
                        => str_contains($sql, 'HAVING (search_priority, relevance, id) < (?, ?, ?)')
                        && str_contains($sql, 'ORDER BY search_priority DESC, relevance DESC, id DESC')
                ),
                ['книга', 'книга', 'книга', 'книга', 'книга', 'книга', 1, '2.500000', 58]
            )
            ->willReturn([]);

        $repository = new FeedRepository($db);

        $repository->search(
            'книга',
            10,
            (object) ['priority' => 1, 'rank' => 2.5, 'id' => 58],
            new User(id: 1, email: 'admin@example.com', role: AccessService::ROLE_ADMIN)
        );
    }

    public function testSearchWithoutACursorHasNoHavingClause(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(static fn (string $sql): bool => ! str_contains($sql, 'HAVING')),
                ['книга', 'книга', 'книга', 'книга', 'книга', 'книга']
            )
            ->willReturn([]);

        $repository = new FeedRepository($db);

        $repository->search('книга', 10, null, new User(id: 1, email: 'admin@example.com', role: AccessService::ROLE_ADMIN));
    }

    public function testFindChildIdsAtPositionsReturnsEmptyArrayForEmptyInput(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('fetchAll');

        $repository = new FeedRepository($db);

        $this->assertSame([], $repository->findChildIdsAtPositions([], 'chapter'));
    }

    public function testFindChildIdsAtPositionsReturnsIdsKeyedByParentId(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(
                    static fn (string $sql): bool => str_contains($sql, 'type = ?')
                        && str_contains($sql, '(parent_id = ? AND position = ?)')
                ),
                ['chapter', 58, 0, 62, 1]
            )
            ->willReturn([
                ['id' => 59, 'parent_id' => 58],
                ['id' => 71, 'parent_id' => 62],
            ]);

        $repository = new FeedRepository($db);

        $this->assertSame(
            [
                58 => 59,
                62 => 71,
            ],
            $repository->findChildIdsAtPositions([58 => 0, 62 => 1], 'chapter')
        );
    }

    public function testFindLastChildPositionsReturnsEmptyArrayForEmptyInput(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db->expects($this->never())->method('fetchAll');

        $repository = new FeedRepository($db);

        $this->assertSame([], $repository->findLastChildPositions([], 'chapter'));
    }

    public function testFindLastChildPositionsReturnsMaxPositionPerParent(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->callback(
                    static fn (string $sql): bool => str_contains($sql, 'type = ?')
                        && str_contains($sql, 'GROUP BY parent_id')
                        && str_contains($sql, 'parent_id IN (?)')
                ),
                ['chapter', 58]
            )
            ->willReturn([
                ['parent_id' => 58, 'last_position' => 12],
            ]);

        $repository = new FeedRepository($db);

        $this->assertSame([58 => 12], $repository->findLastChildPositions([58], 'chapter'));
    }

    public function testDeleteExecutesDeleteById(): void
    {
        $db = $this->createMock(PdoDatabase::class);
        $db
            ->expects($this->once())
            ->method('execute')
            ->with('DELETE FROM feeds WHERE id = ?', [902]);

        $repository = new FeedRepository($db);

        $repository->delete(902);
    }
}

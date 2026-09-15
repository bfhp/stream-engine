<?php

declare(strict_types=1);

namespace Tests\Modules\Users;

use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use StreamEngine\Core\Config;
use StreamEngine\Core\Formatter;
use StreamEngine\Core\PageTree;
use StreamEngine\Core\PdoDatabase;
use StreamEngine\Core\QueryParams;
use StreamEngine\Core\RequestContext;
use StreamEngine\Core\TranslationManager;
use StreamEngine\Core\UrlGenerator;
use StreamEngine\Domain\FeedTerm;
use StreamEngine\Domain\Page;
use StreamEngine\Domain\User;
use StreamEngine\Modules\Users\UsersController;
use StreamEngine\Repository\NotificationDeliveryRepository;
use StreamEngine\Repository\NotificationPreferenceRepository;
use StreamEngine\Repository\UserRepository;
use StreamEngine\Repository\UserSessionRepository;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\FeedService;
use StreamEngine\Service\MailService;
use StreamEngine\Service\MessageService;
use StreamEngine\Service\NotificationService;
use StreamEngine\Service\UserService;
use Tests\Support\ArrayCache;
use Tests\Support\FakeFeedRepository;

/**
 * The community page's view builders - the tag cloud and the two member lists.
 *
 * Pure shaping: rows in, arrays for Twig out. They were untested because they
 * are private and live on a 3000-line controller, not because they need
 * anything - which is the same reason the library's HTML pipeline was
 * untested.
 *
 * The rules worth pinning are the ones a reader would notice and a developer
 * would not. The cloud normalises font sizes against the *observed* range, so
 * its behaviour when every tag is equally popular is a real question with a
 * non-obvious answer. And the two member lists label roles **differently** -
 * the public one is deliberately quieter than the moderator's - which is easy
 * to "tidy up" into a single helper and thereby leak who is a mere subscriber
 * onto the public page.
 */
final class UsersControllerCommunityCardsTest extends TestCase
{
    private const string COMMUNITY_URL = '/communities/devs/';

    private function makeModule(?FeedService $feedService = null, ?PdoDatabase $db = null): UsersController
    {
        $db ??= $this->createStub(PdoDatabase::class);

        $reflection = new ReflectionClass(UsersController::class);
        $module = $reflection->newInstanceWithoutConstructor();
        $tm = new TranslationManager('ru', 'en');

        $reflection->getParentClass()->getProperty('db')->setValue($module, $db);
        $reflection->getParentClass()->getProperty('context')->setValue(
            $module,
            new RequestContext(new User(0, '', AccessService::ROLE_USER), new DateTimeZone('UTC'), QueryParams::fromGlobals())
        );

        $userShowPage = $this->userShowPage();
        $pageTree = new PageTree([$userShowPage]);

        $reflection->getProperty('pageTree')->setValue($module, $pageTree);
        $reflection->getProperty('urlGenerator')->setValue(
            $module,
            new UrlGenerator($pageTree, new FakeFeedRepository([]), new ArrayCache())
        );
        $reflection->getProperty('userService')->setValue(
            $module,
            new UserService(
                new UserRepository($db),
                $db,
                (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
                $tm,
                new NotificationService(
                    (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor(),
                    new NotificationDeliveryRepository($db),
                    new NotificationPreferenceRepository($db),
                    new UserRepository($db),
                    (new ReflectionClass(MailService::class))->newInstanceWithoutConstructor(),
                    translationManager: new \StreamEngine\Core\TranslationManager('ru', 'en'),
                    config: new \StreamEngine\Core\Config(['SITE_URL' => 'https://example.test']),
                ),
                new UserSessionRepository($db),
                new Config([]),
            )
        );
        $reflection->getProperty('tm')->setValue($module, $tm);
        $reflection->getProperty('formatter')->setValue($module, new Formatter($tm, 'ru'));

        if ($feedService !== null) {
            $reflection->getProperty('feedService')->setValue($module, $feedService);
        }

        return $module;
    }

    private function userShowPage(): Page
    {
        // parentId null, like the fixture UsersControllerTest uses for this
        // page: the profile route sits at the site root, so `/anton/` is the
        // whole URL.
        return new Page(
            id: 50,
            parentId: null,
            pattern: '{username}',
            pageName: 'Профиль',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'user.show',
        );
    }

    private function call(UsersController $module, string $method, mixed ...$args): mixed
    {
        return (new ReflectionClass(UsersController::class))->getMethod($method)->invoke($module, ...$args);
    }

    /** One `getTopTermsByVocabulary()` row. */
    private function tag(string $name, string $slug, int $count): array
    {
        return [
            'term' => new FeedTerm(id: 1, vocabulary: 'tag', name: $name, slug: $slug),
            'feedCount' => $count,
        ];
    }

    private function feedServiceReturning(array $terms): FeedService
    {
        $feedService = $this->createStub(FeedService::class);
        $feedService->method('getTopTermsByVocabulary')->willReturn($terms);

        return $feedService;
    }

    /* ===============================
       The tag cloud
    =============================== */

    public function testAnEmptyVocabularyIsAnEmptyCloud(): void
    {
        // The early return matters: min() of an empty array is a fatal, and a
        // community with no tagged posts is the normal state of a new one.
        $module = $this->makeModule($this->feedServiceReturning([]));

        $this->assertSame([], $this->call($module, 'buildCommunityTagCloud', self::COMMUNITY_URL, new User(0, '', AccessService::ROLE_USER)));
    }

    public function testEachTagCarriesItsNameSlugAndFilterUrl(): void
    {
        $module = $this->makeModule($this->feedServiceReturning([$this->tag('PHP', 'php', 5)]));

        $cloud = $this->call($module, 'buildCommunityTagCloud', self::COMMUNITY_URL, new User(0, '', AccessService::ROLE_USER));

        $this->assertSame('PHP', $cloud[0]['name']);
        $this->assertSame('php', $cloud[0]['slug']);
        // The cloud links back to the community filtered by tag, not to the
        // site-wide tag page - staying inside the community is the point.
        $this->assertSame('/communities/devs/?tag=php', $cloud[0]['url']);
    }

    public function testTheSlugIsUrlEncodedInTheFilterLink(): void
    {
        // Tag slugs keep their letters (Formatter::unicodeSlug), so a Cyrillic
        // slug reaches this as-is and has to be encoded for the query string.
        $module = $this->makeModule($this->feedServiceReturning([$this->tag('Веб', 'веб', 3)]));

        $cloud = $this->call($module, 'buildCommunityTagCloud', self::COMMUNITY_URL, new User(0, '', AccessService::ROLE_USER));

        $this->assertSame('/communities/devs/?tag='.rawurlencode('веб'), $cloud[0]['url']);
        $this->assertStringNotContainsString('веб', $cloud[0]['url']);
    }

    public function testTheRarestTagGetsTheSmallestFontAndTheCommonestTheLargest(): void
    {
        $module = $this->makeModule($this->feedServiceReturning([
            $this->tag('Часто', 'often', 100),
            $this->tag('Средне', 'mid', 50),
            $this->tag('Редко', 'rare', 1),
        ]));

        $cloud = $this->call($module, 'buildCommunityTagCloud', self::COMMUNITY_URL, new User(0, '', AccessService::ROLE_USER));

        // Normalised against the observed range, not an absolute scale - so
        // the extremes always land exactly on the configured bounds.
        $this->assertSame(1.9, $cloud[0]['fontSize']);
        $this->assertSame(0.85, $cloud[2]['fontSize']);
        $this->assertGreaterThan(0.85, $cloud[1]['fontSize']);
        $this->assertLessThan(1.9, $cloud[1]['fontSize']);
    }

    public function testTheOrderTheServiceGaveIsKept(): void
    {
        // array_map preserves order, and the service already sorted by count -
        // the cloud does not re-sort, so the template renders them as ranked.
        $module = $this->makeModule($this->feedServiceReturning([
            $this->tag('A', 'a', 9),
            $this->tag('B', 'b', 3),
        ]));

        $cloud = $this->call($module, 'buildCommunityTagCloud', self::COMMUNITY_URL, new User(0, '', AccessService::ROLE_USER));

        $this->assertSame(['A', 'B'], array_column($cloud, 'name'));
    }

    /**
     * The division-by-zero guard, and the one answer worth arguing about: when
     * every tag is equally popular the range is zero, so weight is forced to
     * `1.0` and *everything* renders at the maximum size. The alternative
     * (`0.0`, everything smallest) would be equally defensible; what matters
     * is that neither is a crash, and that a brand-new community with two
     * equally-used tags does not show a cloud of identical tiny text.
     */
    public function testWhenEveryTagIsEquallyPopularTheyAllRenderLargest(): void
    {
        $module = $this->makeModule($this->feedServiceReturning([
            $this->tag('A', 'a', 4),
            $this->tag('B', 'b', 4),
        ]));

        $cloud = $this->call($module, 'buildCommunityTagCloud', self::COMMUNITY_URL, new User(0, '', AccessService::ROLE_USER));

        $this->assertSame([1.9, 1.9], array_column($cloud, 'fontSize'));
    }

    public function testASingleTagIsAlsoTheEqualCase(): void
    {
        $module = $this->makeModule($this->feedServiceReturning([$this->tag('A', 'a', 7)]));

        $cloud = $this->call($module, 'buildCommunityTagCloud', self::COMMUNITY_URL, new User(0, '', AccessService::ROLE_USER));

        $this->assertSame(1.9, $cloud[0]['fontSize']);
    }

    public function testFontSizesAreRoundedForTheStylesheet(): void
    {
        // Two decimals: these go straight into a `style="font-size: …rem"`
        // attribute, and an unrounded float would print 17 digits of noise.
        $module = $this->makeModule($this->feedServiceReturning([
            $this->tag('A', 'a', 3),
            $this->tag('B', 'b', 1),
            $this->tag('C', 'c', 2),
        ]));

        foreach ($this->call($module, 'buildCommunityTagCloud', self::COMMUNITY_URL, new User(0, '', AccessService::ROLE_USER)) as $tag) {
            $this->assertSame($tag['fontSize'], round($tag['fontSize'], 2));
        }
    }

    /* ===============================
       The public member list
    =============================== */

    /** One row as the membership query returns it. */
    private function member(array $overrides = []): array
    {
        return array_merge([
            'id' => 7,
            'username' => 'anton',
            'nick' => 'Антон',
            'avatarUrl' => '',
            'roleLevel' => 1,
        ], $overrides);
    }

    public function testNoMembersIsNoCards(): void
    {
        $this->assertSame([], $this->call($this->makeModule(), 'buildCommunityMemberCards', []));
    }

    public function testAMemberCardCarriesTheirNameAndProfileLink(): void
    {
        $cards = $this->call($this->makeModule(), 'buildCommunityMemberCards', [$this->member()]);

        $this->assertSame('Антон', $cards[0]['displayName']);
        $this->assertSame('/anton/', $cards[0]['url']);
    }

    public function testAMemberWithNoNickIsShownByIdRatherThanBlank(): void
    {
        // An empty nick is allowed, and a card with no name at all reads as a
        // rendering bug.
        $cards = $this->call($this->makeModule(), 'buildCommunityMemberCards', [$this->member(['nick' => ''])]);

        $this->assertSame('#7', $cards[0]['displayName']);
    }

    public function testAMemberWithNoUsernameGetsNoLink(): void
    {
        // Not a link to nowhere: the profile route needs a username, and
        // `/` + empty would resolve to the site root.
        $cards = $this->call($this->makeModule(), 'buildCommunityMemberCards', [$this->member(['username' => ''])]);

        $this->assertNull($cards[0]['url']);
    }

    /**
     * @return array<string, array{int, ?string}>
     */
    public static function publicRoleProvider(): array
    {
        return [
            'owner' => [3, 'Владелец'],
            'moderator' => [2, 'Модератор'],
            // Deliberately unlabelled: on the public list, being a plain
            // member or a subscriber is not anybody's business.
            'member' => [1, null],
            'subscriber' => [0, null],
        ];
    }

    #[DataProvider('publicRoleProvider')]
    public function testThePublicListOnlyNamesTheRolesThatCarryAuthority(int $roleLevel, ?string $expected): void
    {
        $cards = $this->call(
            $this->makeModule(),
            'buildCommunityMemberCards',
            [$this->member(['roleLevel' => $roleLevel])]
        );

        $this->assertSame($expected, $cards[0]['roleLabel']);
    }

    /* ===============================
       The moderator's member list
    =============================== */

    public function testTheManageListCarriesTheIdBecauseItsButtonsNeedIt(): void
    {
        // The public list has no id: the manage page's promote/remove calls
        // are the only thing that needs one, and not shipping it to a public
        // page is one less identifier to leak.
        $cards = $this->call($this->makeModule(), 'buildCommunityManageMemberCards', [$this->member()]);

        $this->assertSame(7, $cards[0]['id']);
        $this->assertArrayNotHasKey(
            'id',
            $this->call($this->makeModule(), 'buildCommunityMemberCards', [$this->member()])[0]
        );
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function manageRoleProvider(): array
    {
        return [
            'owner' => [3, 'Владелец'],
            'moderator' => [2, 'Модератор'],
            // Where the two lists part company: every role is named here,
            // because the moderator is choosing what to do about each one.
            'member' => [1, 'Участник'],
            'subscriber' => [0, 'Подписчик'],
            // Anything unexpected reads as the lowest role rather than as a
            // blank cell.
            'unknown' => [99, 'Подписчик'],
        ];
    }

    #[DataProvider('manageRoleProvider')]
    public function testTheManageListNamesEveryRole(int $roleLevel, string $expected): void
    {
        $cards = $this->call(
            $this->makeModule(),
            'buildCommunityManageMemberCards',
            [$this->member(['roleLevel' => $roleLevel])]
        );

        $this->assertSame($expected, $cards[0]['roleLabel']);
    }

    public function testNoRowsIsNoManageCards(): void
    {
        $this->assertSame([], $this->call($this->makeModule(), 'buildCommunityManageMemberCards', []));
    }

    public function testTheManageListFallsBackToTheIdForANamelessMemberToo(): void
    {
        $cards = $this->call(
            $this->makeModule(),
            'buildCommunityManageMemberCards',
            [$this->member(['nick' => ''])]
        );

        $this->assertSame('#7', $cards[0]['displayName']);
    }

    /**
     * Both lists resolve the avatar through `UserService`, so a member with no
     * avatar gets the shared placeholder rather than an empty `src` - which
     * renders as a broken-image icon in every row.
     */
    public function testAnAvatarIsAlwaysResolvedToSomething(): void
    {
        $module = $this->makeModule();

        foreach (['buildCommunityMemberCards', 'buildCommunityManageMemberCards'] as $method) {
            $cards = $this->call($module, $method, [$this->member(['avatarUrl' => ''])]);

            $this->assertNotSame('', $cards[0]['avatarUrl'], $method.' must resolve an empty avatar');
        }
    }

    public function testEveryRowBecomesACard(): void
    {
        $rows = [
            $this->member(['id' => 1, 'username' => 'a']),
            $this->member(['id' => 2, 'username' => 'b']),
            $this->member(['id' => 3, 'username' => 'c']),
        ];

        $this->assertCount(3, $this->call($this->makeModule(), 'buildCommunityMemberCards', $rows));
        $this->assertCount(3, $this->call($this->makeModule(), 'buildCommunityManageMemberCards', $rows));
    }
}

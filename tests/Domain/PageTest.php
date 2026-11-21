<?php

declare(strict_types=1);

namespace Tests\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Domain\Page;
use StreamEngine\Service\AccessService;

/**
 * `Page::allowsMethod()` - the 405 guard, moved off `StreamEngine`.
 *
 * It is the first thing `handleRequest()` asks about an API route, and it runs
 * before the controller is built, before CSRF and before any handler sees the
 * request. Every "unsupported method" test in the controller suites is written
 * against the assumption that this refuses first; this is where that
 * assumption is actually checked.
 *
 * It also fixes something on the way over. The call site was
 * `in_array($_SERVER['REQUEST_METHOD'], $page->requestMethods, true)`, and
 * `$requestMethods` is declared `?array` - so a page row with no methods was a
 * TypeError, i.e. a 500 from the guard whose job is to answer 405. Nothing
 * produces such a row today, which is precisely why nobody would have noticed.
 */
final class PageTest extends TestCase
{
    public function testRuntimePageUsesSafeHtmlGetDefaults(): void
    {
        $settings = (object) [
            'layout' => 'compact',
            'commentsEnabled' => true,
            'relatedArticlesEnabled' => true,
        ];

        $page = Page::runtime(
            id: 20,
            parentId: 10,
            pattern: '{slug}',
            action: 'article.show',
            accessRule: AccessService::ACCESS_PUBLIC,
            feedType: 'article',
            changefreq: 'weekly',
            settings: $settings,
            pageName: 'Article',
        );

        self::assertSame(['GET'], $page->requestMethods);
        self::assertSame('html', $page->responseType);
        self::assertSame(AccessService::ACCESS_PUBLIC, $page->accessRule);
        self::assertTrue($page->commentsEnabled());
        self::assertTrue($page->relatedArticlesEnabled());
        self::assertSame('article', $page->feedType);
        self::assertSame('weekly', $page->changefreq);
        self::assertSame($settings, $page->settings);
        self::assertSame('Article', $page->pageName);
    }

    public function testHiddenWidgetsDefaultToNoneAndIgnoreMalformedValues(): void
    {
        self::assertSame([], $this->page(['GET'])->hiddenWidgets());

        $page = Page::runtime(
            1,
            0,
            'x',
            'article.show',
            AccessService::ACCESS_PUBLIC,
            null,
            null,
            (object) ['hiddenWidgets' => ['sidebar_top', 7, 'footer_legal']]
        );
        self::assertSame(['sidebar_top', 'footer_legal'], $page->hiddenWidgets());

        $page = Page::runtime(
            1,
            0,
            'x',
            'article.show',
            AccessService::ACCESS_PUBLIC,
            null,
            null,
            (object) ['hiddenWidgets' => 'sidebar_top']
        );
        self::assertSame([], $page->hiddenWidgets());
    }

    public function testRuntimePagesInheritHiddenWidgetsFromTheNearestAdminPage(): void
    {
        $home = $this->stored(1, null, []);
        $profile = $this->stored(2, 1, ['sidebar_top']);
        $post = $this->runtimePage(3, 2, null);
        $postEdit = $this->runtimePage(4, 3, null);

        // user.show hides sidebar_top: its post and the post's editor follow.
        self::assertSame(['sidebar_top'], Page::hiddenWidgetsAlong([$home, $profile, $post]));
        self::assertSame(['sidebar_top'], Page::hiddenWidgetsAlong([$home, $profile, $post, $postEdit]));
        // The admin page itself is unchanged.
        self::assertSame(['sidebar_top'], Page::hiddenWidgetsAlong([$home, $profile]));
    }

    public function testAdminPagesNeverInheritAndRuntimePagesMayOverride(): void
    {
        $profile = $this->stored(2, 1, ['sidebar_top']);

        // An admin-managed child keeps its own choice, even "show everything".
        self::assertSame([], Page::hiddenWidgetsAlong([$profile, $this->stored(5, 2, null)]));
        // A runtime page that states its own list keeps it.
        self::assertSame(
            ['footer_legal'],
            Page::hiddenWidgetsAlong([$profile, $this->runtimePage(3, 2, (object) ['hiddenWidgets' => ['footer_legal']])])
        );
        // A runtime page whose settings carry other flags still inherits.
        self::assertSame(
            ['sidebar_top'],
            Page::hiddenWidgetsAlong([$profile, $this->runtimePage(3, 2, (object) ['commentsEnabled' => true])])
        );
        self::assertSame([], Page::hiddenWidgetsAlong([]));
    }

    /** @param list<string>|null $hidden */
    private function stored(int $id, ?int $parentId, ?array $hidden): Page
    {
        return new Page(
            id: $id,
            parentId: $parentId,
            pattern: 'p'.$id,
            pageName: null,
            settings: $hidden === null ? null : (object) ['hiddenWidgets' => $hidden],
            feedType: null,
            listFeedType: null,
            feedId: null,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
        );
    }

    private function runtimePage(int $id, int $parentId, ?object $settings): Page
    {
        return Page::runtime($id, $parentId, 'r'.$id, 'user.post-show-slug', AccessService::ACCESS_PUBLIC, null, null, $settings);
    }

    /**
     * @param list<string>|null $methods
     */
    private function page(?array $methods): Page
    {
        return new Page(
            id: 1,
            parentId: null,
            pattern: 'stub',
            pageName: 'Stub',
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            requestMethods: $methods,
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
        );
    }

    public function testADeclaredMethodIsAllowed(): void
    {
        $this->assertFalse($this->page(['GET'])->relatedArticlesEnabled());
        $this->assertTrue($this->page(['GET', 'POST'])->allowsMethod('POST'));
    }

    public function testAnUndeclaredMethodIsNot(): void
    {
        $this->assertFalse($this->page(['GET', 'POST'])->allowsMethod('DELETE'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function caseProvider(): array
    {
        return [
            'lower case' => ['get'],
            'mixed case' => ['Get'],
            'padded' => [' GET'],
        ];
    }

    /**
     * Strict, and case-sensitive. HTTP verbs are uppercase by specification and
     * `$_SERVER['REQUEST_METHOD']` gives them that way, so a lower-case verb is
     * not a browser being lenient - it is something hand-rolled, and refusing
     * it is right.
     */
    #[DataProvider('caseProvider')]
    public function testTheComparisonIsExact(string $method): void
    {
        $this->assertFalse($this->page(['GET'])->allowsMethod($method));
    }

    /**
     * The `?? []` that replaced a TypeError. "No methods declared" has to read
     * as "no methods allowed": for a guard, silence is refusal.
     */
    public function testAPageThatDeclaresNoMethodsAllowsNone(): void
    {
        $page = $this->page(null);

        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
            $this->assertFalse($page->allowsMethod($method), $method.' must be refused');
        }
    }

    public function testAnEmptyListAlsoAllowsNothing(): void
    {
        $this->assertFalse($this->page([])->allowsMethod('GET'));
    }

    /**
     * Every route registered through `Page::api()` names its verbs, and the
     * factory is where that is guaranteed - so this is the shape the guard
     * actually meets in production.
     */
    public function testAnApiPageAnswersOnlyWhatItRegistered(): void
    {
        $page = Page::api(
            id: 2,
            parentId: 1,
            pattern: 'conversations',
            requestMethods: ['GET', 'POST'],
            action: 'conversations.list',
        );

        $this->assertTrue($page->allowsMethod('GET'));
        $this->assertTrue($page->allowsMethod('POST'));
        $this->assertFalse($page->allowsMethod('PUT'));
        $this->assertFalse($page->allowsMethod('DELETE'));
    }

    /**
     * HEAD is not GET here. Worth recording rather than fixing: a HEAD request
     * to an API route gets a 405, which is unusual but harmless (no client
     * makes one) and changing it would mean deciding what a HEAD response body
     * should be.
     */
    public function testHeadIsNotTreatedAsGet(): void
    {
        $this->assertFalse($this->page(['GET'])->allowsMethod('HEAD'));
    }
}

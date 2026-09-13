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
            commentsEnabled: false,
            requestMethods: $methods,
            responseType: 'json',
            accessRule: AccessService::ACCESS_PUBLIC,
        );
    }

    public function testADeclaredMethodIsAllowed(): void
    {
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

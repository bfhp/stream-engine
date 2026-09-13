<?php

declare(strict_types=1);

namespace Tests\Service;

use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StreamEngine\Core\Exceptions\NotFoundException;
use StreamEngine\Domain\Page;
use StreamEngine\Service\AccessService;
use StreamEngine\Service\BreadcrumbsService;
use StreamEngine\View\Breadcrumb;

/**
 * Fifteen lines that turn a list of page slugs into the links above every page.
 *
 * The subtlety worth writing down is where the leading slash comes from: it is
 * not in this class at all. Router::resolve() prepends an empty segment to
 * every URI, so the first page in the chain is always the root, whose slug is
 * `''` - and `'' . '/'` is what makes the first path absolute and every later
 * one absolute by inheritance. Nothing here enforces that; the invariant lives
 * one class away, and the failure if it broke would be relative hrefs that
 * resolve against the current page.
 */
final class BreadcrumbsServiceTest extends TestCase
{
    /** @param list<string> $slugs */
    private function finalizeChain(array $slugs): array
    {
        $service = new BreadcrumbsService();

        foreach ($slugs as $slug) {
            $service->add(new Breadcrumb('Заголовок '.$slug, $slug));
        }

        return array_map(
            static fn (Breadcrumb $breadcrumb): string => $breadcrumb->url,
            $service->finalize()
        );
    }

    public function testEachCrumbCarriesThePathUpToAndIncludingItself(): void
    {
        // The root's empty slug is what supplies the leading slash.
        $this->assertSame(
            ['/', '/forum/', '/forum/php/', '/forum/php/topic-1/'],
            $this->finalizeChain(['', 'forum', 'php', 'topic-1'])
        );
    }

    public function testTheRootAloneIsTheSiteRoot(): void
    {
        // The home page: one crumb, and its link is '/'.
        $this->assertSame(['/'], $this->finalizeChain(['']));
    }

    /**
     * Without the router's empty leading segment the paths come out relative,
     * which in an `href` resolves against the current URL rather than the site
     * root. Asserted so the dependency on Router::resolve() is visible here
     * rather than only discoverable by breaking it.
     */
    public function testWithoutTheRootCrumbThePathsAreRelative(): void
    {
        $this->assertSame(['forum/', 'forum/php/'], $this->finalizeChain(['forum', 'php']));
    }

    public function testAnEmptyTrailIsEmptyRatherThanASingleSlash(): void
    {
        // Not every page has a trail; the template iterates and renders
        // nothing.
        $this->assertSame([], (new BreadcrumbsService())->finalize());
    }

    /**
     * finalize() mutates the Breadcrumb objects it was given and hands the same
     * instances back - callers (StreamEngine) pass the result straight to Twig,
     * and the controllers that built them keep no other reference, so this is
     * observable only as "the objects you added are the objects you get".
     */
    public function testTheAddedInstancesAreTheOnesReturned(): void
    {
        $service = new BreadcrumbsService();

        $root = new Breadcrumb('Главная', '');
        $forum = new Breadcrumb('Форум', 'forum');

        $service->add($root);
        $service->add($forum);

        $finalized = $service->finalize();

        $this->assertSame([$root, $forum], $finalized);
        $this->assertSame('/forum/', $forum->url);
    }

    public function testTheOrderCrumbsWereAddedInIsThePathOrder(): void
    {
        // Reversing the chain reverses the path - there is no sorting here, so
        // the caller's iteration order is the whole of the structure.
        $this->assertSame(
            ['/', '/php/', '/php/forum/'],
            $this->finalizeChain(['', 'php', 'forum'])
        );
    }

    /**
     * Recomputed from the slugs each time rather than appended to, so a second
     * call cannot double the path. StreamEngine calls it once today; this is
     * what makes that not load-bearing.
     */
    public function testFinalizingTwiceGivesTheSameUrls(): void
    {
        $service = new BreadcrumbsService();
        $service->add(new Breadcrumb('Главная', ''));
        $service->add(new Breadcrumb('Форум', 'forum'));

        $first = array_map(static fn (Breadcrumb $b): string => $b->url, $service->finalize());
        $second = array_map(static fn (Breadcrumb $b): string => $b->url, $service->finalize());

        $this->assertSame($first, $second);
    }

    /**
     * `Breadcrumb::$url` is a typed property with no default, so reading it
     * before finalize() is an Error rather than an empty string - and the
     * template reads `breadcrumb.url` on every item. Adding crumbs and skipping
     * finalize() is therefore a 500, not a page with dead links; pinned because
     * the fix ("give it a default of ''") would silently turn that into the
     * quieter, worse failure.
     */
    public function testAnUnfinalizedCrumbHasNoUrlAtAll(): void
    {
        $breadcrumb = new Breadcrumb('Форум', 'forum');

        $this->expectException(Error::class);
        $this->expectExceptionMessage('must not be accessed before initialization');

        /** @noinspection PhpExpressionResultUnusedInspection */
        $breadcrumb->url;
    }

    /**
     * A slug that is empty anywhere but the root collapses two separators
     * together. No page has one today - patterns come from the `pages` table -
     * but the behaviour is arithmetic on strings with no guard, so it is
     * recorded rather than assumed away.
     */
    public function testAnEmptySlugMidChainDoublesTheSeparator(): void
    {
        $this->assertSame(['/', '//', '//forum/'], $this->finalizeChain(['', '', 'forum']));
    }

    /* ===============================
       addFor(): a crumb that fails
    =============================== */

    /**
     * A page as Router::resolve() leaves it: the pattern it was indexed under,
     * and the named captures from the segment that matched it.
     *
     * @param array<string, string> $params
     */
    private function page(string $pattern, array $params = [], ?string $pageName = 'Раздел'): Page
    {
        $page = new Page(
            id: 42,
            parentId: 1,
            pattern: $pattern,
            pageName: $pageName,
            settings: null,
            feedType: null,
            listFeedType: null,
            feedId: null,
            commentsEnabled: false,
            requestMethods: ['GET'],
            responseType: 'html',
            accessRule: AccessService::ACCESS_PUBLIC,
            action: 'catalog.section',
        );

        $page->params = $params;

        return $page;
    }

    /** Silences the error_log() a failing crumb writes, and hands it back. */
    private function captureLog(callable $run): string
    {
        $file = tempnam(sys_get_temp_dir(), 'crumb');
        $previous = ini_set('error_log', $file);

        try {
            $run();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $written = (string) file_get_contents($file);
        unlink($file);

        return $written;
    }

    public function testAWorkingCrumbIsUsedAsIs(): void
    {
        $service = new BreadcrumbsService();
        $crumb = new Breadcrumb('Магия', 'magiya');

        $service->addFor($this->page('{slug}', ['slug' => 'magiya']), static fn (): Breadcrumb => $crumb);

        $this->assertSame([$crumb], $service->finalize());
    }

    /**
     * The whole point. The trail is built *after* show() has returned a view,
     * outside the try/catch that wraps it - so a crumb that threw took down a
     * response whose actual content was fine. Twice, both times a
     * getBreadcrumb() resolving a slug that no longer exists.
     */
    public function testAThrowingCrumbDoesNotTakeThePageDown(): void
    {
        $service = new BreadcrumbsService();

        $this->captureLog(function () use ($service): void {
            $service->addFor(
                $this->page('{slug}', ['slug' => 'magiya']),
                static fn (): Breadcrumb => throw new NotFoundException('Section not found')
            );
        });

        $this->assertCount(1, $service->finalize());
    }

    /**
     * `Throwable`, not `Exception`: the commonest shape of this failure is not
     * a throw at all but reading a property off a nullable lookup that came
     * back null - UsersController::getBreadcrumb() does exactly that with
     * findPublicUserByUsername().
     */
    public function testAnErrorIsSurvivedAsWellAsAnException(): void
    {
        $service = new BreadcrumbsService();

        $this->captureLog(function () use ($service): void {
            $service->addFor(
                $this->page('{username}', ['username' => 'anton']),
                static fn (): Breadcrumb => throw new Error(
                    'Attempt to read property "nick" on null'
                )
            );
        });

        $this->assertSame('anton', $service->finalize()[0]->slug);
    }

    /**
     * Skipping the failed crumb would be the obvious fix and the wrong one:
     * finalize() accumulates the slugs before each crumb, so a missing one
     * shortens the path and every link *after* it points one level too high.
     */
    public function testTheFallbackKeepsTheLinksOfEveryLaterCrumbIntact(): void
    {
        $service = new BreadcrumbsService();

        $service->add(new Breadcrumb('Главная', ''));

        $this->captureLog(function () use ($service): void {
            $service->addFor(
                $this->page('{slug}', ['slug' => 'ezoterika']),
                static fn (): Breadcrumb => throw new NotFoundException('Section not found')
            );
        });

        $service->add(new Breadcrumb('Магия', 'magiya'));

        $this->assertSame(
            ['/', '/ezoterika/', '/ezoterika/magiya/'],
            array_map(static fn (Breadcrumb $b): string => $b->url, $service->finalize())
        );
    }

    public function testTheFailureIsWrittenToTheLog(): void
    {
        $service = new BreadcrumbsService();

        $written = $this->captureLog(function () use ($service): void {
            $service->addFor(
                $this->page('{slug}', ['slug' => 'ezoterika']),
                static fn (): Breadcrumb => throw new NotFoundException('Section not found')
            );
        });

        // Degrading quietly is the behaviour; degrading *silently* would mean
        // a broken getBreadcrumb() could sit there for months.
        $this->assertStringContainsString('Section not found', $written);
        $this->assertStringContainsString(NotFoundException::class, $written);
    }

    /**
     * A null crumb is a controller saying it has no place in the trail -
     * APIController returns one - and that is a choice rather than a failure,
     * so it is still skipped. API pages are never in an HTML trail, so the
     * path arithmetic above is not at risk from it.
     */
    public function testANullCrumbIsStillSkipped(): void
    {
        $service = new BreadcrumbsService();

        $service->addFor($this->page('v1'), static fn (): ?Breadcrumb => null);

        $this->assertSame([], $service->finalize());
    }

    /* ===============================
       fallbackFor(): the segment
    =============================== */

    /**
     * @return array<string, array{string, array<string, string>, string}>
     */
    public static function segmentProvider(): array
    {
        return [
            'a static pattern is already the segment' => ['forum', [], 'forum'],
            'a placeholder takes its matched value' => ['{slug}', ['slug' => 'magiya'], 'magiya'],
            'a constrained placeholder too' => ['{id:\d+}', ['id' => '42'], '42'],
            'a different name' => ['{username}', ['username' => 'anton'], 'anton'],
            // Router::compilePattern() only ever produces one capture per
            // segment today, but the substitution is per-placeholder rather
            // than "the first param wins".
            'two placeholders' => ['{year}-{month}', ['year' => '2026', 'month' => '08'], '2026-08'],
        ];
    }

    #[DataProvider('segmentProvider')]
    public function testTheFallbackReconstructsThePagesOwnUrlSegment(
        string $pattern,
        array $params,
        string $expected
    ): void {
        $this->assertSame($expected, BreadcrumbsService::fallbackFor($this->page($pattern, $params))->slug);
    }

    /**
     * An unmatched placeholder is left as written rather than dropped. It
     * cannot happen through Router::resolve() - the page is here because its
     * regex matched - but a visibly wrong `/catalog/{slug}/` is a better failure
     * than a link that silently points at `/catalog/`.
     */
    public function testAPlaceholderWithNoParamIsLeftInPlace(): void
    {
        $this->assertSame('{slug}', BreadcrumbsService::fallbackFor($this->page('{slug}'))->slug);
    }

    public function testTheFallbackTitleIsThePagesNameWhenItHasOne(): void
    {
        // The generic name from the `pages` row: worse than the book's own
        // title, better than showing the visitor a slug.
        $crumb = BreadcrumbsService::fallbackFor($this->page('{slug}', ['slug' => 'magiya']));

        $this->assertSame('Раздел', $crumb->title);
    }

    public function testWithoutAPageNameTheSegmentIsTheLabelToo(): void
    {
        $crumb = BreadcrumbsService::fallbackFor(
            $this->page('{slug}', ['slug' => 'magiya'], null)
        );

        $this->assertSame('magiya', $crumb->title);
    }
}

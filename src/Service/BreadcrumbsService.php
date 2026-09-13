<?php

declare(strict_types=1);

namespace StreamEngine\Service;

use StreamEngine\Domain\Page;
use StreamEngine\View\Breadcrumb;
use Throwable;

class BreadcrumbsService
{
    /** @var Breadcrumb[] */
    private array $breadcrumbs = [];

    public function add(Breadcrumb $breadcrumb): void
    {
        $this->breadcrumbs[] = $breadcrumb;
    }

    /**
     * Resolve one page's crumb, and survive the attempt failing.
     *
     * The trail is built *after* `show()` has already succeeded and returned
     * a view - it is decoration on a page that has otherwise rendered. It was
     * built outside the try/catch that wraps `show()`, though, so a crumb that
     * threw took the whole response down as an uncaught fatal: HTTP 500, no
     * page, on a request whose actual content was fine. That has happened
     * twice, both times for the same reason - a getBreadcrumb() resolving a
     * slug that no longer exists (`ArticleController`, for example).
     *
     * `Throwable`, not `Exception`, because the commonest shape of the failure
     * is not a throw at all: `$this->userService->findPublicUserByUsername()`
     * and friends are nullable, and reading `->nick` off the null is an Error.
     *
     * The fallback is deliberately not "skip it". `finalize()` builds each
     * URL by accumulating the slugs before it, so a missing crumb shortens the
     * path and silently corrupts the link of every crumb *after* it - a
     * three-level trail would point one level up. Substituting the page's own
     * URL segment keeps the arithmetic right; only the human-readable title is
     * degraded, which is the part that was unavailable anyway.
     *
     * The resolver is a callable rather than a controller so that *building*
     * the controller is inside the try as well: `createForPage()` throws for a
     * page whose action no controller claims, which is the same class of
     * problem and was equally fatal.
     *
     * @param callable(Page): ?Breadcrumb $resolve
     */
    public function addFor(Page $page, callable $resolve): void
    {
        try {
            $breadcrumb = $resolve($page);
        } catch (Throwable $e) {
            error_log(sprintf(
                'Breadcrumb for page %d (%s) failed, falling back to its slug: %s: %s',
                $page->id,
                $page->action ?? $page->pattern,
                $e::class,
                $e->getMessage()
            ));

            $breadcrumb = self::fallbackFor($page);
        }

        // A null crumb is a controller saying this page has no place in the
        // trail (APIController does), and that is left alone - unlike a
        // failure, it is a choice, and API pages are never in an HTML trail.
        if ($breadcrumb) {
            $this->add($breadcrumb);
        }
    }

    /**
     * The crumb for a page whose controller could not produce one: its own URL
     * segment, used as both the link and the label.
     *
     * `Router::resolve()` matched this segment to get here and stored the
     * named captures on the page, so substituting them back into the pattern
     * reconstructs the segment exactly - `{slug}` becomes `kak-gadat`,
     * `{id:\d+}` becomes `42`, and a static pattern is already the segment.
     * A placeholder with no matching param is left as-is rather than dropped,
     * so the result stays visibly wrong instead of quietly pointing somewhere
     * else.
     */
    public static function fallbackFor(Page $page): Breadcrumb
    {
        $segment = (string) preg_replace_callback(
            '/\{(\w+)(?::[^}]+)?}/',
            static fn (array $m): string => $page->params[$m[1]] ?? $m[0],
            $page->pattern
        );

        return new Breadcrumb($page->pageName ?? $segment, $segment);
    }

    public function finalize(): array
    {
        $path = '';
        foreach ($this->breadcrumbs as $breadcrumb) {
            $path .= $breadcrumb->slug . '/';

            $breadcrumb->url = $path;
        }
        return $this->breadcrumbs;
    }
}

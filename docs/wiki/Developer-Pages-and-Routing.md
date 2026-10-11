# Developer Guide: Pages and routing

Stream Engine represents public HTML routes, internal module routes, and API
endpoints as one in-memory page tree. The tree decides which controller owns a
request; the same definitions also drive canonical URL generation,
breadcrumbs, menus, and sitemaps.

This chapter defines the routing contract for developers. Administrator-facing
page editing and operational precautions are covered in
[Content, pages, and routing](https://github.com/bfhp/stream-engine/wiki/Administrator-Content-and-Routing).

## URL ownership

Keep these URL classes separate:

| URL class | Owner | Generation rule |
| --- | --- | --- |
| Public HTML pages | `PageTree` and `UrlGenerator` | Resolve by action, page, feed, or term. Do not concatenate known route segments. |
| Versioned API | Explicit `/api/v1/...` transport contract | Keep paths explicit in PHP and TypeScript; encode dynamic segments. |
| Administration shell | Engine-owned `/admin/` entry | Module admin pages are hash routes below the shell, not public `PageTree` pages. |
| Installer | Installer-only `/install` entry | Handled before normal application dispatch. |
| Assets and stored files | Web server and storage layer | Keep `/assets`, favicon, and `/uploads` paths outside page/API generators. |

The site root `/`, `/admin/`, `/api`, and `/api/v1` are reserved by the
engine. A persisted page or module runtime page that conflicts with an
engine-owned definition fails complete-tree validation at boot.

Changing a public page pattern must not rename an API endpoint. Conversely,
moving an API route must be treated as a transport-version compatibility
change, not as an administrator page-tree edit.

## Page sources and boot order

The final `PageTree` is assembled in this order:

1. `PageRepository` loads persisted administrator-configured pages.
2. Module controllers add fixed HTML descendants through
   `registerRuntimePages()`.
3. The engine adds `/api`, `/api/v1`, and `/admin`.
4. Module controllers add versioned API pages through `registerApi()`.
5. `PageTree::validateDefinitions()` validates all collected definitions.

The router is created only after this sequence. A conflict between a database
page, a module runtime page, or an engine page is therefore a boot error rather
than an order-dependent route choice.

`PageTree` maintains indexes by ID, action, feed ID, feed type, and term
vocabulary. It also retains registration history so validation can detect a
structural duplicate covered by its contract even though a live associative
index would otherwise overwrite it. Request-specific resolved clones update
the live indexes without becoming new definitions.

## The `Page` model

The principal fields are:

| Field | Meaning |
| --- | --- |
| `id`, `parentId` | Node identity and parent relationship. Runtime IDs exist only for the current boot. |
| `pattern` | One path-segment pattern under the parent. The root pattern is empty. |
| `action` | Globally unique controller dispatch name. |
| `pageName` | Static display/breadcrumb fallback. Dynamic breadcrumbs may come from the controller. |
| `settings` | Action-specific and presentation settings. |
| `feedId` | Bind one specific feed to a page and URL anchor. |
| `feedType` | Associate a dynamic route level with a feed type. |
| `listFeedType` | Tell a listing action which child feed type to list; it does not itself generate a path. |
| `termVocabulary` | Associate a dynamic route with term slugs from one vocabulary. |
| `accessRule` | Page audience: `public`, `authenticated`, `moderator`, or `admin`. |
| `requestMethods`, `responseType` | Transport method list and `html`, `json`, or other response mode. |
| `commentsEnabled` | Presentation/feature flag consumed by supporting controllers and templates. |
| `changefreq`, `updated` | Sitemap metadata; `noindex` is the exclusion value used by current sitemap logic. |

Persisted pages are loaded as `GET`/`html`. `Page::runtime()` also creates a
`GET`/`html` definition. `Page::api()` creates a `json` definition and requires
an explicit method list.

## Public pages and action contracts

Persisted public pages are genuine site configuration. A module advertises
their actions through `ControllerInterface::pageActions()`. The Pages editor
and its server API validate the selected action's `singleton`, standard field,
`oneOf`, and settings contract.

New pages cannot use an unregistered action. An existing page left behind by a
removed module may retain its unknown action, but its action-specific fields
cannot be changed. This permits recovery without presenting a removed action
as a valid choice.

Use [Modules](https://github.com/bfhp/stream-engine/wiki/Developer-Modules#public-page-actions)
for the complete descriptor format. The route must still be structurally
valid: an action contract does not make an invalid parent, pattern, or sibling
combination safe.

Every non-root persisted page requires a parent. Page `1` is the root, has no
parent, and uses an empty pattern. The administration API prevents direct
self-parenting, descendant cycles, a missing parent, moving the root, deleting
the root, deleting a page with persisted children, and deleting a page still
referenced by a menu.

The complete-tree checks run at application boot. The administration API does
not currently simulate every duplicate-pattern and duplicate-action conflict
before writing, so a structurally conflicting edit may make the next request
fail during bootstrap. Treat page-tree changes as configuration migrations and
test them on a staging copy.

## Static and dynamic patterns

Each `pattern` represents one segment below its parent. Use no leading or
trailing slash. A normal static pattern such as `articles` matches that exact
segment. Any pattern containing `{` is classified as dynamic.

Supported placeholder forms are:

```text
{slug}
{id:\d+}
```

The first uses the default `[^/]+` rule. The second supplies a raw regular
expression for that named segment. The router wraps the compiled pattern with
start/end anchors, but it does not escape or validate a custom expression.
Keep constraints small, deterministic, and segment-local; never include `/`,
delimiters, or untrusted text in a route pattern. Invalid or pathological
regular expressions can break request handling.

At each parent, matching order is:

1. exact static child; then
2. the dynamic child.

This lets `/articles/new/` coexist with `/articles/{slug}/`; `new` reaches the
static page. `PageTree` rejects two static siblings with the same pattern and
rejects more than one dynamic sibling under the same parent, even when their
regular expressions appear disjoint. Put alternatives under different static
parents or combine them in one deliberate action.

The router trims leading and trailing slashes before walking the tree, so
`/articles` and `/articles/` resolve identically inside the application. Use
generated URLs for the canonical trailing-slash form and let the deployment's
web-server policy handle external redirects consistently.

## Route parameters

`Router::resolve()` returns:

- the final resolved `Page`;
- an aggregate `params` map; and
- the root-to-leaf page chain used for breadcrumbs.

Each matched page is cloned and receives only the parameters captured by its
own segment. This prevents one request from mutating the definition used by a
later request.

The aggregate map is keyed by parameter name. If nested pages reuse a name,
such as `/forums/{slug}/{slug}/`, the deeper value replaces the earlier value
in the aggregate passed to `show()` or `callApi()`. The page clones retained in
the breadcrumb chain still hold their own per-level value. Use distinct names
when a final controller needs both values, or resolve ancestry through the
domain hierarchy instead of expecting two `slug` keys in one array.

## Module-owned runtime pages

A module tree has two kinds of HTML pages:

- a persisted public mount declared by `pageActions()`; and
- fixed internal descendants registered in memory by
  `registerRuntimePages()`.

Only the mount belongs in the Pages editor. For example:

```php
public static function pageActions(): array
{
    return [
        'notes.list' => [
            'label' => 'Notes',
            'singleton' => true,
        ],
    ];
}

public static function registerRuntimePages(
    PageTree $pageTree,
    TranslationManager $tm,
): void {
    $mount = $pageTree->findByAction('notes.list');
    if ($mount === null) {
        return;
    }

    $pageTree->add(Page::runtime(
        id: $pageTree->getMaxPageId(),
        parentId: $mount->id,
        pattern: 'create',
        action: 'notes.create',
        accessRule: AccessService::ACCESS_AUTHENTICATED,
        feedType: null,
        changefreq: 'noindex',
        settings: null,
        pageName: $tm->trans('notes.create'),
    ));
}
```

The hook must do nothing when the mount is absent. Creating, moving, or
deleting the mount then creates, moves, or removes the runtime subtree on the
next application boot without persisting internal pages.

The module owns each runtime pattern, action, access rule, indexing behavior,
feed binding, settings, and breadcrumb name. Forms, editors, and management
pages normally use `changefreq: 'noindex'` and an explicit restricted access
rule. A dynamic content page declares `feedType` when feed URL and sitemap
generation depend on it.

Runtime IDs are allocation details. Never store them in MariaDB, cache them as
stable identifiers, expose them as extension IDs, or make a persisted page
their child. Generate links by action or content identity, not by ID.

## Complete-tree validation

`PageTree::validateDefinitions()` rejects:

- duplicate page IDs;
- duplicate non-null actions;
- a missing parent;
- parent cycles;
- duplicate static sibling patterns; and
- more than one dynamic sibling under the same parent.

Validation does not currently enforce uniqueness of `feedId`, `feedType`, or
`termVocabulary` indexes. Their lookup methods return the last registered
matching definition. Treat a direct feed binding and a term vocabulary as
globally unique. A feed type used as the root of canonical URL resolution must
also have one unambiguous root page; repeated descendant feed types are safe
only when their parent context selects the route.

When a static page and a dynamic page are siblings, static matching wins.
Content creation must reserve all actual static sibling patterns so a new slug
cannot become unreachable. The blog/community services implement this by
reading `PageTree::findChildren()` rather than maintaining a separate hardcoded
reserved-word list. New slug-producing features must do the same.

## Request dispatch and access

After routing, the engine checks the page's `accessRule` before controller
construction:

- inaccessible HTML routes render `404` to avoid disclosing their existence;
- inaccessible JSON routes return a forbidden response; and
- unknown routes render an HTML or JSON `404` according to the requested path.

Page access is only the outer route gate. A controller must still enforce feed
visibility, ownership, capability-specific authorization, input validation,
and CSRF for mutating operations. Menu visibility is presentation, not an
authorization boundary.

The declared `requestMethods` guard currently runs for non-HTML dispatch and
produces `405` for a disallowed method. HTML dispatch does not apply that
guard. Do not implement state changes by relying on a `GET` declaration on an
HTML page; put mutations behind an API route that declares its methods and
performs authorization and CSRF checks.

## API page definitions

Modules attach API definitions below the engine-owned `/api/v1` page:

```php
public static function registerApi(int $apiV1PageId, PageTree $pageTree): void
{
    $pageTree->add(Page::api(
        id: $pageTree->getMaxPageId(),
        parentId: $apiV1PageId,
        pattern: 'notes',
        requestMethods: ['GET', 'POST'],
        action: 'notes.api',
        accessRule: AccessService::ACCESS_AUTHENTICATED,
    ));
}
```

The engine binds each newly registered API action to the declaring module.
`callApi()` receives the resolved `Page` and aggregate route parameters. Keep
the endpoint path explicit in clients. Encode inserted path segments with
`rawurlencode()` in PHP or `encodeURIComponent()` in TypeScript; consume a URL
provided by the server without encoding the complete string again.

[API and security](https://github.com/bfhp/stream-engine/wiki/Developer-API-and-Security)
defines versioning, response and error payloads, authorization, validation,
and CSRF. A route definition alone never makes a handler safe.

## Generate public URLs

Use the method that matches the identity you already have:

| Method | Use |
| --- | --- |
| `action($action, $params, $query, $fragment)` | A uniquely named page action, such as registration or search. |
| `page($page, $params, $query, $fragment)` | A concrete `Page` already available to the controller. |
| `feed($feed, $query, $fragment)` | One content object whose canonical route comes from a direct feed binding or feed hierarchy. |
| `feeds($feeds)` | Batch canonical URL generation without per-feed hydration calls. |
| `feedTerm($term, $query, $fragment)` | A term whose vocabulary selects a dynamic page. |
| `childAction($action, $parentUrl)` | A registered static child appended to an already resolved dynamic parent URL. |
| `listing($baseUrl, $page, $filters, $fragment)` | Pagination/filter query and fragment handling for an already resolved listing URL. |
| `pageNumber($query)` | Read the `page` query parameter, default to `1`, and reject values below `1` as not found. |

Twig can use `action_url()` for action-owned links:

```twig
{% set registerUrl = action_url('user.register') %}
{% if registerUrl %}
    <a href="{{ registerUrl }}">{{ trans('view.auth.register') }}</a>
{% endif %}
```

Controllers should resolve feed, term, and contextual page URLs and pass them
to templates. Do not make templates reproduce route topology.

Parameters and query strings are RFC 3986 encoded. Generated public paths use
a trailing slash. `action()`, `feed()`, `feedTerm()`, and `childAction()` may
return `null` when no route can be resolved. Callers must handle that absence
instead of falling back to a guessed path.

`page()` leaves a placeholder unchanged when its parameter is missing. Supply
every required parameter and assert the complete URL in a test. Its flat
parameter map also cannot assign different values to two ancestors that reuse
the same placeholder name. Use `feed()` for feed hierarchies or
`childAction()` when a known static child follows an already resolved dynamic
parent.

## Feed and term URL resolution

`UrlGenerator::feed()` resolves a canonical path as follows:

1. A page bound directly to the target `feedId` supplies its static path.
2. Otherwise the deepest ancestor feed with a direct `feedId` page becomes the
   route anchor.
3. Without a direct anchor, the root feed type selects the initial feed-typed
   page.
4. Descendant feed types select child pages under the chosen page chain.
5. A feed-typed page fills its first placeholder with the matched feed slug.
6. Consecutive feeds of the same type collapse to the deepest item, allowing a
   self-nested type such as a subforum to reuse one route level.

When the target feed itself has a direct `feedId` page, that page and its
ancestry must form a static path: the direct branch builds the registered path
without placeholder values. A direct binding used only as an ancestor anchor
may still precede descendant feed-typed dynamic pages.

If a feed, required slug, root page, or descendant type page is missing,
generation logs the configuration error and omits that feed URL. It never
returns a path containing an unresolved feed placeholder.

Feed canonical URLs are cached under the feed ID for up to 24 hours. A page
move, pattern edit, feed slug edit, or hierarchy change does not currently
invalidate those keys automatically. Tests and deployment procedures must
account for the old URL until expiration or perform a deliberately scoped
cache invalidation. Never flush a Memcached service shared with another site.

`feedTerm()` locates the page indexed by `termVocabulary` and fills its `slug`
parameter. It returns `null` when no matching page exists. Keep vocabulary
bindings unambiguous and test the actual nested path.

## Breadcrumbs

The router's resolved root-to-leaf chain drives breadcrumbs. For each page,
the engine constructs the controller that owns that page action and calls
`getBreadcrumb()` with the page clone containing its segment-local params.

Breadcrumb failures are isolated so a decoration error does not turn a
successfully rendered page into a `500`. That resilience is not a reason to
ignore errors: test dynamic names and links, and keep breadcrumb lookup work
bounded. Static runtime page names should be translated during registration;
dynamic labels may be resolved by the controller.

## Tests for a route change

At minimum, add tests at the layer being changed:

- `PageTreeTest` for parents, IDs, actions, sibling ambiguity, cycles, and
  lookup indexes;
- `RouterTest` for static precedence, regex matching, nested parameters,
  breadcrumbs, and `404` behavior;
- `UrlGeneratorTest` for actions, feed/term hierarchies, encoding, queries,
  fragments, missing routes, and cache behavior; and
- a module test for mount absence, mount creation/movement/removal, runtime
  registration, access, methods, and controller ownership.

Run the routing core suite with:

```bash
vendor/bin/phpunit \
  tests/Core/PageTreeTest.php \
  tests/Core/RouterTest.php \
  tests/Core/UrlGeneratorTest.php
```

For an end-to-end change, boot the application with the intended database
page tree and test the canonical URL as a guest and every permitted role.
Verify menus and sitemaps as separate consumers of the same definitions.

## Review checklist

- The route belongs to the public page tree, versioned API, or resource layer
  and uses that layer's generation rule.
- The action name, ID, static sibling, and dynamic sibling are conflict-free in
  the complete boot tree.
- The pattern is one segment, its regex is bounded, and static precedence is
  covered by a test.
- Page action fields describe every binding the controller consumes.
- Runtime descendants disappear cleanly when their mount is absent.
- Access, content visibility, request methods, authorization, and CSRF are
  enforced at the appropriate independent layers.
- Generated links use `UrlGenerator` or an explicit API/resource contract and
  encode only dynamic components.
- Feed/type/vocabulary bindings are unambiguous and missing-route behavior is
  handled.
- Cache staleness and redirects are planned when an existing URL changes.
- PageTree, Router, URL generator, module, menu, breadcrumb, and sitemap tests
  relevant to the change pass.

[Back: Modules](https://github.com/bfhp/stream-engine/wiki/Developer-Modules) · [Developer Guide](https://github.com/bfhp/stream-engine/wiki/Developer-Guide) · [Next: Themes](https://github.com/bfhp/stream-engine/wiki/Developer-Themes)

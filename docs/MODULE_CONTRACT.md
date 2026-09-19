# Module contract: modules only see what's handed to them

This project has one concrete, enforced structural rule:

> Code under `src/Modules/<Name>` may depend on Core/Service/Repository/Domain
> classes (the CMS's shared interfaces to data and cross-cutting
> functionality) and on classes it defines inside its own folder — nothing
> else. It must never reference another module's namespace. And nothing under
> `src/Core`, `src/Service`, `src/Repository`, `src/Domain` may reference
> `StreamEngine\Modules\*` — the dependency arrow points one way: modules
> depend on the platform, the platform never depends on a module.

This isn't a style preference — it's what makes "delete a module's folder and
the site still boots" an actual guarantee instead of a hope. If Forums
quietly relied on a class living inside another module, deleting that module would
silently break Forums, and nobody would notice until a page 500s in
production.

## What a module is allowed to depend on

- Anything passed to `ControllerFactory` at boot — `PdoDatabase`,
  `SettingsService`, `FeedService`, `AuthService`, `AccessService`,
  `TermService`, `UploadService`, `MessageService`, `PageTree`,
  `UrlGenerator`, `Config`, `Cache`, `Formatter`, `TranslationManager` (see
  `StreamEngine::__construct()` for the current, authoritative list). Concrete
  classes and the interfaces they implement are indexed automatically. These
  are exactly Core's interfaces to data and shared functionality; a module
  is a consumer of them, never a provider.
- `RequestContext`, injected per request by `ControllerFactory`.
- Classes the module defines itself, inside its own `src/Modules/<Name>/`
  folder — a module-specific repository, domain object, helper.
  A module can own repositories and helpers as it grows beyond a single
  controller; those classes stay private to the module.

## What a module must never do

- `use StreamEngine\Modules\<OtherName>\...` — reach into another module's
  namespace. If two modules need the same thing, that thing belongs in
  Core/Service/Repository/Domain, not in one module's folder.
- Get `use`d back from Core/Service/Repository/Domain. Those layers exist to
  be depended on, not to depend on a feature. A repository, a service, or
  `ControllerInterface`/`AbstractController` referencing anything from
  `StreamEngine\Modules\*` is a layering violation, full stop.
- Rely on another module's page actions, routes, or database rows existing.
  Forum content is `feed_type = 'forum'` rows in the shared `feeds` table,
  owned and served by Core's `FeedService` — not a table Forums owns. That's
  what makes deleting the Forums folder safe: nothing else was reaching into it,
  and it wasn't the sole owner of any data another part of the CMS needs.
- Bolt module-specific fields onto a shared Domain class (`Feed`, `User`,
  ...) just because nothing mechanically stops it. Domain classes never
  `use StreamEngine\Modules\*` either way, so the greps in "Checking it"
  below won't catch this one — it has to be caught by eye. A field only one
  module's view ever populates or reads (a forum's topic/post counts, say)
  doesn't belong on `Domain\Feed`; it belongs in a small structure the
  module builds itself and passes into its own view alongside the feed list
  (e.g. `Modules\Forums\ForumsController` passing a
  `forumStats: array<int, array{topics: int, posts: int}>` keyed by feed id
  into `forums.list.twig`, computed by its own `ForumStatsRepository`) —
  same as the logic that computes it belongs in the module's own
  repository, not `FeedRepository`/`FeedService`. Ask "would another feed type
  ever populate this field?" — if the honest answer is no,
  it doesn't belong on `Feed`.

  (`Feed::$readingProgress` predates this note and is a partial exception:
  it serves one content workflow but is set by Core's own
  `FeedService::getUnfinishedFeeds()`, not by a module reaching in, so it
  never crossed the namespace rule this doc enforces mechanically. Still
  worth tightening some day; don't treat it as precedent for new
  module-only fields on `Feed` — follow the rule above instead.)

## Where a new piece of logic belongs

Three questions, in this order, decide where new code lives:

1. **Is it useful to more than one module?** Rating, comments, favorites,
   membership - anything a forum topic and a blog post
   might all want - belongs in Core/Service/Repository/Domain and is passed
   to `ControllerFactory` at boot by `StreamEngine`, exactly like
   `FeedService`/`AccessService`/`TermService` today.
2. **Is it useful to exactly one module?** Friend requests and blog
   subscriptions or forum moderation -
   anything only one module's controller ever calls - belongs inside that
   module's own `src/Modules/<Name>/` folder, same as any other
   module-private class. It must **not** be constructed by `StreamEngine`'s
   global bootstrap (`StreamEngine::__construct()`), nor passed to
   `ControllerFactory` by it - doing so would force that file to
   `use StreamEngine\Modules\<Name>\...`, which is exactly the "platform
   depends on a module" violation this doc forbids above, just committed
   by the bootstrap file instead of another module.

   Instead, the module's own controller builds it directly in its own
   constructor, out of whatever Core/Service it already receives plus
   repositories it constructs itself - no global bootstrap wiring,
   no interface change, nothing for the bootstrap file to know about.
   `Modules\Users\BlogPostService`/`Modules\Users\FriendService` are the
   concrete example: `UsersController`'s constructor builds both itself
   (from `$feedService`/`$urlGenerator`/`$tm`/`$pageTree` it already has,
   plus fresh `FeedRepository`/`FeedTermRepository`/`MembershipRepository`
   instances - all stateless wrappers over `PdoDatabase`, so there's no
   need to share `StreamEngine`'s own), so `StreamEngine.php` never imports
   a class from `StreamEngine\Modules\Users`, and `BlogPostService`/
   `FriendService` are never constructor-injected types `ControllerFactory`
   has to resolve at all.
3. **Does one module need to call into a service another module privately
   owns?** This is the case the rule above doesn't solve on its own:
   `MessageService`'s messenger half (conversations/messages, used by
   `Modules\Messages` itself) and its `notify()` wrapper for the reserved
   "system" account conceptually belong in a future `Modules\Messages` (see
   `docs/TODO.md`'s note on first-run setup) - but today it still lives in
   the shared `Service` layer and is passed to `ControllerFactory`
   unconditionally, because `Users` (via `FriendService`) needs to send a
   system message too. Once/if it does move into a module, a call into it
   from another module stops being "depend on Core" and becomes "depend on
   a specific other module" - exactly what's disallowed. The fix is never a
   `use StreamEngine\Modules\Messages\...` import from Users; extract the
   shared capability into a Core interface and provide a no-op implementation
   when the Messages module is absent. Deleting the module should then merely
   stop that optional delivery path, not break another module's construction.

## How a module is recognized

`ModuleRegistry` reads Composer's optimized classmap and
registers concrete classes implementing `ControllerInterface`. No directory
scan or per-module registration class is needed. An optional `views/` directory
next to the controller supplies its templates.

Rebuild the classmap with `composer dump-autoload` after changing controller
classes. See [Modules](MODULES.md) for the root Composer configuration and
module ID conventions.

## Page action configuration descriptors

`ControllerInterface::pageActions()` is also the source of truth for the
Pages editor. A plain string value remains supported as shorthand for an
action that uses none of the action-specific page fields:

```php
return ['module.index' => 'Module landing page'];
```

An action that uses page configuration must declare a descriptor beside its
label. When different fields select different lookup algorithms, expose them
as different actions instead of making one action infer its mode:

```php
return [
    'module.show-id' => [
        'label' => 'Show a fixed item',
        'fields' => [
            'feedId' => ['status' => 'required', 'feedTypes' => ['article']],
        ],
    ],
    'module.show-slug' => [
        'label' => 'Show an item from the route slug',
        'fields' => [
            'feedType' => ['status' => 'required', 'values' => ['article']],
        ],
    ],
];
```

The only configurable field names are `feedId`, `feedType`, `listFeedType`,
and `termVocabulary`. Their status is `unsupported`, `optional`, or
`required`; omitted fields normalize to `unsupported`. `values` restricts a
string field to specific registered values. `feedTypes` restricts the type of
the feed referenced by `feedId`. A `oneOf` requirement means that at least one
of its listed supported fields must be set. It is intended for fields that are
truly interchangeable inputs to the same behavior, not for choosing between
different resolvers such as a fixed ID and a route slug.

`ModuleRegistry` validates and normalizes descriptors during bootstrap. The
admin catalog returns that normalized contract, and the admin write API
enforces the same rules. New pages cannot use an unregistered action. A page
left behind by a removed module may still be edited, but its unknown action
and four action-specific configuration fields must remain logically unchanged.

## Where this applies

All module directories. The common admin shell discovers page descriptions
without importing a specific module.

## Checking it

No CI check for this yet — same posture as `docs/PERFORMANCE_CONTRACT.md`,
enforced through code review. Until there's automation, these two greps
answer "did anything cross the boundary":

```bash
# A module reaching into a different module's namespace (run per module,
# swap Forums for the one you're checking) - should always be empty:
grep -rF 'StreamEngine\Modules\' src/Modules/Forums | grep -vF 'Modules\Forums'

# The platform layers reaching into any module - should always be empty.
# Includes StreamEngine.php itself: it wires up every Core/Service at boot,
# so it's exactly as bound by this rule as anything under src/Core - see
# "Where a new piece of logic belongs" above for what used to go wrong here
# (BlogPostService/FriendService used to be constructed and registered by
# StreamEngine.php directly; now UsersController builds them itself).
grep -rn 'StreamEngine\\Modules\\' src/Core src/Service src/Repository src/Domain src/StreamEngine.php
```

Both were run against the current tree while writing this doc and come back
empty. If either ever returns a match, that's the review comment.

## Enforcement

Manual, via code review — same as `docs/PERFORMANCE_CONTRACT.md`. The check
above is cheap enough to wire into CI or a Composer script (`composer
check-module-boundaries`) once there's more than one reviewer relying on
catching this by eye; not needed at the current scale.

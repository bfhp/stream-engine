# Runtime pages refactoring plan

Status: in progress (Phase 1 complete).

## Goal

Split site pages into two categories:

- public module mount points that an administrator can create, position, and
  remove;
- internal module pages that are registered in memory during application boot
  and are not exposed in the page editor.

For example, an administrator creates only a `community.main` page mounted at
`/communities/`. The Users module then registers this runtime tree beneath it:

```text
/communities/
  {slug}/                       community.show-slug
    post/                       community.post-new
    {slug}/                     community.post-show-slug
      edit/                     community.post-edit
    manage/                     community.manage
```

The module owns the internal pages' `pattern`, `accessRule`, `changefreq`,
`feedType`, and other behavior. These properties are not administrator
configuration.

## Decisions

- `pageActions()` remains the public contract consumed by the page editor. It
  does not contain internal runtime actions.
- Modules describe runtime pages in PHP code rather than in another declarative
  schema or in the `pages` table.
- If a mount point is absent, its runtime subtree is not registered.
- Runtime page IDs are valid only within the constructed in-memory `PageTree`.
- Arbitrary database pages cannot use a runtime page as their parent. Modules
  may extend internal trees through the runtime registration mechanism.
- Static database pages may be placed next to a runtime `{slug}` route. Static
  routes take precedence, and their patterns become reserved content slugs.
- API registration remains a separate lifecycle hook for now. Unifying API and
  HTML runtime routes is outside the scope of this refactoring.

## Phase 1: runtime page infrastructure

- [x] Add `Page::runtime()` with safe HTML/GET defaults and explicit parameters
  for `action`, `pattern`, `parentId`, `accessRule`, `feedType`, `changefreq`,
  and `settings`.
- [x] Add a `registerRuntimePages(PageTree $pageTree): void` hook to
  `ControllerInterface` and `AbstractController`.
- [x] Call the hook for every active module after loading database pages and
  before constructing `Router`.
- [x] Associate newly registered actions with their owning controller in
  `ControllerFactory`, following the existing `registerApi()` lifecycle.
- [x] Fail boot with a clear error if a runtime action is already publicly
  declared or owned by another module.
- [x] Validate page definitions before constructing `Router`:
  - every parent exists;
  - definition IDs are unique;
  - definition actions are unique;
  - a parent has no duplicate static child pattern;
  - ambiguous dynamic sibling patterns are rejected.
- [x] Keep strict ID rejection out of `PageTree::add()`. Definition
  registration is tracked separately from the resolved clones that
  `Router::resolve()` stores with populated `params`; definitions are checked
  in a dedicated boot-time validation step.
- [x] Cover lifecycle order, missing mount points, conflicts, and controller
  ownership with tests.

## Phase 2: move module trees to runtime

- [x] Inventory the exact public mount actions and internal runtime actions
  before changing `pageActions()`.
- [x] Register the forum tree beneath `forums.list`:
  - `forums.topic-list` at `{slug}`;
  - `forums.topic-new` at `new`;
  - `forums.topic-view` at `{slug}`;
  - `forums.topic-edit` at `edit`.
- [x] Register internal user blog routes beneath the public profile page.
- [x] Register the community tree beneath `community.main`, including community
  view, post creation, post view, post editing, and community management.
- [x] Define these properties in module code for every runtime route:
  - access level (`public`, `authenticated`, and so on);
  - indexing behavior (`changefreq`, normally `noindex` for forms and
    management pages);
  - `feedType`, `listFeedType`, or `termVocabulary` when required by URL
    generation or sitemap generation;
  - page settings that are genuinely part of module behavior.
- [x] Remove internal actions from `pageActions()`, leaving only pages that an
  administrator may create and configure.
- [x] Remove manually assembled `new/` and `edit/` URLs from controllers and
  frontend code. Generate them from runtime actions in `PageTree`.
- [x] Derive reserved content slugs from actual static siblings of a runtime
  route instead of maintaining word-list constants.
- [x] Verify URL generation, access control, breadcrumbs, and sitemap output for
  every migrated tree.

### Phase 2 action inventory

| Tree | Public/configurable actions | Runtime-owned actions |
| --- | --- | --- |
| Forums | `forums.list` | `forums.topic-list`, `forums.topic-new`, `forums.topic-view`, `forums.topic-edit` |
| Personal blog | `user.show`, `user.post-show-id` | `user.post-new`, `user.post-show-slug`, `user.post-edit` |
| Communities | `community.main`, `community.show-id`, `community.post-show-id` | `community.create`, `community.show-slug`, `community.post-new`, `community.post-show-slug`, `community.post-edit`, `community.manage` |

Standalone public pages such as `users.list`, `user.register`, and
`user.retrieve` remain configurable and are not part of these internal trees.

## Phase 3: page editor

- [ ] Return only public `pageActions()` entries through the page editor API.
- [ ] Do not show runtime pages in the editable database page list or tree.
- [ ] Reject a second instance of a singleton public mount action with a clear
  validation error.
- [ ] Ensure that creating, moving, or deleting a mount page causes its runtime
  subtree to appear, move, or disappear on the next request without database
  writes for internal pages.
- [ ] Do not add an "add child pages" checkbox. The runtime subtree is an
  unconditional part of the mounted module.

## Phase 4: migrate existing pages

- [ ] Update the installer so new installations persist only public mount
  points, not rows for internal runtime routes.
- [ ] Identify existing `pages` rows whose actions are becoming runtime-owned.
- [ ] Check for non-standard database descendants before removing those rows.
- [ ] Never cascade-delete or silently move non-standard descendants. Stop the
  migration with a diagnostic and administrator instructions.
- [ ] Remove standard internal rows only after validating the entire affected
  tree, avoiding a mixed database/runtime state.
- [ ] Add migration tests for both a standard tree and a tree containing custom
  descendants.

## Completion criteria

- Enabling a module requires creating only its public mount page.
- The page editor cannot create, move, or modify an internal route.
- Internal URLs are resolved and generated through the same `PageTree`.
- Moving a mount page automatically changes URLs throughout its runtime
  subtree.
- Forms and management pages have code-defined access and are not indexed.
- Public dynamic pages continue to appear in sitemaps through `feedType` or
  `termVocabulary`.
- Runtime route conflicts fail during boot rather than depending on insertion
  order.
- No standard internal module pages remain in the `pages` table.

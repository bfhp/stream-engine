# ADR-002: A framework-free `default` theme and a Bootstrap theme built on top of it

**Status:** Accepted
**Date:** 2026-10-04
**Deciders:** bfhp
**Related:** ADR-001 (JS markup comes from the active theme), `THEME_CONTRACT.md`

## Context

`default` used to be both the fallback every other theme inherits from and a
complete Bootstrap design:

- `theme.json` loaded `/assets/css/bootstrap.css`, and `ThemeCatalog`
  prepended it to any theme that listed `site.css` without it.
- 25 of 34 theme templates and 40 of 50 module templates
  (`src/Modules/*/views`) used Bootstrap classes; 41 places used `data-bs-*`.
- `site.js` bundled Bootstrap's JS (`Toast`, `Modal`, `Tooltip`).

So every theme inherited Bootstrap whether it wanted it or not, a theme on
another framework had to override almost everything, and the fallback after a
Twig error (`default`) silently brought Bootstrap back.

## Decision

Two layers, one rule: **`default` knows nothing about any CSS framework and
works without one; a theme overrides the pieces of markup where it wants its
own framework's styles.**

### 1. `default` — the base theme

The smallest theme that keeps **all functionality**: every page, form,
modal, toast, comment thread, pagination and messenger view works.

- **No CSS framework.** Semantic HTML, `default`'s own classes and each
  module's own classes. No `btn`, `card`, `d-flex`, `data-bs-*`.
- **Own CSS** (`/assets/css/base.css`): custom properties for colors,
  spacing and typography; light/dark through `data-color-mode`; logical
  properties, so RTL needs no separate build.
- **Native behavior.** The native UI adapter (ADR-001): `<dialog>` for
  modals, a toast region, `title` tooltips, `<details>` for dropdowns, and
  declarative `data-ui-*` toggles. No framework JS.
- **Block-rich templates.** Layouts, partials, components and module
  templates expose `{% block %}`s (including class blocks such as
  `main_class`) wherever a theme is likely to change something, so a theme
  overrides blocks, not whole files.
- Stays the hard fallback: if the active theme fails, the site is plain but
  fully usable.

### 2. `bootstrap` — the reference extended theme

The site's Bootstrap look, and the example theme authors copy from.

- `theme.json`: `"parent": "default"`, `"inheritAssets": false` and an
  explicit asset order: `base.css` → `bootstrap.css` (Bootstrap's prebuilt
  CSS, RTL build in `assets.rtl`) → `bootstrap-skin.css` (site colors,
  navbar/footer tweaks) → `site.css`. Scripts: `site.js` and
  `ui-bootstrap.js` (the Bootstrap UI adapter, ADR-001).
- **Overrides only where it wants Bootstrap markup**, extending the original
  and overriding blocks:

  ```twig
  {# views/themes/bootstrap/layouts/with-sidebar.twig #}
  {% extends '@default/layouts/with-sidebar.twig' %}

  {% block layout_container_class %}container{% endblock %}
  {% block layout_row_class %}row{% endblock %}
  ```

  The same for module templates, through the module's namespace:

  ```twig
  {# views/themes/bootstrap/modules/users/register1.twig #}
  {% extends '@Users/modules/users/register1.twig' %}

  {% block register_header %}
      <h1>{{ trans('user.registration') }} <small class="text-muted">{{ trans('view.users.register1.01') }}</small></h1>
      <hr>
  {% endblock %}
  ```

  Components whose structure is framework-specific (navbar, dropdowns,
  modals, toast, tabs) are its own files at the same path.
- **Everything it does not override renders `default`'s markup.** That is
  why it loads `base.css` first: Bootstrap wins for plain elements (`body`,
  `h1`, `a`, ...), while `default`'s classes keep styling the markup the
  theme left alone.

### 3. Theme inheritance

- `theme.json` has an optional `"parent"` (default: `"default"`); templates
  resolve *active → parent → … → default → module views*.
- Every theme is a Twig namespace (`@default`, `@bootstrap`, `@<id>`) and
  every module's views too (`@Users/...`), so an override extends the file
  it replaces instead of copying it.
- A child inherits its parent's assets, deduplicated by path, unless it sets
  `"inheritAssets": false` to control the order itself.

### 4. Markup built by scripts

API responses stay JSON and become markup through `<template>`: the Twig
component that renders an item on the server is rendered once more inside
`<template data-ui="...">`, and the script clones and fills it. A theme that
overrides the component restyles the script-added items too. Details and
limits in ADR-001 and `THEME_CONTRACT.md` ("JS surface").

## Options considered

| | A. Bootstrap in `default` (before) | B. Shared `ui-*` vocabulary mapped by each theme | C. Theme overrides markup (this ADR) |
|---|---|---|---|
| How a theme restyles a module | Override nearly everything | Map `ui-*` classes onto its framework (Sass `@extend`) | Override the module's blocks/templates it cares about |
| Fallback after an error | Bootstrap | Plain, fully working | Plain, fully working |
| Build | Prebuilt Bootstrap | Bootstrap from Sass sources + `rtlcss` | Prebuilt Bootstrap |
| Cost per module | — | Rewrite to the vocabulary; vocabulary grows | Write it for `default`; theme overrides only what it wants |

B was implemented for Feedback, Search, Article and Profile and then
reverted: the vocabulary grew to ~60 classes (a home-made mini-framework),
needed a Sass build with `rtlcss`, and a simple change of one class turned
into a mapping exercise. Overriding a block is plain Twig every theme author
already knows.

## Trade-offs

- **Theme files per module.** `bootstrap` carries its own copies of the
  blocks it restyles; when a module changes those blocks, the theme can lag
  behind. Mitigated by small, well-named blocks in module templates and by
  keeping behavior hooks (`data-*`, ids, `<template>` fill points) outside
  what a theme is expected to change.
- **Two stylesheets on one page.** Under `bootstrap`, markup the theme did
  not override is styled by `base.css` next to Bootstrap. They do not share
  class names, and Bootstrap loads later, so it wins for elements; a visual
  check per migrated module catches the rest.
- **Two themes to maintain.** Every feature lands in `default` first and is
  checked in `bootstrap` - the price of a real proof that the base is
  framework-free.

## Consequences

- **Easier:** a theme on any framework starts from a neutral base; theme
  authors work with ordinary Twig overrides; no CSS build beyond Vite.
- **Harder:** module templates must be rewritten for `default`, with blocks
  where a theme will want its own markup; the `bootstrap` theme grows a
  file per restyled module template.
- **Switching installs (done in code, not a DB migration):** a site with no
  saved `theme.active` uses `bootstrap` (`ThemeCatalog::PREFERRED_ID`), and a
  child theme reads its parent's saved value for a same-named setting until
  it has its own, so `color_mode` carries over. A data migration would have
  required rebuilding the install schema snapshot.
- **Release note:** sites that explicitly saved `default` should switch to
  `bootstrap` to keep their look.

## Plan

### Done

1. [x] Theme inheritance: `parent`, loader chain, Twig namespaces for themes
   and modules, `assets.rtl`, no implicit Bootstrap in `ThemeCatalog`.
2. [x] `CMS.ui` adapter (native + Bootstrap), `ui-bootstrap.js` as its own
   entry; `site.js` contains no Bootstrap.
3. [x] `views/themes/bootstrap` with `"parent": "default"`; sites that never
   chose a theme use it; settings inherit from the parent.
4. [x] `default`'s platform layer (layouts, partials, `components/*`) is
   framework-free with `base.css`; `bootstrap` overrides it (class blocks,
   `block()` reuse, own framework-specific components).
5. [x] `<template>` rendering for comments (`partials/js-templates.twig`,
   `ui.clone()` / `ui.fill()`).
6. [x] Bootstrap skin as its own entry (`bootstrap-skin.css`); `bootstrap`
   loads `base.css` first so non-overridden markup stays styled.

### Next: modules, one at a time

For each module (Feedback, Search, Article, Profile, Users, Forums;
Messages is already framework-free; the admin interface is out of scope, it
is a separate Mantine app):

7. [ ] Rewrite its templates for `default`: semantic markup, module classes
   in its own stylesheet, `default`'s classes, `hidden` instead of `d-none`,
   `data-ui-*` instead of `data-bs-*`, blocks where a theme will want its
   own markup.
8. [ ] Move its script-built item markup to `<template>` rendered by the
   module's page template, next to the list it feeds.
9. [ ] In `bootstrap`, override the module's blocks/templates that should
   keep the Bootstrap look.
10. [ ] Check the module under both themes.

Progress:
- Feedback: templates for `default`, styles in `modules/feedback.css`;
  `bootstrap` overrides the page's class blocks, title and success alert,
  and has its own form component.
- Search: results come from `<template data-ui="search-result">`
  (`components/search/result.twig`), the loading line and "load more"
  button are rendered by `results.twig`, `search.ts` builds no item markup;
  styles in `modules/search.css`; `bootstrap` overrides `results.twig`'s
  blocks and has its own `result.twig` with the same fill points.
- Article: components for `default` (grids, card, sections, sibling
  navigation), styles in `modules/article.css`; `bootstrap` has its own
  `components/article/*.twig` and overrides `article_comments_class`.
- Profile: tabs for `default` (form controls added to `base.css`), styles in
  `modules/profile.css`; friend and community cards are
  `components/profile/{friend,community}-card.twig`, rendered as
  `<template>` by their tabs and filled by `profile.ts` (badges and actions
  are optional fill points); the save status tone is a `data-tone`
  attribute, not classes. `bootstrap` has its own tab and card components
  and overrides `profile_tabs_class` and the unsubscribe page's class
  blocks.

### Verify

11. [ ] Functional pass on `default`: every page, form, modal, toast,
    comments, pagination, messenger.
12. [ ] Grep checks from `THEME_CONTRACT.md` return nothing for `default`,
    module views and `assets-src`.
13. [ ] Update `INSTALLATION.md`.

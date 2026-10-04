# Theme contract: themes style, modules own their content

This project has one concrete, enforced structural rule:

> A theme (`views/themes/<name>`) may only contain layout scaffolding, chrome,
> and content-agnostic UI. Any Twig template whose markup is specific to one
> module's domain belongs under that module's own `views/` folder
> (`src/Modules/<Name>/views/components/...`), never under
> `views/themes/<theme>/components/...`. Themes restyle and rearrange; they do
> not own module content.

This is not a style preference — it's what makes "add a second theme" a
matter of copying a handful of layout/chrome files and overriding what you
want, instead of forking the entire `components/` tree module by module. If a
theme has to carry `components/article/*` and
`components/users/*` just to exist, themes and modules have stopped being
separable, and creating a new theme means re-implementing every module's
markup from scratch.

## Why this is a problem today, not hypothetically

Right now every component lives under `views/themes/default/components/`,
regardless of who it belongs to. Two very different kinds of templates are
mixed together in that one folder:

- **Platform/shared components** — `nav/*`, `comments/*`, `auth/*`,
  `share/*`, `common/*`, `ui/*`. These render data owned by Core/Service
  (`FeedService` for comments, `AuthService` for auth, `MenuService`/
  `PageTree` for nav) or are pure UI atoms (modals, toasts, tabs) with no
  domain knowledge. Nothing wrong with these living in the theme — they're
  legitimately theme content, used across unrelated modules.
- **Module-owned components** — `article/*`,
  `users/*`, `feedback/*`, `search/*`, `messages/*`, `profile/tabs/*`. Each of
  these is `{% include %}`-ed from exactly one module's page templates and
  renders that module's domain data. Such templates belong to their module,
  while shared navigation and authentication markup belong to the theme.

That second group is misplaced. It's why "add a theme" currently means
"recreate every module's component markup," and it's why nobody can look at
`views/themes/default/components/` and tell which files a new theme is
required to provide versus which ones belong to a module and travel with it.

## Ownership rule

A component's folder name is the test: if it names a module
(`article`, `users`, `feedback`, `search`,
`messages`, `profile`) or renders that module's domain data, it is
module-owned and lives at:

```
src/Modules/<Name>/views/components/<name>/*.twig
```

referenced the same way it is today — `{% include 'components/<name>/...' %}`
— just resolved from the module's own views path instead of the theme's.
This is consistent with `docs/MODULE_CONTRACT.md`: a module's `views/`
folder is what it owns, and components are as much "views" as the page
template that includes them.

Everything else — `layouts/`, `partials/`, and the components that render
platform-level concepts (`nav`, `comments`, `auth`, `share`, `common`, `ui`)
— stays under `views/themes/<theme>/`. These are cross-module by nature (used
by, or meaningful to, more than one module, or owned by a Core/Service
class rather than a module), which is exactly what makes them theme
material: a new theme is expected to restyle them, not reinvent them per
module.

## What a theme must never do

- Contain a `components/<name>/` folder whose name matches a directory under
  `src/Modules/` and whose templates are only ever included from that
  module's own views. That content is the module's, not the theme's.
- Be required to exist for a module to render. A module's own components are
  part of its default rendering path; a theme overrides them optionally, it
  does not supply them.

## What a module must never do

- Reach into `views/themes/<theme>/components/` for markup that renders its
  own domain data. If a module needs a component, that component lives in
  the module's own `views/components/`, mirroring the "modules depend on the
  platform, not the other way around" direction from `docs/MODULE_CONTRACT.md`
  — a theme is platform-adjacent presentation, and a module's own content
  should not depend on a specific theme supplying it.

## Catalog, settings, and override resolution

Every selectable theme is a direct child of `views/themes/` and has a
`theme.json` manifest. The manifest owns the stable `id`, display `name`,
version, a settings schema with defaults, and ordered `styles`/`scripts` asset
lists. `THEME_DIR` remains an environment-only compatibility hook: when it
points to a directory with a valid manifest, that theme joins the catalog. An
admin selects only a catalog ID; filesystem paths are never accepted by the
admin API.

A minimal manifest looks like this:

```json
{
  "id": "example",
  "name": "Example",
  "version": "1",
  "themeColor": "#0F172A",
  "settings": {
    "color_mode": {
      "type": "select",
      "label": "Color mode",
      "default": "dark",
      "options": { "dark": "Dark", "light": "Light" }
    }
  },
  "assets": {
    "styles": ["/themes/example/site.css"],
    "scripts": ["/themes/example/site.js"]
  }
}
```

Optional inheritance and direction keys (see "Inheritance" below):

```json
{
  "parent": "default",
  "inheritAssets": true,
  "assets": {
    "rtl": { "/themes/example/site.css": "/themes/example/site-rtl.css" }
  }
}
```

`parent` defaults to `default` and is ignored on `default` itself.
`inheritAssets` defaults to `true`. `assets.rtl` maps a stylesheet to its
right-to-left build; an entry whose RTL file is not deployed is skipped.

Theme values are stored as `theme.<id>.<setting>`, while `theme.active` stores
the selected ID. This keeps each theme's values intact when switching. The
server accepts only keys declared by that theme and validates select, color,
boolean, and string values against the manifest schema.

`themeColor` is fixed theme metadata rather than an administrator setting. It
sets the browser chrome color through `<meta name="theme-color">`; changing it
requires changing the theme manifest.

Theme assets must be deployed below `public/themes/<id>/` and referenced as
`/themes/<id>/...`; a theme may also reuse core `/assets/...` files. External
URLs, traversal, and arbitrary public paths are rejected. Rendered asset URLs
receive an mtime-based `?v=` value, so changing the manifest or asset invalidates
browser caches.

The engine adds no CSS framework on its own. Core Bootstrap is a separate
stylesheet, `/assets/css/bootstrap.css`, that a manifest lists like any other
asset. For right-to-left locales (see `<html dir>`) the engine swaps every
stylesheet that the theme chain maps in `assets.rtl`, so only one build of
each is loaded; `default` maps `bootstrap.css` to `bootstrap-rtl.css`. A
theme that ships its own framework declares its own RTL mapping. (Per
ADR-002, Bootstrap and its mapping later move from `default` to the
`bootstrap` theme.)

If the selected theme disappears or its manifest is invalid, the application
uses `views/themes/default/`. If an override produces a Twig loader, runtime,
or syntax error, the request is retried once with the default theme. An
administrator can preview a catalog theme with `?theme_preview=<id>` without
changing the saved selection; non-administrators cannot activate previews.

The loader (`StreamEngine::handleRequest()`) resolves a template name
(`layouts/base.twig`, `components/article/example.twig`, ...) against an
ordered list of filesystem paths and returns the first match. For a theme
to be a genuine override layer — able to restyle a layout while leaving
everything it doesn't touch alone — the search order is:

1. The selected catalog theme directory, checked first so the site can override
   anything below. This layer is optional.
2. Its parents, nearest first (see "Inheritance").
3. `views/themes/default/` — the baseline theme, providing every
   layout/partial/platform-component a new theme doesn't bother overriding.
4. Each module's own `views/` — so a module's components resolve even when
   no theme (default or active) overrides them.

Concretely: a site can set `THEME_DIR=/var/www/site/views/theme`, add a valid
manifest, and put only the templates it wants to change there, such as
`layouts/base.twig` and `partials/header.twig`. Every other template keeps
resolving from `default` or from the module that owns it.

## Theme layers: a bare base and themes built on it

> Status: see `docs/ADR-002-BASE-AND-BOOTSTRAP-THEMES.md`. Inheritance,
> namespaces and the UI adapter are implemented. `default` is still the
> Bootstrap design, and the `bootstrap` theme does not exist yet.

### `default` is the base, not a design

`default` is the smallest theme that keeps every feature working: pages,
forms, modals, toasts, comments, pagination, messenger. It is what every
other theme inherits from and what the site falls back to after an error.
So it:

- loads no CSS framework, only `/assets/css/base.css` (custom properties for
  color, spacing and type; light/dark; logical properties for RTL);
- uses semantic HTML and the UI vocabulary below, never framework classes
  or `data-bs-*`;
- uses the native UI adapter (`<dialog>`, an `aria-live` toast region,
  Popover API);
- exposes `{% block %}`s at every point a child is likely to change, so
  children override blocks instead of copying files.

### `bootstrap` is the reference extended theme

`views/themes/bootstrap` reproduces the current look and is the example
theme authors copy from. It shows the three ways to build on `default`:

1. **Extend and override blocks** where structure differs (layouts, navbar,
   modal, toast, tabs):

   ```twig
   {# views/themes/bootstrap/layouts/base.twig #}
   {% extends '@default/layouts/base.twig' %}

   {% block layout_header %}
       {% include 'partials/navbar.twig' %}
   {% endblock %}
   ```

2. **Restyle the UI vocabulary** in CSS instead of overriding module
   templates (`.ui-button { @extend .btn; }` in Sass).
3. **Register its own UI adapter** (Bootstrap's `Modal`, `Toast`,
   `Tooltip`) and its own RTL stylesheet in `theme.json`.

### Inheritance

A manifest may declare a parent; without one, the parent is `default`:

```json
{ "id": "my-theme", "parent": "bootstrap", "...": "..." }
```

The loader then searches *active → parent → … → `default` → module views*.
Every theme is also registered as a Twig namespace (`@default`, `@bootstrap`,
`@<id>`), and every module as `@<Module>` (`@Article/...`), so an override
can `{% extends %}` the file it replaces instead of copying it. Always extend
through the namespace: inside `my-theme/layouts/base.twig`, a plain
`{% extends 'layouts/base.twig' %}` resolves to the same file and recurses.

Parent assets are included before the child's, deduplicated by path, unless
the child sets `"inheritAssets": false` (templates are still inherited;
only assets are not). RTL mappings are inherited too, and a child's entry
wins.

A theme whose parent is missing, which forms a cycle, or whose chain is
deeper than 8 levels is not selectable and resolves to `default`.

### UI vocabulary

The classes module templates and JS-produced markup may use. Modules say
*what* an element is; themes decide *how* it looks. Anything more specific
uses the module's own classes (`msgr-*`, `comment-*`).

| Class | Meaning |
|---|---|
| `ui-button`, `ui-button--primary`, `--secondary`, `--danger`, `--link`, `--sm` | Buttons and button-like links |
| `ui-card`, `ui-card__body`, `ui-card__title` | Boxed content |
| `ui-badge` | Small label / counter |
| `ui-alert`, `ui-alert--error`, `--success`, `--info` | Inline messages |
| `ui-field`, `ui-input`, `ui-label`, `ui-help` | Form controls |
| `ui-stack`, `ui-cluster`, `ui-grid` | Layout primitives |
| `ui-muted`, `ui-small`, `ui-visually-hidden` | Text utilities |
| `ui-spinner` | Loading indicator |

Adding a class here is a contract change: `default` must style it and
`bootstrap` must map it in the same PR.

## JS surface: client-side markup belongs to the theme too

> Status: target state, see `docs/ADR-001-JS-THEME-MARKUP.md`. Existing code
> in `assets-src` still builds Bootstrap markup in template strings and is
> being migrated.

The ownership rule above applies to markup created in the browser as well.
If JS inserts HTML with framework classes baked in (`btn`, `card`, `badge`,
`spinner-border`, ...), switching the theme changes the server-rendered page
but not the AJAX-loaded part of it, so the page ends up mixing two designs.
A theme on a different CSS framework then breaks outright.

The rule: **JS owns data and behavior; the theme owns markup and the UI
framework.** Scripts in `assets-src` must not hard-code a CSS framework's
markup or import its JS directly. They get markup and behavior through one of
three channels, all resolved through the same theme → `default` → module
cascade described above:

1. **Server-rendered fragments** — for content that already has a Twig
   component (comments and replies, user and post cards, pagination, search
   results). The endpoint renders the same `.twig` the full page uses and
   returns `{ "html": "...", "meta": { ... } }` when asked with
   `?render=html`. The client inserts `html` as-is. This is the default
   choice: one template, no drift between SSR and AJAX, escaping done by
   Twig autoescape.
2. **`<template>` elements** — for small client-only atoms that should not
   cost a request (spinner, empty state, error block, reply form, a
   messenger row). The theme provides them in
   `partials/js-templates.twig`, included by `layouts/base.twig`:

   ```twig
   <template data-ui="spinner">
       <span class="ui-spinner" aria-hidden="true"></span>
   </template>
   <template data-ui="empty">
       <p class="ui-muted" data-slot="message"></p>
   </template>
   ```

   JS clones them with `CMS.ui.clone('<id>')` / `CMS.ui.fill('<id>', data)`.
   `data-slot="<name>"` receives text; `data-slot-attr="<attr>:<name>"`
   receives an attribute value. Slots never receive raw HTML.
3. **UI adapter** (`assets-src/shared/ui.ts`, implemented) — for behavior
   that comes from a framework's JS (modals, toasts, tooltips). Bundled code
   imports `ui` from `shared/ui`; theme scripts use `window.CMS.ui`. Never
   `import … from "bootstrap"` outside the adapter.

   | Call | Does |
   |---|---|
   | `ui.modal.open(el)` / `ui.modal.close(el)` | Show / hide a modal element |
   | `ui.modal.onClosed(el, fn)` | Runs `fn` on every close, however it happened; returns an unsubscribe |
   | `ui.toast.show(el, type)` | Shows a toast element; `type` is `success`, `danger`, `warning` or `info` |
   | `ui.tooltip.init(root)` | Enables tooltips on `[data-ui-tooltip]` (and legacy `[data-bs-toggle="tooltip"]`) |
   | `ui.register(adapter)` | Replaces the adapter; the last registration wins |

   An adapter implements `modalOpen`, `modalClose`, `toastShow`,
   `tooltipInit`, and must dispatch `ui:modal-closed` on the modal element
   on every close. The native adapter (`<dialog>` or `hidden`, a timed
   toast, `title` tooltips) is the fallback; `site/ui-bootstrap.ts` is the
   Bootstrap one. The higher-level `CMS.toast({ message, type })` and
   `CMS.confirm({ ... })` fill the theme's markup and then go through the
   adapter. Today `site.js` registers the Bootstrap adapter for `default`;
   per ADR-002 it moves to the `bootstrap` theme.

What a theme gets for free: anything it does not override is inherited from
its parent chain down to `default` (fragments, `js-templates.twig`, the
adapter), exactly as for page templates. Because `default` is
framework-free, an inherited fragment never brings a foreign framework with
it; a theme only overrides what it wants to look different.

### Catalog of the JS surface

This list is the contract a theme author reads. Any PR that adds a new
fragment, template ID, slot, or adapter method must update it.

| Kind | Name | Source | Slots / API |
|---|---|---|---|
| Fragment | comment | `components/comments/comment.twig` | — |
| Fragment | comment reply | `components/comments/reply.twig` | — |
| Template | `spinner` | `partials/js-templates.twig` | — |
| Template | `empty` | `partials/js-templates.twig` | `message` |
| Template | `error` | `partials/js-templates.twig` | `message` |
| Template | `reply-form` | `partials/js-templates.twig` | `parent-id`, `submit-label`, `cancel-label` |
| Adapter | `modal` | theme script | `open(el)`, `close(el)`, `onClosed(el, fn)` |
| Adapter | `toast` | theme script | `show(el, type)` |
| Adapter | `tooltip` | theme script | `init(root)` |

Module-owned fragments (user cards, profile cards, search results) follow the
ownership rule: they live in `src/Modules/<Name>/views/components/` and are
listed in that module's docs, not here.

### What JS must never do

- Build markup with CSS-framework classes in template strings. Structural
  hooks (`data-*` attributes, the module's own BEM-like classes such as
  `msgr-*`) are fine; `btn`, `card`, `d-flex` and the like are not.
- Import a UI framework's JS (`bootstrap`, ...) outside the adapter that the
  theme registers.

### Checking it

```bash
# Framework classes inside client-side template strings:
grep -rnE 'class="[^"]*\b(btn|card|badge|alert|list-group|form-control|spinner-border|d-flex)\b' assets-src

# Framework classes and data-bs-* in the base theme and module views (ADR-002):
grep -rnE 'class="[^"]*\b(btn|card|badge|alert|list-group|form-control|spinner-border|d-flex)\b|data-bs-' \
  views/themes/default src/Modules --include="*.twig"

# Direct framework imports outside the adapter:
grep -rn 'from "bootstrap"' assets-src | grep -v ui-bootstrap.ts
```

Both should come back empty once the migration in ADR-001 is done.

## Where this applies

`views/themes/default/`, the directory configured by `THEME_DIR`, every
module `views/` folder, and — for the JS surface — `assets-src/`.

## Checking it

No CI check for this yet — same posture as `docs/MODULE_CONTRACT.md` and
`docs/PERFORMANCE_CONTRACT.md`, enforced through code review. Until there's
automation, this answers "is a component actually module-owned, and is it in
the wrong place":

```bash
# For a given component folder, e.g. article: does every file that
# includes it live inside that one module? If the only hits are inside
# src/Modules/Article, the component is module-owned and belongs in
# src/Modules/Article/views/components/article, not in the theme.
grep -rl "components/article/" src/Modules views/themes/default --include="*.twig"

# Folders under the theme's components/ whose name matches a module
# directory are the ones to move:
comm -12 \
  <(ls views/themes/default/components | sort) \
  <(ls src/Modules | tr 'A-Z' 'a-z' | sort)
```

Run these checks per component folder. Includes built from variable template
paths need manual inspection; a literal search cannot find every dependency.

## Enforcement

Manual, via code review — same as the two contracts above. Any PR that adds
a new `components/<name>/` folder under a theme should be asked "does `name`
match a module, and are these templates only ever included from that
module's own views?" If yes, it belongs in `src/Modules/<Name>/views/`
instead.

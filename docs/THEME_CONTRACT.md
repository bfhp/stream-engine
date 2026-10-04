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
boolean, and string values against the manifest schema. A theme without its
own saved value reads the nearest ancestor's saved value for a setting of the
same name, if it is valid for this theme, before falling back to its manifest
default.

A site that never saved `theme.active` uses the `THEME_DIR` theme if there is
one, otherwise `bootstrap` if it is installed, otherwise `default`.

`themeColor` is fixed theme metadata rather than an administrator setting. It
sets the browser chrome color through `<meta name="theme-color">`; changing it
requires changing the theme manifest.

Theme assets must be deployed below `public/themes/<id>/` and referenced as
`/themes/<id>/...`; a theme may also reuse core `/assets/...` files. External
URLs, traversal, and arbitrary public paths are rejected. Rendered asset URLs
receive an mtime-based `?v=` value, so changing the manifest or asset invalidates
browser caches.

The engine adds no CSS framework on its own; a theme lists its framework like
any other asset. The `bootstrap` theme lists `/assets/css/bootstrap.css`
(Bootstrap's own build) and `/assets/css/bootstrap-skin.css` (the site's
colors and navbar/footer tweaks). For right-to-left locales (see
`<html dir>`) the engine swaps every stylesheet that the theme chain maps in
`assets.rtl`, so only one build of each is loaded; `bootstrap` maps
`bootstrap.css` to `bootstrap-rtl.css`. `default` needs no mapping: its
`base.css` uses logical properties.

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

> Status: see `docs/ADR-002-BASE-AND-BOOTSTRAP-THEMES.md`. The platform layer
> (layouts, partials, `components/*` in the theme) is done: `default` is
> framework-free and `bootstrap` keeps the previous look. Module templates
> still use Bootstrap classes; they move to framework-free markup module by
> module, with `bootstrap` overriding them (see "Module templates in
> themes"). Done: Feedback, Search, Article, Profile. Under `default`, the other module pages are only
> partly styled until then. A module's own styles live in
> `assets-src/site/modules/<module>.css` (bundled into `site.css`).

### `default` is the base, not a design

`default` is the smallest theme that keeps every feature working: pages,
forms, modals, toasts, comments, pagination, messenger. It is what every
other theme inherits from and what the site falls back to after an error.
So it:

- loads no CSS framework: `/assets/css/base.css` (custom properties for
  color, spacing and type; light/dark through `data-color-mode`; logical
  properties for RTL) plus the shared `site.css`;
- uses semantic HTML and its own component classes (`site-nav__*`,
  `ui-modal`, `comment-*`, ...), never framework classes or `data-bs-*`;
- uses the native UI adapter (`<dialog>`, a toast region, `title`
  tooltips), `<details>` for dropdowns and the declarative `data-ui-*`
  toggles (see "JS surface");
- exposes `{% block %}`s at every point a child is likely to change,
  including class blocks (`main_class`, `layout_container_class`,
  `header_class`, ...), so children override blocks instead of copying
  files.

### `bootstrap` is the reference extended theme

`views/themes/bootstrap` keeps the site's Bootstrap look and is the example
theme authors copy from. It shows the ways to build on `default`:

1. **Override class blocks** where only classes differ:

   ```twig
   {# views/themes/bootstrap/layouts/with-sidebar.twig #}
   {% extends '@default/layouts/with-sidebar.twig' %}

   {% block layout_container_class %}container{% endblock %}
   {% block layout_row_class %}row{% endblock %}
   {% block layout_content_class %}col-lg-8{% endblock %}
   ```

2. **Override structural blocks and reuse the rest** with `block()`
   (`partials/header.twig`, `partials/footer.twig`, `partials/comments.twig`).
3. **Replace a component** whose structure is framework-specific (navbar,
   dropdowns, modals, toast, tabs, cards): same path, own markup. Keep the
   data-* hooks scripts rely on (`data-slot*`, `data-comment-form`,
   `data-reply-toggle`, ids such as `authFormModal`).
4. **Bring its own assets and adapter**: Bootstrap's prebuilt CSS (RTL
   build in `assets.rtl`), the site skin (`bootstrap-skin.css`) and
   `ui-bootstrap.js`. It sets `"inheritAssets": false` to control the order:
   `base.css` first (see below), then Bootstrap and the skin, then
   `site.css`.

### Module templates in themes

The rule, in both directions:

- **`default` knows nothing about any CSS framework and works without
  one.** Module templates are written for `default`: semantic HTML, the
  module's own classes and `default`'s classes, no framework classes.
- **A theme overrides only the pieces of markup where it wants its own
  framework's styles.** Everything it does not override keeps rendering
  `default`'s markup, so a theme built on `default` loads `default`'s
  `base.css` too - first, so the theme's framework wins for plain elements
  while `default`'s classes keep styling the markup the theme left alone.

There is no class mapping layer between modules and themes; a theme
overrides a module's templates the same way it overrides platform
components:

```twig
{# views/themes/bootstrap/modules/users/register1.twig - same path as the
   module's own file, so it is found first; @Users/ reaches the original #}
{% extends '@Users/modules/users/register1.twig' %}

{% block register_header %}
    <h1>{{ trans('user.registration') }} <small class="text-muted">{{ trans('view.users.register1.01') }}</small></h1>
    <hr>
{% endblock %}
```

- Prefer extending the module template (`@<Module>/...`) and overriding its
  blocks over copying the whole file; a module therefore puts the parts a
  theme is likely to restyle into blocks.
- Keep the module's behavior hooks (`data-*` attributes, ids, `<template>`
  fill points - see "JS surface"); they are the module's contract with its
  scripts, not styling.
- An override is optional. A module template no theme overrides renders as
  the module wrote it, under every theme.

This follows the ownership rule: the module still owns its content and its
default markup; the theme only replaces presentation it chooses to.

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

### `default`'s own classes

`base.css` styles the markup of `default`'s own templates: a few generic
`ui-*` classes (`ui-container`, `ui-button`, `ui-card`, `ui-input`,
`ui-badge`, `ui-alert`, `ui-muted`, `ui-spinner`, ...) plus component classes
(`site-nav__*`, `ui-modal`, `ui-toast`, `ui-tabs`, `comment-*`, ...). They
are an implementation detail of `default`, not a vocabulary other themes must
support or map: a theme that wants different markup overrides the template.
Themes built on `default` load `base.css` (before their own CSS) so these
classes keep working wherever the theme did not override the markup.

Visibility is the `hidden` attribute in every theme, not a class (`d-none`
and the like): scripts toggle `hidden`, and both `base.css` and Bootstrap's
reboot hide it.

## JS surface: client-side markup belongs to the theme too

> Status: see `docs/ADR-001-JS-THEME-MARKUP.md`. The UI adapter, the
> declarative toggles and `<template>` rendering are implemented, and
> comments use them. Module scripts (users, profile, search, forums, ...)
> still build Bootstrap markup in template strings; they move to
> `<template>` with their modules.

The ownership rule above applies to markup created in the browser as well.
If JS inserts HTML with framework classes baked in (`btn`, `card`, `badge`,
`spinner-border`, ...), switching the theme changes the server-rendered page
but not the AJAX-loaded part of it, so the page ends up mixing two designs.
A theme on a different CSS framework then breaks outright.

The rule: **JS owns data and behavior; the theme owns markup and the UI
framework.** Scripts in `assets-src` must not hard-code a CSS framework's
markup or import its JS directly. They get markup and behavior through these
channels, all resolved through the same theme → parents → `default` → module
cascade described above:

1. **`<template>` rendering** — the way API responses become markup. The
   Twig component that renders an item on the server is rendered once more,
   with placeholder data, inside `<template data-ui="<name>">`; the script
   clones it and fills in the API data. One component, so a theme that
   overrides it restyles both the server-rendered items and the ones the
   script adds, with nothing to change in JS. The API keeps returning JSON.

   - Platform templates (comments: `comment`, `reply`, `load-replies`) come
     from `partials/js-templates.twig`, included by `layouts/base.twig`
     only on pages that have comments. A theme adds its own in the
     `js_templates_extra` block.
   - A module renders its templates in its own page template, next to the
     list they feed, and only on pages whose scripts use them (for example
     a post card template inside the blog feed block). Never into every
     page.
   - One template per repeated item (a card, a row, a "show more" button),
     not whole sections: what the script adds is items.

   JS does `ui.clone('<name>')` (null if the theme has no such template)
   and `ui.fill(el, data)`. Fill points in the markup:

   | Attribute | Receives |
   |---|---|
   | `data-slot="key"` | Text content |
   | `data-slot-html="key"` | Inner HTML; server-sanitized values only (comment bodies) |
   | `data-slot-attr="attr:key attr2:key2"` | Attribute values; an empty value removes the attribute |
   | `data-slot-optional="key"` | The element is removed when the value is empty |

   Small transient states with no component of their own (a spinner inside
   a button while saving, an empty or error message) may stay in the script
   as plain text or minimal markup without framework classes.
2. **Declarative toggles** (`shared/ui.ts`, any theme):

   | Markup | Does |
   |---|---|
   | `data-ui-toggle="modal" data-ui-target="#id"` | Opens the modal through the adapter |
   | `data-ui-dismiss="modal"` | Closes the open modal it is in |
   | `data-ui-toggle="collapse" data-ui-target="#id"` | Toggles `hidden` on the target and `aria-expanded` on the trigger |
   | `data-ui-toggle="tab" data-ui-target="#pane"` | Shows the pane (`data-ui-active`), hides its siblings' panes, dispatches `ui:tab-shown` |

   Listen for `ui:tab-shown` (`TAB_SHOWN`) and use `ui.tabs.isActive(pane)`;
   the Bootstrap adapter re-emits `shown.bs.tab` as `ui:tab-shown`.
3. **UI adapter** (`assets-src/shared/ui.ts`) — for behavior that comes
   from a framework's JS (modals, toasts, tooltips). Bundled code imports
   `ui` from `shared/ui`; theme scripts use `window.CMS.ui`. Never
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
   toast, `title` tooltips) is what `default` uses; `site/ui-bootstrap.ts`,
   built as `/assets/js/ui-bootstrap.js` and listed only in the `bootstrap`
   manifest, is the Bootstrap one. Never import it from another bundle:
   Bootstrap loaded twice installs its data API twice. The higher-level
   `CMS.toast({ message, type })` and `CMS.confirm({ ... })` fill the
   theme's markup (`#globalToast`, `#globalConfirmModal`) and then go
   through the adapter.

What a theme gets for free: anything it does not override is inherited from
its parent chain down to `default` (templates, `js-templates.twig`, the
adapter), exactly as for page templates. Because `default` is
framework-free, an inherited component never brings a foreign framework
with it; a theme only overrides what it wants to look different.

### Catalog of the JS surface

This list is the contract a theme author reads. Any PR that adds a new
template, slot, toggle or adapter method must update it.

| Kind | Name | Source | Slots / API |
|---|---|---|---|
| Template | `comment` | `components/comments/comment.twig` (+ `form.twig` inside) | `id`, `author`, `avatar`, `date`, `dateTitle`, `datetime`, `content` (html), `inputId` |
| Template | `reply` | `components/comments/reply.twig` | `id`, `author`, `avatar`, `date`, `dateTitle`, `datetime`, `content` (html) |
| Template | `load-replies` | `components/comments/load-replies.twig` | `parentId`, `nextCursor`, `label` |
| Markup | toast | `components/ui/toast.twig` | `#globalToast`, `#globalToastBody` |
| Markup | confirm | `components/ui/modal_confirm.twig` | `#globalConfirmModal`, `#globalConfirmTitle`, `#globalConfirmBody`, `#globalConfirmOk` |
| Markup | sign-in | `components/auth/form.twig` | `#authFormModal`, `#authForm` |
| Adapter | `modal` | theme script | `open(el)`, `close(el)`, `onClosed(el, fn)` |
| Adapter | `toast` | theme script | `show(el, type)` |
| Adapter | `tooltip` | theme script | `init(root)` |

Module-owned templates (post cards, user cards, search results, ...) follow
the ownership rule: the component lives in
`src/Modules/<Name>/views/components/`, the module page renders its
`<template>`, and its name and slots are listed in that module's docs, not
here. A theme overriding such a component must keep its fill points.

### What JS must never do

- Build item markup in template strings: clone the item's `<template>`
  instead. Structural hooks (`data-*` attributes, the module's own classes
  such as `msgr-*`) in small transient markup are fine; `btn`, `card`,
  `d-flex` and the like are not.
- Import a UI framework's JS (`bootstrap`, ...) outside the adapter that the
  theme registers.

### Checking it

```bash
# Framework classes inside client-side template strings:
grep -rnE 'class="([^"]* )?(btn|card|badge|alert|list-group|form-control|spinner-border|d-flex|d-none)\b' assets-src

# Framework classes and data-bs-* in the base theme and module views (ADR-002):
grep -rnE 'class="([^"]* )?(btn|card|badge|alert|list-group|form-control|spinner-border|d-flex|d-none)\b|data-bs-' \
  views/themes/default src/Modules --include="*.twig"

# Direct framework imports outside the adapter:
grep -rn 'from "bootstrap"' assets-src | grep -v ui-bootstrap.ts
```

All three should come back empty once the module migrations are done; for
`views/themes/default` the second one already does. Bootstrap classes in
`views/themes/bootstrap` are expected and not checked.

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

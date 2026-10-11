# Developer Guide: Themes

A Stream Engine theme is a presentation layer for the public site. It can
provide layouts, shared chrome, CSS and browser adapters, and it can override
selected platform or module templates. It must not become the only place where
a module's feature-specific markup or behavior exists.

This chapter is the theme authoring and compatibility contract. The React
administration application is a separate interface and is not themed by this
system. Site administrators should use
[Appearance, navigation, and files](https://github.com/bfhp/stream-engine/wiki/Administrator-Appearance-and-Files)
to select and configure an installed theme.

## Theme and module ownership

The owner provides the canonical implementation; a theme may provide an
optional presentation override:

| Concern | Canonical owner | Theme responsibility |
| --- | --- | --- |
| Layouts, header, footer, navigation, auth, comments, share, common UI, and widget wrappers | `views/themes/default/` | Inherit or override presentation. |
| Feature pages and feature-specific components | The module's `views/` directory | Optionally override the same relative path. Never be the sole provider. |
| Feature behavior and data | Module PHP and TypeScript | Preserve the documented IDs, `data-*` hooks, form semantics, and slots. |
| Framework behavior | A theme adapter | Register through the shared UI API; do not make module code import the framework. |

For example, the Users module owns
`src/Modules/Users/views/modules/users/register1.twig`. A theme may override it
at `views/themes/acme/modules/users/register1.twig`, but removing that theme
must still leave a working registration page.

Prefer extending the owned template and replacing small blocks:

```twig
{# views/themes/acme/modules/users/register1.twig #}
{% extends '@Users/modules/users/register1.twig' %}

{% block register_header %}
    <h1 class="acme-heading">{{ trans('user.registration') }}</h1>
{% endblock %}
```

Every module is registered as a Twig namespace such as `@Users`; every theme
is registered as `@default`, `@bootstrap`, or `@<theme-id>`. Always use a
namespace when an override extends the file it replaces. A plain
`{% extends 'modules/users/register1.twig' %}` inside that override resolves
back to itself and recurses.

Twig is presentation code. Authorization, visibility decisions, validation,
CSRF enforcement, and business logic remain in PHP even when a theme changes a
form or page structure.

## Discovery and directory layout

The catalog scans direct child directories of:

1. the engine's `views/themes/` directory;
2. the root application's `views/themes/` directory when the engine is
   installed as a dependency; and
3. the exact directory configured by `THEME_DIR`, an environment-level legacy
   compatibility hook.

Themes inside an arbitrary Composer package are not discovered automatically.
Expose them through the root application's theme directory or configure one
exact directory with `THEME_DIR`.

Each directory needs a readable `theme.json`. Theme IDs must be globally
unique across all discovery locations. The first discovered valid manifest for
an ID wins; a root application must not rely on shadowing a built-in theme with
the same ID.

A small child theme can contain only its manifest and the files it changes:

```text
views/themes/acme/
├── theme.json
├── layouts/
│   └── base.twig          # optional override
└── partials/
    └── header.twig        # optional override

public/themes/acme/
├── site.css
├── site-rtl.css           # optional
└── site.js                # optional
```

The Twig directory and public assets are deliberately separate. The root Vite
configuration does not discover or build custom theme assets. Build or copy
them into `public/themes/<id>/` before the application creates its theme
catalog and deploy templates and assets together.

## Manifest

A representative `theme.json` is:

```json
{
  "id": "acme",
  "name": "Acme",
  "version": "1",
  "parent": "default",
  "inheritAssets": true,
  "themeColor": "#0F172A",
  "settings": {
    "color_mode": {
      "type": "select",
      "label": "Color mode",
      "default": "system",
      "options": {
        "light": "Light",
        "dark": "Dark",
        "system": "System"
      }
    },
    "accent": {
      "type": "color",
      "label": "Accent color",
      "default": "#2563EB"
    }
  },
  "assets": {
    "styles": ["/themes/acme/site.css"],
    "scripts": ["/themes/acme/site.js"],
    "rtl": {
      "/themes/acme/site.css": "/themes/acme/site-rtl.css"
    }
  }
}
```

The catalog rejects the whole manifest when a required value is invalid:

- `id` matches `[a-z][a-z0-9-]{0,49}`;
- `name` is non-empty and at most 100 bytes;
- `parent`, when present, is a valid different theme ID;
- `inheritAssets` is a boolean and defaults to `true`;
- `themeColor` uses `#RRGGBB` and defaults to `#0F172A`;
- `settings` and `assets` satisfy the schemas below.

`version` is string metadata and defaults to `1`. The engine does not currently
interpret it or enforce engine/theme compatibility ranges, so document any
compatibility requirements outside the manifest as well.

Every non-default theme implicitly has `default` as its parent. The `default`
theme is always the root regardless of a `parent` value in its manifest. A
missing parent, a cycle, or a chain deeper than eight themes including
`default` makes the theme unselectable.

## Inheritance and fallback

Twig lookup order is:

```text
active theme → nearest parent → … → default → module view directories
```

This lets a child override one layout while inheriting everything else. The
`default` theme is framework-free and provides the platform baseline and error
fallback. The `bootstrap` theme is the reference extended theme: it demonstrates
block overrides, structural replacements, module-template overrides, explicit
asset ordering, RTL CSS, and a framework UI adapter.

If `theme.active` has never been saved, Stream Engine selects a valid
`THEME_DIR` theme, otherwise `bootstrap` when available, otherwise `default`.
If the configured ID disappears or becomes invalid, the resolved theme is
`default`; the administration payload retains the missing configured ID and
reports the fallback.

Twig loader, syntax, or runtime errors while rendering a non-default theme
cause one retry with `default`. This protects the public response from a broken
override, but it does not catch controller failures and is not a substitute for
testing the theme.

An administrator can preview an installed catalog theme on a public page with
`?theme_preview=<id>`. Preview does not persist the selection and is ignored
for non-administrators. Do not use it as an access or feature flag.

## Assets and inheritance

Manifest asset paths may point only below `/assets/` or the current theme's
`/themes/<id>/` directory. External URLs, traversal, another theme's directory,
and arbitrary public paths are rejected. Every listed stylesheet or script
must exist below the root application's `public/` directory when the catalog
loads; one missing main asset invalidates the theme.

Parent assets load before child assets and are deduplicated by URL path.
Re-listing the same path in a child does not move or replace it. Give a changed
asset its own path, or set `inheritAssets` to `false` and declare the complete
ordered list. That flag affects assets only; Twig template inheritance still
uses the parent chain.

The catalog adds an mtime-based `?v=` query to local asset URLs. Keep manifest
paths free of query strings. For right-to-left locales, `assets.rtl` replaces a
listed LTR stylesheet with its RTL build. Child mappings win. A missing RTL
target is skipped and the LTR stylesheet remains in use; missing normal styles
or scripts invalidate the theme.

The engine does not add a CSS framework. If a theme requires one, list its CSS
and adapter script explicitly. Do not load the same framework a second time
from a module bundle.

## Theme settings

Supported setting types are `select`, `color`, `string`, and `boolean`. Keys
match `[a-z][a-z0-9_]{0,49}` and labels are non-empty and at most 100 bytes.
Select settings need a non-empty string-to-string `options` object and a
default contained in it. Color values use `#RRGGBB`; boolean defaults and
stored values are `0` or `1`; submitted strings are limited to 500 bytes.
Unknown keys and invalid values are rejected.

Values are stored as `theme.<id>.<key>` and the selection as `theme.active`, so
switching themes does not erase their individual settings. A child without its
own stored value reads the nearest ancestor's saved value for a setting of the
same name when that value is valid under the child's schema, then falls back to
the child's manifest default.

Manifest labels and option labels are currently literal strings; there is no
manifest translation-key mechanism. A `color_mode` select that contains
`light` and `dark` automatically gains `system` if it is missing. The default
base layout resolves `system` from `prefers-color-scheme` before styles load and
tracks operating-system changes. A replacement base layout must preserve that
behavior. `themeColor` is fixed metadata for browser chrome rather than an
administrator setting.

## Layouts, widgets, and stable markup

The default layout family consists of `base.twig`, `simple.twig`,
`single.twig`, and `with-sidebar.twig`. Extend their blocks where possible;
copying a whole file makes upgrades harder to merge.

Compatible layouts preserve the standard widget placements:

- `after_header`;
- `before_content` and `after_content`;
- `sidebar_top` and `sidebar_bottom`; and
- `footer_legal` and `footer_contacts`.

Render a placement through `components/widgets/block.twig`. Widget values are
administrator-managed trusted HTML and that component renders them raw. A
theme may reposition a placement deliberately, but should not silently omit a
supported placement.

When overriding any template, preserve semantic form behavior, security
fields, element IDs, ARIA relationships, and the `data-*` hooks consumed by
scripts. Visibility uses the HTML `hidden` attribute across all themes; keep
`[hidden] { display: none !important; }` stronger than framework display
utilities. Invalid inputs use `aria-invalid="true"`, not a framework-only
class.

## Browser UI contract

Browser code owns data and behavior; Twig owns markup and framework classes.
Shared and module scripts must not assemble framework-specific cards, buttons,
alerts, or spinners in template strings. Repeated API-backed markup comes from
`<template data-ui="name">`, cloned with `ui.clone(name)` and populated with
`ui.fill(element, data)`.

Fill points are:

| Attribute | Operation |
| --- | --- |
| `data-slot="key"` | Set text content. |
| `data-slot-html="key"` | Set inner HTML; use only for server-sanitized values. |
| `data-slot-attr="attr:key attr2:key2"` | Set attributes; an empty value removes one. |
| `data-slot-optional="key"` | Remove the element when the value is empty. |

Use `"1"` and `""` for flags, separate elements plus `hidden` for distinct
states, and semantic `data-tone` values for visual variants. A theme override
of a module component must retain all slots that the module script fills.

The platform templates `comment`, `reply`, and `load-replies` live in the
theme's comments components and `partials/js-templates.twig`. Modules render
their own templates only on pages that use them. Current module examples
include search results, profile cards, Users list/card/pagination items, and
Forums form previews.

Declarative behavior uses:

| Markup | Behavior |
| --- | --- |
| `data-ui-toggle="modal" data-ui-target="#id"` | Open a modal. |
| `data-ui-dismiss="modal"` | Close the containing modal. |
| `data-ui-toggle="collapse" data-ui-target="#id"` | Toggle `hidden` and `aria-expanded`. |
| `data-ui-toggle="tab" data-ui-target="#pane"` | Select a pane and emit `ui:tab-shown`. |

Bundled code imports `ui` from `assets-src/shared/ui`; a theme script uses
`window.CMS.ui`. The adapter surface is `modal.open`, `modal.close`,
`modal.onClosed`, `toast.show`, `tooltip.init`, `tabs.isActive`, and
`register`. A custom adapter implements modal open/close, toast show, and
tooltip initialization, and dispatches `ui:modal-closed` on every modal close.
The last registered adapter wins.

`default` uses the native adapter. `bootstrap` lists
`/assets/js/ui-bootstrap.js` only in its own manifest. Framework JavaScript
must be imported only by its adapter; importing or bundling it elsewhere can
install duplicate data APIs. Higher-level `CMS.toast()` and `CMS.confirm()`
use the theme-owned `#globalToast` and `#globalConfirmModal` markup through the
same adapter.

## RTL and accessibility

The locale controls `<html dir>`. Use CSS logical properties for direction-
independent spacing and alignment, and provide `assets.rtl` mappings only when
a dependency needs a separate build. Do not infer direction from a language
name in theme code.

Preserve semantic headings, labels, keyboard operation, focus behavior, ARIA
relationships, and visible focus indicators. Test long translated labels and
mixed-direction user content as well as ordinary LTR text. Theme compatibility
includes behavior with keyboard navigation and assistive technology, not just
pixel similarity.

## Upgrade and compatibility workflow

An override can lag behind the canonical template after a module or platform
change. Keep overrides small, extend named blocks, and audit the upstream diff
on every engine upgrade. In particular, look for new security fields, element
IDs, data hooks, slots, ARIA relationships, and widget placements.

Before publishing a theme:

1. Build or copy every manifest asset into the root `public/` tree.
2. Select the theme and every supported color mode in the administration UI.
3. Exercise guest, authenticated, moderator, and administrator-visible public
   states as applicable.
4. Test narrow and wide screens, LTR and RTL locales, keyboard use, and pages
   with widgets, forms, comments, and AJAX-added items.
5. Remove or break one override locally and verify inherited and `default`
   fallback behavior.
6. Compare every overridden canonical template with the current engine/module
   version before each release.

Useful repository checks include:

```bash
# Framework-specific markup must not leak into default or module templates.
rg -n 'data-bs-|class="[^"]*(btn|card|badge|alert|list-group|form-control|spinner-border|d-flex|d-none)' \
  views/themes/default src/Modules -g '*.twig'

# Framework JS imports belong only in the theme adapter.
rg -n 'from "bootstrap"' assets-src -g '*.ts' \
  | rg -v 'assets-src/site/ui-bootstrap.ts'

vendor/bin/phpunit \
  tests/Core/ThemeCatalogTest.php \
  tests/Service/ThemeServiceTest.php \
  tests/Service/WidgetServiceTest.php

npm test -- --run tests/js/ui.test.ts
npm run typecheck
```

The searches are review aids rather than complete policy enforcement. Exclude
the separate `src/Modules/Admin` application when investigating an expected
framework match, and inspect dynamically selected template paths manually.

## Review checklist

- The theme ID is unique, its manifest is valid, and every listed asset exists
  in an allowed public path.
- The parent chain is deliberate; asset inheritance and load order are tested.
- Module-specific canonical markup remains in its module; theme copies are
  optional overrides at the same relative path.
- Overrides extend namespaced templates where possible and retain security,
  accessibility, widget, ID, hook, and slot contracts.
- Browser code contains behavior and data mapping, not framework-specific item
  markup; framework JavaScript stays inside the theme adapter.
- `hidden`, invalid state, modal-close events, templates, AJAX-added content,
  and administrator preview work under the theme.
- Light, dark, system, LTR, RTL, responsive, keyboard, and supported user states
  have been exercised.
- Default rendering and fallback still work after the change.
- Theme catalog, setting, widget, shared UI, type, and frontend build checks
  relevant to the change pass.

[Back: Pages and routing](https://github.com/bfhp/stream-engine/wiki/Developer-Pages-and-Routing) · [Developer Guide](https://github.com/bfhp/stream-engine/wiki/Developer-Guide) · [Next: Frontend development](https://github.com/bfhp/stream-engine/wiki/Developer-Frontend)

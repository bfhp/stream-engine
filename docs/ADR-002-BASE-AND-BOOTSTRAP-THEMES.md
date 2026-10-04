# ADR-002: A framework-free `default` theme and a Bootstrap theme built on top of it

**Status:** Proposed
**Date:** 2026-10-04
**Deciders:** bfhp
**Related:** ADR-001 (JS markup comes from the active theme), `THEME_CONTRACT.md`

## Context

Today `default` is both the fallback every other theme inherits from and a
complete Bootstrap design:

- `theme.json` loads `/assets/css/bootstrap.css`, and `ThemeCatalog`
  prepends it to any theme that lists `site.css` without it.
- 25 of 34 theme templates and 40 of 50 module templates
  (`src/Modules/*/views`) use Bootstrap classes; 41 places use `data-bs-*`.
- `site.js` bundles Bootstrap's JS (`Toast`, `Modal`, `Tooltip`).

As a result, every theme inherits Bootstrap whether it wants it or not. A
theme on another framework has to override almost everything, and the
fallback after a Twig error (`default`) silently brings Bootstrap back.

## Decision

Split the current theme into two layers.

### 1. `default` — the base theme

The smallest theme that keeps **all functionality**: every page, form,
modal, toast, comment thread, pagination and messenger view works.

- **No CSS framework.** Markup uses semantic HTML plus a small, documented
  UI vocabulary of classes (see below). No `btn`, `card`, `d-flex`,
  `data-bs-*`.
- **Minimal CSS** (`/assets/css/base.css`): CSS custom properties for colors,
  spacing and typography; light/dark through `color_mode` and
  `prefers-color-scheme`; logical properties (`margin-inline-start`, ...) so
  RTL works without a separate build.
- **Native behavior.** The default UI adapter from ADR-001 uses platform
  features: `<dialog>` for modals, a small toast region with
  `aria-live`, the Popover API / `title` for tooltips. No framework JS.
- **Block-rich templates.** Layouts, partials and components expose
  `{% block %}`s at every point a child theme is likely to change (wrapper,
  header, body, actions, footer of each component), so child themes override
  blocks, not whole files.
- Stays the hard fallback: if the active theme fails, the site is plain but
  fully usable.

### 2. `bootstrap` — the reference extended theme

A theme that looks like today's site and shows theme developers how
extending works.

- `theme.json` declares `"parent": "default"` and loads Bootstrap CSS/JS and
  its own `site.css`.
- **Templates extend the base, not copy it:**

  ```twig
  {# views/themes/bootstrap/layouts/base.twig #}
  {% extends '@default/layouts/base.twig' %}

  {% block layout_header %}
      {% include 'partials/navbar.twig' %}
  {% endblock %}
  ```

  It overrides only layouts, chrome and platform components where the
  structure differs (navbar, modal, toast, tabs).
- **Module markup is restyled, not overridden.** Module templates stay owned
  by modules (`THEME_CONTRACT.md`). The theme maps the UI vocabulary onto
  Bootstrap in Sass (`.ui-button { @extend .btn; }`, ...), so 40 module
  templates do not need per-theme copies.
- Registers the Bootstrap UI adapter (ADR-001) from its own script entry.
- Handles its own RTL (`bootstrap.rtl.css`), the job `ThemeCatalog` does for
  everyone today.

### 3. UI vocabulary

A short, stable set of classes that both module templates and JS-produced
markup are allowed to use. It is the contract between modules and themes:
modules describe *what* something is, themes decide *how* it looks.

| Class | Meaning |
|---|---|
| `ui-button`, `ui-button--primary`, `--secondary`, `--danger`, `--link`, `--sm` | Buttons and button-like links |
| `ui-card`, `ui-card__body`, `ui-card__title` | Boxed content |
| `ui-badge` | Small label / counter |
| `ui-alert`, `ui-alert--error`, `--success`, `--info` | Inline messages |
| `ui-field`, `ui-input`, `ui-label`, `ui-help` | Form controls |
| `ui-stack`, `ui-cluster`, `ui-grid` | Layout primitives (vertical, wrapping row, grid) |
| `ui-muted`, `ui-small`, `ui-visually-hidden` | Text utilities |
| `ui-spinner` | Loading indicator |

The list is deliberately small. Anything more specific uses the module's own
classes (`msgr-*`, `comment-*`), which are styled by the theme like any
other selector.

### 4. Theme inheritance

- `theme.json` gets an optional `"parent"` (default: `"default"`). The loader
  builds the chain *active → parent → … → default → module views* instead of
  the fixed *active → default → modules*.
- Every theme in the chain is registered as a Twig namespace (`@default`
  already exists; add `@bootstrap`, `@<id>`), so a child can
  `{% extends '@<parent>/...' %}` the file it overrides.
- Module views get namespaces too (`@Article/...`), so a theme that does
  override a module component can extend the original instead of copying it.
- Assets: a child lists its own `styles`/`scripts`; parent assets are
  included unless the child sets `"inheritAssets": false` (the `bootstrap`
  theme keeps `base.css` for custom properties and drops nothing else).

## Options considered

| | A. Keep Bootstrap in `default` (today) | B. Bare `default` + `bootstrap` child (this ADR) | C. Bare `default`, no reference theme |
|---|---|---|---|
| Fallback after an error | Bootstrap | Plain, fully working | Plain, fully working |
| Cost of a non-Bootstrap theme | Override nearly everything | Override blocks + map UI vocabulary | Same as B |
| Example for theme authors | None (default *is* the theme) | `bootstrap` shows parent/blocks/adapter | None |
| Look of existing sites | Unchanged | Unchanged after migration to `bootstrap` | Plain |
| Effort | — | High: rewrite markup of ~65 templates + JS | Same as B minus one theme |

## Trade-offs

- **Two themes to maintain.** Every feature now lands in `default` first and
  is checked in `bootstrap`. That is the price of having a real proof that
  the base is framework-free; without a second theme it would quietly drift
  back to Bootstrap.
- **Sass `@extend` vs template overrides in `bootstrap`.** `@extend` keeps
  module templates untouched but produces heavier selectors, and some
  Bootstrap components need structure the vocabulary does not have (e.g.
  `input-group`). Those cases go through block overrides or extra vocabulary
  classes, decided case by case.
- **Plain fallback.** If `bootstrap` breaks, users briefly see the plain
  base. Acceptable: it is a failure mode, and the site keeps working.

## Consequences

- **Easier:** a theme on Tailwind or anything else starts from a neutral
  base; the default bundle gets lighter (no Bootstrap CSS/JS); theme authors
  have a working example of `parent`, `@extends` and block overrides.
- **Harder:** every module template must be rewritten to the UI vocabulary;
  new markup must be reviewed against it; two themes are tested.
- **Migration:** existing installs with `theme.active = default` (or unset)
  are switched to `bootstrap` by a migration, so their look does not change.
  `theme.default.*` settings are copied to `theme.bootstrap.*`.
- **Revisit:** whether `bootstrap` should be the installer's preselected
  theme; whether the UI vocabulary needs a CI check.

## Plan

Phases are ordered so the site works and looks the same after each one.

### Phase 0 — groundwork

1. [ ] `theme.json` `parent` + loader chain + Twig namespaces for every theme
   and module (`StreamEngine::createTwigEnvironment`, `ThemeCatalog`).
2. [ ] Remove the automatic `bootstrap.css` prepend and RTL swap from
   `ThemeCatalog`; move both into the `bootstrap` theme manifest.
3. [ ] `CMS.ui` adapter interface with a native implementation and a
   Bootstrap implementation (ADR-001, item 1).

### Phase 1 — create `bootstrap` as a copy

4. [ ] Create `views/themes/bootstrap` with `"parent": "default"` and the
   current Bootstrap assets; migrate installs to it. Nothing looks different.

### Phase 2 — strip `default`

5. [ ] Define the UI vocabulary and write `base.css`.
6. [ ] Rewrite `default` layouts, partials and platform components to the
   vocabulary, adding blocks; move Bootstrap-specific structure into
   `bootstrap` overrides that `{% extends '@default/...' %}`.
7. [ ] Rewrite module templates to the vocabulary, module by module; add
   the Sass mapping in `bootstrap` alongside each module.
8. [ ] Replace `data-bs-*` with `data-ui-*` handled by the adapter.
9. [ ] Finish ADR-001 items 2–4 (fragments and `<template>`s now come out
   framework-free from `default`).

### Phase 3 — verify

10. [ ] Visual regression: screenshots of key pages in `bootstrap` before vs
    after each phase.
11. [ ] Functional pass on `default`: every page, form, modal, toast,
    comments, pagination, messenger.
12. [ ] Grep checks from `THEME_CONTRACT.md` return nothing for `default`,
    module views and `assets-src`.
13. [ ] Update `THEME_CONTRACT.md` and `INSTALLATION.md`.

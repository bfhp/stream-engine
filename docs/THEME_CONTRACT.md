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

## Override resolution — how a new theme is meant to work

The loader (`StreamEngine::handleRequest()`) resolves a template name
(`layouts/base.twig`, `components/article/example.twig`, ...) against an
ordered list of filesystem paths and returns the first match. For a theme
to be a genuine override layer — able to restyle a layout while leaving
everything it doesn't touch alone — that search order needs to be:

1. The directory configured by `THEME_DIR`, checked first so the site can
   override anything below. This layer is optional.
2. `views/themes/default/` — the baseline theme, providing every
   layout/partial/platform-component a new theme doesn't bother overriding.
3. Each module's own `views/` — so a module's components resolve even when
   no theme (default or active) overrides them.

Concretely: a site can set `THEME_DIR=/var/www/site/views/theme` and put only
the files it wants to change there, such as `layouts/base.twig` and
`partials/header.twig`. Every other template keeps resolving from `default`
or from the module that owns it.

## Where this applies

`views/themes/default/`, the directory configured by `THEME_DIR`, and every
module `views/` folder.

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

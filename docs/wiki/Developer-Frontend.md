# Developer Guide: Frontend development

Stream Engine has two browser surfaces: progressively enhanced public pages
rendered by Twig, and a React administration application. Both are written in
TypeScript, share framework-neutral utilities, and are built by Vite into
committed files under `public/assets/`.

This chapter documents source ownership, entry points, runtime integration,
admin extensions, localization, testing, and the generated-asset contract.
Theme-owned markup and framework adapters are covered separately in
[Themes](https://github.com/bfhp/stream-engine/wiki/Developer-Themes).

## Frontend surfaces

| Surface | Source | Runtime model |
| --- | --- | --- |
| Public site shell | `assets-src/site/` | `site.js` initializes shared behavior on server-rendered HTML. |
| Public page features | `assets-src/pages/` | A controller includes only the entry needed by that page. |
| Shared browser utilities | `assets-src/shared/` | Imported by site, page, admin, and module entries. No standalone entry unless Vite creates a shared chunk. |
| Administration application | `assets-src/admin/` | React and Mantine mounted below the `/admin` shell with a `HashRouter`. |
| Root site modules | `modules/<Name>/assets/` | Optional top-level `.ts` or `.tsx` entries discovered by Vite. |
| Theme behavior | Theme manifest plus an adapter entry | Loaded only by that theme; must use the shared UI contract. |
| Tests | `tests/js/*.test.ts` | Vitest in jsdom with explicit imports from `vitest`. |

Browser code does not establish trust. Server routes still authenticate,
authorize, validate input, verify CSRF for mutations, and sanitize any HTML
that the browser is allowed to insert.

## TypeScript contract

`tsconfig.json` checks application sources, tests, and Vite/Vitest
configuration together. It uses strict mode, `noImplicitReturns`,
`noUnusedLocals`, DOM libraries, and `noEmit`. Vite transpiles the source but
does not perform the complete type check, so a successful production build
does not replace:

```bash
npm run typecheck
```

Use `.ts` for DOM and utility code and `.tsx` for React components. Keep
framework-neutral utilities in `assets-src/shared/`; keep page behavior close
to its public feature in `assets-src/pages/`; and keep React administration
code in `assets-src/admin/`. Do not put server policy or trusted validation in
any of them.

Engine browser code can import by a relative path. Root module and site-owned
code can use `@stream-engine/...`, which Vite and Vitest resolve to the active
engine's `assets-src/` directory. The root TypeScript configuration must map
the same alias and include any site-owned module sources it type-checks.

Avoid adding values to `window`. The public shell deliberately exposes one
compatibility surface, `window.CMS`, for separately loaded page and theme
entries. New reusable code should normally be an exported module first; expose
it through `CMS` only when independent entries must consume it at runtime.

## Vite entry points

The root `vite.config.ts` builds explicit entries with stable output names:

- `admin` for the administration SPA;
- `site` for shared public behavior and public-site CSS;
- page entries such as `register`, `search`, `feedback`, `messages`,
  `retrieve`, `profile`, `users`, `forums`, and `blog-post-form`; and
- theme-support entries such as `base`, `bootstrap`, `bootstrap-rtl`,
  `bootstrap-skin`, and `ui-bootstrap`.

Entry JavaScript is written to `/assets/js/<name>.js`; extracted CSS, images,
fonts, and shared chunks use the configured `css/`, `img/`, `fonts/`, and
`js/chunks/` directories. Only entry filenames are a stable integration
surface. Do not link a generated chunk directly: its existence and name depend
on the current import graph.

The build has no Vite HTML entry and the PHP application does not read a Vite
manifest. Twig and controllers link the known entry files themselves. It also
means Vite does not inject stylesheets or preload tags into public pages.

### Root module entries

Vite scans only immediate `.ts` and `.tsx` children of
`modules/<Name>/assets/`. A `.d.ts` file is ignored and subdirectories are for
helpers imported by an entry. The filename becomes a global output name:

```text
modules/Notes/assets/notes.ts  →  public/assets/js/notes.js
```

Names must not collide with another module entry or the built-in reserved
entries. Duplicate module names and reserved names fail the build. Removing a
module directory removes its discovered entry on the next build.

This discovery applies to root site modules, not controllers installed inside
the engine's `src/Modules/` tree. An engine-owned page entry must be declared
explicitly in `vite.config.ts`.

## Connecting an entry to a public page

The theme manifest loads shared site assets. A feature controller adds its
page-specific entry through trusted `head_ext` view data:

```php
return ViewModel::fromPage($page, 'modules/notes/index.twig', [
    'head_ext' => [
        '<script type="module" src="/assets/js/notes.js" defer></script>',
    ],
]);
```

`layouts/base.twig` renders `head_ext` raw. Populate it only with fixed,
developer-owned tags; never concatenate administrator or request input into an
asset tag. Use `type="module"` for generated JavaScript and load an entry only
on pages that need it.

An entry that imports CSS may produce a dedicated stylesheet or contribute to
a shared stylesheet after Vite code splitting. Because there is no generated
HTML to inject that CSS, inspect the production build and explicitly link every
required extracted stylesheet. Trix is the current example: both Forums and
the blog editor import it, so their controllers explicitly include
`/assets/css/trix.css` as well as their JavaScript entry.

Do not guess or link internal chunks to work around a missing style. Fix the
entry/import arrangement or link the stable extracted CSS output deliberately.

## Public-site initialization

`assets-src/site/main.ts` imports the public style graph, installs the shared
`CMS` API, starts hash-route observation, and initializes global behavior on
`DOMContentLoaded`. Page entries are independent modules and may execute
before or after that callback.

Initialization functions that scan the DOM must be safe when their markup is
absent. When a function may run more than once, mark initialized elements with
a stable `data-*-ready` hook or otherwise make the operation idempotent. Prefer
event delegation for dynamic descendants and return unsubscribe functions from
reusable listeners where practical.

Public pages are server-rendered first. JavaScript should enhance a usable
semantic baseline, preserve form submission and accessibility where the
feature permits, and avoid owning markup that belongs to Twig. For API-loaded
repeated items, follow the `<template data-ui>` and slot contract in
[Themes](https://github.com/bfhp/stream-engine/wiki/Developer-Themes#browser-ui-contract).

Use `CMS.hashRoute` when several features share public-page fragment state. It
treats the hash as parameters, preserves keys owned by other features,
distinguishes navigation from programmatic writes, and keeps Back/Forward
behavior coherent. The admin application has its own React `HashRouter`; do
not mix the two routing models.

## Shared browser utilities

Before adding another local implementation, check `assets-src/shared/` and the
public `CMS` bridge. Current shared contracts include:

| Utility | Purpose |
| --- | --- |
| `csrf.ts` | Read the CSRF cookie and create the mutation header. |
| `api-errors.ts` | Normalize current API failure payloads into displayable errors. |
| `i18n.ts` and `plural.ts` | Translation, interpolation, locale fallback, number formatting, and plural selection. |
| `ui.ts` | Theme-neutral modal, toast, tooltip, tab, toggle, and template behavior. |
| `uploads.ts` | Upload calls and their CSRF handling. |
| `navigation-url.ts` | Resolve safe same-origin navigation targets. |
| `date-time-format.ts` | Format using document locale/date/time preferences. |
| `bytes.ts` | Format byte quantities consistently. |
| `feed-types.ts` | Read feed-type labels generated from the PHP module registry. |
| `escape.ts` | Escape text for the limited places that must assemble HTML strings. |

Prefer DOM `textContent` or React text interpolation to HTML strings. If HTML
insertion is required, its provenance and sanitization boundary must be
explicit. `CMS.escapeHtml` protects text inserted into a deliberately built
HTML fragment; it does not make arbitrary server HTML safe. Use
`data-slot-html` only for content sanitized by the server.

Mutating calls send `X-CSRF-Token`. Public JSON calls normally use `CMS.api()`;
uploads use the shared upload helpers because `FormData` must set its own
content boundary. The admin bundle does not load `site/app.ts`, so admin calls
use `csrfHeaders()` directly. Do not copy the cookie parser into a feature.

[API and security](https://github.com/bfhp/stream-engine/wiki/Developer-API-and-Security)
defines response, authorization, validation, and CSRF rules. Preserve that
server contract and use `getApiErrorMessage()` or `getApiResponseError()`
instead of inventing another error shape parser.

## Styling and theme boundaries

`assets-src/site/main.ts` is the root of shared public-site CSS. Module-owned
default styles currently live below `assets-src/site/modules/` and are imported
there. Page-only vendor CSS may be imported by its page entry and linked as a
separate build output.

Public feature markup remains framework-free. Do not add Bootstrap or another
theme framework's class names to `src/Modules/*/views` or construct them in
shared/page TypeScript. A theme can replace the Twig component and register a
framework adapter while behavior continues to target stable semantic IDs,
`data-*` attributes, slots, `hidden`, and ARIA state.

The React administration application is not part of the public theme. Its
Mantine styles are imported by the admin entry, and it may use admin-specific
React components and styling without extending the public theme contract.

## Administration application

PHP serves the shell at `/admin`; `assets-src/admin/main.tsx` mounts React into
`#root`. `mountAdmin()` installs Mantine providers, notifications, direction
from the document, and React Router's `HashRouter`. Built-in client routes live
after the hash, for example `/admin/#/themes`.

Keep page components under `assets-src/admin/pages/`, shared React components
under `assets-src/admin/components/`, and pure helpers under
`assets-src/admin/lib/`. Extract data transformations from components when
they can be tested without rendering. Lazy-load unusually heavy or infrequent
pages; `FileBrowser` and `Cron` demonstrate the current `lazy()` and
`Suspense` pattern.

Route visibility is not authorization. Hiding a navigation item or protecting
a React route never replaces permissions in its API controller.

### Module-provided admin pages

A site module may describe a lazy route in `modules/<Name>/admin/index.ts`:

```ts
import type { AdminPage } from "@stream-engine/admin/module-pages";

export default [
    {
        path: "/notes",
        labelKey: "js.notes.admin.navigation",
        load: () => import("./Notes"),
    },
] satisfies AdminPage[];
```

Paths are static absolute lowercase paths made of letters, digits, and
hyphens. Parameters and trailing slashes are not supported. A route cannot
duplicate another module or use a built-in first segment such as `feeds`,
`pages`, `menus`, `settings`, `themes`, `widgets`, `file-browser`, `uploads`,
or `users`. Labels use `js.*` translation keys. Route metadata loads eagerly;
the component returned by `load()` is lazy.

The shipped `assets-src/admin/main.tsx` currently calls `mountAdmin()` without
a module map, so it does not discover root module admin pages automatically. A
site that needs them must own or adapt the admin entry, collect them, and pass
the result:

```ts
import { mountAdmin } from "@stream-engine/admin/admin";

const pages = import.meta.glob("/modules/*/admin/index.ts", { eager: true });
mountAdmin(pages);
```

Point the site's `admin` Vite input at that entry and include the site sources
in its TypeScript configuration. Do not document module admin discovery as
automatic until the shipped entry actually performs it.

## Browser localization and generated build data

Browser messages come from `src/Lang/<locale>.php`. The Vite translation plugin
executes PHP during build, loads each catalog, and includes only keys beginning
with `js.` in the virtual `virtual:translations` module. Therefore frontend
work that adds a visible string must add its key to every supported server
catalog; a build is required after catalog changes.

`trans()` falls back to English and then the key. Locale resolution normalizes
case and underscores, tries the exact locale and its base language, and reads
the requested value from `<html lang>`. Use `transChoice()` for plural forms
and `transChoiceWithCount()` when the formatted number should be part of the
label. Do not concatenate translated sentence fragments.

There is currently no module-local translation catalog discovery. A site-owned
module must customize the translation integration or accept visible fallback
keys. This limitation applies to module admin page labels too.

The second build plugin executes the Composer autoloader and `ModuleRegistry`
to generate `virtual:feed-types`. Consequently, a production frontend build
requires a working `php` executable, installed Composer dependencies, and an
autoload file. Rebuild after changing language catalogs, module discovery, or
feed-type declarations, even if no `.ts` file changed.

## Testing

Vitest uses `vitest.config.ts`, not the production build configuration. It
resolves the same engine alias and build-data virtual modules, runs
`tests/**/*.test.ts` in jsdom, restores mocks between tests, and limits workers
for predictable CI memory use. Tests import `describe`, `it`, `expect`, and
mock helpers explicitly; globals are disabled.

Put pure behavior behind exported functions and inject browser/API dependencies
when doing so makes failure paths deterministic. Reset modified DOM, timers,
translations, adapters, and global state in `afterEach`. Cover the regression,
not only the successful branch: malformed payloads, missing markup, rejected
requests, repeated initialization, navigation, accessibility state, and stale
asynchronous responses commonly need explicit tests.

Run one file while iterating:

```bash
npm test -- --run tests/js/example.test.ts
```

Then run the frontend gate:

```bash
npm test
npm run typecheck
npm run build
```

The build is a separate check because tests do not exercise entry discovery,
name collisions, CSS extraction, PHP-backed virtual modules, asset output, or
third-party license generation.

## Generated assets and deployment

`npm run build` writes production files to `public/assets/` with
`emptyOutDir: true`. It deletes that directory's previous contents before
writing, so never store hand-maintained files there. Source assets belong in
an imported source directory or another intentional public location.

The generated assets and `THIRD_PARTY_LICENSES.md` are tracked in Git and are
part of release packages. After any source, dependency, entry, translation, or
build-configuration change:

1. run a clean production build;
2. review added, removed, and unexpectedly renamed outputs;
3. commit the matching `public/assets/` changes with the source changes; and
4. deploy PHP/templates and compiled assets from the same revision.

Do not edit generated JavaScript or CSS directly. A generated-only fix is lost
on the next build and leaves the source incorrect. Stable entry names also mean
deployments must replace related files together; serving an old chunk beside a
new entry is not a supported rolling compatibility mechanism.

`npm run dev` starts the standalone Vite development server, but Stream Engine
continues to reference compiled `/assets/` URLs and does not automatically use
HMR. For browser verification through the application use:

```bash
npm run build -- --watch
```

or run `npm run build` after each source change.

## Review checklist

- The change belongs to the public shell, one page entry, a shared utility,
  the admin SPA, a root module entry, or a theme adapter, with no duplicated
  owner.
- Entry names are unique; page controllers link only stable entry outputs and
  every extracted stylesheet they require.
- Initializers tolerate missing markup, repeated initialization where needed,
  dynamic content, and asynchronous teardown or navigation.
- Public markup remains theme-neutral and accessible; browser code preserves
  the Twig/slot/UI-adapter contract.
- Visible text uses translated `js.*` keys in every catalog and correct plural
  handling.
- HTML insertion, same-origin navigation, API errors, uploads, and CSRF use the
  shared utilities and have an explicit trust boundary.
- Admin navigation and route guards are not treated as server authorization.
- Vitest covers success and relevant failure/regression paths; strict
  TypeScript passes.
- A production build succeeds, generated assets and licenses are reviewed, and
  the source and output changes are committed together.

[Back: Themes](https://github.com/bfhp/stream-engine/wiki/Developer-Themes) · [Developer Guide](https://github.com/bfhp/stream-engine/wiki/Developer-Guide) · [Next: API and security](https://github.com/bfhp/stream-engine/wiki/Developer-API-and-Security)

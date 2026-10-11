# Developer Guide: Modules

Modules are Stream Engine's feature boundary. A module may provide public page
actions, runtime routes, templates, frontend entries, administration pages,
API endpoints, scheduled tasks, and dashboard cards. The engine discovers the
module controller through Composer; there is no central module list to edit.

This chapter describes the current extension contract for built-in modules and
site-owned modules. It also calls out the places where a site build still has
to perform explicit integration.

## The dependency boundary

The dependency direction is deliberately one-way:

```text
module -> Core / Service / Repository / Domain
platform -X-> module
module A -X-> module B
```

A module may use shared platform services and its own private classes. It must
not import another module, and code in `src/Core`, `src/Service`,
`src/Repository`, `src/Domain`, or `src/StreamEngine.php` must not import a
module. This makes removing one module independent of unrelated features.

A module must not assume that another module's actions, routes, tables, or
records exist. Shared data and behavior belong behind a platform-owned service
or interface. If the capability is optional, the platform contract should
remain usable when the providing feature is absent rather than importing that
feature directly.

When more than one module needs a capability, put a general contract or
service in the shared platform. When only one module needs it, keep the
repository, service, value object, and other supporting code inside that
module. Construct module-private collaborators in the module controller from
the shared dependencies it receives; do not wire them into
`StreamEngine::__construct()`.

Do not add a feature-specific field to a shared domain object merely because
it is accessible. If only one module produces and consumes the value, build a
module-private view model or data structure and pass it to that module's
template.

These commands provide a useful boundary review:

```bash
# The platform must not import any module. Expected result: no output.
rg -n 'StreamEngine\\Modules\\' \
  src/Core src/Service src/Repository src/Domain src/StreamEngine.php

# Replace Forums with the module under review. Inspect every match and reject
# imports from a different module namespace.
rg -n 'StreamEngine\\Modules\\' src/Modules/Forums
```

The second check includes the module's own namespace, so it requires review
rather than an empty result. The boundary is currently enforced through code
review, not a dedicated CI check.

## Module layout

A site-owned module may grow into this structure:

```text
modules/
  Notes/
    NotesController.php
    NotesRepository.php
    views/
      modules/
        notes/
          index.twig
    assets/
      notes.ts
      helpers/
        editor.ts
    admin/
      index.ts
      Notes.tsx
```

Only the controller is required. It must be a concrete class implementing
`ControllerInterface`; extending `AbstractController` supplies no-op defaults
for optional contributions.

The controller contract exposes these contribution points:

| Method | Contribution |
| --- | --- |
| `pageActions()` | Administrator-created public page types and their configuration contract. |
| `feedTypes()` | Globally named feed types available to page action descriptors. |
| `getBreadcrumb()` | Breadcrumb data for a resolved public page. |
| `show()` | The public page view model. |
| `registerRuntimePages()` | In-memory module-owned routes below a configured mount. |
| `registerApi()` and `callApi()` | `/api/v1` route declarations and request dispatch. |
| `registerCron()` and `runCron()` | Scheduled task declarations and execution. |

The controller's location is not discovered by scanning `modules/`. Instead,
`ModuleRegistry` examines the classmaps registered with Composer and selects
instantiable `ControllerInterface` implementations. Abstract controllers and
unloadable optional integration classes are ignored.

## Configure discovery

Add a PSR-4 mapping for the site namespace to the root site's
`composer.json`, and enable optimized autoloading in that same root manifest:

```json
{
  "autoload": {
    "psr-4": {
      "MySite\\Modules\\": "modules/"
    }
  },
  "config": {
    "optimize-autoloader": true
  }
}
```

Merge these keys with the existing manifest; do not replace unrelated
autoload mappings or configuration. A dependency's Composer settings do not
enable optimization for the consuming site.

Regenerate the classmap after adding, removing, renaming, or moving a
controller:

```bash
composer dump-autoload -o
```

`composer install` and `composer update` also regenerate it. Module discovery
does not read `composer.json` and does not search directories at runtime.

For a conventional class such as
`MySite\Modules\Notes\NotesController`, the module ID is `Notes`. When the
short class name is not `<last namespace segment>Controller`, the short class
name becomes the ID. IDs and page action names are global and must be unique.
Prefer the conventional form so controller IDs used by cron and diagnostics
remain predictable.

## Build a minimal public module

Create `modules/Notes/NotesController.php`:

```php
<?php

declare(strict_types=1);

namespace MySite\Modules\Notes;

use StreamEngine\Core\AbstractController;
use StreamEngine\Domain\Page;
use StreamEngine\View\ViewModel;

final class NotesController extends AbstractController
{
    public static function pageActions(): array
    {
        return [
            'notes.index' => [
                'label' => 'Notes page',
                'singleton' => true,
            ],
        ];
    }

    public function show(Page $page, array $args = []): ?ViewModel
    {
        if ($page->action !== 'notes.index') {
            return null;
        }

        return ViewModel::fromPage(
            $page,
            'modules/notes/index.twig',
            ['message' => 'The Notes module is active.'],
        );
    }
}
```

Create `modules/Notes/views/modules/notes/index.twig`:

```twig
{% extends "layouts/single.twig" %}

{% block content %}
    <h1>{{ title }}</h1>
    <p>{{ message }}</p>
{% endblock %}
```

Regenerate the classmap, open **Pages** in the administration interface, and
create a page using the `notes.index` action. Discovery registers an action;
it does not create its public mount page. The active theme supplies the shared
layout used by the module template.

## Controller construction

`ControllerFactory` resolves typed constructor arguments from the shared
services supplied by the composition root. It also supplies the current
`RequestContext` and the `ModuleRegistry`. Services are indexed by their
concrete class, parent classes, and implemented interfaces.

Unknown required types and untyped required parameters fail construction.
Default values are used only when no matching shared service exists. The
current service list in `StreamEngine::__construct()` is authoritative; do not
assume that every internal object is injectable merely because it exists.

A module-private repository should be constructed by its controller:

```php
public function __construct(PdoDatabase $db, RequestContext $context)
{
    parent::__construct($db, $context);
    $this->notes = new NotesRepository($db);
}
```

This keeps the global composition root independent of the module. If another
module later needs the same behavior, extract an appropriately general shared
contract instead of importing `NotesRepository` from that module.

## Public page actions

`pageActions()` declares pages that an administrator may create and configure.
A string value is supported as a label-only shorthand:

```php
return ['notes.index' => 'Notes page'];
```

The descriptor form supports `label`, `singleton`, `fields`, `requirements`,
and `settings`. `singleton` prevents creating multiple pages for an action.
The standard action-specific fields are:

- `feedId`;
- `feedType`;
- `listFeedType`; and
- `termVocabulary`.

Each field has the status `unsupported`, `optional`, or `required`. A string
status is shorthand for a descriptor. `values` restricts a string field;
`feedTypes` restricts the type referenced by `feedId`. A `oneOf` requirement
means at least one listed, supported field must be set.

```php
return [
    'notes.list' => [
        'label' => 'Notes list',
        'fields' => [
            'feedType' => [
                'status' => 'required',
                'values' => ['note'],
            ],
        ],
        'settings' => [
            'layout' => [
                'control' => 'select',
                'label' => 'notes.page_setting.layout',
                'required' => true,
                'options' => [
                    ['value' => 'cards', 'label' => 'notes.layout.cards'],
                    ['value' => 'compact', 'label' => 'notes.layout.compact'],
                ],
            ],
        ],
    ],
];
```

Action settings currently support only string-valued `select` controls.
Setting keys and labels must be non-empty, option values must be unique
non-empty strings, and a saved required value must match an option. Unknown
page setting keys are preserved so global settings and data from an older
module version are not destroyed.

`ModuleRegistry` validates and normalizes all descriptors at boot. It also
validates referenced feed types after collecting `feedTypes()` from every
module. Prefer separate actions when fields select genuinely different lookup
algorithms; use `oneOf` only for interchangeable inputs to the same behavior.

The administration API looks for `admin.page_action.<action>` in the server
translation catalog and uses the descriptor's `label` when that key is
absent.

## Runtime pages and routing

Use `registerRuntimePages()` for fixed, module-owned descendants below an
existing public mount page. Do not expose those internal actions through
`pageActions()`. Runtime pages are rebuilt in memory at boot and are not
persisted to the database.

The engine associates every newly registered runtime action with the module
controller and validates the complete page tree for duplicate IDs, duplicate
actions, conflicting static patterns, and ambiguous dynamic siblings. A
module must register its runtime tree only when the required public mount
exists.

The complete route ownership and pattern contract is documented in
[Pages and routing](https://github.com/bfhp/stream-engine/wiki/Developer-Pages-and-Routing).

## Templates and theme overrides

A `views/` directory beside the controller is optional. Every discovered
module view directory is added to Twig both as a normal search path and as a
namespace named after the module directory, such as `@Notes/`.

Use a unique path such as `modules/notes/index.twig`; generic names are likely
to collide. Theme paths have priority, allowing a theme to override module
markup while the module retains its default template. Keep business rules,
authorization, and trusted validation in PHP rather than Twig.

The complete ownership, lookup, namespaced extension, stable markup, and
browser UI contract is documented in
[Themes](https://github.com/bfhp/stream-engine/wiki/Developer-Themes).

## Browser assets

The root Vite configuration discovers `.ts` and `.tsx` entry files directly
under `modules/*/assets/`. It ignores `.d.ts` files and does not recursively
turn helper files into entries. The entry filename becomes the output name,
so it must not collide with a built-in or another module entry. Put helpers in
subdirectories and import CSS, images, and helpers from the top-level entry.

Module browser code can import shared engine utilities through the
`@stream-engine` alias. Rebuild the frontend after adding or removing an entry
and deploy PHP and generated assets from the same revision.

Follow [Frontend development](https://github.com/bfhp/stream-engine/wiki/Developer-Frontend)
for entry discovery, stable outputs, page integration, admin extension wiring,
localization, tests, and committed build artifacts.

## Database changes

There is no module-local migration discovery. A site module that requires
schema changes adds uniquely versioned SQL files to the root site's
`migrations/` directory. Removing the PHP module does not reverse those
changes or delete its records.

Follow [Database and migrations](https://github.com/bfhp/stream-engine/wiki/Developer-Database-and-Migrations)
for ordering, checksums, snapshot updates, tests, and rollback planning.

## Administration pages

A module may describe lazy-loaded administration routes in
`modules/<Name>/admin/index.ts`:

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

Paths must be static absolute paths made from lowercase letters, digits, and
hyphens. They cannot contain parameters or a trailing slash. The following
first segments are reserved by the built-in administration interface:
`feeds`, `pages`, `menus`, `settings`, `themes`, `widgets`, `file-browser`,
`uploads`, and `users`. Duplicate paths fail the frontend boot.

> [!IMPORTANT]
> The current shipped `assets-src/admin/main.tsx` calls `mountAdmin()` without
> a module map. It does not automatically discover site-owned admin pages. A
> site that wants them must own or adapt its admin entry, collect the modules,
> and pass them to `mountAdmin()`.

For example, a site-owned admin entry can use:

```ts
import { mountAdmin } from "@stream-engine/admin/admin";

const moduleAdminPages = import.meta.glob(
    "/modules/*/admin/index.ts",
    { eager: true },
);

mountAdmin(moduleAdminPages);
```

Point the `admin` Vite input at that entry and ensure the site's TypeScript
configuration includes module sources. Route metadata loads with the shell;
the page component returned by `load()` is lazy-loaded. Navigation visibility
is not authorization: every backing API endpoint must enforce permissions and
CSRF independently.

## API endpoints

`registerApi()` may add descendants below `/api/v1` with `Page::api()`. The
engine binds every newly added action to the controller that declared it.
`callApi()` must dispatch only known actions, set the response type, validate
input, enforce authentication and authorization, and verify CSRF for mutating
requests. Declare allowed request methods in the page definition.

API paths are an explicit public contract. Do not infer security from a hidden
admin link, and do not expose internal exceptions or credentials in a JSON
response. Follow the complete route, response, authorization, validation, and
review contract in
[API and security](https://github.com/bfhp/stream-engine/wiki/Developer-API-and-Security).

## Scheduled tasks

Register a task and interval through `registerCron()` and dispatch it in
`runCron()`:

```php
public static function registerCron(CronRegistry $cron): void
{
    $cron->add('notes:cleanup', 'Notes', 60 * 60);
}

public function runCron(string $task): void
{
    match ($task) {
        'notes:cleanup' => $this->notes->removeExpiredDrafts(),
    };
}
```

The controller name must match the discovered module ID. Use a globally
namespaced task name such as `notes:cleanup`; `CronRegistry` currently indexes
tasks by name and does not report a duplicate before replacement. Intervals
are expressed in seconds. Cron creates a guest UTC request context, so tasks
must not depend on an HTTP session or a browser request. Design each task for
repeat execution and follow the
[scheduler operations guide](https://github.com/bfhp/stream-engine/wiki/Administrator-Scheduler)
for locking, execution, and diagnostics.

## Dashboard cards

A controller may implement `DashboardCardProviderInterface` to contribute a
generic administration dashboard card. Card IDs are global, stable, and
namespaced, such as `notes.summary`. Supported kinds are `metrics`, `links`,
and `list`; supported sizes are `small`, `medium`, and `wide`.

The descriptor declares a valid `AccessService` permission, allowed sizes,
default size, and non-negative default position. The provider returns a
`ready`, `empty`, or `unavailable` result. Ready payloads are validated for
their declared kind, and links must be safe internal `#/...` admin routes.
The server checks permission before invoking a provider, and provider
exceptions are isolated as an error state.

Saved layouts contain only IDs, positions, and supported sizes. They never
contain executable provider code or database queries.

## Localization limitations

Server catalogs currently live only in `src/Lang/<locale>.php`; there is no
module-local catalog discovery. The Vite translation plugin reads the same
engine catalogs and exports `js.*` keys for browser code.

Consequently, a built-in or upstream module must add its keys to every
supported engine catalog. There is no supported catalog hook for a site-owned
module: it must customize the translation integration or accept the visible
fallback key. Server translation falls back from the selected locale to its
base locale, then English, then the key; browser labels fall back to English
and then the key. Do not document a module-local language directory until the
loader actually supports one.

Follow [Localization and accessibility](https://github.com/bfhp/stream-engine/wiki/Developer-Localization-and-Accessibility)
for key ownership, catalog parity, browser builds, plural/date/time behavior,
RTL and bidirectional content, and accessible UI review.

## Removing a module

Before removal, identify public pages, runtime mounts, cron configuration,
admin links, asset references, and integrations that use the module. Then:

1. disable or replace its public pages and dependent navigation;
2. remove the controller and module files;
3. regenerate Composer's optimized classmap;
4. rebuild frontend assets;
5. deploy PHP and assets together; and
6. verify that unrelated pages, cron, and the administration interface boot.

Removal stops module code from registering. It does not delete page records,
settings, uploads, migration history, schema objects, or content. Retain or
archive that state according to an explicit data-retention decision.

## Test and review checklist

- The optimized classmap discovers the controller under its intended module
  ID.
- Module IDs, page actions, feed types, dashboard IDs, asset entry names,
  admin paths, API actions, and cron task names do not collide.
- The module imports shared platform code and its own private code only.
- Public actions declare every page field and setting they consume.
- Runtime pages are conditional on their mount and are not advertised as
  administrator-created actions.
- Templates render under the default theme and an intended override works.
- API and admin features enforce server-side permission and CSRF checks.
- Cron tasks are repeatable and do not assume an authenticated web request.
- Schema changes use the root migration history and pass both upgrade and
  fresh-install tests.
- Removing the module and rebuilding autoload/assets leaves unrelated
  features operational.
- PHP unit/integration tests, Vitest, type checking, the production build, and
  the relevant manual workflow pass.

[Back: Database and migrations](https://github.com/bfhp/stream-engine/wiki/Developer-Database-and-Migrations) · [Developer Guide](https://github.com/bfhp/stream-engine/wiki/Developer-Guide) · [Next: Pages and routing](https://github.com/bfhp/stream-engine/wiki/Developer-Pages-and-Routing)

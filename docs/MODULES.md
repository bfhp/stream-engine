# Modules

Modules are discovered by directory convention. The loader scans `src/Modules/`
and the project's `modules/` directory. The latter may be absent.

## Directory structure and autoloading

```text
modules/
  Notes/
    NotesController.php
    views/
    assets/
      notes.ts
    admin/
      index.ts
      Notes.tsx
```

A module is a concrete class implementing `ControllerInterface`.
`AbstractController` provides defaults for optional methods. The conventional
name `<prefix>\Notes\NotesController` gives the module ID `Notes`; other
controller names use their short class name as the ID. The filename and
directory depth do not affect discovery.

Enable optimized autoloading in the **root site's** `composer.json`:

```json
"autoload": {
    "psr-4": {
        "Example\\Features\\": "modules/"
    }
},
"config": {
    "optimize-autoloader": true
}
```

Discovery reads only Composer's generated classmap and checks
`ControllerInterface`. It does not scan directories or read `composer.json`.
Abstract controllers are not registered. The same class in multiple classmaps
is registered once; module IDs and page actions must remain unique.

Run `composer dump-autoload` after adding, removing or renaming controller
classes or changing mappings. `composer install` and `composer update` also
regenerate the map. A dependency's Composer config does not enable optimization
for its consumer: set it in the site's root manifest as shown above.

Controllers receive shared services and `RequestContext` through the
controller factory. Page actions still require pages configured in the CMS;
discovery does not create pages. See [the module contract](MODULE_CONTRACT.md)
for dependency rules.

The engine discovers modules internally:

```php
$engine = new \StreamEngine\StreamEngine();
```

## Templates and assets

`views/` is optional. Theme templates take priority over
module templates. Module template paths should be unique, such as
`pages/notes/show.twig`. See [the theme contract](THEME_CONTRACT.md).

Vite discovers `.ts` and `.tsx` entry points directly under
`modules/*/assets/`, excluding `.d.ts`. Filenames become output names and
must not collide with other module entries or engine entries. Put helpers
in subdirectories and import CSS and images from entry points as needed.

## Migrations

A single command runs migrations from two directories: the engine's
`migrations/` and the site's `migrations/`.

```bash
php bin/migrate.php status
php bin/migrate.php make create_notes
php bin/migrate.php migrate
php bin/migrate.php baseline 20260909090000_create_notes
```

SQL filenames follow `YYYYMMDDHHMMSS_name.sql` and must be unique across all
sources. Migrations run in version order with one history file:
`storage/migrations.json` in the site. Checksums protect applied migrations
regardless of which directory contains them. `make` creates files in the site's
`migrations/` directory. Choose versions so prerequisite migrations run first.

## Admin pages

A module exports page descriptions from `admin/index.ts`:

```ts
import type { AdminPage } from "@stream-engine/admin/module-pages";

export default [
    {
        path: "/notes",
        label: "Notes",
        load: () => import("./Notes"),
    },
] satisfies AdminPage[];
```

The root site's Vite entry discovers `modules/*/admin/index.ts` and passes the
result to the engine's `mountAdmin()` function. One list supplies routes and
navigation. Metadata loads with the shell; components load on demand with a
loading fallback. Module paths determine ordering, preserving each page list's
order. Paths must be static absolute paths using lowercase letters, digits and
hyphens, with no trailing slash. Duplicate paths and engine sections are
reserved. API handlers must enforce authorization and CSRF independently of
menu visibility. Modules register API routes and cron tasks through their
controller's existing methods.

The root Vite and TypeScript configurations map `@stream-engine` to the
installed engine's `assets-src/` directory. Module code uses that alias for
shared browser utilities instead of paths relative to the engine checkout.

Rebuild the frontend after adding or removing modules and deploy PHP and
assets together. Removing a module does not delete its data or settings.

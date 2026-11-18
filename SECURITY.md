# Security Policy

## Supported versions

Only the latest release of Stream Engine receives security fixes. Fixes are
published as a new release; older versions are not patched. Please upgrade to
the latest release before reporting an issue, and check whether it still
reproduces there.

| Version | Supported |
| --- | --- |
| Latest release (currently 0.7.x) | Yes |
| Anything older | No |

## Reporting a vulnerability

Please do not report security vulnerabilities through public issues, pull
requests, or discussions.

Report them privately through GitHub instead:
[Report a vulnerability](https://github.com/bfhp/stream-engine/security/advisories/new)
(Security tab → Report a vulnerability).

Include as much of the following as you can:

- the affected version or commit;
- the type of issue, such as SQL injection, cross-site scripting, CSRF, or
  authentication bypass;
- the affected URL, endpoint, module, or source file;
- step-by-step instructions to reproduce it, and any configuration it needs;
- a proof of concept, if you have one;
- the impact: what an attacker could do with it.

## What to expect

Stream Engine is maintained by volunteers, so the timelines below are goals
rather than guarantees:

- an acknowledgement of your report within 7 days;
- an initial assessment, including whether the issue is accepted, within
  14 days;
- updates on progress until the issue is fixed or closed.

Once a fix is released, the issue is disclosed in a GitHub Security Advisory
and in the changelog. You will be credited for the discovery unless you prefer
to stay anonymous. Please keep the details private until the fix is released.

## Reviewed dependency advisories

The [Dependency audit workflow](https://github.com/bfhp/stream-engine/actions/workflows/dependency-audit.yml)
runs `npm audit --audit-level=high` on pushes, pull requests, weekly, and on
demand. It includes development dependencies and fails on high or critical
findings, independently of the CI test and build workflow. Findings described
below are not suppressed; the README badge links to the audit runs. A passing
CI badge does not mean the dependency audit passed. Composer auditing remains
part of the PHP CI jobs.

### picomatch in frontend build and test tools

Reviewed November 18, 2026 for picomatch 4.0.7, used by Vite, Vitest,
tinyglobby, and fdir; the React Vite plugin is also flagged through Vite.
[GHSA-fc5q-39xc-vjpf](https://github.com/advisories/GHSA-fc5q-39xc-vjpf)
describes a denial of service through specially crafted repeated extglob
patterns. At review time, no patched picomatch release is available. The six
high-severity audit entries describe this one dependency chain.

The current application serves compiled assets from `public/assets/` through
PHP/nginx. Application browser sources do not import these build and test
packages, and no path from an HTTP request to their glob-pattern inputs was
found in the reviewed code. This assessment depends on the execution path,
not merely the packages being listed under `devDependencies`. It does not
establish safety for a publicly exposed Vite development server or tooling
that accepts untrusted glob patterns.

The dependency remains vulnerable. Current versions are retained pending an
upstream fix; the audit continues to fail. The suggested forced downgrade to
Vite 6.2.7, Vitest 3.1.4, and the React plugin 5.2.0 was checked separately:
picomatch remained through Vitest, other advisories included critical
findings, and the older tools were incompatible with the current configuration.

Adopt a patched release when available and rerun the audit, tests, type check,
and build. Reassess exposure if deployment starts running Vite, glob patterns
become user-controlled, or these packages enter the application runtime.

### deepmerge in the administration file browser

Reviewed November 8, 2026 for `@samuelncui/chonky` 0.3.6 and `deepmerge`
4.3.1, as resolved by `package-lock.json`.
[GHSA-gcqx-w48m-w2h4](https://github.com/advisories/GHSA-gcqx-w48m-w2h4)
affects the prototype of the object returned by a merge. For example, merging
`JSON.parse('{"__proto__":{"marker":"poisoned"}}')` as the **target** with
an empty source produces a result inheriting `marker`. Checking only whether
`Object.prototype` changed would miss this issue.

The vulnerable library is shipped in the frontend bundle. Its classification
as a development dependency is not a mitigation. The current mitigation is the
file browser's separation of API data from theme configuration:

- In the installed Chonky ESM implementation, the imported `deepmerge` is
  called when building the MUI/Chonky theme: default themes, dark mode,
  `theme`, `muiThemeOptions`, and the mobile theme. File data is not an input
  to these calls. The icon package depends on Chonky and adds no separate
  deepmerge call.
- `assets-src/admin/pages/FileBrowser.tsx` passes explicit props to
  `FullFileBrowser`. It never spreads the API payload or passes API values as
  `theme` or `muiThemeOptions`. The locale comes from application i18n;
  dark mode comes from Mantine.
- API `files` and `folderChain` entries pass through `toBrowserFile()` in
  `assets-src/admin/lib/uploads-browser.ts`, which creates new objects using
  explicit fields. Extra prototype and theme properties are discarded.
  File names and paths remain values, not theme keys.

`tests/js/file-browser-security.test.ts` covers malicious JSON properties
including `__proto__`, `constructor`, and theme options at the mapping boundary.
It also mounts the actual administration FileBrowser and Chonky in light and
dark modes with a malicious API response. It observes the real deepmerge
calls, verifies that neither file values nor the injected marker reaches
them, and verifies that the folder breadcrumb renders after loading.
This is a component integration check in jsdom, not a full browser check of
uploads, drag and drop, or virtualized rows.

The November 8 audit reported three low-severity entries for this single
dependency chain (`deepmerge`, Chonky, and its icon package), with no fix
available. The advisory remains visible in `npm audit`; it is not suppressed.
No dependency override or lockfile change is needed for this mitigation.

Reassess this conclusion when upgrading Chonky/deepmerge, introducing dynamic
themes, or changing how API data is passed to Chonky. Never merge untrusted
objects into theme configuration. Adopt an upstream fix when available and
rerun the component checks and dependency audit.

# Releases

This chapter describes how a maintainer turns a reviewed `main` revision into
a Stream Engine release. It covers versioning, release notes, dependency and
frontend builds, the installation snapshot, upgrade verification, tags,
distribution, and post-release checks.

The repository currently validates pushes and pull requests, but it does not
contain automation that creates a GitHub Release, uploads release artifacts,
or publishes the Composer package. Those publication steps remain a maintainer
operation. Never describe a tag as a usable release until its exact commit has
passed the release checks and its supported distribution channels have been
verified.

## Release sources of truth

The files and Git objects below have distinct roles:

| Source | Meaning |
| --- | --- |
| `package.json` | The application release version used by the installer snapshot builder. |
| `package-lock.json` | The same root package version plus the exact frontend development dependency graph. |
| `CHANGELOG.md` | Human-readable release notes, with current work collected under `Unreleased`. |
| `resources/install/manifest.json` | The release identity, schema checksum, and exact migration set incorporated into a fresh-install snapshot. |
| `resources/install/schema.sql` | Generated schema for a clean installation. |
| `composer.lock` | Exact PHP dependency graph used and tested in the repository. |
| `public/assets/` | Generated production browser assets and third-party license report shipped with the source. |
| annotated tag `vX.Y.Z` | Immutable Git identity of the published release. |

`composer.json` intentionally has no hard-coded project version. Composer
derives the package version from Git tags or repository metadata. Do not add a
second manually maintained version there.

The version in `package.json`, the root version recorded in
`package-lock.json`, the install manifest release, the changelog heading, and
the tag without its leading `v` must agree. `storage/installation.json` belongs
to an installed site and is not a release-version source.

## Choose a version

The changelog states the project's intent to follow Semantic Versioning. While
the project remains below `1.0.0`, compatibility is still evolving; release
notes must identify breaking or operationally significant changes instead of
asking users to infer safety from the version number alone.

Use:

- a patch version for backward-compatible fixes and small internal changes;
- a minor version for backward-compatible features and, before `1.0.0`, any
  deliberately announced compatibility change; and
- a major version once the stable public contracts require an incompatible
  change after `1.0.0`.

For a prerelease, use a valid semantic version such as `0.6.0-beta.1` and a tag
such as `v0.6.0-beta.1`. State the supported upgrade path, data risk, and
production-readiness level explicitly.

One release version identifies one immutable source tree. Never reuse a
version or move a published tag to another commit.

## Maintain the changelog during development

Add notable user, administrator, extension-author, security, and operational
changes to `CHANGELOG.md` under `Unreleased` in the pull request that introduces
them. Use the existing Keep a Changelog headings where applicable:

- `Added` for new capabilities;
- `Changed` for behavior or contract changes;
- `Deprecated` for supported behavior scheduled for removal;
- `Removed` for deleted behavior;
- `Fixed` for defect corrections; and
- `Security` for security-relevant changes that are safe to disclose.

Write observable outcomes, not a commit log. Call out migrations, required
rebuilds, environment changes, changed defaults, manual operator work,
compatibility limits, and rollback consequences. Link to the relevant guide
when a short note cannot safely carry the full procedure.

During release preparation, move all entries for the release from
`Unreleased` beneath a dated heading:

```markdown
## [Unreleased]

## [0.6.0] - 2026-10-20
```

Keep `Unreleased` present for the next change. Compare the complete commit and
pull-request range since the previous tag so that documentation, migrations,
dependency changes, generated assets, and operational changes are not omitted.

## Prepare a release pull request

Prepare the release from an up-to-date branch based on `main`. Do not release
from a dirty working tree or from a commit that contains unreviewed release-only
fixes.

Set a shell variable only after reviewing the intended version:

```bash
release_version='0.6.0'
```

Update `package.json` and the root entries in `package-lock.json` together:

```bash
npm version --no-git-tag-version "$release_version"
```

This command changes metadata; it does not create the Git tag because of
`--no-git-tag-version`. Review both files and confirm that it did not introduce
an unintended dependency change.

Then complete these release-specific changes in the same pull request:

1. finalize the dated changelog section;
2. regenerate the installation snapshot with the exact release version;
3. perform a clean frontend build and commit its generated output;
4. update documentation for changed contracts and operating procedures; and
5. record any explicit compatibility, migration, or rollback warning.

Do not combine opportunistic feature work with release preparation. If a
release check uncovers a product defect, fix it in a normal reviewed change,
then rebuild and restart release verification from the resulting commit.

## Regenerate the installation snapshot

The release snapshot must represent the complete migration chain at the
candidate commit. Build it on MariaDB: the snapshot is canonical MariaDB SQL
that the installer adapts to MySQL, and the builder refuses to run against
MySQL. From an environment whose configured MariaDB account may create and drop
the restricted temporary schema, run:

```bash
composer install-schema:build -- --release="$release_version"
```

The builder creates a disposable database, applies all migrations, generates
`resources/install/schema.sql`, writes checksums and the ordered migration set
to `resources/install/manifest.json`, and removes the database. Review both
generated files. The manifest release must equal `$release_version`.

Never build the snapshot from a production database, edit the generated files
by hand, or amend a migration that has shipped in an earlier tag. Follow the
full migration contract in
[Database and migrations](https://github.com/bfhp/stream-engine/wiki/Developer-Database-and-Migrations).

Generation proves that the current chain can build an empty schema. It does
not prove that a migration safely transforms every existing dataset; that
requires an upgrade test from a supported previous release.

## Rebuild production browser assets

Install the exact locked dependency graph and produce a clean build:

```bash
npm ci
npm run build
```

The build empties `public/assets/` before regenerating it. Review deleted,
renamed, and new files, entry sizes, and `THIRD_PARTY_LICENSES.md`. Generated
assets are tracked and must come from the same candidate commit as their PHP,
Twig, translation, module, and theme sources.

Do not hand-edit the output, omit a generated deletion, or publish an artifact
with assets copied from another revision. See
[Frontend development](https://github.com/bfhp/stream-engine/wiki/Developer-Frontend#generated-assets-and-deployment)
for the complete source/output contract.

## Validate the candidate in a clean checkout

Run release checks from a fresh checkout or disposable worktree of the exact
candidate commit. A developer tree with cached dependencies or ignored files
can conceal an incomplete package.

Install from the committed lockfiles:

```bash
composer install --no-interaction --no-progress --prefer-dist
npm ci
```

Validate metadata and run the project gates:

```bash
composer validate --strict
php bin/check-wiki-docs.php
composer lint
composer test
composer audit:csrf
npm test
npm run typecheck
npm run build
```

For release verification, supply the disposable-database variables used by CI
so `tests/Integration/InstallationSchemaTest.php` runs instead of skipping.
The complete CI environment and test-scope distinctions are documented in
[Testing](https://github.com/bfhp/stream-engine/wiki/Developer-Testing).

Also review dependency advisories:

```bash
composer audit
npm audit
```

An advisory is a release decision, not a command to update dependencies
blindly. Determine whether the affected package and path are shipped or
reachable, document an accepted exception, or land a separately reviewed lock
update and repeat the release checks.

After the clean build, require `git status --short` to be empty. A diff means
the candidate did not contain reproducible generated output or the build used
different tooling; resolve the cause before tagging.

## Test installation and upgrade paths

A releasable candidate must pass both lifecycle paths.

### Fresh installation

Install into an empty disposable database using the generated snapshot and
complete the first-login and baseline smoke tests. Confirm that:

- manifest and schema checksum validation succeeds;
- migrations listed in the manifest are baselined once;
- any newer migration not in the snapshot runs normally;
- the default pages, system account, administrator, settings, and storage state
  are created; and
- public, administration, upload, mail-sink, and scheduler basics work.

### Upgrade from the previous supported release

Use the exact previous tag and a representative database created by that
release. Add legacy fixtures for every data migration. Back up the database and
persistent state, deploy the candidate, run `composer migrate`, and require
`composer migrate:status` to report `Pending: 0`.

Complete the smoke tests from
[Administrator: Upgrades and rollback](https://github.com/bfhp/stream-engine/wiki/Administrator-Upgrades-and-Rollback),
including themes, permissions, routes, uploads, scheduler behavior, and logs.
Then rehearse restoration of the previous release and its matching recovery
set. There are no down migrations; switching only the application files is not
a valid rollback after schema or persistent data changes.

If the release supports upgrading from more than one maintained version, test
each declared starting point or document the required intermediate version.
Never claim upgrade compatibility from migration syntax inspection alone.

## Review the release diff

Before approval, compare the candidate with the previous tag:

```bash
git diff --stat "vPREVIOUS_VERSION..HEAD"
git log --oneline "vPREVIOUS_VERSION..HEAD"
```

Replace the placeholder with the reviewed previous tag; do not copy it
literally. Inspect at least:

- changelog coverage and compatibility notes;
- version agreement across package, lockfile, manifest, and proposed tag;
- new, removed, or changed migrations and their checksums;
- schema snapshot changes;
- PHP and frontend dependency lock changes;
- generated assets and third-party licenses;
- environment, scheduler, storage, object-storage, theme, module, API, and
  security contract changes; and
- documentation required by administrators and extension authors.

The release pull request must be approved and all required checks must pass on
its final commit. If the branch changes afterward, approval and verification
apply to the new commit, not the earlier one.

## Tag the release

After the release pull request is merged and the exact `main` commit is green,
verify its identity and create an annotated tag:

```bash
release_version='0.6.0'
git switch main
git pull --ff-only
git status --short
git tag -a "v$release_version" -m "Release $release_version"
git show --no-patch "v$release_version"
git push origin "v$release_version"
```

`git status --short` must print nothing before tagging. Verify that the tag
points to the intended release commit and that its message and version are
correct. Signing the tag is recommended when the maintainer has an established
signing key and the project's verification policy can be applied consistently.

The CI workflow runs on the tag push because it listens to all pushes. Wait for
the documentation, PHP, and frontend jobs for the tag's exact commit. A tag is
not a substitute for those checks.

Never move or overwrite a published tag. If a published release is defective,
prepare a new version with corrected notes. If a tag fails before any release
or package is published, resolve it deliberately with the other maintainers;
do not silently retarget it while anyone may already have fetched it.

## Publish and verify distribution

The current repository has no release-publication workflow. After tag CI is
green, a maintainer must:

1. create a GitHub Release from the existing annotated tag;
2. use the matching changelog section as release notes and add any security or
   upgrade warning that requires special visibility;
3. publish it as a prerelease when the version is a prerelease;
4. verify that the Composer/Packagist channel has indexed the same tag; and
5. verify every additional artifact or checksum that was uploaded manually.

Do not build a different source archive locally and label it as the tagged
release. GitHub's tag archives and Composer source/dist metadata must resolve
to the tagged commit. The repository tracks compiled `public/assets/`, but it
does not track `vendor/` or `node_modules/`; a consumer installation must
resolve PHP dependencies through Composer.

Test the public distribution in a new directory, using the exact published
version rather than a branch constraint. For the canonical Composer channel:

```bash
release_version='0.6.0'
composer create-project --no-dev --no-interaction \
  bfhp/stream-engine stream-engine-release "$release_version"
cd stream-engine-release
composer check-platform-reqs --no-dev
```

Confirm the installed source identity, manifest release, compiled assets,
licenses, and absence of development-only or local secret files. Then perform
a clean installation in a disposable environment. If publication indexing is
delayed, keep the release open and report that state; do not claim the channel
is ready until the exact version can be resolved.

## Post-release checks

After publication:

- open the GitHub Release and all documentation links;
- confirm the release tag and Composer package resolve to the same commit;
- repeat a minimal fresh install from the public distribution;
- monitor installation, migration, scheduler, and dependency reports;
- verify that the published Wiki reflects the release documentation merged to
  `main`;
- keep the previous supported release and rollback instructions accessible;
  and
- add newly discovered work under `Unreleased` rather than editing published
  release history without explanation.

If the release has a critical defect, communicate the impact and safe operator
action immediately. Prefer a corrective release. Remove or mark a release only
when leaving it available creates a greater security or data-loss risk, and
preserve an audit trail explaining the decision.

## Release checklist

- [ ] The version and compatibility impact are agreed.
- [ ] `CHANGELOG.md` has a complete dated section and a retained `Unreleased`
      heading.
- [ ] `package.json`, `package-lock.json`, install manifest, changelog, and tag
      use the same version.
- [ ] The migration chain and fresh-install snapshot were regenerated and
      reviewed.
- [ ] Production assets and third-party licenses were rebuilt and reviewed.
- [ ] Clean-checkout PHP, frontend, security, documentation, and dependency
      checks passed with the disposable-database integration suite enabled on both MariaDB and MySQL.
- [ ] Fresh installation and every declared upgrade path passed.
- [ ] Rollback from a representative upgrade was rehearsed.
- [ ] The final release pull request was approved and merged without later
      unverified changes.
- [ ] The annotated tag points to the intended green `main` commit.
- [ ] Tag CI passed before publication.
- [ ] GitHub Release, Composer indexing, public installation, and release notes
      were verified against the same tag.
- [ ] Post-release documentation and operational checks passed.

[Back: Performance](https://github.com/bfhp/stream-engine/wiki/Developer-Performance) · [Developer Guide](https://github.com/bfhp/stream-engine/wiki/Developer-Guide) · [Next: Contributing](https://github.com/bfhp/stream-engine/wiki/Developer-Contributing)

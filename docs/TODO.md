# TODO

The project's current backlog. Last reviewed against the code and tests on
**October 9, 2026**.

This file contains only unfinished work and decisions that have been made but
not yet implemented. Completed work is not recorded here; Git provides that
history. When a task is completed, it should be removed from this file together
with any related `TODO` comments in the code.

Priorities:

- **P0** — a clean installation does not work, the user receives a false
  success response, or data may be lost or returned incorrectly;
- **P1** — important reliability, security, or operational work;
- **P2** — product improvements, refactoring, and localized technical debt.

## P2

### GitHub-native administrator and developer documentation

Create two maintained documentation entry points inside the repository: an
administrator guide and a developer guide. Keep them native to GitHub using
reviewable Markdown, relative links, GitHub-rendered tables and Mermaid only
where a diagram materially helps; do not require a separate documentation
generator, hosted site, or build step.

- Add clearly linked entry points under `docs/` and from the root `README.md`,
  with a compact table of contents and stable relative links between topics.
- The administrator guide must cover installation and upgrades, global
  settings, users, feeds, pages and routing, menus, themes, widgets, routine
  operations, permissions, backups, and troubleshooting.
- The developer guide must cover local setup, architecture and request flow,
  database migrations, modules, page-action contracts, themes and template
  inheritance, frontend assets, API conventions, authorization and CSRF,
  testing, localization, performance rules, and the release workflow.
- Reconcile and link the existing contract documents instead of duplicating
  them. Examples and commands must be executable against the current tree, and
  documentation changes should become part of the completion criteria for
  user-visible features and extension contracts.
- Consider the task complete when a new administrator can operate a clean
  installation and a new developer can run, change, test, and extend it using
  only the repository documentation, with every internal link checked.

## File maintenance rule

A new task must describe the observable problem, the desired outcome, and,
where important, how completion will be verified. Keep diagnostic notes and
reports about completed work in commits/PRs instead of turning TODO back into a
changelog.

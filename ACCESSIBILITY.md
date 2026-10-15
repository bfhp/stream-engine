# Accessibility Statement

> **Draft.** This statement has not been reviewed or published yet. Items
> marked **TODO** need a decision or an evaluation before release.

Stream Engine aims to let people use every site built on it, and its
administration panel, regardless of disability, input method, or assistive
technology. This document says what the project targets, what it covers, how
conformance is checked, and where the known gaps are.

## Conformance status

| | |
| --- | --- |
| Target standard | [WCAG 2.2](https://www.w3.org/TR/WCAG22/), level AA |
| Related standard | EN 301 549 v3.2.1 (its web content clauses follow WCAG 2.1 AA) |
| Current status | **Not evaluated.** No conformance is claimed. |
| Applies to | Latest release (currently 0.5.x) |
| Last reviewed | **TODO** |

"Not evaluated" means that no complete audit against the WCAG success
criteria has been performed. Individual features are designed and reviewed
against the rules in
[Localization and accessibility](https://github.com/bfhp/stream-engine/wiki/Developer-Localization-and-Accessibility),
but that review is not a substitute for an audit, and the project does not
currently state that any level of WCAG is met.

Once an audit has been done, this section will use the standard WCAG terms:
**fully conformant** (all criteria met), **partially conformant** (some
criteria not met), or **non-conformant**.

## Scope

Stream Engine is a CMS: the pages a visitor sees are produced by the engine,
a theme, site-specific modules, and content written by the site's authors.
The project can only answer for the parts it ships.

**In scope**

- the administration panel (`assets-src/admin`);
- the bundled `default` and `bootstrap` themes, unmodified;
- built-in modules and their shipped templates, such as accounts and
  registration, profiles, comments, blogs, forums, and feedback;
- the installer; and
- all shipped locales, including right-to-left Arabic.

**Out of scope**

- third-party themes and themes changed by a site;
- site-owned or third-party modules;
- content written by site authors and users (text, images, video, uploaded
  documents); and
- site configuration that changes colours, navigation, or layout.

A site that runs Stream Engine is responsible for the accessibility of its own
content, theme, and modules, and for publishing its own accessibility
statement where the law requires one (for example under the European
Accessibility Act or the EU Web Accessibility Directive). This document can be
referenced as evidence for the engine's share only.

## What is in place

- `<html lang>` and `<html dir>` follow the selected site locale on public
  pages and in the administration panel.
- Layouts use CSS logical properties; mixed-direction user content is isolated
  with `<bdi>` and `dir="auto"`.
- Shipped layouts mark the primary region with `<main>`, and every public page
  and the administration panel start with a "Skip to main content" link
  (WCAG 2.4.1).
- Animations, transitions, and smooth scrolling are reduced when the system
  asks for reduced motion (`prefers-reduced-motion`; WCAG 2.3.3).
- Light and dark colour modes follow the system preference by default.
- Feature code is expected to use native HTML controls, associated labels,
  translated accessible names for icon-only controls, ARIA state updates, and
  polite live regions for asynchronous status.
- Vitest covers several ARIA state transitions, keyboard handlers, and
  direction changes.
- Automated WCAG checks run in CI (see below).

## How accessibility is checked

| Method | Status |
| --- | --- |
| Design and code review against the developer guide checklist | In use |
| Component checks with axe-core in Vitest (administration components) | In use, every push and pull request |
| Page checks with axe-core and Playwright in CI (both bundled themes, English and Arabic, light and dark) | In use, every push and pull request; known violations are listed in [`tests/a11y/baseline.json`](tests/a11y/baseline.json) |
| Keyboard-only review of key workflows | **TODO**: not yet systematic |
| Screen reader review | **TODO**: not yet performed |
| Zoom to 200% and reflow at 320 CSS px | **TODO**: not yet systematic |
| Complete WCAG 2.2 AA audit | **TODO**: not yet performed |

Both automated checks run the axe-core rules for WCAG 2.0, 2.1, and 2.2 at
levels A and AA. They cover both bundled themes, `default` and
`bootstrap`. How they work is described in
[Accessibility checks](https://github.com/bfhp/stream-engine/wiki/Developer-Testing#accessibility-checks).

Automated tools find only part of the issues WCAG covers. A conformance claim
will be made only after manual review.

### Test environments

**TODO**: agree on the matrix. Suggested minimum:

| Assistive technology | Browser | Platform |
| --- | --- | --- |
| NVDA (current) | Firefox, Chrome | Windows |
| VoiceOver | Safari | macOS |
| VoiceOver | Safari | iOS |
| TalkBack | Chrome | Android |
| Keyboard only | Any supported browser | Any |

## Known limitations

These are known or likely gaps. Each will either be fixed or confirmed and
documented with a workaround.

- **Third-party editors and widgets.** The administration panel embeds the
  Monaco code editor, the Chonky file browser, and the Trix rich-text editor.
  Their keyboard and screen reader support is controlled upstream and has not
  been evaluated in Stream Engine. The automated scan reports one known issue
  in the file browser: Chonky's file list uses `role="list"` without list
  items (WCAG 1.3.1); it is recorded in `tests/a11y/baseline.json`.
- **Alternative text for images.** **TODO**: confirm whether the editor and
  file uploads let authors add alternative text, and whether they prompt for
  it.
- **Colour contrast.** Text contrast (WCAG 1.4.3) passes the automated scan on
  the scanned pages of the `default` theme and the administration panel in
  both colour modes. Non-text contrast (1.4.11), such as borders and focus
  indicators, is not checked automatically and has not been reviewed.

## Support for content authors

A CMS also affects whether authors can publish accessible content
([ATAG 2.0](https://www.w3.org/TR/ATAG20/), part B). **TODO**: evaluate and
describe, for example:

- whether authors can set alternative text, headings, table headers, link
  text, and content language;
- whether the editor produces semantic markup rather than visual-only
  formatting; and
- whether any accessibility guidance or checks are shown to authors.

## Reporting a problem

If something in Stream Engine is hard or impossible to use with your
assistive technology or input method, please
[open an issue](https://github.com/bfhp/stream-engine/issues/new) with the
`accessibility` label. **TODO**: create the label and an issue template.

Include as much of the following as you can:

- the version or commit;
- the page, administration screen, or workflow;
- what you tried to do and what happened instead;
- your browser, operating system, and assistive technology with versions; and
- the site locale, if it matters (for example right-to-left).

If the problem is on a particular site rather than in Stream Engine itself,
please contact that site's operator first.

Stream Engine is maintained by volunteers, so the timelines below are goals
rather than guarantees:

- an acknowledgement within 7 days;
- an assessment, including whether the issue is in the engine, a theme, or
  site content, within 14 days; and
- issues that block access, such as an unlabelled control or a keyboard trap,
  are treated as bugs rather than visual polish.

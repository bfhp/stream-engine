# Accessibility Statement

Stream Engine aims to let people use every site built on it, and its
administration panel, regardless of disability, input method, or assistive
technology. This document says what the project targets, what it covers, how
conformance is checked, and where the known gaps are.

## Conformance status

| | |
| --- | --- |
| Target standard | [WCAG 2.2](https://www.w3.org/TR/WCAG22/), level AA |
| Related standard | EN 301 549 v3.2.1 (its web content clauses follow WCAG 2.1 AA) |
| Current status | **Fully conformant** with WCAG 2.2 level AA: [results per criterion](docs/ACCESSIBILITY_AUDIT.md) |
| Also meets | WCAG 2.1 level AA. WCAG 2.2 contains every 2.1 criterion except 4.1.1 Parsing, which W3C's 2023 errata for WCAG 2.1 treats as always met for HTML |
| Applies to | Latest release (currently 0.5.x) |
| Last reviewed | 2026-10-30 |

Fully conformant means that every WCAG 2.2 success criterion at levels A and
AA is met, or does not apply, in the parts listed under [Scope](#scope): 51
criteria are met and 4 do not apply (the remarks in the audit say why). The
claim rests on an internal audit, not an independent third-party
evaluation: automated checks, a code review, and a manual review with the
keyboard and with VoiceOver on macOS, in both bundled themes, in English and
Arabic, in light and dark mode. It covers what the project ships. Content
written by a site's authors, site-specific modules, and third-party themes
are outside it; a site built on Stream Engine conforms only if those parts
do too.

New features are designed and reviewed against the rules in
[Localization and accessibility](https://github.com/bfhp/stream-engine/wiki/Developer-Localization-and-Accessibility),
and the automated checks below run on every change, so a regression they can
detect fails CI. A change that affects a criterion they cannot check calls
for a new manual review of that criterion.

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
| Page checks with axe-core and Playwright in CI (both bundled themes, English and Arabic, light and dark) | In use on every change to templates, styles, scripts or translations, and weekly; known violations are listed in [`tests/a11y/baseline.json`](tests/a11y/baseline.json) |
| Keyboard checks in CI: Tab walk with focus visibility and indicator contrast, no traps, focus kept in dialogs and returned on close | In use, together with the page checks |
| Keyboard-only review of key workflows by a person | Done for the October 2026 audit |
| Screen reader review | Done for the October 2026 audit (VoiceOver on macOS, both themes, English and Arabic) |
| Zoom to 200% and reflow at 320 CSS px | Done for the October 2026 audit; reflow at 320 CSS px is also checked in CI |
| Complete WCAG 2.2 AA audit | Done 2026-10-30: 51 criteria supported, 4 not applicable; results per criterion in [`docs/ACCESSIBILITY_AUDIT.md`](docs/ACCESSIBILITY_AUDIT.md) |

Both automated checks run the axe-core rules for WCAG 2.0, 2.1, and 2.2 at
levels A and AA. They cover both bundled themes, `default` and
`bootstrap`. How they work is described in
[Accessibility checks](https://github.com/bfhp/stream-engine/wiki/Developer-Testing#accessibility-checks).

Automated tools find only part of the issues WCAG covers. The conformance
claim rests on the manual review in the table above; the automated checks
keep the result from regressing between audits.

### Test environments

| Assistive technology | Browser | Platform | Status |
| --- | --- | --- | --- |
| VoiceOver | Safari | macOS | Used for the October 2026 audit |
| Keyboard only | Desktop browsers | macOS | Used for the October 2026 audit; Chromium also in CI |
| NVDA (current) | Firefox, Chrome | Windows | Not yet tested |
| VoiceOver | Safari | iOS | Not yet tested |
| TalkBack | Chrome | Android | Not yet tested |

## Known limitations

These are known or likely gaps. Each will either be fixed or confirmed and
documented with a workaround.

- **Screen readers other than VoiceOver on macOS** (NVDA and JAWS on
  Windows, VoiceOver on iOS, TalkBack) have not been tested yet; see
  [Test environments](#test-environments).
- **Third-party editors and widgets.** The administration panel embeds the
  Monaco code editor, the Chonky file browser, and the Trix rich-text editor,
  whose behaviour is controlled upstream; Stream Engine adds what they lack,
  and an update of them can change it. The file browser moves between files
  with Tab or the arrow keys and opens one with Enter; Stream Engine corrects
  the list roles Chonky renders. Files are moved with Cut and Paste in the
  Actions menu rather than by dragging. The Trix toolbar is one Tab stop with
  the arrow keys, which Stream Engine adds. Monaco uses Tab for indentation,
  so Tab does not leave the editor until Ctrl+M (Ctrl+Shift+M on macOS)
  switches that off. The administration panel says so next to each code
  editor and in the editor's accessible name, which is what WCAG 2.1.2 asks
  for when the standard keys do not move focus.
- **CAPTCHA on registration.** A site can require a CAPTCHA when people
  register. Cloudflare Turnstile, the recommended option, usually needs no
  action. hCaptcha may show an image puzzle, which blind people and some
  people with cognitive disabilities cannot solve without hCaptcha's separate
  accessibility sign-up; the administration panel says so next to the setting.
- **Language of parts.** Authors cannot mark a passage in another language in
  the rich-text editor, so screen readers read it with the page's voice
  (WCAG 3.1.2 applies to that content).
- **Images are described by captions, not alt text.** Authors describe an
  image in the content editors by its caption: the image gets `alt=""` and
  the `<figcaption>` of its `<figure>` carries the description (WCAG 1.1.1).
  An image published without a caption therefore has no text alternative;
  the editors prompt for one but do not require it. There is no separate alt
  text field.
- **Older content** may contain images whose alt text is their file name,
  which earlier versions of the sanitizer inserted. It is not migrated, and
  re-saving the content does not change existing alt attributes.
- **Colour contrast.** Text contrast (WCAG 1.4.3) passes the automated scan on
  the scanned pages of both bundled themes and the administration panel, in
  both colour modes. For non-text contrast (1.4.11) only focus indicators are
  checked automatically; field borders, switches, checkboxes, icons and
  charts were reviewed by hand for the October 2026 audit. Colours set by a
  site's own theme settings are the site's responsibility.

## Support for content authors

A CMS also affects whether authors can publish accessible content
([ATAG 2.0](https://www.w3.org/TR/ATAG20/), part B). This section describes
what the shipped editors allow; no conformance with ATAG is claimed.

Stream Engine has three kinds of editor:

- the **rich-text editor** (Trix) for blog posts, community posts and forum
  topics and replies;
- the **HTML editor** (Monaco) in the administration panel, for articles
  and other feed content, which takes HTML as written;
- **plain text** fields for comments and messages; links in them become
  links, nothing else is formatted.

Whatever the editor, stored HTML goes through HTML Purifier, which keeps
semantic markup and drops scripts and inline event handlers.

| | Rich-text editor | HTML editor |
| --- | --- | --- |
| Structure | Real elements, not visual formatting: one heading level, quotes, code blocks, bulleted and numbered lists, bold (`<strong>`), italic (`<em>`), strikethrough (`<del>`) | Any element the sanitizer keeps, including `<h1>`–`<h6>`, lists, `<figure>` and `<blockquote>` |
| Headings | One level, `<h2>` | All levels |
| Tables | Not available | Available, with `<th>`, `scope` and `<caption>` |
| Link text | The selected text becomes the link; the author chooses it | As written |
| Language of a passage | Not available | `lang` on any element is kept |
| Images | The caption field asks the author to describe the image; it is not required | The image button inserts a `<figure>` with an empty `<figcaption>` and puts the cursor in it |

- **Images.** An image is described by its caption: it gets `alt=""` and the
  caption is its text alternative (see [Known limitations](#known-limitations)).
  An image without alt text gets an empty one instead of its file name.
- **Checks.** Apart from the caption prompt, neither editor checks content
  for accessibility problems (an empty link, a skipped heading level, a
  table without headers); authors are responsible for them.
- **Headings in the rich-text editor.** Its one heading is stored as `<h2>`,
  a section under the page title. Content saved when it was still `<h1>`
  keeps that until it is next edited. Authors who need more levels or tables
  write the content in the HTML editor.

## Reporting a problem

If something in Stream Engine is hard or impossible to use with your
assistive technology or input method, please
[open an issue](https://github.com/bfhp/stream-engine/issues/new) with the
`accessibility` label; the
[accessibility issue form](https://github.com/bfhp/stream-engine/issues/new?template=accessibility.yml)
asks for the details below and adds the label.

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

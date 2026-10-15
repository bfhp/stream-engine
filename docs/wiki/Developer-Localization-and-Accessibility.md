# Developer Guide: Localization and accessibility

Localization and accessibility are application contracts, not finishing work.
A new feature is incomplete when its text exists only in one language, its
layout assumes left-to-right writing, or its controls cannot be understood and
operated without a pointer.

Stream Engine currently ships English, Russian, German, French, Spanish,
Brazilian Portuguese, Italian, Dutch, Polish, Arabic, Japanese, Korean, and
Simplified Chinese catalogs. The selected site locale controls server and
browser messages, document language, writing direction, number and automatic
date formatting, and directional theme assets.

This chapter covers the current catalog and runtime contracts plus the review
required for user-visible changes. Frontend integration is introduced in
[Frontend development](https://github.com/bfhp/stream-engine/wiki/Developer-Frontend),
theme direction and markup ownership in
[Themes](https://github.com/bfhp/stream-engine/wiki/Developer-Themes), and test
scope in [Testing](https://github.com/bfhp/stream-engine/wiki/Developer-Testing).

## Runtime map

| Concern | Current implementation |
| --- | --- |
| Canonical catalogs | `src/Lang/<locale>.php`, one PHP array per locale. |
| Server lookup | `TranslationManager::trans()` and Twig `trans()`. |
| Server plural selection | `Formatter::transChoice()` and Twig `trans_choice()`, CLDR rules from intl. |
| Browser catalogs | Build-generated `virtual:translations`, containing only `js.*` keys. |
| Browser lookup | `assets-src/shared/i18n.ts`: `trans()`, `transChoice()`, and `transChoiceWithCount()`. |
| Server dates | `Formatter`, using PHP `IntlDateFormatter` and site format settings. |
| Browser dates | `assets-src/shared/date-time-format.ts`, using `Intl.DateTimeFormat` or explicit site formats. |
| Request timezone | `RequestContext->tz`, resolved from the validated `tz` cookie or authenticated user's stored timezone. |
| Direction | `TranslationManager::direction()` supplies `<html dir="ltr|rtl">`; browser code reads that attribute. |
| RTL assets | `ThemeCatalog::forDirection()` applies a theme manifest's `assets.rtl` replacements. |
| Mixed-direction content | Semantic `<bdi>` and `dir="auto"` at user-content boundaries. |

There is no module-local catalog discovery today. Built-in and upstream module
messages live in the engine catalogs. A site-owned module must customize the
translation integration or accept visible fallback keys; do not create a
`src/Modules/<Name>/Lang` directory and assume it will be loaded.

## Add or change a message

Treat English as the key inventory and add the same key to every supported
catalog in the same change. `TranslationManagerTest` verifies that every
catalog has the same number and names of keys as English. Missing entries make
the PHP suite fail.

Choose a stable semantic key rather than embedding the English wording:

```php
// src/Lang/en.php
'notes.delete_confirmation' => 'Delete “{title}”?',

// src/Lang/de.php
'notes.delete_confirmation' => '„{title}“ löschen?',
```

Use the key through the translator:

```php
$message = $this->tm->trans(
    'notes.delete_confirmation',
    ['title' => $note->title],
);
```

```twig
<button type="submit">
    {{ trans('notes.delete') }}
</button>
```

The key belongs to the code that owns the meaning. Existing prefixes include
domain/feature areas such as `user.*`, `community.*`, and `forums.*`, rendered
view labels below `view.*`, administration messages below `admin.*`, mail text
below `mail.*`, and browser-exported messages below `js.*`. Extend an existing
owner's namespace instead of creating a generic `common` key whose wording
happens to match today.

Do not:

- use a complete English sentence as the key;
- assemble a sentence from translated fragments whose order may change;
- reuse a key when the same English word has a different grammatical meaning;
- translate stored identifiers, API codes, route patterns, CSS hooks, database
  enum values, log field names, or configuration keys; or
- put markup in a message unless the renderer, escaping context, and translator
  contract explicitly require it.

Catalog values are trusted source strings, but interpolated values are still
untrusted data. Translation does not escape them. Pass the completed result
through the output-context rules from
[API and security](https://github.com/bfhp/stream-engine/wiki/Developer-API-and-Security#output-html-and-script-safety).

## Placeholders and catalog parity

Placeholders use `{name}` and are replaced literally by server and browser
helpers. Keep names descriptive and identical across locales:

```php
'user.password_too_short' =>
    'Password length should be at least {min} characters',
```

Use named placeholders instead of position-dependent `%s`; translators may
need to reorder the sentence. A catalog entry must preserve every placeholder
that the caller supplies and must not introduce a placeholder the caller does
not know about.

The existing catalog completeness test checks keys and catalog size. It does
**not** currently verify that corresponding values are all strings versus
arrays, that arrays have compatible form counts, or that placeholder sets
match. Review those properties manually and add a focused regression test when
a message has several placeholders or plural forms. A missing placeholder is
left visible rather than raising an error, so a successful render alone is not
enough.

When wording changes meaning, update all catalogs together. When only one
translation is corrected, preserve its key and placeholders. Do not rely on
fallback as a way to leave a newly shipped locale incomplete.

## Server localization

`TranslationManager` normalizes locale case and underscores, loads an exact
catalog over its base-language catalog, then merges the configured fallback
(normally English). If no message exists, `trans()` returns the key. That
visible key is a diagnostic fallback, not acceptable finished UI.

The server exposes:

- `trans(key, params)` in Twig and `TranslationManager::trans()` in PHP;
- `trans_choice(key, count)` in Twig and `Formatter::transChoice()` in PHP;
- `locale`, `dir`, `dateFormat`, and `timeFormat` as Twig globals; and
- locale-aware `Formatter` methods for dates, date-times, relative time,
  month/year labels, and plural forms.

Controllers and services receive the shared translator/formatter through
their normal dependencies. Do not load a catalog with `require`, inspect the
locale setting directly, or implement another placeholder replacement helper.

User-visible validation and deliberate API errors must be translated at the
server boundary. Internal logs should remain concise and diagnosable; do not
make machine-oriented log parsing depend on translated text.

## Browser localization and build data

The Vite translations plugin executes PHP at build time, loads every
`src/Lang/*.php` array, filters each catalog to `js.*`, and produces the virtual
`virtual:translations` module consumed by `assets-src/shared/i18n.ts`.

Therefore a browser-visible message needs a `js.*` key in every catalog and a
production build after catalog changes:

```ts
import { trans } from "@stream-engine/shared/i18n";

button.textContent = trans("js.notes.delete");
status.textContent = trans("js.notes.saved", { title: note.title });
```

`trans()` selects the document's `<html lang>`, normalizes underscores/case,
tries the exact and base locales, merges English fallback, and finally exposes
the key when absent. Do not read a separate browser locale, copy catalog text
into TypeScript, or use the browser's UI language in place of the server-
selected site language.

Use DOM `textContent` or React interpolation for plain translated text. If a
message is placed in an attribute, HTML fragment, URL, or script context, use
that context's safe API. Translation never makes interpolation safe HTML.

Changing a PHP catalog without rebuilding can leave committed browser assets
with the old labels. Run `npm run build`, review the generated asset diff, and
commit source and generated output together when the repository's release
assets change.

## Plural forms

A plural catalog value is an array keyed by
[CLDR plural category](https://cldr.unicode.org/index/cldr-spec/plural-rules)
(`zero`, `one`, `two`, `few`, `many`, `other`). Each locale lists exactly the
categories it uses, in that order. Use the shared selector instead of choosing
a form in feature code:

```php
// en.php
'notes.item_count' => ['one' => 'note', 'other' => 'notes'],
// ru.php: `other` is the fraction form ("1,5 заметки")
'notes.item_count' => ['one' => 'заметка', 'few' => 'заметки', 'many' => 'заметок', 'other' => 'заметки'],
// ar.php uses all six; ja.php, ko.php and zh-cn.php only `other`
```

```twig
{{ count }} {{ trans_choice('notes.item_count', count) }}
```

```ts
label.textContent = transChoiceWithCount("js.notes.item_count", count);
```

`transChoice()` returns the word/phrase only. `transChoiceWithCount()` formats
and prepends the number in the browser locale. This distinction lets markup
style a count separately without duplicating grammar. A plain string value is
a unit that does not inflect (`'js.common.kilobyte' => 'КБ'`) and is returned
as is by both runtimes.

| Locale | Categories |
| --- | --- |
| `de`, `en`, `nl` | `one`, `other` |
| `es`, `fr`, `it`, `pt-br` | `one`, `many` (exact millions: "1 000 000 de messages"), `other` |
| `pl`, `ru` | `one`, `few`, `many`, `other` |
| `ar` | `zero`, `one`, `two`, `few`, `many`, `other` |
| `ja`, `ko`, `zh-cn` | `other` |

Both runtimes take the rules from ICU's CLDR data: the server through
`Formatter::pluralCategory()` (intl `MessageFormatter`), the browser through
`Intl.PluralRules` in `assets-src/shared/plural.ts`. Neither hand-writes a
rule. `tests/Support/plural-rules.json` records the category of a set of
sample numbers (integers, negatives, fractions) for every shipped locale;
`tests/Core/PluralRulesTest.php` asserts PHP against it and
`tests/js/plural.test.ts` asserts the browser against it, so an ICU upgrade
that moves a rule on either side fails a suite instead of changing a word
between the server render and the next browser update. The same PHP test
checks that every plural value in every catalog has exactly its locale's
categories. When adding a locale, add its entry to the fixture.

A missing category falls back to `other`. Positional lists from older
catalogs (`[one, few, many]` or `[one, other]`) are still read, through the
mirrored `legacyPluralIndex()` tables, but shipped catalogs must be keyed.
`Formatter::plural($n, $one, $few, $many)` is kept for the same reason and is
deprecated: put the forms in the catalog and call `transChoice()`.

This selects the noun form only; it is not ICU MessageFormat. A sentence whose
wording beyond one noun depends on the count needs separate keys.

Do not handle plural by appending `s`, concatenate a number with an unrelated
singular label, or assume two forms are enough for every locale. Preserve the
array value type and compatible semantic forms in every catalog.

## Dates, times, numbers, and timezones

Store and exchange timestamps as Unix timestamps/UTC instants. Convert them
for display at the edge. The request timezone is available as
`$this->context->tz`; create a `DateTimeImmutable` from the instant and apply
that timezone before formatting when the caller has not already done so.

The browser writes a site-wide `tz` cookie from
`Intl.DateTimeFormat().resolvedOptions().timeZone`. `AuthService` validates it
against PHP's timezone identifiers, falls back to the authenticated user's
stored timezone or UTC, and persists a valid changed timezone for a signed-in
user. Never trust an arbitrary cookie value as a timezone or SQL value.

Site settings select `auto`, `dmy`, `mdy`, or `iso` for dates and `auto`,
`24h`, or `12h` for time. The document exposes those settings through
`data-date-format` and `data-time-format`. Use `Formatter` on the server and
the shared `formatDateValue()`, `formatTimeValue()`, or
`formatDateTimeValue()` helpers in browser code so both surfaces respect them.

Use `Intl.NumberFormat` for localized browser numbers. Do not display a stored
UTC value with a manually appended timezone abbreviation, parse a localized
display string back into application state, or assume that date component
order, separators, month names, digit grouping, decimal characters, or 12/24-
hour conventions are universal.

Tests must use an explicit locale, format setting, timezone, and fixed instant.
A test that relies on the developer machine's timezone or locale is not
deterministic.

## Right-to-left layout

`TranslationManager` derives direction from the locale's base language and
renders `<html dir="rtl">` or `<html dir="ltr">`. The public theme and admin
shell also render `<html lang>` and date/time data attributes. Browser code
must read the document direction, not infer it again from language names.

Use CSS logical properties:

```css
.notes-list {
    border-inline-start: 3px solid var(--ui-primary);
    padding-inline-start: 1rem;
    margin-inline-end: auto;
}
```

Prefer `inline-start`, `inline-end`, `block-start`, and `block-end` over
physical left/right properties for layout and spacing. Directional arrows,
indent/outdent icons, and previous/next affordances may need mirroring; neutral
icons do not. A dependency that needs separate RTL CSS belongs in the theme's
`assets.rtl` mapping rather than a runtime stylesheet swap in feature code.

Use `<bdi>` around short user-generated names, titles, usernames, and filenames
embedded in interface text. Use `dir="auto"` on blocks of user-authored content
whose primary direction may differ from the UI. This prevents punctuation,
numbers, and neighboring labels from being reordered by the Unicode bidi
algorithm. Do not force all user content to the site direction.

Test Arabic or another RTL locale at narrow and wide widths. Check ordering,
alignment, overflow, icons, menus, tables, form controls, mixed Latin/Arabic
content, numbers, and keyboard navigation—not only whether the stylesheet
loads.

## Semantic structure and names

Start with native HTML semantics. Use the correct element before adding ARIA:

- `<button>` for an action and `<a href>` for navigation;
- `<label for>` or an equivalent accessible name for every form control;
- headings in a meaningful hierarchy and landmarks for page regions;
- `<table>` with real headers for tabular data;
- `<fieldset>` and `<legend>` for related controls; and
- native checkbox, radio, select, dialog, and disclosure behavior where it
  meets the interaction requirement.

An icon-only control needs a translated accessible name. Decorative icons and
images use `aria-hidden="true"` or empty `alt`; meaningful images need concise
alternative text that communicates their purpose, not their filename. Do not
duplicate nearby visible text in an image's alternative text.

ARIA supplements semantics; it does not add behavior. If a custom element has
`role="button"`, it must also be focusable and implement the expected keyboard
activation. Prefer a real button so the browser supplies those rules.

Keep relationships stable when a theme overrides markup. IDs used by
`for`, `aria-labelledby`, `aria-describedby`, `aria-controls`, tab/panel pairs,
and error messages are part of the component contract.

## Keyboard and focus

Every interactive path must work without a pointer. Preserve a visible focus
indicator and a logical DOM/tab order. Do not use a positive `tabindex` to
repair visual ordering; fix the document order.

For dynamic behavior:

- return focus to the triggering control after a modal/dialog closes;
- move focus to the first invalid field after a failed submission when that
  helps the user recover;
- support Escape where the component contract allows dismissal;
- implement arrow-key behavior for composite widgets such as tabs, menus, or
  listboxes according to their role;
- do not steal focus when background content refreshes; and
- expose an explicit teardown for global key handlers owned by a component.

Disabling a control must be reflected semantically (`disabled` for native
controls or a complete `aria-disabled` interaction contract), not only through
colour or a CSS class. Loading state must prevent duplicate activation without
making the current focus disappear unexpectedly.

## Errors, status, and dynamic content

Validation cannot rely only on colour. Associate the error with its control,
set `aria-invalid="true"`, and provide visible text that says how to recover.
Clear both the visual and ARIA state when the value becomes valid.

Use a restrained live region for meaningful asynchronous status:

```html
<p role="status" aria-live="polite" data-note-status></p>
```

Update its text when saving starts, succeeds, or fails. Do not announce every
keystroke or repeated polling result. Reserve assertive announcements for
urgent information that genuinely requires interruption.

When JavaScript toggles a disclosure, tab, pressed button, selection, current
page, or busy region, update the corresponding `aria-expanded`,
`aria-selected`, `aria-pressed`, `aria-current`, or `aria-busy` state at the
same time as the visual state. Hidden content must also be removed from normal
interaction, normally with `hidden` or the component library's equivalent.

## Accessibility review and current limits

The repository currently has focused markup and Vitest assertions for several
ARIA state transitions, keyboard handlers, direction changes, and rendered
views. It does not currently run an automated accessibility scanner, publish a
browser/screen-reader matrix, or claim formal conformance to a WCAG level.
Automated tests would not replace manual review even if added.

For every substantial UI change, manually verify:

1. keyboard-only navigation from page entry through the changed workflow;
2. visible focus, activation, dismissal, validation, and focus restoration;
3. accessible names, roles, states, error associations, and live announcements
   in browser accessibility tools;
4. heading/landmark structure and sensible reading order;
5. zoom/reflow and long translated strings at narrow width;
6. light/dark themes and information that might rely only on colour;
7. LTR and RTL layouts with mixed-direction user content; and
8. at least one representative screen-reader pass for a new custom widget or
   complex workflow.

Record a known exception instead of claiming conformance that was not tested.
Do not postpone a blocker such as an unnamed control or keyboard trap as visual
polish.

## Tests and project gate

Localization changes normally need:

```bash
vendor/bin/phpunit \
  tests/Core/TranslationManagerTest.php \
  tests/Core/FormatterTest.php \
  tests/Core/PluralRulesTest.php

npm test -- --run \
  tests/js/i18n.test.ts \
  tests/js/plural.test.ts \
  tests/js/date-time-format.test.ts \
  tests/js/admin-direction.test.ts

npm run typecheck
npm run build
```

Add focused view or browser tests for the component itself. Assert translated
visible text, placeholder interpolation, plural boundaries, direction-sensitive
state, semantic attributes, and keyboard/state transitions. Avoid snapshots so
large that a reviewer cannot see which contract matters.

Then run the complete gate from
[Testing](https://github.com/bfhp/stream-engine/wiki/Developer-Testing#focused-and-complete-runs).
A production build is mandatory after catalog changes because it regenerates
the browser catalogs. Review generated assets and ensure no source-only English
string replaced a key.

## Review checklist

- Every new user-visible server and browser string has one stable semantic key
  with complete entries in all supported catalogs.
- Value types and named placeholder sets match across catalogs, and plural
  values are keyed by exactly the locale's CLDR categories;
  interpolated values are escaped for their final output context.
- Sentences are translated as complete units; identifiers, API codes, routes,
  logs, hooks, and persisted enums remain stable and untranslated.
- Server and browser code use the shared translation, plural, date/time,
  number, timezone, and direction helpers instead of local alternatives.
- Dates are derived from instants with an explicit validated timezone; tests do
  not depend on the workstation locale or clock.
- Layout uses logical properties, directional icons are reviewed, and mixed-
  direction user content is isolated with `<bdi>` or `dir="auto"`.
- Native semantic controls, labels, headings, landmarks, table headers, image
  alternatives, and translated accessible names remain intact.
- Keyboard operation, focus order/restoration, visible focus, disabled/loading
  behavior, error recovery, and live announcements are verified.
- The UI was reviewed with long text, narrow reflow, light/dark presentation,
  LTR/RTL direction, browser accessibility tools, and an appropriate assistive
  technology pass for complex new interaction.
- Focused localization/accessibility tests, the production build, and the
  complete project gate pass; current limitations are reported honestly.

[Back: Testing](https://github.com/bfhp/stream-engine/wiki/Developer-Testing) · [Developer Guide](https://github.com/bfhp/stream-engine/wiki/Developer-Guide) · [Next: Performance](https://github.com/bfhp/stream-engine/wiki/Developer-Performance)

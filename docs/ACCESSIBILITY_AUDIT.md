# WCAG 2.2 AA audit

> **In progress.** This report records the state of the audit; it is not a
> conformance claim. The public statement is [`ACCESSIBILITY.md`](../ACCESSIBILITY.md).

The audit covers every WCAG 2.2 success criterion at levels A and AA (55
criteria; 4.1.1 Parsing was removed in WCAG 2.2). The scope is the one in
[`ACCESSIBILITY.md`](../ACCESSIBILITY.md#scope): the administration panel, the
bundled `default` and `bootstrap` themes, the built-in modules, the
installer, and all shipped locales.

| | |
| --- | --- |
| Standard | [WCAG 2.2](https://www.w3.org/TR/WCAG22/), levels A and AA |
| Version audited | `main`, October 2026 (0.5.x) |
| Last updated | 2026-10-16 |
| Methods | Automated checks (axe-core in Vitest and Playwright, keyboard walk) and code review; manual review with assistive technology is in progress |

## Results

The results use the terms of the
[VPAT / Accessibility Conformance Report](https://www.itic.org/policy/accessibility/vpat):

- **Supports**: the criterion is met, confirmed by manual review;
- **Partially Supports**: some parts do not meet it (the remarks say which);
- **Does Not Support**: most of the functionality does not meet it;
- **Not Applicable**: the engine has no content the criterion applies to;
- **Not Evaluated**: not reviewed by a person yet. "Automated checks pass"
  in the remarks means the CI checks find no problem, which is not enough to
  mark the criterion as supported.

| Result | Criteria |
| --- | ---: |
| Supports | 8 |
| Partially Supports | 10 |
| Does Not Support | 0 |
| Not Applicable | 4 |
| Not Evaluated, automated checks pass | 8 |
| Not Evaluated | 25 |

### 1. Perceivable

| Criterion | Level | Result | Remarks |
| --- | --- | --- | --- |
| 1.1.1 Non-text Content | A | Not Evaluated | Automated checks pass. Icon-only controls have translated names. Images in posts are described by their caption and get `alt=""`. Audio attachments in messages are named after the file. Open: image attachments in messages use the file name as `alt` and have no caption; avatars, covers and the CAPTCHA need review; older content may keep file-name `alt` text. |
| 1.2.1 Audio-only and Video-only (Prerecorded) | A | Partially Supports | The profile audio player and audio attachments in messages have no place for a transcript. Decide whether to add one or to leave transcripts to authors and say so in the statement. |
| 1.2.2 Captions (Prerecorded) | A | Not Applicable | There is no video player, and the HTML sanitizer removes `<video>`, `<iframe>` and `<embed>`. Message attachments are images, audio, or downloadable files. Revisit if video or embeds are added. |
| 1.2.3 Audio Description or Media Alternative (Prerecorded) | A | Not Applicable | As 1.2.2. |
| 1.2.4 Captions (Live) | AA | Not Applicable | The engine has no live media. |
| 1.2.5 Audio Description (Prerecorded) | AA | Not Applicable | As 1.2.2. |
| 1.3.1 Info and Relationships | A | Not Evaluated | Automated checks pass; profile labels are tied to their fields with `for`/`id` and `aria-describedby`. The bot trap in the feedback form was reachable by screen readers and Tab, so a person could fill it and be rejected as a bot; it is now hidden from both. To review with a screen reader: heading levels, administration tables, field groups. |
| 1.3.2 Meaningful Sequence | A | Not Evaluated | Check the reading order, especially right-to-left (Arabic) and card grids. |
| 1.3.3 Sensory Characteristics | A | Not Evaluated | |
| 1.3.4 Orientation | AA | Not Evaluated | |
| 1.3.5 Identify Input Purpose | AA | Not Evaluated | Automated checks pass. `autocomplete` is set on sign-in, registration, password reset and retrieval, feedback (`name`, `email`), profile (`nickname`, `url`, `bday`) and security (`email`, `new-password`) in both themes. To confirm: browsers offer the right values. |
| 1.4.1 Use of Color | A | Partially Supports | The messenger's online status was colour only; offline is now a hollow ring, and both states are named for screen readers and on hover. To review: links inside text, active tabs and filters, form errors. |
| 1.4.2 Audio Control | A | Not Evaluated | The audio player resumes playback on the next page. Check that its pause button is visible and keyboard-operable straight away. |
| 1.4.3 Contrast (Minimum) | AA | Not Evaluated | Automated checks pass for both themes, light and dark, English and Arabic, including content pages of a seeded site. To review: hover and disabled states, placeholders, pages outside the scan. |
| 1.4.4 Resize Text | AA | Not Evaluated | |
| 1.4.5 Images of Text | AA | Not Evaluated | |
| 1.4.10 Reflow | AA | Partially Supports | Only some public pages and the profile are checked at 320 CSS px. Not yet reviewed: administration panel, messages, forums, editors. |
| 1.4.11 Non-text Contrast | AA | Partially Supports | Focus indicators are checked for 3:1 in the keyboard walk. Not yet reviewed: field borders, checkboxes, switch tracks, icons without text, dashboard charts. |
| 1.4.12 Text Spacing | AA | Not Evaluated | |
| 1.4.13 Content on Hover or Focus | AA | Not Evaluated | The mention popup closes with Escape. To review: Mantine and Bootstrap tooltips are dismissible, hoverable and persistent. |

### 2. Operable

| Criterion | Level | Result | Remarks |
| --- | --- | --- | --- |
| 2.1.1 Keyboard | A | Partially Supports | The keyboard walk passes on ten administration screens and the profile. Known: the settings select list is not keyboard-scrollable (`scrollable-region-focusable`, in `tests/a11y/baseline.json`). To review: Chonky file browser, audio player, Trix. |
| 2.1.2 No Keyboard Trap | A | Not Evaluated | Automated checks pass. Tab leaves Monaco after Ctrl+M (Ctrl+Shift+M on macOS), as the hint next to each editor says; the keyboard walk uses that toggle. To review: Trix, audio player. |
| 2.1.4 Character Key Shortcuts | A | Not Evaluated | No single-character shortcuts were found outside text fields in the source. |
| 2.2.1 Timing Adjustable | A | Not Evaluated | Automated checks pass. Sessions last 30 days. Administration notifications stay 8 s, pause while hovered, and errors stay until closed (they used to close after 2 s). |
| 2.2.2 Pause, Stop, Hide | A | Not Evaluated | |
| 2.3.1 Three Flashes or Below Threshold | A | Supports | Nothing in the interface flashes. |
| 2.4.1 Bypass Blocks | A | Supports | "Skip to main content" on public pages (both themes) and in the administration panel; covered by automated tests. |
| 2.4.2 Page Titled | A | Supports | Public pages set their title in the `meta_title` block. Each administration screen sets its own window title ("Section · site title"), and the navigation marks the current item with `aria-current="page"`. |
| 2.4.3 Focus Order | A | Not Evaluated | Automated checks pass: focus stays in dialogs and returns to the control that opened them. To review: overall order, focus after deleting an item. |
| 2.4.4 Link Purpose (In Context) | A | Not Evaluated | |
| 2.4.5 Multiple Ways | AA | Not Evaluated | |
| 2.4.6 Headings and Labels | AA | Supports | Reviewed in the source against the English strings: headings name their section, labels name their field. The "Email" label on sign-in and feedback was not translated and now uses `view.common.email`; English labels and headings lost stray trailing full stops. The empty headings in the topic form belong to the preview, which fills them with the topic title. Translations in the other twelve locales have not been reviewed by native speakers. |
| 2.4.7 Focus Visible | AA | Not Evaluated | Automated checks pass: every stop of the keyboard walk changes visibly. To review: pages outside the walk, Trix, Chonky. |
| 2.4.11 Focus Not Obscured (Minimum) | AA | Supports | The page scrolls focused elements clear of the sticky header in both themes (the header height is tracked) and of the fixed administration header. Recheck if a cookie banner or another sticky bar is added. |
| 2.5.1 Pointer Gestures | A | Not Evaluated | |
| 2.5.2 Pointer Cancellation | A | Supports | Reviewed in the source. The only action on the down event was choosing a mention suggestion; it now happens on click, so sliding off an option cancels it (covered by a Vitest test). Drag and drop acts on release and is cancelled outside the target; everything else uses native controls and click. The third-party widgets (Mantine, Chonky, Monaco, Trix) were not reviewed in detail. |
| 2.5.3 Label in Name | A | Not Evaluated | |
| 2.5.4 Motion Actuation | A | Supports | No function is operated by device motion. |
| 2.5.7 Dragging Movements | AA | Partially Supports | Dashboard cards can also be moved with "Move up" and "Move down" buttons. To review: moving files in Chonky without dragging. |
| 2.5.8 Target Size (Minimum) | AA | Not Evaluated | The dashboard drag handle is now 24 × 24 px. To review: small link buttons (`btn-sm p-0`), the Trix toolbar, icons in messages. |

### 3. Understandable

| Criterion | Level | Result | Remarks |
| --- | --- | --- | --- |
| 3.1.1 Language of Page | A | Supports | `<html lang>` and `dir` follow the site locale in the public layouts, the error page, the administration panel and e-mail. |
| 3.1.2 Language of Parts | AA | Not Evaluated | There is no public language switcher, and the administration settings name languages in the interface language. Open: authors cannot mark text in another language in the rich-text editor; to be described in the statement. |
| 3.2.1 On Focus | A | Not Evaluated | |
| 3.2.2 On Input | A | Not Evaluated | |
| 3.2.3 Consistent Navigation | AA | Not Evaluated | |
| 3.2.4 Consistent Identification | AA | Not Evaluated | |
| 3.2.6 Consistent Help | A | Not Evaluated | |
| 3.3.1 Error Identification | A | Partially Supports | Failed sign-in is covered by the scan. To review: registration, topic form, profile and administration forms (error text, `aria-invalid`, link to the field). |
| 3.3.2 Labels or Instructions | A | Partially Supports | Registration and password reset state the password rule. The new-password field in the profile had no instructions; it now says "at least 10 characters, leave empty to keep the current password" and is linked to the field. To review: the remaining forms. |
| 3.3.3 Error Suggestion | AA | Not Evaluated | |
| 3.3.4 Error Prevention (Legal, Financial, Data) | AA | Not Evaluated | |
| 3.3.7 Redundant Entry | A | Not Evaluated | |
| 3.3.8 Accessible Authentication (Minimum) | AA | Partially Supports | Sign-in allows pasting and password managers. Registration can use a CAPTCHA: Turnstile usually needs no task, hCaptcha may show an image puzzle. Decide whether to recommend Turnstile or document the alternative. |

### 4. Robust

| Criterion | Level | Result | Remarks |
| --- | --- | --- | --- |
| 4.1.2 Name, Role, Value | A | Partially Supports | Automated checks pass except Chonky's file list (`aria-required-children`, in `tests/a11y/baseline.json`), so the file browser screen does not meet the criterion. To review with a screen reader: tabs, menus, switches, Trix, audio player. |
| 4.1.3 Status Messages | AA | Not Evaluated | Search announces progress with `role="status"`. To review: administration notifications, new messages, upload errors. |

## Updating this report

The audit is worked through in a shared checklist; this file is the
versioned record and is updated from it when results change. When a fix
lands, update the criterion's row in the same pull request. Change a result
to **Supports** only after a person has checked the criterion, not on the
strength of the automated checks alone. When every row has a result other
than Not Evaluated, update the conformance status in
[`ACCESSIBILITY.md`](../ACCESSIBILITY.md#conformance-status).

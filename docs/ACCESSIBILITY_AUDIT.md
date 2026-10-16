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
| Supports | 37 |
| Partially Supports | 5 |
| Does Not Support | 0 |
| Not Applicable | 4 |
| Not Evaluated, automated checks pass | 7 |
| Not Evaluated | 2 |

### 1. Perceivable

| Criterion | Level | Result | Remarks |
| --- | --- | --- | --- |
| 1.1.1 Non-text Content | A | Not Evaluated | Automated checks pass. Icon-only controls have translated names. Images in posts are described by their caption and get `alt=""`. Audio attachments in messages are named after the file. Images attached to messages are described by their sender and, when it says something, the file name ("Image from Anna: floor plan"); camera and screenshot names are left out, and people are not asked to caption images in private messages. Open: avatars, covers and the CAPTCHA need review; older content may keep file-name `alt` text. |
| 1.2.1 Audio-only and Video-only (Prerecorded) | A | Supports | Audio in blogs and communities is music, for which the track title is the alternative; the player shows it (file name or post title). Reading artist and title from ID3 tags is planned in `docs/TODO.md`. Audio attached to messages is user content. |
| 1.2.2 Captions (Prerecorded) | A | Not Applicable | There is no video player, and the HTML sanitizer removes `<video>`, `<iframe>` and `<embed>`. Message attachments are images, audio, or downloadable files. Revisit if video or embeds are added. |
| 1.2.3 Audio Description or Media Alternative (Prerecorded) | A | Not Applicable | As 1.2.2. |
| 1.2.4 Captions (Live) | AA | Not Applicable | The engine has no live media. |
| 1.2.5 Audio Description (Prerecorded) | AA | Not Applicable | As 1.2.2. |
| 1.3.1 Info and Relationships | A | Not Evaluated | Automated checks pass; profile labels are tied to their fields with `for`/`id` and `aria-describedby`. The bot trap in the feedback form was reachable by screen readers and Tab, so a person could fill it and be rejected as a bot; it is now hidden from both. To review with a screen reader: heading levels, administration tables, field groups. |
| 1.3.2 Meaningful Sequence | A | Not Evaluated | Check the reading order, especially right-to-left (Arabic) and card grids. |
| 1.3.3 Sensory Characteristics | A | Supports | Reviewed against the English strings. The empty messenger said "open a conversation on the left", which is on the right in right-to-left locales and above on a phone; it now says "from the list" in all locales. The file browser help now names Enter as well as double-click. Remaining "above" and "below" describe reading order next to a link or label. |
| 1.3.4 Orientation | AA | Supports | Nothing locks the orientation: no `orientation` media queries, no `screen.orientation.lock()`, no manifest `orientation`. Layouts are responsive in both themes and the administration panel. |
| 1.3.5 Identify Input Purpose | AA | Supports | `autocomplete` is set on sign-in, registration, password reset and retrieval, feedback (`name`, `email`), profile (`nickname`, `url`, `bday`) and email and password (`email`, `new-password`, `current-password`) in both themes; checked by hand that browsers offer the right values. |
| 1.4.1 Use of Color | A | Partially Supports | The messenger's online status was colour only; offline is now a hollow ring, and both states are named for screen readers and on hover. To review: links inside text, active tabs and filters, form errors. The latest-topic link in the `bootstrap` forum list on narrow screens was colour only (1.03:1 against the text); it is underlined now. |
| 1.4.2 Audio Control | A | Supports | Nothing plays on its own. The player resumes on the next page only the track the person started, and only if that page lists it; its pause button is visible, named and keyboard-operable. |
| 1.4.3 Contrast (Minimum) | AA | Not Evaluated | Automated checks pass for both themes, light and dark, English and Arabic, including content pages of a seeded site. To review: hover and disabled states, placeholders, pages outside the scan. |
| 1.4.4 Resize Text | AA | Supports | Checked at 200% zoom. The administration navigation did not scroll, so items below the sixth were out of reach and Tab moved focus off screen; the panel now scrolls, and a Playwright test focuses every item in a 640 × 360 window. |
| 1.4.5 Images of Text | AA | Supports | No text is shown as an image in the interface. |
| 1.4.10 Reflow | AA | Partially Supports | Checked at 320 CSS px by the scan: home, sign-in dialog, profile, forum list, forum, topic, blog post, messages (inbox and a conversation), and seven administration screens with the navigation closed (dashboard, feeds, pages, settings, users, scheduler, page editor); wide tables may scroll in their own container. Not yet reviewed: editors other than the page editor, community pages. Fixed: the pages and menus tables in the administration panel widened the whole page at 320 px; they now scroll in their own box. |
| 1.4.11 Non-text Contrast | AA | Partially Supports | Focus indicators are checked for 3:1 in the keyboard walk. Not yet reviewed: field borders, checkboxes, switch tracks, icons without text, dashboard charts. |
| 1.4.12 Text Spacing | AA | Not Evaluated | Automated checks pass. A scan check applies the WCAG spacing (line height 1.5, paragraph spacing 2, letter 0.12, word 0.16) and looks for clipped text on the home page, sign-in dialog, a forum topic, a blog post, and the administration dashboard, settings and feeds. Clamped excerpts are skipped: the full text is one link away. First run found the type and visibility badges in the feeds list losing their last letters; badges now keep the width of their text. |
| 1.4.13 Content on Hover or Focus | AA | Supports | Administration tooltips (Mantine) close with Escape and now stay open while the pointer is over them; they also show on keyboard focus. The `bootstrap` theme's tooltips (share buttons) now close with Escape and stay open while hovered; the `default` theme uses the browser's own `title` tooltips. The mention popup closes with Escape. Menus and popovers open on click, not hover. |

### 2. Operable

| Criterion | Level | Result | Remarks |
| --- | --- | --- | --- |
| 2.1.1 Keyboard | A | Partially Supports | The keyboard walk passes on ten administration screens and the profile. The settings select list is flagged by axe (`scrollable-region-focusable`, in `tests/a11y/baseline.json`), but focus stays in the input and the arrow keys reach every option; a scan check now confirms each option scrolls into view. The Chonky file browser opens files with Enter but moves between them only with Tab, not the arrow keys; that meets the criterion but is slow in large folders. To review: audio player, Trix. Arrow keys and list roles for the file browser are planned in `docs/TODO.md`. |
| 2.1.2 No Keyboard Trap | A | Not Evaluated | Automated checks pass. Tab leaves Monaco after Ctrl+M (Ctrl+Shift+M on macOS), as the hint next to each editor says; the keyboard walk uses that toggle. To review: Trix, audio player. |
| 2.1.4 Character Key Shortcuts | A | Supports | Key handlers use Enter, Escape, Tab, Backspace and arrows. The only character key, the comma in the tag field, works only while that field has focus. Trix and Monaco shortcuts use Ctrl/Cmd and work only inside the editor. |
| 2.2.1 Timing Adjustable | A | Supports | Sessions last 30 days. Administration notifications stay 8 s, pause while hovered, and errors stay until closed. Site notifications in both themes now do the same (they closed after 5 s, errors included, and did not pause in the `default` theme). |
| 2.2.2 Pause, Stop, Hide | A | Supports | Only loading and "typing…" indicators move; they are short and stop with `prefers-reduced-motion`. The message list updates as messages arrive, which is essential to a chat. The Scheduler table refreshed every 2–30 s with no way to stop it; it now has an "Auto-refresh" switch. |
| 2.3.1 Three Flashes or Below Threshold | A | Supports | Nothing in the interface flashes. |
| 2.4.1 Bypass Blocks | A | Supports | "Skip to main content" on public pages (both themes) and in the administration panel; covered by automated tests. |
| 2.4.2 Page Titled | A | Supports | Public pages set their title in the `meta_title` block. Each administration screen sets its own window title ("Section · site title"), and the navigation marks the current item with `aria-current="page"`. |
| 2.4.3 Focus Order | A | Not Evaluated | Automated checks pass: focus stays in dialogs and returns to the control that opened them. To review: overall order, focus after deleting an item. |
| 2.4.4 Link Purpose (In Context) | A | Supports | Links name their target through their text or context. Fixed: the forum pagination arrows had no name (now "Previous"/"Next", both themes), the group-chat "Remove" button now names the member, and "Read on" includes the article title for screen readers. The share buttons in the `bootstrap` theme were icons with no name; each is now named ("Share on Telegram (opens in a new tab)"). |
| 2.4.5 Multiple Ways | AA | Supports | Pages are reachable through the menus, search and the sitemap. |
| 2.4.6 Headings and Labels | AA | Supports | Reviewed in the source against the English strings: headings name their section, labels name their field. The "Email" label on sign-in and feedback was not translated and now uses `view.common.email`; English labels and headings lost stray trailing full stops. The empty headings in the topic form belong to the preview, which fills them with the topic title. Translations in the other twelve locales have not been reviewed by native speakers. |
| 2.4.7 Focus Visible | AA | Not Evaluated | Automated checks pass: every stop of the keyboard walk changes visibly. To review: pages outside the walk, Trix, Chonky. |
| 2.4.11 Focus Not Obscured (Minimum) | AA | Supports | The page scrolls focused elements clear of the sticky header in both themes (the header height is tracked) and of the fixed administration header. Recheck if a cookie banner or another sticky bar is added. |
| 2.5.1 Pointer Gestures | A | Supports | No function needs a path-based or multipoint gesture. |
| 2.5.2 Pointer Cancellation | A | Supports | Reviewed in the source. The only action on the down event was choosing a mention suggestion; it now happens on click, so sliding off an option cancels it (covered by a Vitest test). Drag and drop acts on release and is cancelled outside the target; everything else uses native controls and click. The third-party widgets (Mantine, Chonky, Monaco, Trix) were not reviewed in detail. |
| 2.5.3 Label in Name | A | Supports | Accessible names compared with visible text for every button and link in the templates and the administration panel. The article card's "Read on" link was named "Read the article …"; its name now starts with the visible words ("Read on: <title>"). Icon-only controls and the "×" close buttons have no visible text to match. |
| 2.5.4 Motion Actuation | A | Supports | No function is operated by device motion. |
| 2.5.7 Dragging Movements | AA | Supports | Dashboard cards have "Move up" and "Move down" buttons. In the file browser, files are selected with the keyboard or a click and moved with Cut and Paste in the Actions menu. Forum attachments have a file picker besides drag and drop. |
| 2.5.8 Target Size (Minimum) | AA | Supports | Buttons on the site are at least 24 × 24 px (icon-only link buttons with no padding were about 16 px wide); the close button in the `default` theme is 32 px (it was about 13 px); the dashboard drag handle and card refresh button are 24 and 28 px. Inline links are exempt. |

### 3. Understandable

| Criterion | Level | Result | Remarks |
| --- | --- | --- | --- |
| 3.1.1 Language of Page | A | Supports | `<html lang>` and `dir` follow the site locale in the public layouts, the error page, the administration panel and e-mail. |
| 3.1.2 Language of Parts | AA | Supports | The interface is translated in every shipped locale: the administration Scheduler screen (65 strings in eleven locales) and seventeen Russian error messages were translated on 2026-10-16. Untranslated strings left are proper names (Stream Engine Admin, CAPTCHA, Cloudflare Turnstile, hCaptcha) and date-format patterns. There is no public language switcher; the settings name languages in the interface language. Author content is out of scope; the rich-text editor cannot mark text in another language, which the statement should mention. |
| 3.2.1 On Focus | A | Supports | No focus handler in the source opens a window, submits a form or moves to another page. |
| 3.2.2 On Input | A | Supports | No change or input handler submits a form or navigates; filters, sorting and settings apply with a button. Choosing a file starts its upload, which changes content, not context. |
| 3.2.3 Consistent Navigation | AA | Supports | Both themes repeat the same navigation in the same order on every page. |
| 3.2.4 Consistent Identification | AA | Supports | The same actions use the same names and icons throughout (Delete with a bin, Remove and close with a cross, Edit with a pencil; Log out, Sign in). Checked across all thirteen locales by comparing strings that share an English text: "Remove" controls were labelled "Delete" in English, the untranslated "Disabled" and "Enabled" now reuse each locale's words, and stray full stops were removed from Arabic, Dutch and Polish labels. |
| 3.2.6 Consistent Help | A | Supports | The feedback link is in the same place on every page. |
| 3.3.1 Error Identification | A | Supports | The browser checks required fields and formats and names the field. Server errors are text that names the problem, shown in a notification (`role="status"`/`alert`) or the profile tab status (`role="status"`); error notifications now stay until closed. Possible improvement: mark the field (`aria-invalid`) and move focus to it. |
| 3.3.2 Labels or Instructions | A | Supports | Every form states its rules before submission. Registration, password reset and the profile email and password form state the password rule and why the current password is needed. Added: the feedback message length (30 characters), the homepage format, tag limits (8 tags, 50 characters each), accepted formats and sizes for avatars, covers and tracks (the cover hint said 8 MB, the server allows 50 MB), the page pattern format and the username rule in the administration panel. Required titles and names are marked `aria-required`; the blog tag field is named by its heading and described by its rules. |
| 3.3.3 Error Suggestion | AA | Supports | Error messages say what to do next in all 13 locales: an expired session asks to reload the page, stale links ask for a new one, a failed sign-in suggests checking or resetting the password, length and count errors give the limit (title 200, tag 50, 8 tags, comment 5,000, description 2,000), file errors name the formats and sizes, administration conflicts name the fix (choose another name or parent, make another administrator first, enter both CAPTCHA keys, check the JSON), and failed requests, on the site and in the administration panel, say to check the connection or reload the page. |
| 3.3.4 Error Prevention (Legal, Financial, Data) | AA | Supports | There are no legal or financial transactions. Deleting user data (topics, messages, comments, posts, files, administration pages and menus) always asks for confirmation; resetting the site icon or logo can be undone by uploading again. Changing the email or password needs the current password, and a new email must be confirmed. The engine has no account deletion. |
| 3.3.7 Redundant Entry | A | Supports | Nothing asks again for information given earlier in the same process. Password reset no longer asks for the new password twice; a "Show password" button catches typos instead. |
| 3.3.8 Accessible Authentication (Minimum) | AA | Supports | Sign-in allows pasting and password managers, and every password field has a "Show password" button. Changing the email or password asks for the current password, which a password manager fills in. For registration, Turnstile is recommended in the settings help and the statement: Cloudflare states it meets WCAG 2.2 AA and it usually needs no task. hCaptcha may show an image task, which the Object Recognition exception allows at AA; blind visitors need its accessibility cookie. |

### 4. Robust

| Criterion | Level | Result | Remarks |
| --- | --- | --- | --- |
| 4.1.2 Name, Role, Value | A | Partially Supports | Automated checks pass except Chonky's file list (`aria-required-children`, in `tests/a11y/baseline.json`), so the file browser screen does not meet the criterion. To review with a screen reader: tabs, menus, switches, Trix, audio player. Arrow keys and list roles for the file browser are planned in `docs/TODO.md`. |
| 4.1.3 Status Messages | AA | Not Evaluated | Administration notifications are `role="alert"`; site toasts sit in a live region in both themes; search progress and the save messages on every profile tab use `role="status"`. Messages that arrive in the open conversation were not announced; a live region now reads the sender and text (up to three at a time, own messages left out). To confirm with a screen reader. |

## Updating this report

The audit is worked through in a shared checklist; this file is the
versioned record and is updated from it when results change. When a fix
lands, update the criterion's row in the same pull request. Change a result
to **Supports** only after a person has checked the criterion, not on the
strength of the automated checks alone. When every row has a result other
than Not Evaluated, update the conformance status in
[`ACCESSIBILITY.md`](../ACCESSIBILITY.md#conformance-status).

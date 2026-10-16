# TODO

The project's current backlog. Last reviewed against the code and tests on
**October 11, 2026**.

This file contains only unfinished work and decisions that have been made but
not yet implemented. Completed work is not recorded here; Git provides that
history. When a task is completed, it should be removed from this file together
with any related `TODO` comments in the code.

Priorities:

- **P0** — a clean installation does not work, the user receives a false
  success response, or data may be lost or returned incorrectly;
- **P1** — important reliability, security, or operational work;
- **P2** — product improvements, refactoring, and localized technical debt.

## File maintenance rule

A new task must describe the observable problem, the desired outcome, and,
where important, how completion will be verified. Keep diagnostic notes and
reports about completed work in commits/PRs instead of turning TODO back into a
changelog.

## P2

### Arrow keys and list roles in the file browser

**Problem.** The administration file browser (Chonky) opens a file with Enter,
but moves between files only with Tab and Shift+Tab; the arrow keys do
nothing, which is slow in large folders. Its file list also has the wrong
roles for a screen reader (axe `aria-required-children`, accepted for
`admin/file-browser` in `tests/a11y/baseline.json`), so the screen does not
meet WCAG 4.1.2.

**Outcome.** The arrow keys move focus between files in both the list and
grid views (Home and End to the first and last), and the list exposes a
valid structure (a `grid` or `listbox` with `row`/`option` children), either
through Chonky's options or a thin wrapper. The baseline entry is removed.

**Verification.** The keyboard walk moves through a folder with the arrows;
the `admin/file-browser` scan passes with no baseline entry.

### Name audio tracks from their ID3 tags

**Problem.** The audio player on blogs and communities names a track by the
uploaded file's name (`UsersController::buildPlaylistItems()`), so people and
screen readers hear names such as `track_01_final.mp3`. WCAG 1.2.1 asks for a
text alternative for prerecorded audio; for music, the title and artist are
that alternative.

**Outcome.** When an MP3 or other tagged file is uploaded, its title and
artist (ID3 or the format's own tags) are read once and stored with the
upload; the player shows "Artist — Title", falling back to the post title and
then to the file name. The player's accessible label for the current track
uses the same text.

**Verification.** A tagged and an untagged file in a test blog: the player and
its `aria-label` show the tags for the first and the file name for the second.

# TODO

The project's current backlog. Last reviewed against the code and tests on
**November 2, 2026**.

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

### Resolve the deepmerge advisory in the Chonky dependency chain

**Problem.** `package-lock.json` includes `deepmerge` 4.3.1 through
`@samuelncui/chonky` and `@samuelncui/chonky-icon-fontawesome`. npm reports
[GHSA-gcqx-w48m-w2h4](https://github.com/advisories/GHSA-gcqx-w48m-w2h4), a
low-severity prototype-poisoning issue. The packages are used by the
administration file browser, so their development-dependency classification
does not establish that shipped frontend code is unaffected.

**Outcome.** Assess whether untrusted file-browser data can reach the affected
merge operation and resolve the risk with an upstream fix, a maintained
replacement, or a reviewed mitigation. Keep the dependency and lockfile changes
reproducible.

**Verification.** Run the dependency audit and exercise the file browser after
the change. Cover the reachable malicious object shape if applicable; any
remaining advisory must have a documented, verified mitigation.

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

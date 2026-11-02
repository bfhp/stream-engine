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

## P1

### Enforce parent access rules for comments

**Problem.** `FeedService::createComment()` stores comments with the default
`public` visibility. `getComments()` does not authorize the parent, and generic
feed lookups and listings authorize only the comment itself. Guests can read
comments on private or members-only publications, including through
`GET /api/v1/feeds?type=comment`.

**Outcome.** Comments and nested replies require access to their parent
publication through every read path. The rule follows the parent's current
visibility and membership policy, including after a visibility change or
membership revocation; copying visibility only when a comment is created is
not sufficient.

**Verification.** Create discussions on public, members-only, and private
publications. Exercise thread endpoints, direct feed lookups, generic listings,
and search as a guest, an unrelated member, an allowed reader, and the owner.
Repeat after changing visibility and revoking membership. Unauthorized readers
receive no protected comment content.

### Revoke existing access when a password is reset

**Problem.** `UserService::resetPasswordByToken()` changes the password and
deletes only the submitted reset token. Existing sessions remain authenticated,
and another outstanding reset link can change the password again. A person
recovering an account cannot reliably remove an attacker's access.

**Outcome.** Password recovery atomically consumes the submitted token, changes
the password, invalidates every other reset token for that user, and revokes
all existing sessions. Concurrent requests cannot reuse a consumed token.

**Verification.** Seed multiple sessions and reset links for one user. After a
successful reset, every old session and reset link is rejected, the old password
fails, and the new password works. Concurrent uses of one reset token permit
only one successful password change.

### Prevent shared caching of personalized responses

**Problem.** `StreamEngine::dispatchHtmlRequest()` sends
`Cache-Control: public, max-age=60` for every successful HTML response,
including authenticated profiles and protected content. With a shared HTTP
cache, one visitor's response may be served to another visitor.

**Outcome.** Personalized and protected responses use `Cache-Control: private,
no-store`. Apply a consistent policy to HTML, sensitive API responses, and
errors. Public caching is allowed only for responses whose content is
independent of the visitor and session.

**Verification.** Assert response headers for guest and authenticated requests,
including profile and private-publication pages. Use a shared-cache integration
test to confirm that one user's protected response cannot be reused for a guest
or another account.

## P2

### Restrict CSS in user-authored HTML

**Problem.** `FeedService` registers `nav.style` with HTMLPurifier as `Text`,
bypassing CSS validation. A user can publish a fixed-position element covering
the entire viewport and replace the visible site interface with misleading
content or links.

**Outcome.** Remove the unrestricted style definition. If inline styles are
needed, validate them through a restricted CSS policy that prevents
user-authored content from covering the surrounding interface.

**Verification.** Submit rich-text blog posts and forum content containing
fixed-position, viewport-sized overlays and external CSS URLs. Inspect the
stored HTML and rendered page: unsafe styles are removed, site navigation stays
accessible, and supported formatting still works.

### Limit authentication attempts and password recovery requests

**Problem.** Login and password recovery have no application rate limits, and
the bundled Nginx configuration supplies none. Callers can repeatedly attempt
passwords or trigger reset emails and token creation. CSRF protection does not
bound requests from a caller using its own valid token.

**Outcome.** Add bounded policies for login attempts and reset requests keyed
by account and client IP. Define their windows, storage, trusted-proxy handling,
and failure behavior. Rejected requests must not create reset tokens or send
mail, and recovery responses must not disclose whether an account exists.

**Verification.** Exercise repeated requests for one account, multiple accounts
from one IP, and one account from multiple IPs. Verify enforcement, expiry of
the limit, consistent account-existence responses, and the absence of mail or
token writes for rejected requests.

### Protect attachments in private conversations

**Problem.** `MessageService::mapMessage()` returns attachment URLs below
`/uploads/`, which Nginx serves directly without authorization. Anyone who
obtains a URL can download the attachment regardless of conversation membership;
removing access to the conversation does not revoke that URL.

**Outcome.** Define separate public and private upload policies. Serve private
conversation attachments from storage outside the public alias through a
download endpoint that checks the current user's access. Define revocation and
shared-file behavior while preserving intentionally public uploads.

**Verification.** An authorized participant can download an attachment; guests,
unrelated accounts, and removed participants cannot. Direct static paths cannot
bypass authorization. Verify the defined revocation behavior and that public
blog and forum attachments continue to work.

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

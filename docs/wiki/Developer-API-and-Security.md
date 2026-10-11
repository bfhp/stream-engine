# Developer Guide: API and security

Stream Engine exposes versioned JSON routes through the same page tree and
controller system as public HTML. The current API is a same-origin browser API:
it authenticates with the site's session cookie and protects mutations with a
double-submit CSRF cookie/header check. It is not a bearer-token API and does
not provide a general cross-origin authentication contract.

This chapter defines route, response, authentication, authorization,
validation, output, upload, secret, and review requirements for new code. The
route-tree mechanics are documented in
[Pages and routing](https://github.com/bfhp/stream-engine/wiki/Developer-Pages-and-Routing),
and browser integration is documented in
[Frontend development](https://github.com/bfhp/stream-engine/wiki/Developer-Frontend).

## Security layers

Treat these as independent checks. Passing an earlier layer never implies that
a later one is safe:

| Layer | Current owner | Question answered |
| --- | --- | --- |
| Route definition | `Page::api()` and `PageTree` | Does this path/action exist, and which HTTP methods can reach it? |
| Page audience | `AccessService::canAccessPage()` | May this broad role reach the endpoint at all? |
| Authentication | `RequestContext->user` / `requireAuthenticatedUser()` | Does the request have a valid account session? |
| Resource authorization | Service/controller policy | May this user read or change this specific object? |
| CSRF | `Security::verifyCsrf()` | Did a same-origin browser return the token for this mutation? |
| Input validation | Controller and domain service | Is every supplied value well-formed and allowed by business rules? |
| Persistence | Repository / `PdoDatabase` | Are values bound safely and state changes atomic where required? |
| Output boundary | Explicit JSON shape, escaping, purification | Can the response disclose data or execute as markup? |

Client routes, hidden buttons, guessed-unlikely IDs, theme visibility, and
JavaScript validation are not security boundaries.

## Register a versioned endpoint

Modules add descendants below `/api/v1` in `registerApi()`:

```php
public static function registerApi(int $apiPageId, PageTree $pageTree): void
{
    $notesId = $pageTree->getMaxPageId();
    $pageTree->add(Page::api(
        id: $notesId,
        parentId: $apiPageId,
        pattern: 'notes',
        requestMethods: ['GET', 'POST'],
        action: 'notes.collection',
        accessRule: AccessService::ACCESS_AUTHENTICATED,
    ));

    $pageTree->add(Page::api(
        id: $pageTree->getMaxPageId(),
        parentId: $notesId,
        pattern: '{noteId:\d+}',
        requestMethods: ['GET', 'PATCH', 'DELETE'],
        action: 'notes.item',
        accessRule: AccessService::ACCESS_AUTHENTICATED,
    ));
}
```

`Page::api()` sets `responseType` to `json`. Actions and page IDs must be
unique in the complete boot tree. Use explicit resource nouns and named route
parameters; nested dynamic levels must not reuse a parameter name because the
router's aggregate parameter map keeps only the deepest value.

The engine verifies the resolved page's declared method before calling the
controller. An undeclared method becomes a `405` JSON error. A multi-method
action must still branch explicitly in its handler and must not let one method
fall into another method's write path.

`/api/v1` is a transport compatibility boundary. Additive fields and new
resources may remain in v1 when old clients continue to work. Renaming or
removing fields, changing their type or meaning, changing an established
status, or moving a resource requires an explicit compatibility plan and
normally a new API version. Never derive API URLs from administrator-editable
public page patterns.

## Dispatch and success responses

`ControllerInterface::callApi()` returns `void`; the current transport writes
headers, status, and body directly. Dispatch only known actions and make the
default branch a deliberate client error:

```php
public function callApi(Page $page, array $args = []): void
{
    header('Content-Type: application/json');

    match ($page->action) {
        'notes.collection' => $this->handleCollection(),
        'notes.item' => $this->handleItem((int) ($args['noteId'] ?? 0)),
        default => throw new NotFoundException('Unknown API action'),
    };
}
```

Use `Formatter::json()` for application responses. It preserves Unicode and
slashes and throws rather than silently returning an empty successful body for
unencodable data. Prefer an explicit array over serializing a repository row or
domain object wholesale: the response should contain only fields intentionally
approved for this audience.

Use the established HTTP meanings:

- `200` for a successful read or update with a body;
- `201` for a newly created resource;
- `204` for successful deletion or another operation with no body; and
- the error statuses described below.

A `204` response must not print JSON. Set `Content-Type: application/json` for
JSON success responses; the central exception path sets it for JSON errors.
The current codebase does not wrap every successful result in one common
envelope, so preserve a resource's documented shape and add focused response
tests when changing it.

Bound list work and return pagination metadata or a cursor rather than an
unbounded array. Clamp client-provided limits on the server.

## Error contract

Throw the exception that describes the deliberate client-visible result:

| Exception | Default status | `code` |
| --- | --- | --- |
| `ValidationException` | `400` | `bad_request` |
| `ForbiddenException` | `403` | `forbidden` |
| `NotFoundException` | `404` | `not_found` |
| `ValidationException(..., 405, 'method_not_allowed')` | `405` | `method_not_allowed` |
| Other `Throwable` | `500` | `internal_error` |

The JSON error shape is:

```json
{
  "error": "Human-readable message",
  "code": "machine_readable_code"
}
```

A deliberate `ValidationException` message is returned to the client in every
environment. It must not contain SQL, filesystem paths, credentials, tokens,
private identifiers, or internal exception text. Unexpected throwables are
logged with method, path, class, source location, and message, while the HTTP
response remains a generic localized `500`. The main request handler currently
does not expose exception detail even when `APP_ENV=dev`.

Do not catch an unexpected exception and turn it into `403`, `404`, an empty
array, or a successful response. That hides defects and can cause clients to
retry a write incorrectly. Catch only when the operation has a defined recovery
rule; for example, a notification failure after a committed comment may be
logged without changing the already successful comment response.

Legacy handlers may still emit an ad hoc `{ "error": ... }` response directly.
New code should throw a typed exception so the status and machine code use the
central path.

## Authentication and sessions

`AuthService` authenticates the `SE_AUTH` cookie. Login creates 32 random bytes,
sends the hexadecimal token to the browser, and stores only its SHA-256 hash in
`user_sessions`. The cookie is `Secure`, `HttpOnly`, `SameSite=Lax`, scoped to
`/`, and has a fixed 30-day expiry. Session lookup rejects expired rows; the
scheduled cleanup removes them later. Logout deletes the current token's row
and expires the cookie.

Passwords are verified with `password_verify()` and newly saved with
`PASSWORD_DEFAULT`. The current registration/password-change policy accepts
10–255 characters. Do not add a second hashing scheme, log credentials, return
password hashes, or compare a password yourself.

Controllers receive identity through immutable `RequestContext`; use
`$this->context->user`, not a user ID supplied by the browser. A guest is a
`User` with ID `0`. `requireAuthenticatedUser()` rejects a guest, but it says
nothing about resource ownership, membership, moderator scope, or admin status.

The API has no bearer, API-key, OAuth, or refresh-token authentication and no
general CORS policy. Do not advertise it to third-party clients or enable
credentialed cross-origin requests without designing and reviewing a complete
authentication, origin, CSRF, token-revocation, and rate-limit contract.

## Authorization and object scope

Page `accessRule` accepts `public`, `authenticated`, `moderator`, or `admin`.
The engine checks it before constructing the controller. An inaccessible JSON
route returns `403`; an inaccessible HTML route is rendered as `404` to avoid
disclosing its existence.

That broad audience check is only the first authorization step. A handler must
authorize the concrete resource and requested operation after loading it:

- use `AccessService` for shared feed visibility and edit rules;
- require ownership for user uploads and private user state;
- check container membership or moderator level in the relevant service;
- use `AccessService::isAdmin()` for genuinely site-wide operations; and
- return the same absence result where distinguishing “missing” from “exists
  but belongs to someone else” would disclose private state.

Never trust `ownerId`, `userId`, role, visibility, price, quota, or another
policy field merely because it came from an authenticated request. Derive the
actor from `RequestContext`, allowlist fields that the operation accepts, and
have the service enforce invariants. Test an authorized user and a different
authenticated user; positive tests alone do not catch insecure direct object
references.

Repository queries that list content must enforce visibility in the query or
through one bounded policy-aware service operation. Filtering an unrestricted
result in Twig or JavaScript leaks data and wastes work.

## CSRF protection

Every successfully rendered public HTML response issues a `csrfToken` cookie
when one is absent. It contains 32 random bytes encoded as 64 lowercase
hexadecimal characters, is
`Secure`, `SameSite=Lax`, readable by browser code, and expires after 24 hours.
Mutating requests return the exact value in `X-CSRF-Token`.

Every `POST`, `PATCH`, `PUT`, and `DELETE` endpoint must verify it before any
state change:

```php
Security::verifyCsrf(
    $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null,
    $this->tm,
);
```

Use `CMS.api()` for normal public JSON calls, `csrfHeaders()` in the admin SPA,
and the shared upload helper for `FormData`. Do not copy the cookie parser.
CSRF applies to authenticated and guest mutations alike: a public feedback
form or guest preference can still be triggered from another origin.

CSRF is not authentication, authorization, validation, or replay prevention.
A valid token does not prove which user owns a resource. Check it before a
write, then continue with identity, resource policy, and input checks.

Run the repository audit:

```bash
composer audit:csrf
```

The audit scans route declarations and follows handler calls heuristically. It
checks that each declared mutating method appears to reach `verifyCsrf()`, but
it does not execute the endpoint and can be confused by dynamic dispatch or
unusual control flow. Add runtime tests proving a missing, malformed, and
mismatched token cannot reach the write.

## Input validation

All request data is untrusted, including route captures, query strings,
headers, cookies, filenames, JSON values, and data previously stored by an old
version.

For query parameters, use the request snapshot in
`$this->context->query`. `QueryParams::string()`, `trimmed()`, `int()`, and
`boolOrNull()` reject arrays and invalid scalar shapes without warnings. Clamp
numeric bounds after parsing.

For JSON, require an object/associative array before reading fields. A safe
new-handler pattern is:

```php
try {
    $input = json_decode(
        (string) file_get_contents('php://input'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
} catch (JsonException) {
    throw new ValidationException('Malformed JSON body');
}

if (! is_array($input) || array_is_list($input)) {
    throw new ValidationException('JSON body must be an object');
}
```

Then validate presence, scalar type, normalization, length, enum membership,
relationships, and business constraints explicitly. Do not cast an array to a
string or accept a numeric-looking identifier without checking its allowed
range. Reject or deliberately ignore unknown fields; never pass the whole input
array to a general repository update.

Transport validation belongs near the controller. Reusable domain invariants
and authorization belong in the service so HTML, API, cron, and future callers
cannot bypass them. Database constraints remain the last line of defense and
must not be the first user-visible validator.

`PdoDatabase` uses native prepared statements. Bind every value through its
parameter array. SQL identifiers, sort expressions, and directions cannot be
bound; map them from a closed allowlist. Escape `%` and `_` deliberately when a
user value is used as literal text inside a `LIKE` pattern.

## Output, HTML, and script safety

JSON values encoded with `Formatter::json()` do not become trusted HTML. In
the browser, use `textContent` or React interpolation for plain text. Insert
HTML only when the server has sanitized it for that exact context.

The current public `CachedEnvironment` does **not** enable Twig autoescape
globally. Treat every plain untrusted value as requiring explicit escaping:

```twig
<p>{{ displayName|e('html') }}</p>
<input value="{{ value|e('html_attr') }}">
```

JavaScript, JSON-in-attribute, URL, CSS, and XML contexts need their own
context-aware encoding; HTML escaping alone is not interchangeable with them.
Use `json_encode|e('html_attr')` for structured data in an attribute. Prefer
generated URLs and safe same-origin navigation helpers over concatenating
untrusted URL fragments.

`|raw`, `innerHTML`, and `data-slot-html` require a documented trust boundary.
Feed content, comments, forum content, and signatures are purified on their
server write paths before templates intentionally render them raw. If a new
write path can reach one of those columns, it must use the same sanitizer.
Administrator widget HTML and fixed developer-owned `head_ext` tags are
explicit trusted-author boundaries; never mix request values into them.

Do not create a one-off regular-expression HTML sanitizer. Extend the relevant
HTMLPurifier policy, test allowed and forbidden markup, sanitize before storage,
and keep the renderer's `|raw` comment tied to that invariant.

The regular application currently relies on the deployment for headers such as
HSTS, `X-Content-Type-Options`, framing restrictions, referrer policy, and any
site-wide Content Security Policy; only the installer sets its own restrictive
CSP and `nosniff`. Coordinate new inline or third-party scripts with the actual
web-server policy. Do not claim an application-wide CSP until normal responses
set and test one.

## Uploads and filesystem input

Uploads require authentication and CSRF before file processing. The standard
service:

- reads the temporary file selected by PHP, not a client path;
- detects MIME from file contents with `finfo`, not the browser media type or
  extension;
- enforces a closed MIME allowlist and a 50 MiB application limit;
- enforces the non-admin user's aggregate storage quota;
- chooses a random server filename and a canonical extension;
- re-encodes supported still images, while preserving GIF bytes to avoid
  flattening animations; and
- records owner, path, detected MIME, final size, and original display name.

PHP and the web server may reject a request before these checks, so application,
PHP, and reverse-proxy limits must be tested together. Never trust
`$_FILES['name']`, serve an uploaded file as executable code, or infer safety
from an image extension.

When a later request attaches an existing upload, resolve it through an
owner-scoped method such as `findOwnedUpload()` and verify purpose/MIME. An
integer upload ID is not proof of ownership. Administrator file-browser paths
must remain below the configured uploads root, reject absolute/traversal/control
segments, and avoid following a symlink out of that root.

Standard uploads are served publicly below `/uploads/`. Do not put secrets,
private documents, or authorization-dependent bytes there without designing a
private download controller and storage policy. The reference object-storage
client is not part of the active upload authorization lifecycle.

## Secrets and external services

Infrastructure credentials belong in `.env` or the deployment secret system,
outside `public/` and Git. Access them through `Config`; do not read a secret in
Twig or expose it to browser code. Never put database, SMTP, object-storage, or
application secrets in URLs, JSON responses, exception messages, screenshots,
fixtures, or query logs.

`APP_SECRET` signs selected application values but is not an authentication
key for API callers. Preserve it across deployments and restores. A missing
secret must fail closed for a feature that depends on it, as the feedback form
does, rather than silently signing with an empty value.

Some feature secrets, currently CAPTCHA credentials, are stored in database
settings. Administration responses return only public configuration and a
“secret configured” boolean; preserve that write-only behavior. A blank update
that means “keep the current secret” must never echo the stored value.

Generate verification/session/reset credentials with `random_bytes()`, give
them an expiry and single-use lifecycle, compare signatures with
`hash_equals()`, and store a one-way token hash when lookup permits it. The
current password-reset table is a legacy exception that stores its token
directly; do not copy that design into new features.

External requests must use fixed or allowlisted endpoints, bounded connection
and total timeouts, TLS verification, no uncontrolled redirects, and a defined
failure mode. Never fetch an arbitrary user-supplied URL from the application
server. CAPTCHA verification follows this pattern with fixed provider
endpoints; CAPTCHA and honeypots are abuse controls, not replacements for
authorization or CSRF.

There is no centralized application rate limiter today. Abuse-sensitive work
therefore needs an explicit bounded policy at the reverse proxy, provider, or
feature layer. Document its identity key, window, response, storage, proxy-IP
trust assumptions, and failure behavior; do not return `429` without an
implemented limit.

## Security testing

For every new or changed endpoint, cover:

- route registration, exact methods, response type, and page access rule;
- guest, permitted role, wrong role, owner, and different authenticated user;
- missing resource versus unauthorized resource disclosure behavior;
- missing, array-shaped, empty, mismatched, and valid CSRF tokens for each
  mutating method;
- malformed JSON, wrong scalar/array types, unknown fields, boundary lengths,
  invalid enums, zero/negative/overflow identifiers, and bounded pagination;
- expected success status/body and the standard error status/body;
- persistence rollback or idempotency where a partial write is possible;
- output escaping or sanitizer allow/deny cases for any rendered content; and
- upload MIME, size, ownership, traversal, processing, and cleanup behavior
  where files are involved.

Use focused PHPUnit controller/service/repository tests while iterating, then
run the security and project gates. [Testing](https://github.com/bfhp/stream-engine/wiki/Developer-Testing)
defines the scope, isolation, database, regression, and CI rules behind these
commands:

```bash
vendor/bin/phpunit \
  tests/Core/ApiErrorResponseTest.php \
  tests/Core/SecurityTest.php \
  tests/Security/CsrfAuditTest.php \
  tests/Security/CsrfCoverageTest.php

composer audit:csrf
composer test
npm test
npm run typecheck
```

Run `npm run build` when browser sources or generated catalogs changed. A clean
CSRF scan is necessary but not sufficient; authorization, disclosure, input,
and output failures require runtime tests and review.

## Review checklist

- The endpoint is below the intended API version, has one owner, declares only
  supported methods, and has a compatibility plan for wire-format changes.
- The controller sends explicit JSON/status results and uses the standard
  typed error path without leaking internal detail.
- Identity comes from `RequestContext`; broad page access and concrete
  resource authorization are both enforced server-side.
- Every mutating method verifies CSRF before writing and has a runtime failure
  test in addition to the static audit.
- Route, query, JSON, header, cookie, and stored values are type-, length-,
  range-, relationship-, and allowlist-validated at the correct layer.
- SQL values are bound; dynamic identifiers and ordering come from closed
  maps; multi-step state changes are atomic where required.
- Responses expose an explicit field set; every HTML/URL/attribute/JSON/XML
  context uses the correct encoding or a documented purifier boundary.
- Uploads use detected MIME, quotas, safe paths/names, ownership checks, and
  non-executable public delivery.
- Secrets and internal exceptions remain out of responses, browser bundles,
  logs, URLs, fixtures, and Git.
- Negative authorization, CSRF, malformed-input, disclosure, and regression
  tests pass together with the complete project gate.

[Back: Frontend development](https://github.com/bfhp/stream-engine/wiki/Developer-Frontend) · [Developer Guide](https://github.com/bfhp/stream-engine/wiki/Developer-Guide) · [Next: Testing](https://github.com/bfhp/stream-engine/wiki/Developer-Testing)

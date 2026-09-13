/* ==========================================================================
   CSRF token

   The one reader of the CSRF cookie on the client side.

   Everything that mutates has to send this back as X-CSRF-Token (see
   Core\Security::verifyCsrf()). Most of the site gets that for free from
   CMS.api(), which sets the header itself - this module exists for the calls
   that can't go through it:

     - uploads (shared/uploads.ts), which need a FormData body and so can't use
       api()'s JSON-only wrapper;
     - the admin SPA (assets-src/admin/*), which doesn't load site/app.ts at all
       and therefore has no CMS surface to reach for.

   Both of those groups previously hand-rolled `fetch` calls with no token, and
   the endpoints behind them had no check either - see docs/TODO.md.
   ========================================================================== */

// Kept in sync with Core\Security::CSRF_COOKIE_NAME on the PHP side.
const CSRF_COOKIE_NAME = 'csrfToken';

/**
 * Returns '' when there is no cookie, rather than throwing: a request with no
 * token earns a clean ValidationException from the server, which is a better
 * failure than a TypeError in the middle of a file picker.
 *
 * The cookie is deliberately not httponly so this can read it (see
 * Security::getCsrfToken()), and StreamEngine::handleRequest() issues it on
 * every HTML render, so by the time any of this runs it exists.
 */
export function csrfToken(): string {
    const prefix = `${CSRF_COOKIE_NAME}=`;

    return document.cookie
        .split('; ')
        .find(row => row.startsWith(prefix))
        ?.slice(prefix.length) ?? '';
}

/**
 * Just the token header, to merge into a fetch's own headers.
 *
 * Deliberately nothing else - in particular no Content-Type, because callers
 * differ: a FormData upload needs the browser to write it (boundary included),
 * while a JSON call sets application/json itself.
 */
export function csrfHeaders(): Record<string, string> {
    return { 'X-CSRF-Token': csrfToken() };
}

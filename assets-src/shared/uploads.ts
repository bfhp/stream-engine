/* ==========================================================================
   File uploads

   The one client-side door to POST /api/v1/uploads.

   Uploads can't go through CMS.api(): that wrapper sets
   `Content-Type: application/json` and JSON-stringifies its payload, whereas
   an upload needs a FormData body and must let the browser set the multipart
   Content-Type (including the boundary) itself. So every caller wrote its own
   `fetch` instead - four byte-identical uploadFile() helpers plus two inline
   copies - and, going around CMS.api(), none of them sent X-CSRF-Token. The
   server didn't ask for one either, which is how /api/v1/uploads ended up the
   only mutating endpoint in APIController with no CSRF check at all.

   This module lives in shared/ rather than on the CMS surface because the
   admin bundle (assets-src/admin/*) needs it too and doesn't load site/app.ts:
   app.ts sets window.CMS and drags in Bootstrap, which the admin SPA has no
   use for. A plain ES module both entry points can import is the only thing
   that covers all of them.
   ========================================================================== */

import { csrfHeaders, csrfToken } from './csrf';

/**
 * Re-exported so upload callers don't need a second import, and so app.ts's own
 * getCsrfToken() can keep pointing here. The cookie reading itself lives in
 * shared/csrf.ts, which the admin SPA's JSON calls use as well - a module named
 * "uploads" is the wrong place to look for it.
 */
export { csrfToken };

/**
 * Headers for a hand-rolled upload request. Deliberately does NOT set
 * Content-Type: for a FormData body the browser has to write it itself so it
 * can include the multipart boundary, and setting it by hand produces a body
 * the server cannot parse.
 */
export function uploadHeaders(): Record<string, string> {
    return csrfHeaders();
}

export type UploadResponse = {
    id: number;
    url: string;
    mime: string;
    size: number;
    originalName: string;
};

/**
 * Rejects with the parsed error payload rather than an Error, matching
 * CMS.api()'s contract - callers pass the rejection to getApiErrorMessage()
 * and expect the server's shape.
 */
export async function uploadFile(uploadsUrl: string, file: File): Promise<UploadResponse> {
    const body = new FormData();
    body.append('file', file);

    const response = await fetch(uploadsUrl, {
        method: 'POST',
        body,
        credentials: 'include',
        headers: uploadHeaders(),
    });

    const result = await response.json();

    if (!response.ok) {
        throw result;
    }

    return result;
}

import { transChoice } from "./i18n";

/* ==========================================================================
   File size labels

   One implementation of the localized three-tier byte label shown next to
   every uploaded file. It had three byte-identical copies - `forums.ts`'s
   `bytesToLabel()`, `blog-post-form.js`'s, and `messages.ts`'s
   `formatFileSize()` - each carrying a comment about being kept in sync with
   the others by hand.

   A fourth lives server-side, `ForumsController::formatFileSize()`, and has
   to: forum attachments get their label from the database on a page render
   and from here while they are still uploading, so the two must agree or a
   file appears to change size the moment the page reloads. That one stays a
   manual mirror - it is PHP - but there is now only one thing for it to
   mirror.

   Not the same as the admin's `formatSize()` (B/KB/MB, a decimal from KB
   up): that one is an English admin label for a multi-megabyte catalogue,
   where the question is "did it get bigger".
   ========================================================================== */

export function bytesToLabel(bytes: number): string {
    if (bytes >= 1024 * 1024) {
        const megabytes = bytes / (1024 * 1024);
        return `${megabytes.toFixed(1)} ${transChoice("js.common.megabyte", megabytes)}`;
    }
    if (bytes >= 1024) {
        const kilobytes = Math.round(bytes / 1024);
        return `${kilobytes} ${transChoice("js.common.kilobyte", kilobytes)}`;
    }

    return `${bytes} ${transChoice("js.common.byte", bytes)}`;
}

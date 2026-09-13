/* ==========================================================================
   Admin display formatting

   Both of these render a value that may legitimately be absent - a sync that
   has never run, a file that was never downloaded - so both answer "-"
   rather than "0" or "1 Jan 1970", which would read as data.

   Kept separate from page components for the same reason as feed-label.ts:
   nothing here is React, and importing the page to test them means importing
   Mantine.
   ========================================================================== */

/**
 * A Unix timestamp (seconds) as a local date and time.
 *
 * `undefined` as the locale on purpose: the admin is one person looking at
 * their own machine, so the browser's locale is the right answer and pinning
 * one here would only be wrong somewhere.
 */
export function formatTimestamp(timestamp: number | null): string {
    if (!timestamp) {
        return "-";
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: "medium",
        timeStyle: "short"
    }).format(new Date(timestamp * 1000));
}

/**
 * Byte count as B/KB/MB.
 *
 * Distinct from the forum's own localized three-tier formatter (one decimal
 * only at the top tier): this one is an admin-facing English label and shows
 * a decimal from KB up, because the interesting question about a downloaded
 * catalogue is "did it get bigger", not "roughly how big".
 */
export function formatSize(bytes: number | null): string {
    if (bytes === null) {
        return "-";
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/* ==========================================================================
   Admin display formatting

   Timestamp and size values may legitimately be absent - a sync that has
   never run, a file that was never downloaded - so they answer "-" rather
   than "0" or "1 Jan 1970", which would read as data.

   Kept separate from page components for the same reason as feed-label.ts:
   nothing here is React, and importing the page to test them means importing
   Mantine.
   ========================================================================== */

/**
 * A Unix timestamp (seconds) formatted with the CMS locale from the document.
 */
import { getLocale, trans } from "../../shared/i18n";

export function formatTimestamp(timestamp: number | null, locale = getLocale()): string {
    if (!timestamp) {
        return "-";
    }

    return new Intl.DateTimeFormat(locale, {
        dateStyle: "medium",
        timeStyle: "short"
    }).format(new Date(timestamp * 1000));
}

/**
 * Byte count as B/KB/MB.
 *
 * Distinct from the forum's three-tier formatter (one decimal only at the top
 * tier): this one shows a localized decimal from KB up, because the useful
 * question about a downloaded catalogue is "did it get bigger".
 */
export function formatNumber(value: number, options: Intl.NumberFormatOptions = {}, locale = getLocale()): string {
    return new Intl.NumberFormat(locale, options).format(value);
}

export function formatSize(bytes: number | null, locale = getLocale()): string {
    if (bytes === null) {
        return "-";
    }

    if (bytes < 1024) {
        return `${formatNumber(bytes, {}, locale)} ${trans("js.admin.unit_b")}`;
    }

    if (bytes < 1024 * 1024) {
        return `${formatNumber(bytes / 1024, { minimumFractionDigits: 1, maximumFractionDigits: 1 }, locale)} ${trans("js.admin.unit_kb")}`;
    }

    return `${formatNumber(bytes / 1024 / 1024, { minimumFractionDigits: 1, maximumFractionDigits: 1 }, locale)} ${trans("js.admin.unit_mb")}`;
}

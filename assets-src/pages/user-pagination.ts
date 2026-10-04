/* ==========================================================================
   Users list pagination

   Hoisted out of `initUsersList()`'s closure and given their inputs
   explicitly, so the thing they get wrong can be asserted: a paginator that
   builds its links from scratch drops whatever the visitor was filtering by,
   and the next page silently shows a different set. The markup is the
   theme's (`components/users/users-pagination.twig`).
   ========================================================================== */

import { trans } from "../shared/i18n";
import { ui, type SlotData } from "../shared/ui";

export type PaginationMeta = {
    currentPage?: number;
    totalPages?: number;
};

/**
 * The href for a page of the current listing.
 *
 * Built by *editing* the current query string rather than composing a new
 * one, which is what keeps `q`, `sort` and `direction` alive across a page
 * turn. Page 1 deletes the parameter instead of writing `page=1`, so the
 * first page has one canonical URL rather than two.
 *
 * Returns `pathname + search` only - no origin, since it goes straight into
 * an `href` on the same site.
 */
export function pageLink(pageUrl: string, search: string, page: number): string {
    const url = new URL(pageUrl, window.location.origin);
    const params = new URLSearchParams(search);

    if (page > 1) {
        params.set('page', String(page));
    } else {
        params.delete('page');
    }

    url.search = params.toString();

    return url.pathname + url.search;
}

/**
 * The values for the users-pagination template's fill points, or null when
 * there is only one page (no nav at all).
 *
 * At either end the control is the template's disabled `<span>` rather than
 * a link - a link to page 0 would 404, and a disabled anchor is still
 * clickable. Hrefs go in through setAttribute, so the `&` joining query
 * parameters needs no escaping.
 */
export function paginationData(meta: PaginationMeta | undefined, pageUrl: string, search: string): SlotData | null {
    const paginationMeta = meta || {};
    const currentPage = Number(paginationMeta.currentPage || 1);
    const totalPages = Number(paginationMeta.totalPages || 1);

    if (totalPages <= 1) {
        return null;
    }

    const hasPrev = currentPage > 1;
    const hasNext = currentPage < totalPages;

    return {
        prevUrl: hasPrev ? pageLink(pageUrl, search, currentPage - 1) : "",
        noPrev: hasPrev ? "" : "1",
        nextUrl: hasNext ? pageLink(pageUrl, search, currentPage + 1) : "",
        noNext: hasNext ? "" : "1",
        label: trans("js.pagination.page_of", { current: currentPage, total: totalPages }),
    };
}

/** The nav under the list: a filled copy of the theme's template, or null. */
export function renderPagination(meta: PaginationMeta | undefined, pageUrl: string, search: string): HTMLElement | null {
    const data = paginationData(meta, pageUrl, search);
    const nav = data ? ui.clone("users-pagination") : null;

    return nav && data ? ui.fill(nav, data) : null;
}

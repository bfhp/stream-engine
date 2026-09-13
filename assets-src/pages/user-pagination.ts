/* ==========================================================================
   Users list pagination

   Two functions, hoisted out of `initUsersList()`'s closure and given their
   inputs explicitly, so the thing they get wrong can be asserted: a
   paginator that builds its links from scratch drops whatever the visitor
   was filtering by, and the next page silently shows a different set.
   ========================================================================== */

import { escapeHtml } from "../shared/escape";
import { trans } from "../shared/i18n";

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
 * The prev/next nav under the list, or nothing at all when there is only one
 * page.
 *
 * At either end the control is a disabled `<span>` rather than an `<a>` -
 * a link to page 0 would 404, and a disabled anchor is still clickable.
 */
export function pagination(meta: PaginationMeta | undefined, pageUrl: string, search: string): string {
    const paginationData = meta || {};
    const currentPage = Number(paginationData.currentPage || 1);
    const totalPages = Number(paginationData.totalPages || 1);

    if (totalPages <= 1) {
        return '';
    }

    // The href is escaped rather than interpolated raw. A query string joins
    // its parameters with `&`, and HTML5 still resolves a *named* entity
    // without its semicolon inside an attribute - so a filter called `sect`
    // would make `&sect=x` parse as `§=x` and the link would quietly lose the
    // filter. None of today's parameters (q, sort, direction, page) hit that
    // list, which is exactly why it would be found late.
    const href = (page: number): string => escapeHtml(pageLink(pageUrl, search, page));

    const prev = currentPage > 1
        ? `<a class="btn btn-outline-secondary" href="${href(currentPage - 1)}">${trans("js.pagination.previous")}</a>`
        : `<span class="btn btn-outline-secondary disabled" aria-disabled="true">${trans("js.pagination.previous")}</span>`;

    const next = currentPage < totalPages
        ? `<a class="btn btn-outline-secondary" href="${href(currentPage + 1)}">${trans("js.pagination.next")}</a>`
        : `<span class="btn btn-outline-secondary disabled" aria-disabled="true">${trans("js.pagination.next")}</span>`;

    return `
            <nav class="d-flex align-items-center justify-content-between mt-4" aria-label="${trans("js.pagination.users_aria")}">
                ${prev}
                <span class="text-body-secondary small">${trans("js.pagination.page_of", { current: currentPage, total: totalPages })}</span>
                ${next}
            </nav>
        `;
}

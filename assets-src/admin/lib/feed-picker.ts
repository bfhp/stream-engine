/* ==========================================================================
   Feed picker helpers for the page editor

   Pages bind to a feed by ID. These helpers turn what the admin types into
   /api/v1/feeds queries that stay on indexed paths, and describe which page
   already owns a feed (a feed can be mounted on one page only).

   Pure functions so tests do not need to load Mantine.
   ========================================================================== */

export type PickerFeed = {
    id: number;
    title?: string | null;
    slug?: string | null;
    type?: string | null;
    parentId?: number | null;
};

export type PickerPage = {
    id: number;
    parentId?: number | null;
    pattern?: string | null;
    pageName?: string | null;
    feedId?: number | null;
};

export type BoundPage = {
    id: number;
    path: string;
    pageName: string;
};

/** Minimum length of a free-text query when no feed type narrows the search (FULLTEXT path). */
export const FULLTEXT_MIN_LENGTH = 3;
export const FEED_PICKER_LIMIT = 20;

/** "123" or "#123" selects a feed directly by its primary key. */
export function feedIdFromQuery(query: string): number | null {
    const match = /^#?(\d+)$/.exec(query.trim());
    if (!match) {
        return null;
    }

    const id = Number(match[1]);

    return Number.isSafeInteger(id) && id > 0 ? id : null;
}

/**
 * Query strings for /api/v1/feeds. Returns an empty list when the input
 * cannot be searched efficiently yet (free text shorter than the FULLTEXT
 * minimum without a type filter).
 *
 * - numeric input → lookup by ID (PRIMARY);
 * - allowed types known → one `type` (+ `title`) query per type, newest first;
 * - no type restriction → FULLTEXT `search`.
 */
export function buildFeedSearchQueries(query: string, feedTypes: readonly string[] | undefined): string[] {
    const trimmed = query.trim();
    const id = feedIdFromQuery(trimmed);
    const limit = String(FEED_PICKER_LIMIT);

    if (id !== null) {
        return [new URLSearchParams({ id: String(id), limit }).toString()];
    }

    if (feedTypes && feedTypes.length > 0) {
        return feedTypes.map(type => {
            const params = new URLSearchParams({ type, limit });
            if (trimmed !== "") {
                params.set("title", trimmed);
            }

            return params.toString();
        });
    }

    if (trimmed.length < FULLTEXT_MIN_LENGTH) {
        return [];
    }

    return [new URLSearchParams({ search: trimmed, limit }).toString()];
}

/** Merges results of several queries: unique by ID, newest (highest ID) first. */
export function mergeFeedResults<T extends PickerFeed>(groups: readonly T[][]): T[] {
    const byId = new Map<number, T>();

    groups.flat().forEach(feed => {
        if (!byId.has(feed.id)) {
            byId.set(feed.id, feed);
        }
    });

    return [...byId.values()].sort((a, b) => b.id - a.id).slice(0, FEED_PICKER_LIMIT);
}

export function isFeedTypeAllowed(feed: PickerFeed, feedTypes: readonly string[] | undefined): boolean {
    return !feedTypes || feedTypes.length === 0 || feedTypes.includes(feed.type || "");
}

/** Public path of a page built from the patterns of its ancestors. */
export function pagePath(page: PickerPage, pages: readonly PickerPage[]): string {
    const byId = new Map(pages.map(item => [item.id, item]));
    const segments: string[] = [];
    const visited = new Set<number>();
    let current: PickerPage | undefined = page;

    while (current && !visited.has(current.id)) {
        visited.add(current.id);
        if (current.pattern) {
            segments.unshift(current.pattern.replace(/^\/+|\/+$/g, ""));
        }
        current = current.parentId != null ? byId.get(current.parentId) : undefined;
    }

    return "/" + segments.filter(Boolean).join("/");
}

/** Feed ID → the page it is mounted on, ignoring the page being edited. */
export function boundPagesByFeed(
    pages: readonly PickerPage[],
    currentPageId?: number
): Map<number, BoundPage> {
    const bound = new Map<number, BoundPage>();

    pages.forEach(page => {
        if (page.feedId == null || page.id === currentPageId || bound.has(page.feedId)) {
            return;
        }

        bound.set(page.feedId, {
            id: page.id,
            path: pagePath(page, pages),
            pageName: page.pageName || ""
        });
    });

    return bound;
}

/** Pages mounting the given feed (normally zero or one). */
export function pagesBoundToFeed(feedId: number, pages: readonly PickerPage[]): BoundPage[] {
    return pages
        .filter(page => page.feedId === feedId)
        .map(page => ({ id: page.id, path: pagePath(page, pages), pageName: page.pageName || "" }));
}

/** "Title" or "#id" when a feed has no title. */
export function feedDisplayTitle(feed: PickerFeed): string {
    const title = (feed.title || "").replace(/\s+/g, " ").trim();

    return title !== "" ? title : `#${feed.id}`;
}

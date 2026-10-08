/* ==========================================================================
   Load-more behavior for offset and keyset-cursor pagers

   One implementation shared by growing cursor-paginated post feeds and the
   smaller offset-paginated friends/member lists. Callers differ only in the
   endpoint, selectors, renderer and pagination token name.

   The rules that were copied four times, and are worth keeping exactly:

   - the button's `data-offset` or `data-cursor` is the whole paging token;
     absent means "not a pager" and the click is ignored;
   - a next token of `null` **or** `undefined` removes the entire wrapper,
     not just the button - otherwise an empty toolbar is left behind;
   - a *failed* request leaves the paging token untouched, so a retry asks
     for the same page rather than skipping it. Invisible in manual testing,
     because the retry usually succeeds and nobody counts the rows;
   - the button is re-enabled in `finally` only `if (button.isConnected)`,
     since the success path may have just removed it from the document.
   ========================================================================== */

export type OffsetPayload<T> = {
    items?: T[];
    meta?: {
        total?: number;
        nextOffset?: number | null;
        nextCursor?: string | null;
    };
};

export type OffsetLoadMoreOptions<T> = {
    /** The element the click listener is delegated on. */
    containerId: string;
    /** `dataset` key used to mark the container wired, so a second init is a no-op. */
    readyFlag: string;
    /** `dataset` key on the container holding the endpoint. */
    apiUrlKey: string;
    /** Which clicks count. */
    buttonSelector: string;
    /** Where rendered rows are appended. */
    listId: string;
    /** Removed wholesale when there is no next page. */
    wrapperSelector: string;
    /**
     * One row: an element (normally a filled copy of a theme <template>, so
     * the markup stays the theme's) or, for simple callers, an HTML string.
     * null skips the row.
     */
    render: (item: T) => HTMLElement | string | null;
    errorMessage: string;
    /** Growing feed lists use stable keyset cursors; legacy small lists use offsets. */
    pagination?: 'offset' | 'cursor';
};

export function initOffsetLoadMore<T>(options: OffsetLoadMoreOptions<T>): void {
    const container = document.getElementById(options.containerId);

    if (!container || container.dataset[options.readyFlag] === '1') return;

    const apiUrl = container.dataset[options.apiUrlKey];
    if (!apiUrl) return;

    async function loadMore(button: HTMLButtonElement): Promise<void> {
        const pagination = options.pagination ?? 'offset';
        const value = pagination === 'cursor' ? button.dataset.cursor : button.dataset.offset;
        if (value === undefined) return;

        // Read at call time rather than at import: these bundles are loaded
        // as modules alongside site.js, and a test can stub the surface
        // without the module having captured an earlier one.
        const cms = window.CMS;

        button.disabled = true;

        try {
            const url = new URL(apiUrl!, window.location.origin);
            url.searchParams.set(pagination, value);

            const res = await cms.api<OffsetPayload<T>>(`${url.pathname}${url.search}`);
            const list = document.getElementById(options.listId);

            if (list) {
                (res.items ?? []).forEach((item) => {
                    const row = options.render(item);
                    if (typeof row === 'string') {
                        list.insertAdjacentHTML('beforeend', row);
                    } else if (row) {
                        list.append(row);
                    }
                });
            }

            const nextValue = pagination === 'cursor' ? res.meta?.nextCursor : res.meta?.nextOffset;

            if (nextValue === null || nextValue === undefined) {
                button.closest(options.wrapperSelector)?.remove();
            } else if (pagination === 'cursor') {
                button.dataset.cursor = String(nextValue);
            } else {
                button.dataset.offset = String(nextValue);
            }
        } catch (error) {
            cms.toast({ message: options.errorMessage, type: 'danger' });
        } finally {
            if (button.isConnected) {
                button.disabled = false;
            }
        }
    }

    container.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;

        const button = target.closest<HTMLButtonElement>(options.buttonSelector);

        if (button) {
            void loadMore(button);
        }
    });

    container.dataset[options.readyFlag] = '1';
}

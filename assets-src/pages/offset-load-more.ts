/* ==========================================================================
   Offset-paginated load-more behavior

   One implementation of the four near-identical closures `users.ts` carried -
   the blog feed, the community feed, the friends list and the community
   members list. They differed only in which element, which endpoint, which
   selector and which renderer; everything about the pagination itself was
   copied.

   The rules that were copied four times, and are worth keeping exactly:

   - the button's `data-offset` is the whole cursor; absent means "not a
     pager" and the click is ignored;
   - a `nextOffset` of `null` **or** `undefined` removes the entire wrapper,
     not just the button - otherwise an empty toolbar is left behind;
   - a *failed* request leaves `data-offset` untouched, so a retry asks for
     the same page rather than skipping it. Invisible in manual testing,
     because the retry usually succeeds and nobody counts the rows;
   - the button is re-enabled in `finally` only `if (button.isConnected)`,
     since the success path may have just removed it from the document.
   ========================================================================== */

export type OffsetPayload<T> = {
    items?: T[];
    meta?: {
        total?: number;
        nextOffset?: number | null;
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
    render: (item: T) => string;
    errorMessage: string;
};

export function initOffsetLoadMore<T>(options: OffsetLoadMoreOptions<T>): void {
    const container = document.getElementById(options.containerId);

    if (!container || container.dataset[options.readyFlag] === '1') return;

    const apiUrl = container.dataset[options.apiUrlKey];
    if (!apiUrl) return;

    async function loadMore(button: HTMLButtonElement): Promise<void> {
        const offset = button.dataset.offset;
        if (offset === undefined) return;

        // Read at call time rather than at import: these bundles are loaded
        // as modules alongside site.js, and a test can stub the surface
        // without the module having captured an earlier one.
        const cms = window.CMS;

        button.disabled = true;

        try {
            const url = new URL(apiUrl!, window.location.origin);
            url.searchParams.set('offset', offset);

            const res = await cms.api<OffsetPayload<T>>(`${url.pathname}${url.search}`);
            const list = document.getElementById(options.listId);

            if (list) {
                (res.items ?? []).forEach((item) => {
                    list.insertAdjacentHTML('beforeend', options.render(item));
                });
            }

            const nextOffset = res.meta?.nextOffset;

            if (nextOffset === null || nextOffset === undefined) {
                button.closest(options.wrapperSelector)?.remove();
            } else {
                button.dataset.offset = String(nextOffset);
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

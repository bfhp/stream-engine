import { FEED_TYPE_LABELS } from "../shared/feed-types";
import { trans } from "../shared/i18n";
import { ui } from "../shared/ui";

const cms = window.CMS;

type FeedSearchItem = {
    id?: number | null;
    type?: string | null;
    title?: string | null;
    description?: string | null;
    content?: string | null;
    canonicalUrl?: string | null;
    authorDisplayName?: string | null;
    createdAtLabel?: string | null;
    createdAtTitle?: string | null;
    containerType?: string | null;
};

type FeedSearchResponse = {
    data?: FeedSearchItem[];
    meta?: { has_more?: boolean; next_cursor?: string | null };
};

/** Values for the data-slot* points of components/search/result.twig. */
type SearchPresentation = {
    kind: string;
    author: string;
    date: string;
    dateTitle: string;
    title: string;
    url: string;
    snippet: string;
};

const PREVIEW_LENGTH = 180;
const CONTENT_PARSE_LIMIT = 5000;

(function () {

    const resultsContainer = document.getElementById('searchResults');
    if (!resultsContainer) return;

    let currentController: AbortController | null = null;
    let nextCursor: string | null = null;
    let loading = false;
    // The loading line and the "load more" button are the theme's markup
    // (components/search/results.twig); only plain fallbacks are built here,
    // for a theme that dropped them.
    const loadingStatus = document.querySelector<HTMLElement>('[data-search-status]')
        ?? fallbackStatus();
    const loadingMessage = loadingStatus.querySelector<HTMLElement>('[data-search-status-text]') ?? loadingStatus;
    const moreButton = document.querySelector<HTMLButtonElement>('[data-search-more]')
        ?? fallbackMoreButton();
    moreButton.textContent = trans('js.common.load_more');
    moreButton.addEventListener('click', () => {
        void performSearch(resultsContainer.dataset.search || '', nextCursor);
    });

    function fallbackStatus(): HTMLElement {
        const status = document.createElement('div');
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.hidden = true;
        resultsContainer!.after(status);
        return status;
    }

    function fallbackMoreButton(): HTMLButtonElement {
        const button = document.createElement('button');
        button.type = 'button';
        button.hidden = true;
        button.setAttribute('aria-controls', 'searchResults');
        loadingStatus.after(button);
        return button;
    }

    /** A one-line notice in place of the results (too short, nothing found, failed). */
    function showMessage(text: string): void {
        const message = document.createElement('div');
        message.className = 'search-message';
        message.textContent = text;
        resultsContainer!.replaceChildren(message);
    }

    async function performSearch(raw: string, cursor: string | null = null): Promise<void> {
        if (loading) return;

        const query = (raw || '').trim();

        if (query.length < 3) {
            showMessage(trans('js.search.query_too_short'));
            return;
        }

        if (currentController) {
            currentController.abort();
        }

        currentController = new AbortController();

        loading = true;
        moreButton.disabled = true;
        moreButton.textContent = trans('js.common.loading_dots');
        resultsContainer.setAttribute('aria-busy', 'true');
        if (!cursor) {
            resultsContainer.innerHTML = '';
            moreButton.hidden = true;
        }
        loadingMessage.textContent = trans(cursor ? 'js.search.loading_more' : 'js.search.searching');
        loadingStatus.hidden = false;
        const slowSearchTimer = window.setTimeout(() => {
            loadingMessage.textContent = trans('js.search.slow');
        }, 3000);
        let failed = false;

        try {

            const response = await fetch(
                `/api/v1/feeds?search=${encodeURIComponent(query)}${cursor ? `&cursor=${encodeURIComponent(cursor)}` : ""}`,
                {
                    credentials: 'include',
                    signal: currentController.signal
                }
            );

            if (!response.ok) {
                failed = true;

                if (response.status === 429) {
                    cms.toast({
                        message: trans("js.search.too_many_requests"),
                        type: "warning"
                    });
                } else {
                    cms.toast({
                        message: trans("js.search.failed"),
                        type: "danger"
                    });
                }

                return;
            }

            const data = await response.json() as FeedSearchResponse;
            renderResults(data, cursor !== null);
            nextCursor = data.meta?.has_more && data.meta.next_cursor
                ? data.meta.next_cursor : null;
            moreButton.hidden = nextCursor === null;

        } catch (e) {

            if ((e as Error).name !== 'AbortError') {
                failed = true;
                console.error('Search error:', e);

                cms.toast({
                    message: trans("js.search.network_failed"),
                    type: "danger"
                });
            }
        } finally {
            window.clearTimeout(slowSearchTimer);
            loadingStatus.hidden = true;
            loadingMessage.textContent = '';
            loading = false;
            resultsContainer.setAttribute('aria-busy', 'false');
            moreButton.disabled = false;
            moreButton.textContent = trans(failed ? 'js.search.retry' : 'js.common.load_more');
            if (failed) {
                moreButton.hidden = false;
                if (!cursor) {
                    showMessage(trans('js.search.results_failed'));
                }
            }
        }
    }

    function renderResults(data: FeedSearchResponse, append: boolean): void {

        const items = data?.data;

        if (!items?.length) {
            if (append) return;
            showMessage(trans('js.search.empty'));
            return;
        }

        // Each row is a copy of the theme's components/search/result.twig;
        // values go in as text and attributes, never as markup.
        const rows = items
            .map(item => {
                const row = ui.clone('search-result');
                return row && ui.fill(row, presentSearchItem(item));
            })
            .filter((row): row is HTMLElement => row !== null);

        if (append) {
            resultsContainer.append(...rows);
        } else {
            resultsContainer.replaceChildren(...rows);
        }
    }

    function presentSearchItem(item: FeedSearchItem): SearchPresentation {
        const title = makeLabel(item);

        return {
            kind: typeLabel(item),
            author: normalizeText(item.authorDisplayName || ""),
            date: normalizeText(item.createdAtLabel || ""),
            dateTitle: item.createdAtTitle || "",
            title,
            url: item.canonicalUrl || '#',
            snippet: makeSnippet(item, title),
        };
    }

    function typeLabel(item: FeedSearchItem): string {
        if (item.type === "blog-post" && item.containerType === "community") {
            return trans("js.search.community_post");
        }

        return FEED_TYPE_LABELS[item.type || ""] || trans("js.common.item");
    }

    function makeLabel(item: FeedSearchItem): string {
        const title = normalizeText(item.title || "");

        if (title !== "") {
            return title;
        }

        const content = textPreview(item.content || "", 140);

        if (content !== "") {
            return content;
        }

        return item.id ? trans("js.search.item_with_id", { id: item.id }) : trans("js.common.item");
    }

    function makeSnippet(item: FeedSearchItem, label: string): string {
        const description = textPreview(item.description || "", PREVIEW_LENGTH);

        if (description !== "") {
            return description;
        }

        if (normalizeText(item.title || "") === "") {
            return "";
        }

        const content = textPreview(item.content || "", PREVIEW_LENGTH);

        return content !== label ? content : "";
    }

    function textPreview(value: string, length: number): string {
        const text = stripHtml(value);

        return text.length > length
            ? `${text.slice(0, length).trimEnd()}...`
            : text;
    }

    function normalizeText(value: string): string {
        return value.replace(/\s+/g, " ").trim();
    }

    function stripHtml(value: string): string {
        const element = document.createElement("div");
        element.innerHTML = value.slice(0, CONTENT_PARSE_LIMIT);

        return normalizeText(element.textContent || "");
    }


    /* =====================================
       Autoload
    ===================================== */

    const query = resultsContainer.dataset.search;

    if (query) {
        void performSearch(query);
    }

})();

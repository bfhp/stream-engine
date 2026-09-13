import { escapeHtml } from "../shared/escape";
import { FEED_TYPE_LABELS } from "../shared/feed-types";
import { trans } from "../shared/i18n";

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

type SearchPresentation = {
    label: string;
    kindLabel: string;
    url: string;
    snippet: string;
    meta: Array<{ label: string; title?: string }>;
};

const PREVIEW_LENGTH = 180;
const CONTENT_PARSE_LIMIT = 5000;

(function () {

    const resultsContainer = document.getElementById('searchResults');
    if (!resultsContainer) return;

    let currentController: AbortController | null = null;
    let nextCursor: string | null = null;
    let loading = false;
    const moreButton = document.createElement('button');
    moreButton.type = 'button';
    moreButton.className = 'btn btn-outline-primary my-3';
    moreButton.textContent = trans('js.common.load_more');
    moreButton.hidden = true;
    moreButton.setAttribute('aria-controls', 'searchResults');
    const loadingStatus = document.createElement('div');
    loadingStatus.className = 'search-loading py-4 text-body-secondary';
    loadingStatus.setAttribute('role', 'status');
    loadingStatus.setAttribute('aria-live', 'polite');
    loadingStatus.hidden = true;
    loadingStatus.innerHTML = `
        <div class="d-flex align-items-center gap-2">
            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
            <span class="search-loading-message"></span>
        </div>`;
    const loadingMessage = loadingStatus.querySelector('.search-loading-message')!;
    resultsContainer.after(loadingStatus, moreButton);
    moreButton.addEventListener('click', () => {
        void performSearch(resultsContainer.dataset.search || '', nextCursor);
    });

    async function performSearch(raw: string, cursor: string | null = null): Promise<void> {
        if (loading) return;

        const query = (raw || '').trim();

        if (query.length < 3) {
            resultsContainer.innerHTML =
                `<div class="text-muted">${trans('js.search.query_too_short')}</div>`;
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
                    resultsContainer.innerHTML = `<div class="text-muted">${trans('js.search.results_failed')}</div>`;
                }
            }
        }
    }

    function renderResults(data: FeedSearchResponse, append: boolean): void {

        const items = data?.data;

        if (!items?.length) {
            if (append) return;
            resultsContainer.innerHTML =
                `<div class="text-muted">${trans('js.search.empty')}</div>`;
            return;
        }

        const html = items.map(item => {
            const result = presentSearchItem(item);

            return `
            <div class="search-item search-result py-3 border-bottom">
                <div class="search-result-meta small text-muted mb-1">
                    <span class="search-result-kind">${escapeHtml(result.kindLabel)}</span>
                    ${result.meta.map(meta => `
                        <span${meta.title ? ` title="${escapeHtml(meta.title)}"` : ''}>${escapeHtml(meta.label)}</span>
                    `).join('')}
                </div>
                <a href="${escapeHtml(result.url)}" class="search-result-title text-decoration-none fw-semibold">
                    ${escapeHtml(result.label)}
                </a>
                ${result.snippet
                    ? `<div class="search-result-snippet text-body-secondary mt-1">${escapeHtml(result.snippet)}</div>`
                    : ''}
            </div>
        `;
        }).join('');
        if (append) {
            resultsContainer.insertAdjacentHTML('beforeend', html);
        } else {
            resultsContainer.innerHTML = html;
        }
    }

    function presentSearchItem(item: FeedSearchItem): SearchPresentation {
        const label = makeLabel(item);
        const author = normalizeText(item.authorDisplayName || "");
        const createdAt = normalizeText(item.createdAtLabel || "");
        const meta: SearchPresentation["meta"] = [];

        if (author !== "") {
            meta.push({ label: author });
        }

        if (createdAt !== "") {
            meta.push({ label: createdAt, title: item.createdAtTitle || undefined });
        }

        return {
            label,
            kindLabel: typeLabel(item),
            url: item.canonicalUrl || '#',
            snippet: makeSnippet(item, label),
            meta,
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

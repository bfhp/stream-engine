import { Toast, Modal, Tooltip } from "bootstrap";
import {
    siVk,
    siTelegram,
    siWhatsapp,
    siViber,
    siFacebook,
    siX,
    siOdnoklassniki,
    siThreads
} from "simple-icons";
import { hashRoute } from "./hash-route";
import { encodeId, decodeId } from "./opaque-id";
import { csrfToken } from "../shared/uploads";
import { getApiErrorMessage } from "../shared/api-errors";
import { escapeHtml } from "../shared/escape";
import { trans, transChoice, transChoiceWithCount } from "../shared/i18n";

const CMS = (() => {

    function isAuthenticated(): boolean {
        return document.body.dataset.auth === "1";
    }

    /* ===============================
       Security / CSRF
    =============================== */
    /**
     * Delegates to shared/uploads.ts, which is the single reader of the
     * cookie - the upload call sites need the same token and can't come
     * through api() below (see that module's own note on why), and the admin
     * bundle needs it without loading this file at all.
     *
     * Its version also fixes a latent bug in the one this replaced: that
     * one did `.split('=')[1]`, which truncates at the first '=' in the
     * value. Harmless for a hex token, wrong the moment the format changes.
     */
    function getCsrfToken(): string {
        return csrfToken();
    }

    /* ===============================
       API wrapper
    =============================== */
    /**
     * @param {string} url
     * @param {{
     *   method?: string,
     *   data?: Object|null
     * }} options
     * @returns {Promise<any>}
     */
    async function api<T = any>(
        url: string,
        { method = 'GET', data = null }: {
            method?: string;
            data?: any;
        } = {}
    ): Promise<T> {

        const options: RequestInit = {
            method,
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            }
        };

        if (data && method !== 'GET') {
            options.body = JSON.stringify(data);
        }

        const response = await fetch(url, options);

        let result = null;

        const contentType = response.headers.get('content-type');

        // 204/205 responses are never allowed to have a body (per the Fetch/HTTP
        // spec), even if the server also sent a Content-Type header - e.g.
        // APIController::callApi() sets "Content-Type: application/json" up front
        // for every action, including handleFavoriteRequest()'s DELETE branch,
        // which replies with an empty 204. Attempting response.json() there
        // throws on the empty body, so skip parsing for those statuses.
        const hasNoBody = response.status === 204 || response.status === 205;

        if (!hasNoBody && contentType?.includes('application/json')) {
            try {
                result = await response.json();
            } catch (e) {
                throw new Error('Invalid JSON response');
            }
        }

        if (!response.ok) {
            throw result;
        }

        return result;
    }

    /* ===============================
       Toast
    =============================== */
    function toastMessage({ message, type = "success", html = false }) {
        const toastEl = document.getElementById('globalToast');
        const toastBody = document.getElementById('globalToastBody');

        if (!toastEl || !toastBody) return;

        const toast = new Toast(toastEl, { delay: 5000 });

        toastEl.className = "toast align-items-center border-0 text-bg-" + type;

        if (html) {
            toastBody.innerHTML = message;
        } else {
            toastBody.textContent = String(message ?? "");
        }

        toast.show();
    }

    /* ===============================
       Confirm Modal
    =============================== */
    function confirm({ title, message, onConfirm, onDismiss = null }) {
        const modalEl = document.getElementById('globalConfirmModal');
        const okBtn = document.getElementById('globalConfirmOk');
        const titleEl = document.getElementById('globalConfirmTitle');
        const bodyEl = document.getElementById('globalConfirmBody');

        if (!modalEl || !okBtn || !titleEl || !bodyEl) return;

        const modal = new Modal(modalEl);

        let confirmed = false;

        // Assigning .onclick (rather than addEventListener/removeEventListener)
        // guarantees only the latest confirm() call's handler can ever fire.
        // The previous approach only removed its listener when the button was
        // actually clicked - if a modal was dismissed without confirming (e.g.
        // backdrop click, Escape, a Cancel button), its listener stayed
        // attached, and the next confirm() call for a different action would
        // add a second one. Clicking OK then fired both actions.
        okBtn.onclick = () => {
            confirmed = true;
            onConfirm?.();
            modal.hide();
        };

        // Optional "closed without confirming" hook, for callers that disable
        // part of the UI before asking and need it back if the answer is no -
        // see initCommunityManageSettingsForm()'s Save button in users.ts.
        //
        // Bootstrap fires hidden.bs.modal on every close including the OK path,
        // hence the `confirmed` flag; and the listener removes itself, because
        // one left attached would run this call's onDismiss when some *later*
        // confirm() is dismissed - the same failure the okBtn.onclick note
        // above describes. Each call registers its own closure, so a confirm()
        // that supersedes an unanswered one still gets its own dismissal.
        if (onDismiss) {
            const handleHidden = () => {
                modalEl.removeEventListener('hidden.bs.modal', handleHidden);
                if (!confirmed) onDismiss();
            };

            modalEl.addEventListener('hidden.bs.modal', handleHidden);
        }

        titleEl.textContent = title;
        bodyEl.textContent = message;

        modal.show();
    }

    /* ===============================
    Share
    ================================ */
    const shareIcons = {
        vk: siVk,
        telegram: siTelegram,
        whatsapp: siWhatsapp,
        viber: siViber,
        facebook: siFacebook,
        x: siX,
        ok: siOdnoklassniki,
        threads: siThreads
    };

    function getShareIcon(name: string): string {
        const icon = shareIcons[name];
        if (!icon) return "";

        return `<svg viewBox="0 0 24 24" fill="currentColor">
        <path d="${icon.path}"/>
    </svg>`;
    }

    function renderShare(container: HTMLElement, {
        url = window.location.href,
        title = document.title
    } = {}) {

        const encodedUrl = encodeURIComponent(url);
        const encodedTitle = encodeURIComponent(title);

        const links: Record<string, string> = {
            vk: `https://vk.com/share.php?url=${encodedUrl}`,
            telegram: `https://t.me/share/url?url=${encodedUrl}&text=${encodedTitle}`,
            whatsapp: `https://wa.me/?text=${encodedTitle}%20${encodedUrl}`,
            ok: `https://connect.ok.ru/offer?url=${encodedUrl}`,
            viber: `viber://forward?text=${encodedTitle}%20${encodedUrl}`,
            facebook: `https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`,
            x: `https://twitter.com/intent/tweet?url=${encodedUrl}&text=${encodedTitle}`,
            threads: `https://www.threads.net/intent/post?text=${encodedTitle}%20${encodedUrl}`
        };

        container.innerHTML = Object.entries(links)
            .map(([name, link]) => `
        <a href="${link}"
           target="_blank"
           rel="noopener"
           class="share-btn ${name}"
           data-bs-toggle="tooltip"
           data-bs-placement="top"
           data-bs-title="${name.toUpperCase()}">
            ${getShareIcon(name)}
        </a>
    `)
            .join("");
    }

    /**
     * Auto-initialization by data attributes
     */
    function initShare() {
        document.querySelectorAll<HTMLElement>('[data-share]:not([data-share-ready])')
            .forEach(el => {
                renderShare(el, {
                    url: el.dataset.url,
                    title: el.dataset.title
                });

                el.dataset.shareReady = "1";
            });
    }

    function initTooltips(root: ParentNode = document) {
        const tooltipTriggerList = root.querySelectorAll('[data-bs-toggle="tooltip"]');

        tooltipTriggerList.forEach(el => {
            if (Tooltip.getInstance(el)) return;

            new Tooltip(el, {
                placement: 'top',
                trigger: 'hover focus'
            });
        });
    }

    function checkAndSetTimezoneCookie() {
        const key = 'tz';
        const currentTz = Intl.DateTimeFormat().resolvedOptions().timeZone;

        const match = document.cookie.match(new RegExp('(^| )' + key + '=([^;]+)'));
        const savedTz = match ? decodeURIComponent(match[2]) : null;

        if (savedTz !== currentTz) {
            document.cookie = `${key}=${encodeURIComponent(currentTz)}; path=/; max-age=31536000`;
        }
    }

    function buildCommentsApiUrl(parentId: string, cursor: string | undefined | null): string {
        const url = new URL(`/api/v1/comments/${parentId}`, window.location.origin);
        if (cursor) {
            url.searchParams.set("cursor", cursor);
        }
        return `${url.pathname}${url.search}`;
    }

    /**
     * The one HTML-escaper for the whole front end - exported on CMS below, so
     * every page bundle reaches it as `cms.escapeHtml` instead of keeping a
     * copy. It can't be a plain ES import: profile.js, search.js
     * and feedback.js are loaded as classic <script defer>, and a module
     * shared between Rollup entries makes them ESM, which a classic script
     * can't execute. `window.CMS` is the runtime bridge those bundles already
     * use for api/toast/confirm, and this belongs there for the same reason.
     *
     * Escapes five characters, not three. The textContent -> innerHTML
     * round-trip covers &, < and > but not quotes: those are only special
     * inside attribute values, and a text node doesn't know it's about to be
     * pasted into one. Callers interpolate into quoted attributes as much as
     * into text - renderCommentMeta() below puts a comment author's own nick
     * straight into alt="..." - and nicks are stored exactly as typed, so a
     * nick containing `"` would otherwise close the attribute and get the rest
     * parsed as markup.
     *
     * Takes unknown rather than string because most callers hand it optional
     * fields straight off an API payload; null/undefined become "".
     */
    function renderCommentMeta(item: any, avatarSize = 40): string {
        const authorName = escapeHtml(item.authorDisplayName ?? `#${item.ownerId}`);
        const avatarUrl = escapeHtml(item.authorAvatarUrl);
        const safeDate = escapeHtml(item.createdAtLabel ?? "");
        const safeDateTitle = escapeHtml(item.createdAtTitle ?? "");

        return `
            <div class="comment-meta d-flex align-items-center gap-3 mb-3">
                <img
                    src="${avatarUrl}"
                    alt="${authorName}"
                    class="comment-avatar rounded-2"
                    width="${avatarSize}"
                    height="${avatarSize}"
                >
                <div class="comment-meta-text min-w-0">
                    <div class="comment-author fw-semibold lh-sm">${authorName}</div>
                    ${safeDate ? `<time class="comment-date small text-secondary"${safeDateTitle ? ` title="${safeDateTitle}"` : ""}>${safeDate}</time>` : ""}
                </div>
            </div>
        `;
    }

    function createRepliesButton(parentId: string, nextCount: number, nextCursor?: string | null): string {
        if (nextCount <= 0 || !nextCursor) {
            return "";
        }

        return `<button class="load-replies btn btn-sm btn-outline-secondary mt-2"
                    data-parent-id="${escapeHtml(parentId)}"
                    data-next-cursor="${escapeHtml(nextCursor ?? "")}">
                ${trans("js.comments.load_more", { count: nextCount })}
            </button>`;
    }

    function renderReply(reply: any): string {
        return `
            <article class="reply border-top pt-3 mt-3" data-id="${reply.id}">
                ${renderCommentMeta(reply, 32)}
                <div class="reply-content">${reply.content ?? ""}</div>
            </article>
        `;
    }

    function renderReplyForm(parentId: string): string {
        return `
            <form class="comment-form comment-form-reply"
                  data-comment-form
                  data-parent-id="${escapeHtml(parentId)}"
                  data-comment-mode="reply">
                <div class="mb-2">
                    <label class="visually-hidden" for="comment-content-reply-${escapeHtml(parentId)}">${trans("js.comments.label")}</label>
                    <textarea
                        id="comment-content-reply-${escapeHtml(parentId)}"
                        class="form-control"
                        name="content"
                        rows="2"
                        placeholder="${trans("js.comments.reply_placeholder")}"
                        maxlength="5000"
                        required
                    ></textarea>
                </div>
                <div class="comment-form-error invalid-feedback d-none" data-comment-form-error></div>
                <div class="comment-form-actions d-flex flex-wrap align-items-center gap-2">
                    <button type="submit" class="btn btn-sm btn-primary">${trans("js.common.reply")}</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-comment-cancel>${trans("js.common.cancel")}</button>
                </div>
            </form>
        `;
    }

    function renderComment(comment: any): HTMLElement {
        const children = comment.children?.items ?? [];
        const childrenMeta = comment.children?.meta;
        const repliesHtml = children.map(renderReply).join("");
        const repliesButton = createRepliesButton(
            String(comment.id),
            Number(childrenMeta?.next_count ?? 0),
            childrenMeta?.next_cursor
        );

        const el = document.createElement('article');
        el.className = 'comment card border-0 shadow-sm';
        el.dataset.id = String(comment.id);
        const replyActions = isAuthenticated()
            ? `<button type="button" class="btn btn-sm btn-link px-0" data-reply-toggle>${trans("js.common.reply")}</button>`
            : `<button type="button"
                       class="btn btn-sm btn-link px-0"
                       data-bs-toggle="modal"
                       data-bs-target="#authFormModal">${trans("js.common.reply")}</button>`;
        const replyForm = isAuthenticated()
            ? `<div class="comment-reply-form-wrapper d-none mt-3">${renderReplyForm(String(comment.id))}</div>`
            : "";

        el.innerHTML = `
            <div class="card-body">
                ${renderCommentMeta(comment, 44)}
                <div class="comment-content content">${comment.content ?? ""}</div>
                <div class="comment-actions d-flex align-items-center gap-2 mt-2">${replyActions}</div>
                ${replyForm}
                <div class="replies mt-3">${repliesHtml}${repliesButton}</div>
            </div>
        `;

        return el;
    }

    function appendComments(target: HTMLElement, comments: any[]) {
        comments.forEach(comment => {
            target.appendChild(renderComment(comment));
        });
    }

    function insertReplyHtml(repliesEl: HTMLElement, replyHtml: string, position: "start" | "end" = "end") {
        const loadMoreButton = repliesEl.querySelector(".load-replies");

        if (position === "start") {
            const firstReply = repliesEl.querySelector(".reply");
            if (firstReply) {
                firstReply.insertAdjacentHTML("beforebegin", replyHtml);
            } else if (loadMoreButton) {
                loadMoreButton.insertAdjacentHTML("beforebegin", replyHtml);
            } else {
                repliesEl.insertAdjacentHTML("afterbegin", replyHtml);
            }
            return;
        }

        if (loadMoreButton) {
            loadMoreButton.insertAdjacentHTML("beforebegin", replyHtml);
            return;
        }

        repliesEl.insertAdjacentHTML("beforeend", replyHtml);
    }

    function updateLoadButton(button: HTMLButtonElement, meta: any) {
        const nextCount = Number(meta?.next_count ?? 0);
        const nextCursor = meta?.next_cursor ?? "";

        if (nextCount <= 0 || !nextCursor) {
            button.remove();
            return;
        }

        button.dataset.nextCursor = nextCursor;
        button.textContent = trans("js.comments.load_more", { count: nextCount });
    }

    function setCommentFormError(form: HTMLFormElement, message = "") {
        const errorEl = form.querySelector("[data-comment-form-error]") as HTMLElement | null;
        if (!errorEl) {
            return;
        }

        if (message.trim() === "") {
            errorEl.textContent = "";
            errorEl.classList.add("d-none");
            return;
        }

        errorEl.textContent = message;
        errorEl.classList.remove("d-none");
    }

    function toggleCommentForm(form: HTMLFormElement, visible: boolean) {
        const wrapper = form.closest(".comment-reply-form-wrapper") as HTMLElement | null;
        if (!wrapper) {
            return;
        }

        wrapper.classList.toggle("d-none", !visible);

        if (visible) {
            const textarea = form.elements.namedItem("content") as HTMLTextAreaElement | null;
            textarea?.focus();
        }
    }

    async function submitCommentForm(form: HTMLFormElement) {
        const textarea = form.elements.namedItem("content") as HTMLTextAreaElement | null;
        const parentId = form.dataset.parentId;
        const mode = form.dataset.commentMode ?? "root";
        const submitButton = form.querySelector('button[type="submit"]') as HTMLButtonElement | null;
        const content = textarea?.value.trim() ?? "";

        if (!parentId || !textarea) {
            return;
        }

        if (content === "") {
            setCommentFormError(form, trans("js.comments.empty"));
            textarea.focus();
            return;
        }

        setCommentFormError(form);
        textarea.disabled = true;
        if (submitButton) {
            submitButton.disabled = true;
        }

        try {
            const comment = await api(buildCommentsApiUrl(parentId, null), {
                method: "POST",
                data: { content }
            });

            if (mode === "reply") {
                const commentEl = form.closest(".comment") as HTMLElement | null;
                const repliesEl = commentEl?.querySelector(".replies") as HTMLElement | null;

                if (repliesEl) {
                    insertReplyHtml(repliesEl, renderReply(comment), "start");
                }

                form.reset();
                toggleCommentForm(form, false);
            } else {
                const list = document.getElementById("comments-list");
                if (list) {
                    list.prepend(renderComment(comment));
                }
                form.reset();
            }
        } catch (error: any) {
            setCommentFormError(form, getApiErrorMessage(error, trans("js.comments.send_failed")));
        } finally {
            textarea.disabled = false;
            if (submitButton) {
                submitButton.disabled = false;
            }
        }
    }

    async function loadMoreComments(button: HTMLButtonElement) {
        const list = document.getElementById('comments-list');
        const parentId = button.dataset.parentId;
        const cursor = button.dataset.nextCursor;

        if (!list || !parentId) {
            return;
        }

        button.disabled = true;

        try {
            const res = await api(buildCommentsApiUrl(parentId, cursor));
            appendComments(list, res.items ?? []);
            updateLoadButton(button, res.meta);
        } finally {
            if (button.isConnected) {
                button.disabled = false;
            }
        }
    }

    async function loadMoreReplies(button: HTMLButtonElement) {
        const parentId = button.dataset.parentId;
        const cursor = button.dataset.nextCursor;
        const commentEl = button.closest(".comment") as HTMLElement | null;
        const repliesEl = commentEl?.querySelector(".replies") as HTMLElement | null;

        if (!parentId || !commentEl || !repliesEl) {
            return;
        }

        button.disabled = true;

        try {
            const res = await api(buildCommentsApiUrl(parentId, cursor));
            (res.items ?? []).forEach((reply: any) => {
                insertReplyHtml(repliesEl, renderReply(reply));
            });
            updateLoadButton(button, res.meta);
        } finally {
            if (button.isConnected) {
                button.disabled = false;
            }
        }
    }

    function initComments() {
        const container = document.getElementById("comments");

        if (!container || container.dataset.commentsReady === "1") {
            return;
        }

        container.addEventListener("click", async (event) => {
            const target = event.target;
            if (!(target instanceof HTMLElement)) {
                return;
            }

            const rootButton = target.closest(".load-more-comments") as HTMLButtonElement | null;
            if (rootButton) {
                await loadMoreComments(rootButton);
                return;
            }

            const repliesButton = target.closest(".load-replies") as HTMLButtonElement | null;
            if (repliesButton) {
                await loadMoreReplies(repliesButton);
                return;
            }

            const replyToggle = target.closest("[data-reply-toggle]") as HTMLButtonElement | null;
            if (replyToggle) {
                const commentEl = replyToggle.closest(".comment") as HTMLElement | null;
                const form = commentEl?.querySelector("[data-comment-form]") as HTMLFormElement | null;
                if (form) {
                    toggleCommentForm(form, true);
                }
                return;
            }

            const cancelButton = target.closest("[data-comment-cancel]") as HTMLButtonElement | null;
            if (cancelButton) {
                const form = cancelButton.closest("[data-comment-form]") as HTMLFormElement | null;
                if (form) {
                    form.reset();
                    setCommentFormError(form);
                    toggleCommentForm(form, false);
                }
            }
        });

        container.addEventListener("submit", async (event) => {
            const target = event.target;
            if (!(target instanceof HTMLFormElement)) {
                return;
            }

            if (!target.matches("[data-comment-form]")) {
                return;
            }

            event.preventDefault();
            await submitCommentForm(target);
        });

        container.dataset.commentsReady = "1";
    }

    /* ===============================
       Rating
    =============================== */
    function buildRatingApiUrl(feedId: string): string {
        return `/api/v1/feeds/${feedId}/rating`;
    }

    function updateRatingStars(widget: HTMLElement, value: number | null) {
        widget.querySelectorAll<HTMLElement>(".rating-star[data-value]").forEach((star) => {
            const starValue = Number(star.dataset.value);
            const icon = star.querySelector("i");
            const filled = value !== null && starValue <= value;

            icon?.classList.toggle("bi-star-fill", filled);
            icon?.classList.toggle("bi-star", !filled);
        });

        widget.dataset.userRating = value !== null ? String(value) : "";
    }

    function updateRatingSummary(widget: HTMLElement, average: number, count: number) {
        const summaryEl = widget.querySelector("[data-rating-summary]");
        if (!summaryEl) {
            return;
        }

        summaryEl.textContent = count > 0
            ? trans("js.rating.summary", { average: average.toFixed(1), count })
            : trans("js.rating.empty");
    }

    async function submitRating(widget: HTMLElement, value: number) {
        const feedId = widget.dataset.feedId;

        if (!feedId || widget.classList.contains("is-submitting")) {
            return;
        }

        widget.classList.add("is-submitting");

        try {
            const feed = await api(buildRatingApiUrl(feedId), {
                method: "POST",
                data: { value }
            });

            updateRatingStars(widget, value);
            updateRatingSummary(widget, Number(feed.ratingAverage ?? 0), Number(feed.ratingCount ?? 0));
        } catch (error) {
            toastMessage({ message: getApiErrorMessage(error, trans("js.rating.save_failed")), type: "danger" });
        } finally {
            widget.classList.remove("is-submitting");
        }
    }

    function initRating() {
        document.querySelectorAll<HTMLElement>("[data-rate-feed]:not([data-rating-ready])").forEach((widget) => {
            widget.addEventListener("click", (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) {
                    return;
                }

                const star = target.closest(".rating-star[data-value]");
                if (!star || !widget.contains(star)) {
                    return;
                }

                const value = Number(star.getAttribute("data-value"));
                void submitRating(widget, value);
            });

            widget.dataset.ratingReady = "1";
        });
    }

    /* ===============================
       Favorites
    =============================== */
    function buildFavoriteApiUrl(feedId: string): string {
        return `/api/v1/feeds/${feedId}/favorite`;
    }

    function updateFavoriteButton(widget: HTMLElement, favorited: boolean) {
        widget.dataset.favorited = favorited ? "1" : "0";

        const button = widget.querySelector<HTMLElement>("[data-favorite-toggle]");
        const icon = button?.querySelector("i");
        const label = button?.querySelector("[data-favorite-label]");

        // Callers can override the default icon and wording through data
        // attributes while reusing the same favoriting behavior.
        const iconOn = widget.dataset.favoriteIconOn || "bi-heart-fill";
        const iconOff = widget.dataset.favoriteIconOff || "bi-heart";
        const labelOn = widget.dataset.favoriteLabelOn || trans("js.favorite.on");
        const labelOff = widget.dataset.favoriteLabelOff || trans("js.favorite.off");
        const ariaOn = widget.dataset.favoriteAriaOn || trans("js.favorite.remove_aria");
        const ariaOff = widget.dataset.favoriteAriaOff || trans("js.favorite.add_aria");

        icon?.classList.toggle(iconOn, favorited);
        icon?.classList.toggle(iconOff, !favorited);
        button?.setAttribute("aria-pressed", favorited ? "true" : "false");
        button?.setAttribute("aria-label", favorited ? ariaOn : ariaOff);

        if (label) {
            label.textContent = favorited ? labelOn : labelOff;
        }
    }

    /**
     * A favorites listing can remove a card along with the favorite instead
     * of only flipping its icon in place.
     */
    function removeFavoriteCard(widget: HTMLElement) {
        const card = widget.closest<HTMLElement>("[data-favorite-card]") ?? widget;
        const grid = card.closest<HTMLElement>("[data-favorites-grid]");

        card.classList.add("favorite-card-removing");

        window.setTimeout(() => {
            card.remove();

            if (grid && !grid.querySelector("[data-favorite-card]")) {
                const empty = document.createElement("div");
                empty.className = "col-12";

                const message = document.createElement("div");
                message.className = "text-muted";
                message.textContent = grid.dataset.favoritesEmptyMessage ?? trans("js.favorite.empty");

                empty.appendChild(message);
                grid.appendChild(empty);
            }
        }, 200);
    }

    async function submitFavorite(widget: HTMLElement) {
        const feedId = widget.dataset.feedId;

        if (!feedId || widget.classList.contains("is-submitting")) {
            return;
        }

        const currentlyFavorited = widget.dataset.favorited === "1";

        widget.classList.add("is-submitting");

        try {
            await api(buildFavoriteApiUrl(feedId), {
                method: currentlyFavorited ? "DELETE" : "POST"
            });

            if (currentlyFavorited && widget.hasAttribute("data-remove-on-unfavorite")) {
                removeFavoriteCard(widget);

                return;
            }

            updateFavoriteButton(widget, !currentlyFavorited);
        } catch (error) {
            toastMessage({
                message: getApiErrorMessage(error, trans("js.favorite.update_failed")),
                type: "danger"
            });
        } finally {
            widget.classList.remove("is-submitting");
        }
    }

    function initFavorite() {
        document.querySelectorAll<HTMLElement>("[data-favorite-feed]:not([data-favorite-ready])").forEach((widget) => {
            widget.addEventListener("click", (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) {
                    return;
                }

                const button = target.closest("[data-favorite-toggle]");
                if (!button || !widget.contains(button)) {
                    return;
                }

                // On a favorites listing, removal is destructive (the card
                // disappears) rather than an in-place toggle, so confirm first.
                //
                // Only in that direction, which the widget's current state
                // decides: the same button also *adds* to favorites once the
                // card has been toggled off, and asking to remove it before
                // adding one made no sense - the dialog
                // fired on any click of such a widget.
                const willRemove = widget.dataset.favorited === "1";

                if (willRemove && widget.hasAttribute("data-remove-on-unfavorite")) {
                    confirm({
                        title: trans("js.favorite.remove_title"),
                        message: trans("js.favorite.remove_confirm"),
                        onConfirm: () => void submitFavorite(widget)
                    });

                    return;
                }

                void submitFavorite(widget);
            });

            widget.dataset.favoriteReady = "1";
        });
    }

    /* ===============================
       Saved reading progress
    =============================== */
    function buildReadingProgressApiUrl(feedId: string): string {
        return `/api/v1/feeds/${feedId}/reading-progress`;
    }

    /**
     * Drops a card from a progress widget. If it was the last card, the whole
     * widget is removed as well.
     */
    function removeContinueReadingCard(widget: HTMLElement) {
        const card = widget.closest<HTMLElement>("[data-continue-reading-card]") ?? widget;
        const track = card.closest<HTMLElement>("[data-continue-reading]");

        card.classList.add("favorite-card-removing");

        window.setTimeout(() => {
            card.remove();

            if (track && !track.querySelector("[data-continue-reading-card]")) {
                track.closest<HTMLElement>("[data-continue-reading-widget]")?.remove();
            }
        }, 200);
    }

    async function removeReadingProgress(widget: HTMLElement) {
        const feedId = widget.dataset.feedId;

        if (!feedId || widget.classList.contains("is-submitting")) {
            return;
        }

        widget.classList.add("is-submitting");

        try {
            await api(buildReadingProgressApiUrl(feedId), { method: "DELETE" });
            removeContinueReadingCard(widget);
        } catch (error) {
            toastMessage({
                message: getApiErrorMessage(error, trans("js.reading.remove_failed")),
                type: "danger"
            });
        } finally {
            widget.classList.remove("is-submitting");
        }
    }

    function initContinueReading() {
        document.querySelectorAll<HTMLElement>("[data-remove-progress-feed]:not([data-remove-progress-ready])").forEach((widget) => {
            widget.addEventListener("click", (event) => {
                const target = event.target;
                if (!(target instanceof HTMLElement)) {
                    return;
                }

                const button = target.closest("[data-remove-progress-toggle]");
                if (!button || !widget.contains(button)) {
                    return;
                }

                // Removal here also resets reading progress server/cookie-side
                // (see FeedService::removeReadProgressForFeed()), not just a
                // display toggle, so confirm first - same reasoning as the
                // favorites grid's data-remove-on-unfavorite.
                confirm({
                    title: trans("js.reading.remove_title"),
                    message: trans("js.reading.remove_confirm"),
                    onConfirm: () => void removeReadingProgress(widget)
                });
            });

            widget.dataset.removeProgressReady = "1";
        });
    }

    /* ===============================
       Direct message (the profile card's message action)
    =============================== */

    /**
     * The messenger addresses conversations, not people (#c=<code>), and the
     * conversation with this person may not exist yet - so the button can't
     * be a plain link: the row has to be found-or-created first, and only
     * then can the URL be built. That happens on click rather than while
     * rendering the profile on purpose - otherwise merely reading someone's
     * blog would quietly create an empty conversation with them.
     *
     * POST /api/v1/conversations is idempotent for direct chats
     * (MessageService::createOrGetDirect() returns the existing row when the
     * two have talked before), so clicking this repeatedly reopens the same
     * conversation instead of piling up new ones.
     */
    async function openDirectMessage(button: HTMLButtonElement) {
        const userId = parseInt(button.dataset.messageUser ?? '0', 10);
        if (!userId || button.disabled) return;

        const originalHtml = button.innerHTML;

        button.disabled = true;
        button.innerHTML = `<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> ${trans("js.messages.opening")}`;

        try {
            const conversation = await api<{ conversation_id: number }>('/api/v1/conversations', {
                method: 'POST',
                data: { user_id: userId }
            });

            const code = encodeId(conversation.conversation_id);
            if (!code) throw new Error('Conversation id is not encodable');

            // A real navigation, not a hash write - we're leaving this page
            // for the messenger, which reads #c= on load and opens it.
            window.location.href = `/messages/#c=${code}`;
        } catch (error) {
            toastMessage({
                message: getApiErrorMessage(error, trans('js.messages.open_failed')),
                type: 'danger'
            });

            // Only on failure: a successful click is followed by navigation,
            // and restoring the label mid-unload just flickers.
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    }

    function initDirectMessage() {
        document.querySelectorAll<HTMLButtonElement>('[data-message-user]:not([data-message-user-ready])').forEach((button) => {
            button.addEventListener('click', () => void openDirectMessage(button));

            button.dataset.messageUserReady = "1";
        });
    }

    return {
        api,
        toast: toastMessage,
        confirm,
        isAuthenticated,
        // The single HTML-escaper every page bundle uses - see its own note on
        // why it's shared through here rather than imported.
        escapeHtml,
        // Shared URL-fragment state (see hash-route.ts) plus the id
        // obfuscation any feature putting a row id in that fragment should
        // go through (see opaque-id.ts).
        hashRoute,
        encodeId,
        decodeId,
        trans,
        transChoice,
        transChoiceWithCount,
        initShare,
        initTooltips,
        checkAndSetTimezoneCookie,
        initComments,
        initRating,
        initFavorite,
        initContinueReading,
        initDirectMessage
    };
})();

window.CMS = CMS;
export default CMS;

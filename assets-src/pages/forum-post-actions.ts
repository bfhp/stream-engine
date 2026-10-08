import { getApiErrorMessage } from "../shared/api-errors";

type ApiOptions = {
    method: "PATCH" | "DELETE";
    data?: Record<string, unknown>;
};

type ConfirmOptions = {
    title: string;
    message: string;
    onConfirm: () => void;
};

type ToastOptions = {
    message: string;
    type: "danger";
};

export type ForumPostActionsDependencies = {
    api: (url: string, options: ApiOptions) => Promise<unknown>;
    confirm: (options: ConfirmOptions) => void;
    toast: (options: ToastOptions) => void;
    trans: (key: string) => string;
    onEditSaved: (post: HTMLElement | null) => void;
    onReplyDeleted: () => void;
    onQuote: (button: HTMLElement) => void;
};

function showFieldError(el: HTMLElement | null, message: string) {
    if (!el) return;
    el.textContent = message;
    el.hidden = false;
}

function hideFieldError(el: HTMLElement | null | undefined) {
    if (!el) return;
    el.hidden = true;
}

/**
 * Wires the actions rendered beside a forum post.
 *
 * Kept separate from forums.ts so the browser behaviour can be tested without
 * importing Trix. Authorization remains server-side; the conditional buttons
 * are only the friendly UI for those same rules.
 */
export function initForumPostActions(
    postsContainer: HTMLElement,
    dependencies: ForumPostActionsDependencies,
) {
    postsContainer.addEventListener("click", (event) => {
        const target = event.target as HTMLElement;

        const quoteToggle = target.closest("[data-quote-toggle]") as HTMLElement | null;
        if (quoteToggle) {
            dependencies.onQuote(quoteToggle);
            return;
        }

        const editToggle = target.closest("[data-comment-edit-toggle]") as HTMLElement | null;
        if (editToggle) {
            const id = editToggle.dataset.commentId;
            const editWrapper = postsContainer.querySelector(`[data-comment-edit-wrapper="${id}"]`);
            const contentWrapper = postsContainer.querySelector(`[data-comment-content-wrapper="${id}"]`);
            if (editWrapper) (editWrapper as HTMLElement).hidden = false;
            if (contentWrapper) (contentWrapper as HTMLElement).hidden = true;
            return;
        }

        const editCancel = target.closest("[data-comment-edit-cancel]") as HTMLElement | null;
        if (editCancel) {
            const id = editCancel.dataset.commentId;
            const editWrapper = postsContainer.querySelector(`[data-comment-edit-wrapper="${id}"]`);
            const contentWrapper = postsContainer.querySelector(`[data-comment-content-wrapper="${id}"]`);
            if (editWrapper) (editWrapper as HTMLElement).hidden = true;
            hideFieldError(editWrapper?.querySelector("[data-comment-edit-error]") as HTMLElement | null);
            if (contentWrapper) (contentWrapper as HTMLElement).hidden = false;
            return;
        }

        const editSave = target.closest("[data-comment-edit-save]") as HTMLButtonElement | null;
        if (editSave) {
            const commentId = editSave.dataset.commentId;
            const topicId = editSave.dataset.parentId;
            const wrapper = postsContainer.querySelector(`[data-comment-edit-wrapper="${commentId}"]`);
            const contentInput = wrapper?.querySelector("[data-comment-edit-input]") as HTMLInputElement | null;
            const editor = wrapper?.querySelector("[data-comment-edit-editor]") as (HTMLElement & { editor?: any }) | null;
            const errorEl = wrapper?.querySelector("[data-comment-edit-error]") as HTMLElement | null;
            const content = (contentInput?.value || "").trim();
            const plainText = editor?.editor?.getDocument().toString().trim() || "";

            hideFieldError(errorEl);

            if (!plainText && !content.includes("<figure")) {
                showFieldError(errorEl, dependencies.trans("js.forums.message_required"));
                return;
            }

            editSave.disabled = true;

            dependencies.api(`/api/v1/comments/${topicId}/${commentId}`, {
                method: "PATCH",
                data: { content, format: "html" },
            })
                .then(() => dependencies.onEditSaved(editSave.closest('li[id^="post-"]')))
                .catch((error) => {
                    editSave.disabled = false;
                    showFieldError(
                        errorEl,
                        getApiErrorMessage(error, dependencies.trans("js.forums.message_save_failed")),
                    );
                });
            return;
        }

        const deleteButton = target.closest("[data-comment-delete]") as HTMLElement | null;
        if (!deleteButton) return;

        const commentId = deleteButton.dataset.commentId;
        const topicId = deleteButton.dataset.parentId;

        dependencies.confirm({
            title: dependencies.trans("js.forums.message_delete_title"),
            message: dependencies.trans("js.forums.message_delete_confirm"),
            onConfirm: () => {
                dependencies.api(`/api/v1/comments/${topicId}/${commentId}`, { method: "DELETE" })
                    .then(dependencies.onReplyDeleted)
                    .catch((error) => {
                        dependencies.toast({
                            message: getApiErrorMessage(
                                error,
                                dependencies.trans("js.forums.message_delete_failed"),
                            ),
                            type: "danger",
                        });
                    });
            },
        });
    });
}

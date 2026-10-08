// Forums' own JS: forums.topic-view's quick-reply form and per-post
// edit/delete actions, forums.list/forums.topic-list's "mark all read"
// button, and forums.topic-new's create-a-topic form (title + Trix body +
// poll builder, submitted to ForumsController::handleTopicCreateRequest()).
// Was an inline <script> in the twig template itself - moved here to match
// every other page's own bundle (users.ts/profile.ts, ...),
// loaded via head_ext the same way (see ForumsController's own show*Page()
// methods). One shared bundle for the whole module rather than a separate
// one just for forums.topic-new - unlike blog-post-form.js/.css (which
// exist precisely to keep Trix off pages that don't need it), every Forums
// page already loads this bundle, so there's no equivalent win to splitting
// it out further.

import { uploadFile, uploadHeaders } from "../shared/uploads";
import "trix";
import "trix/dist/trix.css";
// forums.topic-new's preview button (initTopicPreview()) opens a modal
// through the theme's UI adapter, never through a framework import.
import { ui } from "../shared/ui";
import { bytesToLabel } from "../shared/bytes";
import { getApiErrorMessage } from "../shared/api-errors";
import { trans, transChoiceWithCount } from "../shared/i18n";
import { buildRichQuoteInsertionHtml } from "../shared/comment-quotes";
import {
    PollApiResponse,
    applyPollResponse,
    syncPollOptionInputs,
    updatePollSelectionUi,
} from "./forum-poll";

const cms = window.CMS;

function showFieldError(el: HTMLElement | null, message: string) {
    if (!el) return;
    el.textContent = message;
    el.hidden = false;
}

function setHidden(el: Element | null | undefined, hidden: boolean) {
    if (el instanceof HTMLElement) el.hidden = hidden;
}

function hideFieldError(el: HTMLElement | null | undefined) {
    if (!el) return;
    el.hidden = true;
}

// forum-quick-reply-form's own preview toggle: swaps the Trix editor for
// a rendered preview of its current HTML
// and back, flipping the button's icon/label each time. Exported as a
// closure returning showEditor() so the submit handler can force the form
// back into edit mode first.
function initQuickReplyPreview(form: HTMLFormElement): () => void {
    const button = form.querySelector("[data-forum-quick-reply-preview-toggle]") as HTMLButtonElement | null;
    const contentInput = document.getElementById("forum-quick-reply-content") as HTMLInputElement | null;
    const editor = document.getElementById("forum-quick-reply-editor") as (HTMLElement & { editor?: any }) | null;
    const previewEl = form.querySelector("[data-forum-quick-reply-preview]") as HTMLElement | null;

    const showEditor = () => {
        if (!button || !contentInput || !editor || !previewEl) return;
        previewEl.hidden = true;
        editor.hidden = false;
        button.innerHTML = `<i class="bi bi-eye"></i><span>${trans("js.forums.preview")}</span>`;
    };

    if (!button || !contentInput || !editor || !previewEl) return showEditor;

    button.addEventListener("click", () => {
        const isPreviewing = !previewEl.hidden;

        if (isPreviewing) {
            showEditor();
            return;
        }

        const content = contentInput.value.trim();
        const plainText = editor.editor?.getDocument().toString().trim() || "";
        if (plainText || content.includes("<figure")) {
            previewEl.innerHTML = content;
            delete previewEl.dataset.empty;
        } else {
            previewEl.textContent = trans("js.forums.content_empty");
            previewEl.dataset.empty = "1";
        }
        editor.hidden = true;
        previewEl.hidden = false;
        button.innerHTML = `<i class="bi bi-pencil"></i><span>${trans("js.common.edit")}</span>`;
    });

    return showEditor;
}

function initQuickReplyForm() {
    const form = document.getElementById("forum-quick-reply-form") as HTMLFormElement | null;
    if (!form) return;

    const showEditor = initQuickReplyPreview(form);

    form.addEventListener("submit", (event) => {
        event.preventDefault();

        showEditor();

        const contentInput = document.getElementById("forum-quick-reply-content") as HTMLInputElement | null;
        const editor = document.getElementById("forum-quick-reply-editor") as (HTMLElement & { editor?: any }) | null;
        const errorEl = form.querySelector("[data-forum-quick-reply-error]") as HTMLElement | null;
        const content = (contentInput?.value || "").trim();
        const plainText = editor?.editor?.getDocument().toString().trim() || "";

        hideFieldError(errorEl);

        if (!plainText && !content.includes("<figure")) {
            showFieldError(errorEl, trans("js.forums.reply_required"));
            return;
        }

        const parentId = form.dataset.parentId;
        const submitButton = form.querySelector('button[type="submit"]') as HTMLButtonElement | null;
        if (submitButton) submitButton.disabled = true;

        // Forums' own action (ForumsController::callApi()'s 'forums.reply'),
        // not the generic /api/v1/comments/{parentId} every other comment
        // form posts to - the extra step after creating the comment
        // (notifying the topic's followers, see
        // ForumsController::notifyTopicFollowers()) is Forums-specific.
        cms.api(`/api/v1/forums/${parentId}/reply`, { method: "POST", data: { content } })
            .then(() => {
                const totalPosts = parseInt(form.dataset.totalPosts || "0", 10) || 0;
                const perPage = parseInt(form.dataset.perPage || "20", 10) || 20;
                const newTotal = totalPosts + 1;
                const lastPage = Math.max(1, Math.ceil(newTotal / perPage));
                const topicUrl = form.dataset.topicUrl || "";
                const target = topicUrl + (lastPage > 1 ? `?page=${lastPage}` : "");
                const hash = `post-${newTotal}`;

                // A reply almost always lands on the page already open (a
                // new page only appears once POSTS_PER_PAGE is crossed) -
                // when target is the same path+query as the current URL,
                // `location.href = target + '#...'` is just an in-page hash
                // change to the browser, not a navigation, so nothing
                // reloads and the new reply never appears. Force a real
                // reload in that case; only rely on a plain href assignment
                // when the target is actually a different page.
                if (target === window.location.pathname + window.location.search) {
                    window.location.hash = hash;
                    window.location.reload();
                } else {
                    window.location.href = `${target}#${hash}`;
                }
            })
            .catch((error) => {
                if (submitButton) submitButton.disabled = false;
                showFieldError(errorEl, getApiErrorMessage(error, trans("js.forums.reply_failed")));
            });
    });
}

// Inserts the post snapshot as a rich blockquote at the start of the reply.
function insertQuote(button: HTMLElement) {
    const editor = document.getElementById("forum-quick-reply-editor") as (HTMLElement & { editor?: any }) | null;
    // No editor to insert into means the reply form isn't on the page at
    // all (shouldn't happen - the button only renders for !user.isGuest,
    // same gate the quick-reply card itself uses - but bail quietly rather
    // than throw if that ever drifts).
    if (!editor?.editor) return;

    const author = button.dataset.quoteAuthor || "";
    const content = button.dataset.quoteContent || "";
    const block = buildRichQuoteInsertionHtml(author, content);
    editor.editor.setSelectedRange([0, 0]);
    editor.editor.insertHTML(block);

    document.getElementById("reply")?.scrollIntoView({ behavior: "smooth", block: "start" });
    editor.focus();
}

function initPostActions() {
    const postsContainer = document.getElementById("topic-posts");
    if (!postsContainer) return;

    // Delegated on the whole posts list rather than bound per-button, same
    // reasoning CMS.initComments() gives for delegating on #comments:
    // buttons only exist for canEdit/canDelete rows (see
    // ForumsController::buildPostRows()), but one listener here is simpler
    // than conditionally attaching N.
    postsContainer.addEventListener("click", (event) => {
        const target = event.target as HTMLElement;

        const quoteToggle = target.closest("[data-quote-toggle]") as HTMLElement | null;
        if (quoteToggle) {
            insertQuote(quoteToggle);
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
                showFieldError(errorEl, trans("js.forums.message_required"));
                return;
            }

            editSave.disabled = true;

            cms.api(`/api/v1/comments/${topicId}/${commentId}`, { method: "PATCH", data: { content, format: "html" } })
                .then(() => {
                    const postEl = editSave.closest('li[id^="post-"]');
                    if (postEl) window.location.hash = postEl.id;
                    window.location.reload();
                })
                .catch((error) => {
                    editSave.disabled = false;
                    showFieldError(errorEl, getApiErrorMessage(error, trans("js.forums.message_save_failed")));
                });
            return;
        }

        const deleteButton = target.closest("[data-comment-delete]") as HTMLElement | null;
        if (deleteButton) {
            const commentId = deleteButton.dataset.commentId;
            const topicId = deleteButton.dataset.parentId;

            cms.confirm({
                title: trans("js.forums.message_delete_title"),
                message: trans("js.forums.message_delete_confirm"),
                onConfirm: () => {
                    cms.api(`/api/v1/comments/${topicId}/${commentId}`, { method: "DELETE" })
                        .then(() => {
                            window.location.reload();
                        })
                        .catch((error) => {
                            cms.toast({
                                message: getApiErrorMessage(error, trans("js.forums.message_delete_failed")),
                                type: "danger",
                            });
                        });
                },
            });
        }
    });
}

function initForumReplyEditors() {
    document.querySelectorAll<HTMLElement>("[data-forum-quick-reply-editor], [data-comment-edit-editor]")
        .forEach((editor) => {
            // Topic attachments have a dedicated upload flow. Replies do not,
            // so reject local Trix attachments instead of serializing a
            // temporary blob/data URL that the server would strip.
            editor.addEventListener("trix-file-accept", (event) => event.preventDefault());
        });
}

function initMarkTopicRead() {
    const button = document.querySelector<HTMLButtonElement>("[data-forum-mark-topic-read]");
    if (!button) return;

    button.addEventListener("click", () => {
        const topicId = button.dataset.topicId;
        if (!topicId) return;

        button.disabled = true;

        cms.api(`/api/v1/forums/${topicId}/read`, { method: "POST" })
            .then(() => {
                button.disabled = false;
                cms.toast({ message: trans("js.forums.topic_marked_read"), type: "success" });
            })
            .catch((error) => {
                button.disabled = false;
                cms.toast({
                    message: getApiErrorMessage(error, trans("js.forums.topic_mark_read_failed")),
                    type: "danger",
                });
            });
    });
}

// Mark all as read - forums.list.twig's site-wide button (no
// data-forum-id) and forums.topic-list.twig's per-section button (with one) -
// both delegate to the same Forums-owned bulk endpoint
// (ForumsController::handleMarkAllReadRequest()), which tells the two apart
// by whether the POST body carries a forumId. A single topic has its own
// manual button too now (initMarkTopicRead() above), on top of the
// automatic on-view marking.
function initMarkAllRead() {
    document.querySelectorAll<HTMLButtonElement>("[data-forum-mark-all-read]").forEach((button) => {
        button.addEventListener("click", () => {
            const forumId = button.dataset.forumId ? parseInt(button.dataset.forumId, 10) : null;

            cms.confirm({
                title: trans("js.forums.mark_all_title"),
                message: forumId
                    ? trans("js.forums.mark_section_confirm")
                    : trans("js.forums.mark_all_confirm"),
                onConfirm: () => {
                    button.disabled = true;

                    cms.api("/api/v1/forums/read-all", {
                        method: "POST",
                        data: forumId ? { forumId } : {},
                    })
                        .then(() => {
                            window.location.reload();
                        })
                        .catch((error) => {
                            button.disabled = false;
                            cms.toast({
                                message: getApiErrorMessage(error, trans("js.forums.mark_all_failed")),
                                type: "danger",
                            });
                        });
                },
            });
        });
    });
}

// Russian plural forms: one, few, and many.
// forums.topic-view's poll widget (#topic-poll, see
// ForumsController::buildPollViewModel() for the server-rendered initial
// state). Votes through the already-generic
// POST /api/v1/feeds/{feedId}/poll/vote (Service\PollService::vote()/
// APIController::handlePollVoteRequest()) - the same endpoint any other
// feed-attached poll would use, nothing here is Forums-specific except
// which feed id it's rendered under.
//
// Both the vote-form and results blocks are always in the DOM (one hidden
// per whichever the initial state isn't) - same pattern initPostActions()'s
// comment edit/view toggle and initQuickReplyPreview() above already use -
// so changing a vote just reveals the already-correctly-pre-checked
// vote-form instead of needing a server round trip to fetch it.
function initPoll() {
    const card = document.getElementById("topic-poll");
    const form = card?.querySelector<HTMLElement>("[data-poll-vote-form]");
    const feedId = card?.dataset.pollFeedId;
    if (!card || !form || !feedId) return;

    form.addEventListener("change", (event) => {
        if ((event.target as HTMLElement)?.matches("[data-poll-option-input]")) {
            updatePollSelectionUi(form);
        }
    });

    form.querySelector("[data-poll-submit]")?.addEventListener("click", () => {
        const submit = form.querySelector<HTMLButtonElement>("[data-poll-submit]");
        const optionIds = Array.from(form.querySelectorAll<HTMLInputElement>("[data-poll-option-input]"))
            .filter((input) => input.checked)
            .map((input) => Number(input.value));

        if (optionIds.length === 0 || !submit) return;

        submit.disabled = true;

        cms.api(`/api/v1/feeds/${feedId}/poll/vote`, { method: "POST", data: { optionIds } })
            .then((poll: PollApiResponse) => {
                applyPollResponse(card, form, poll);
            })
            .catch((error) => {
                submit.disabled = false;
                cms.toast({
                    message: getApiErrorMessage(error, trans("js.forums.vote_save_failed")),
                    type: "danger",
                });
            });
    });

    // Reveals the vote-form (already pre-checked with the current vote,
    // rendered but hidden) in place of the results block - no server call,
    // same as opening any other already-rendered edit view on this page.
    card.querySelector("[data-poll-change-vote]")?.addEventListener("click", () => {
        setHidden(card.querySelector("[data-poll-results]"), true);
        setHidden(card.querySelector("[data-poll-hidden-note]"), true);
        setHidden(card.querySelector("[data-poll-change-vote]"), true);
        form.hidden = false;
        setHidden(form.querySelector("[data-poll-cancel-change]"), false);
        updatePollSelectionUi(form);
    });

    // Discards any in-progress selection changes and goes back to the
    // results view - resyncs inputs to data-poll-current-votes (the last
    // server-confirmed selection) rather than leaving whatever the user was
    // mid-toggling.
    form.querySelector("[data-poll-cancel-change]")?.addEventListener("click", () => {
        const currentVotes = (form.dataset.pollCurrentVotes || "")
            .split(",")
            .filter((id) => id !== "")
            .map(Number);

        syncPollOptionInputs(card, form, currentVotes);
        form.hidden = true;
        setHidden(form.querySelector("[data-poll-cancel-change]"), true);
        setHidden(card.querySelector("[data-poll-results]"), false);
        setHidden(card.querySelector("[data-poll-change-vote]"), false);
        updatePollSelectionUi(form);
    });
}

// forums.topic-new's attachments card - a multi-file drag-and-drop uploader
// wired to the same generic /api/v1/uploads endpoint blog-post-form.js's
// cover-image/track widgets already use, just multi-file with a per-row
// progress bar (via XMLHttpRequest.upload - plain fetch() has no upload
// progress event) instead of either widget's own single slot. Returns just
// enough for initTopicForm()'s own submit handler: the uploaded ids to send
// as 'attachments' (ForumsController::resolveTopicAttachments()'s own
// input), and whether anything is still mid-upload (so submit can wait
// instead of publishing a topic missing a file that was still in flight).
//
// forums.topic-edit reuses the same widget for a topic's *already stored*
// attachments, passed in as $existing (ForumsController::
// buildAttachmentRows()'s own rows, via the form's
// data-attachments-existing) - they're seeded as "done" items with no File
// behind them, so they count toward the 5-file cap, render like any freshly
// uploaded row, and come back out of getUploadedIds() unchanged unless the
// author removes one. That last part is what makes the PATCH's
// "`attachments` is the complete new set" contract work (see
// ForumsController::handleTopicUpdateRequest()): keeping a file simply means
// sending its id back.
function initAttachments(
    form: HTMLFormElement,
    uploadsApiUrl: string,
    onError: (message: string) => void,
    existing: { id: number; name: string; sizeLabel: string; icon: string }[] = [],
) {
    const drop = form.querySelector<HTMLElement>("[data-attachments-drop]");
    const input = form.querySelector<HTMLInputElement>("[data-attachments-input]");
    const list = form.querySelector<HTMLElement>("[data-attachments-list]");
    const emptyNote = form.querySelector<HTMLElement>("[data-attachments-empty]");

    const noop = {
        getUploadedIds: () => [] as number[],
        hasPending: () => false,
        getPreviewRows: () => [] as { name: string; sizeLabel: string; icon: string }[],
    };
    if (!drop || !input || !list) return noop;

    // Mirrors ForumsController::MAX_TOPIC_ATTACHMENTS / UploadService's own
    // limits - enforced again server-side (resolveTopicAttachments() caps
    // the count, UploadService::validateFile() the size/mime), so bypassing
    // this client-side check just means a slower round trip to the same
    // rejection, not a real gap. MAX_SIZE matches UploadService::
    // MAX_FILE_SIZE exactly (10 MB) - keep the two in sync if either changes.
    const MAX_FILES = 5;
    const MAX_SIZE = 10 * 1024 * 1024;
    const ALLOWED_MIME = ["image/jpeg", "image/png", "image/webp", "application/pdf"];

    // name/sizeLabel/icon are stored on the item rather than re-derived from
    // `file` on every render, because an item seeded from $existing has no
    // File at all (file: null) - it's already on the server, so there's
    // nothing to upload and nothing to read a MIME type or byte size off.
    type AttachmentItem = {
        key: number;
        file: File | null;
        name: string;
        sizeLabel: string;
        icon: string;
        status: "uploading" | "done" | "error";
        uploadId: number | null;
        xhr: XMLHttpRequest | null;
        row: HTMLElement;
    };

    let seq = 0;
    const items: AttachmentItem[] = [];

    function sync() {
        if (emptyNote) emptyNote.hidden = items.length > 0;
        drop!.hidden = items.length >= MAX_FILES;
    }

    function removeItem(key: number) {
        const index = items.findIndex((entry) => entry.key === key);
        if (index === -1) return;

        const [item] = items.splice(index, 1);
        item.xhr?.abort();
        item.row.remove();
        sync();
    }

    function renderRow(item: AttachmentItem) {
        const statusText = item.status === "uploading"
            ? trans("js.forums.uploading")
            : item.status === "error"
                ? trans("js.forums.upload_failed_status")
                : trans("js.forums.uploaded_status", { size: item.sizeLabel });

        // A fresh copy of the theme's row per state change, swapped in for
        // the previous one (the progress bar exists only while uploading).
        const row = ui.clone("forum-attachment-upload");
        if (!row) return;
        ui.fill(row, {
            iconClass: `bi ${item.icon}`,
            name: item.name,
            status: statusText,
            uploading: item.status === "uploading" ? "1" : "",
            removeLabel: trans(item.status === "uploading" ? "js.forums.upload_cancel" : "js.forums.attachment_remove"),
        });
        item.row.replaceWith(row);
        item.row = row;
        item.row.querySelector("[data-attachment-remove]")?.addEventListener("click", () => removeItem(item.key));
    }

    function uploadItem(item: AttachmentItem) {
        if (!item.file) return;

        const xhr = new XMLHttpRequest();
        item.xhr = xhr;

        const body = new FormData();
        body.append("file", item.file);

        xhr.upload.addEventListener("progress", (event) => {
            if (!event.lengthComputable) return;
            const bar = item.row.querySelector<HTMLElement>("[data-attachment-progress]");
            if (bar) bar.style.width = `${Math.round((event.loaded / event.total) * 100)}%`;
        });

        xhr.addEventListener("load", () => {
            item.xhr = null;

            let result: any = null;
            try {
                result = JSON.parse(xhr.responseText);
            } catch (e) {
                // Non-JSON response falls through to the generic error below.
            }

            if (xhr.status >= 200 && xhr.status < 300 && result?.id) {
                item.uploadId = result.id;
                item.status = "done";
            } else {
                item.status = "error";
                onError(getApiErrorMessage(result, trans("js.forums.file_upload_failed", { name: item.name })));
            }
            renderRow(item);
        });

        xhr.addEventListener("error", () => {
            item.xhr = null;
            item.status = "error";
            renderRow(item);
            onError(trans("js.forums.file_upload_failed", { name: item.name }));
        });

        xhr.open("POST", uploadsApiUrl);
        xhr.withCredentials = true;
        // This is the one upload that can't go through shared/uploads.ts's
        // uploadFile(): the per-file progress bar needs XMLHttpRequest.upload,
        // which fetch() has no equivalent for. So it borrows just the header.
        // Set after open() and before send(), which is the only window in
        // which setRequestHeader() is legal.
        Object.entries(uploadHeaders()).forEach(([name, value]) => {
            xhr.setRequestHeader(name, value);
        });
        xhr.send(body);
    }

    function addFiles(files: FileList | File[]) {
        Array.from(files).forEach((file) => {
            if (items.length >= MAX_FILES) {
                onError(trans("js.forums.files_limit", { max: MAX_FILES }));
                return;
            }
            if (!ALLOWED_MIME.includes(file.type)) {
                onError(trans("js.forums.file_type_unsupported", { name: file.name }));
                return;
            }
            if (file.size > MAX_SIZE) {
                onError(trans("js.forums.file_too_large", { name: file.name }));
                return;
            }

            const item: AttachmentItem = {
                key: ++seq,
                file,
                name: file.name,
                sizeLabel: bytesToLabel(file.size),
                icon: file.type === "application/pdf" ? "bi-file-earmark-pdf" : "bi-image",
                status: "uploading",
                uploadId: null,
                xhr: null,
                row: makeRow(),
            };

            items.push(item);
            list!.appendChild(item.row);
            renderRow(item);
            sync();
            uploadItem(item);
        });
    }

    // A placeholder renderRow() replaces with the theme's row.
    function makeRow(): HTMLElement {
        return document.createElement("div");
    }

    // Already-stored attachments (forums.topic-edit only) - "done" from the
    // start, with no File and no upload to run, so getUploadedIds() reports
    // them straight away and a save that doesn't touch this card round-trips
    // the same ids back.
    existing.slice(0, MAX_FILES).forEach((row) => {
        const item: AttachmentItem = {
            key: ++seq,
            file: null,
            name: row.name,
            sizeLabel: row.sizeLabel,
            icon: row.icon,
            status: "done",
            uploadId: row.id,
            xhr: null,
            row: makeRow(),
        };

        items.push(item);
        list.appendChild(item.row);
        renderRow(item);
    });

    drop.addEventListener("click", () => input.click());
    drop.addEventListener("dragover", (event) => {
        event.preventDefault();
        drop.classList.add("is-dragover");
    });
    drop.addEventListener("dragleave", () => drop.classList.remove("is-dragover"));
    drop.addEventListener("drop", (event) => {
        event.preventDefault();
        drop.classList.remove("is-dragover");
        if (event.dataTransfer?.files?.length) addFiles(event.dataTransfer.files);
    });
    input.addEventListener("change", () => {
        if (input.files?.length) addFiles(input.files);
        input.value = "";
    });

    sync();

    return {
        getUploadedIds(): number[] {
            return items
                .filter((item) => item.status === "done" && item.uploadId !== null)
                .map((item) => item.uploadId as number);
        },
        hasPending(): boolean {
            return items.some((item) => item.status === "uploading");
        },
        // initTopicPreview()'s own read - every file currently in the list
        // (not just "done" ones, unlike getUploadedIds() above), since a
        // preview is just showing what the author has picked, not what's
        // already safely stored.
        getPreviewRows(): { name: string; sizeLabel: string; icon: string }[] {
            return items.map((item) => ({
                name: item.name,
                sizeLabel: item.sizeLabel,
                icon: item.icon,
            }));
        },
    };
}

function pollDurationLabel(days: number): string {
    if (days === 7) return trans("js.forums.poll_duration_7");
    if (days === 14) return trans("js.forums.poll_duration_14");
    if (days === 30) return trans("js.forums.poll_duration_30");
    return trans("js.forums.poll_duration_unlimited");
}

type TopicPreviewSource = {
    getTitle: () => string;
    getContentHtml: () => string;
    hasContent: () => boolean;
    getPoll: () => { question: string; options: string[]; durationDays: number } | null;
    getAttachments: () => { name: string; sizeLabel: string; icon: string }[];
};

// forums.topic-new's preview button - renders the form's *current*
// state (title/Trix body/poll/attachments) into #topicPreviewModal, no
// server round trip. $source's getters read live off the same form fields
// initTopicForm() already wired (pollOpen, attachments, etc.) rather than
// this function keeping its own duplicate state.
//
// Not a byte-identical preview of the published post: contentHtml is
// whatever Trix's own editor serialized, not the HTMLPurifier-cleaned HTML
// ForumsController::handleTopicCreateRequest() will actually store (see
// forums.topic-form.twig's own comment on this same modal) - close enough
// for "does this look right", not a guarantee.
function initTopicPreview(form: HTMLFormElement, source: TopicPreviewSource) {
    const button = document.getElementById("btnPreviewTopic") as HTMLButtonElement | null;
    const modalEl = document.getElementById("topicPreviewModal");
    const body = document.getElementById("topicPreviewBody");
    if (!button || !modalEl || !body) return;

    const authorName = form.dataset.authorName || "";
    const authorAvatar = form.dataset.authorAvatar || "/assets/img/default-avatar.svg";

    // Bootstrap sets aria-hidden="true" on the modal root as soon as it
    // starts hiding (Escape, backdrop click, or the header's own .btn-close,
    // which is what's usually still focused at that exact moment since it's
    // what the user just clicked) - the browser then blocks it and logs
    // "Blocked aria-hidden on an element because its descendant retained
    // focus" because a focused element would become invisible to assistive
    // tech. Blurring whatever's focused *inside* the modal before Bootstrap
    // applies aria-hidden (hide.bs.modal fires first, aria-hidden only lands
    // on the later 'hidden.bs.modal'/transition end) avoids the warning
    // without fighting Bootstrap's own focus-return behavior.
    modalEl.addEventListener("hide.bs.modal", () => {
        const active = document.activeElement as HTMLElement | null;
        if (active && modalEl.contains(active)) active.blur();
    });

    button.addEventListener("click", () => {
        const title = source.getTitle();
        const contentHtml = source.hasContent() ? source.getContentHtml() : "";
        const poll = source.getPoll();
        const attachmentRows = source.getAttachments();

        const preview = ui.clone("forum-topic-preview");
        if (!preview) return;

        ui.fill(preview, {
            avatar: authorAvatar,
            authorName,
            title: title || trans("js.forums.title_empty"),
            titleEmpty: title ? "" : "1",
            contentHtml: contentHtml || cms.escapeHtml(trans("js.forums.content_empty")),
            contentEmpty: contentHtml ? "" : "1",
            hasAttachments: attachmentRows.length ? "1" : "",
            hasPoll: poll && poll.question ? "1" : "",
            pollQuestion: poll?.question ?? "",
            pollOptionsEmpty: poll && !poll.options.length ? trans("js.forums.poll_options_empty") : "",
            pollDuration: poll ? pollDurationLabel(poll.durationDays) : "",
        });

        const attachmentsWrap = preview.querySelector("[data-preview-attachments]");
        attachmentRows.forEach((row) => {
            const el = ui.clone("forum-preview-attachment");
            if (el && attachmentsWrap) {
                attachmentsWrap.appendChild(ui.fill(el, { iconClass: `bi ${row.icon}`, name: row.name, size: row.sizeLabel }));
            }
        });

        const optionsWrap = preview.querySelector("[data-preview-poll-options]");
        (poll?.options ?? []).forEach((option) => {
            const el = ui.clone("forum-preview-poll-option");
            if (el && optionsWrap) optionsWrap.appendChild(ui.fill(el, { text: option }));
        });

        body.replaceChildren(preview);

        ui.modal.open(modalEl);
    });
}

// The topic form - title + Trix body + an optional poll builder - serving
// both of forums.topic-form.twig's modes: POST /api/v1/forums/topics to
// create (forums.topic-new, see ForumsController::
// handleTopicCreateRequest()) and PATCH /api/v1/forums/topics/{id} to save
// (forums.topic-edit, see handleTopicUpdateRequest()). The mode comes from
// the form's own data-api-method/data-api-url rather than from a separate
// flag, so there's one code path here and the endpoint shape is decided
// server-side.
//
// Mirrors blog-post-form.js's own shape (word count, Trix attachment upload
// wired to the same generic /api/v1/uploads endpoint) but with no cover
// image/tags/track - a topic has none of those - plus the poll card blog
// posts don't have at all.
function initTopicForm() {
    const form = document.querySelector<HTMLFormElement>("[data-forum-topic-form]");
    if (!form) return;

    const apiUrl = form.dataset.apiUrl;
    const uploadsApiUrl = form.dataset.uploadsApiUrl;
    const forumId = form.dataset.forumId ? Number(form.dataset.forumId) : 0;
    if (!apiUrl || !uploadsApiUrl || !forumId) return;

    const apiMethod = (form.dataset.apiMethod || "POST").toUpperCase();
    const isEdit = apiMethod === "PATCH";

    // Server-rendered prefill (edit mode only). Both are plain JSON in a
    // data-* attribute rather than inline <script> - see
    // ForumsController::buildTopicFormViewModel() for what's in them. A
    // malformed value degrades to "no prefill" instead of taking the whole
    // form down with it.
    function readJsonDataset<T>(value: string | undefined, fallback: T): T {
        if (!value) return fallback;
        try {
            return JSON.parse(value) as T;
        } catch (e) {
            return fallback;
        }
    }

    // "Someone already voted, so this poll is frozen" - the card is rendered
    // read-only server-side, and ForumsController::replaceTopicPoll() ignores
    // the field regardless; this just stops the client sending a `poll` at all
    // so the two can't appear to disagree.
    const pollLocked = form.dataset.pollLocked === "1";
    const initialPoll = readJsonDataset<{ question: string; options: string[]; multiple: boolean; durationDays: number } | null>(
        form.dataset.poll,
        null,
    );
    const initialAttachments = readJsonDataset<{ id: number; name: string; sizeLabel: string; icon: string }[]>(
        form.dataset.attachmentsExisting,
        [],
    );

    const titleInput = document.getElementById("topicTitle") as HTMLInputElement | null;
    const titleCountEl = document.getElementById("topicTitleCount");
    const titleErrorEl = form.querySelector<HTMLElement>("[data-topic-title-error]");
    const contentInput = document.getElementById("topicBody") as HTMLInputElement | null;
    const contentEditor = document.getElementById("topicBodyEditor") as (HTMLElement & { editor?: any }) | null;
    const wordCountEl = document.getElementById("topicWordCount");
    const formErrorEl = form.querySelector("[data-topic-form-error]");
    const subscribeInput = document.getElementById("topicSubscribe") as HTMLInputElement | null;
    const btnCreate = document.getElementById("btnCreateTopic") as HTMLButtonElement | null;
    const btnCreateLabel = document.getElementById("btnCreateTopicLabel");

    if (!titleInput || !contentInput || !contentEditor || !btnCreate) return;

    /* ---------- title counter ---------- */
    const updateTitleCount = () => {
        if (titleCountEl) titleCountEl.textContent = `${titleInput.value.length} / 200`;
    };
    titleInput.addEventListener("input", updateTitleCount);
    updateTitleCount();

    /* ---------- word count ---------- */
    function editorPlainText(): string {
        return (contentEditor!.editor ? contentEditor!.editor.getDocument().toString() : contentEditor!.textContent || "").trim();
    }

    const updateWordCount = () => {
        if (!wordCountEl) return;
        const text = editorPlainText();
        const words = text ? text.split(/\s+/).filter(Boolean).length : 0;
        wordCountEl.textContent = transChoiceWithCount("js.common.word", words);
    };
    contentEditor.addEventListener("trix-change", updateWordCount);
    updateWordCount();

    /* ---------- inline attachments (images pasted/dropped into the body) ---------- */
    contentEditor.addEventListener("trix-attachment-add", ((event: CustomEvent) => {
        const attachment = (event as any).attachment;
        if (!attachment.file) return;

        uploadFile(uploadsApiUrl, attachment.file)
            .then((result) => attachment.setAttributes({ url: result.url, href: result.url }))
            .catch((error) => {
                setFormError(getApiErrorMessage(error, trans("js.blog.attachment_failed")));
                attachment.remove();
            });
    }) as EventListener);

    function setFormError(message = "") {
        if (formErrorEl) formErrorEl.textContent = message;
    }

    function setTitleError(message = "") {
        if (!titleErrorEl) return;
        titleErrorEl.textContent = message;
        titleErrorEl.hidden = message === "";
        if (message === "") titleInput!.removeAttribute("aria-invalid");
        else titleInput!.setAttribute("aria-invalid", "true");
    }

    /* ---------- poll card ---------- */
    const pollToggles = form.querySelectorAll<HTMLButtonElement>("[data-poll-toggle]");
    const pollBody = form.querySelector<HTMLElement>("[data-poll-body]");
    const pollHint = form.querySelector("[data-poll-hint]");
    const pollQuestion = document.getElementById("pollQuestion") as HTMLInputElement | null;
    const pollOptionsWrap = form.querySelector<HTMLElement>("[data-poll-options]");
    const pollAddOption = form.querySelector<HTMLButtonElement>("[data-poll-add-option]");
    const pollOptionCount = form.querySelector("[data-poll-option-count]");
    const pollMultiple = document.getElementById("pollMultiple") as HTMLInputElement | null;
    const pollDuration = document.getElementById("pollDuration") as HTMLSelectElement | null;

    const MAX_POLL_OPTIONS = 10;
    let pollOpen = false;

    function pollOptionInputs(): HTMLInputElement[] {
        return pollOptionsWrap ? Array.from(pollOptionsWrap.querySelectorAll("[data-poll-option-text]")) : [];
    }

    // Mirrors initAttachments()'s own dropzone-hides-itself-at-the-cap
    // treatment (see its sync()) - disabling the add-option action at the cap
    // instead of leaving it clickable-but-silently-doing-nothing.
    function renderPollOptionCount() {
        const count = pollOptionInputs().length;
        if (pollOptionCount) pollOptionCount.textContent = trans("js.forums.poll_options_count", { count, max: MAX_POLL_OPTIONS });
        if (pollAddOption) pollAddOption.disabled = count >= MAX_POLL_OPTIONS;
    }

    function addPollOption(prefill = "") {
        if (!pollOptionsWrap || pollOptionInputs().length >= MAX_POLL_OPTIONS) return;

        const row = ui.clone("forum-poll-option");
        if (!row) return;
        // Assigned rather than put into the markup: .value skips HTML
        // parsing, so "&quot;" or "&amp;" in an option round-trips as typed.
        if (prefill !== "") {
            const input = row.querySelector<HTMLInputElement>("[data-poll-option-text]");
            if (input) input.value = prefill;
        }
        row.querySelector("[data-poll-option-remove]")?.addEventListener("click", () => {
            row.remove();
            renumberPollOptions();
        });
        pollOptionsWrap.appendChild(row);
        renumberPollOptions();
    }

    function renumberPollOptions() {
        pollOptionsWrap?.querySelectorAll<HTMLElement>("[data-poll-option-number]").forEach((badge, index) => {
            badge.textContent = String(index + 1);
        });
        renderPollOptionCount();
    }

    function setPollOpen(open: boolean) {
        pollOpen = open;
        if (pollBody) pollBody.hidden = !open;
        pollToggles.forEach((btn) => {
            const icon = btn.querySelector("i");
            if (icon) icon.className = open ? "bi bi-chevron-down" : "bi bi-chevron-right";
            const label = btn.querySelector("span");
            if (label) {
                label.textContent = trans(open ? "js.forums.poll_remove" : "js.forums.poll_add");
            } else {
                // The icon-only chevron toggle (no visible <span> label)
                // carries its own title instead - forums.topic-form.twig's
                // the static expand-poll label was never updated on expand, so
                // it stayed stale/misleading once the card was already open.
                btn.title = trans(open ? "js.forums.poll_collapse" : "js.forums.poll_expand");
            }
        });
        if (pollHint) {
            pollHint.textContent = open
                ? trans("js.forums.poll_position_open")
                : trans("js.forums.poll_position_closed");
        }
    }

    pollToggles.forEach((btn) => btn.addEventListener("click", () => setPollOpen(!pollOpen)));
    pollAddOption?.addEventListener("click", () => addPollOption());

    if (initialPoll && !pollLocked) {
        // Existing, still-unvoted poll: reopen the card on exactly the four
        // values buildPollFormState() flattened it into, so a save that
        // doesn't touch it recreates the same poll (delete-then-create - see
        // ForumsController::replaceTopicPoll() for why that's the shape).
        if (pollQuestion) pollQuestion.value = initialPoll.question;
        initialPoll.options.forEach((option) => addPollOption(option));
        if (pollMultiple) pollMultiple.checked = initialPoll.multiple;
        if (pollDuration) pollDuration.value = String(initialPoll.durationDays);
        setPollOpen(true);
    } else if (!pollLocked) {
        // Three empty options to start, matching the design mockup's default.
        addPollOption();
        addPollOption();
        addPollOption();
    }

    /* ---------- attachments ---------- */
    const attachments = initAttachments(form, uploadsApiUrl, setFormError, initialAttachments);

    function collectPollPayload(): { question: string; options: string[]; multiple: boolean; durationDays: number } | null {
        if (pollLocked || !pollOpen) return null;

        const question = pollQuestion?.value.trim() || "";
        const options = pollOptionInputs()
            .map((input) => input.value.trim())
            .filter((value) => value !== "");

        if (question === "" && options.length === 0) return null;

        return {
            question,
            options,
            multiple: !!pollMultiple?.checked,
            durationDays: pollDuration ? Number(pollDuration.value) : 0,
        };
    }

    /* ---------- preview ---------- */
    initTopicPreview(form, {
        getTitle: () => titleInput.value.trim(),
        getContentHtml: () => contentInput.value.trim(),
        hasContent: () => !!editorPlainText() || /<figure[ >]/i.test(contentInput.value.trim()),
        getPoll: () => (pollOpen
            ? {
                question: pollQuestion?.value.trim() || "",
                options: pollOptionInputs().map((input) => input.value.trim()).filter((value) => value !== ""),
                durationDays: pollDuration ? Number(pollDuration.value) : 0,
            }
            : null),
        getAttachments: () => attachments.getPreviewRows(),
    });

    /* ---------- submit ---------- */
    btnCreate.addEventListener("click", async () => {
        const title = titleInput.value.trim();
        const content = contentInput.value.trim();
        const hasAttachment = /<figure[ >]/i.test(content);

        setTitleError();
        setFormError();

        if (!title) {
            setTitleError(trans("js.forums.topic_title_required"));
            titleInput.focus();
            return;
        }

        if (!editorPlainText() && !hasAttachment) {
            setFormError(trans("js.forums.topic_content_required"));
            contentEditor.focus();
            return;
        }

        const poll = collectPollPayload();
        if (poll && (poll.question === "" || poll.options.length < 2)) {
            setFormError(trans("js.forums.poll_incomplete"));
            return;
        }

        // PollService::createPoll() rejects duplicates ('poll.duplicate_options')
        // and ForumsController::normalizePollInput() reads them as "unusable",
        // which on a create means the topic is published with no poll and on an
        // edit means the save quietly keeps the *old* poll - either way a
        // success response that didn't do what the author asked. Cheaper to
        // catch here, where it can be pointed at.
        if (poll && new Set(poll.options).size !== poll.options.length) {
            setFormError(trans("js.forums.poll_duplicates"));
            return;
        }

        if (attachments.hasPending()) {
            setFormError(trans("js.forums.uploads_pending"));
            return;
        }

        btnCreate.disabled = true;
        const originalLabel = btnCreateLabel?.textContent ?? trans(isEdit ? "js.forums.save" : "js.forums.create_topic");
        if (btnCreateLabel) btnCreateLabel.textContent = trans(isEdit ? "js.common.saving_ellipsis" : "js.forums.publishing");

        // forumId/subscribe are create-only: a PATCH can't move a topic
        // between sections and doesn't touch who follows it (see
        // ForumsController::handleTopicUpdateRequest()'s own docblock), so
        // they're left out rather than sent and silently ignored.
        const payload: Record<string, unknown> = {
            title,
            content,
            poll,
            attachments: attachments.getUploadedIds(),
        };
        if (!isEdit) {
            payload.forumId = forumId;
            payload.subscribe = !!subscribeInput?.checked;
        }

        try {
            const topic = await cms.api(apiUrl, { method: apiMethod, data: payload });

            cms.toast({ message: trans(isEdit ? "js.forums.topic_updated" : "js.forums.topic_created"), type: "success" });

            setTimeout(() => {
                if (topic.canonicalUrl) {
                    window.location.href = topic.canonicalUrl;
                } else {
                    window.location.reload();
                }
            }, 600);
        } catch (error) {
            setFormError(getApiErrorMessage(error, trans(isEdit ? "js.forums.topic_save_failed" : "js.forums.topic_create_failed")));
            btnCreate.disabled = false;
            if (btnCreateLabel) btnCreateLabel.textContent = originalLabel;
        }
    });
}

document.addEventListener("DOMContentLoaded", () => {
    initForumReplyEditors();
    initQuickReplyForm();
    initPostActions();
    initMarkTopicRead();
    initMarkAllRead();
    initTopicForm();
    initPoll();
});

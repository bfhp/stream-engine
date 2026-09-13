import "trix";
import "trix/dist/trix.css";
import { uploadFile } from "../shared/uploads";
import { bytesToLabel } from "../shared/bytes";
import { getApiErrorMessage } from "../shared/api-errors";
import { initCoverWidget } from "../shared/cover-widget";
import { trans, transChoiceWithCount } from "../shared/i18n";

/**
 * Trix ships no types, so the two pieces of it this file touches are declared
 * here rather than pulled in: the editor element's `editor` handle, and the
 * attachment carried by a `trix-attachment-add` event. Both are the documented
 * public surface - see basecamp/trix - and neither is worth a dependency.
 */
type TrixAttachment = {
    file?: File;
    setAttributes(attributes: Record<string, string>): void;
    remove(): void;
};

type TrixAttachmentEvent = Event & { attachment: TrixAttachment };

type TrixEditorElement = HTMLElement & {
    editor?: { getDocument(): { toString(): string } };
};

(function () {
    const form = document.querySelector<HTMLElement>('[data-blog-post-form]');

    if (!form) return;

    const apiUrl = form.dataset.apiUrl;
    const uploadsApiUrl = form.dataset.uploadsApiUrl;
    // PATCH (edit page, apiUrl already points at /blog-posts/{id}) vs POST
    // (create page) - the edit-vs-create split for toast/redirect behavior
    // below all keys off this same flag.
    const method = form.dataset.method === 'PATCH' ? 'PATCH' : 'POST';
    const isEdit = method === 'PATCH';

    if (!apiUrl || !uploadsApiUrl) return;

    const cms = window.CMS;

    const titleInput = document.getElementById('postTitle') as HTMLInputElement | null;
    const contentInput = document.getElementById('postContent') as HTMLInputElement | null;
    const contentEditor = document.getElementById('postContentEditor') as TrixEditorElement | null;
    const wordCountEl = document.getElementById('wordCount');
    const errorEl = document.querySelector('[data-blog-post-error]');

    const visibilitySelect = document.getElementById('postVisibility') as HTMLSelectElement | null;
    const visibilityNote = document.getElementById('visibilityNote');

    const btnPublish = document.getElementById('btnPublish') as HTMLButtonElement | null;
    const btnDraft = document.getElementById('btnDraft') as HTMLButtonElement | null;

    const coverSlot = document.querySelector<HTMLElement>('[data-cover-slot]');
    const coverUrlInput = document.getElementById('coverImageUrl') as HTMLInputElement | null;

    const trackSlot = document.querySelector<HTMLElement>('[data-track-slot]');
    const trackEmpty = document.querySelector<HTMLElement>('[data-track-empty]');
    const trackAddBtn = document.querySelector<HTMLElement>('[data-track-add]');
    const trackFileInput = document.querySelector<HTMLInputElement>('[data-track-file]');
    const trackIdInput = document.getElementById('trackUploadId') as HTMLInputElement | null;

    const tagWrap = document.querySelector<HTMLElement>('[data-tag-wrap]');
    const tagField = document.querySelector<HTMLInputElement>('[data-tag-field]');

    if (!titleInput || !contentInput || !contentEditor || !visibilitySelect || !btnPublish || !btnDraft) return;

    let tags: string[] = [];
    if (isEdit && form.dataset.initialTags) {
        try {
            const parsed = JSON.parse(form.dataset.initialTags);
            if (Array.isArray(parsed)) tags = parsed.filter((t) => typeof t === 'string');
        } catch (e) {
            // Malformed data-initial-tags shouldn't break the rest of the form.
        }
    }

    function editorPlainText(): string {
        return (contentEditor.editor ? contentEditor.editor.getDocument().toString() : contentEditor.textContent || '').trim();
    }

    /* ---------- word count ---------- */
    const updateWordCount = () => {
        if (!wordCountEl) return;
        const text = editorPlainText();
        const words = text ? text.split(/\s+/).filter(Boolean).length : 0;
        wordCountEl.textContent = transChoiceWithCount('js.common.word', words);
    };
    contentEditor.addEventListener('trix-change', updateWordCount);
    updateWordCount();

    const setError = (message = '') => {
        if (!errorEl) return;
        errorEl.textContent = message;
    };

    /* ---------- attachments ---------- */
    contentEditor.addEventListener('trix-attachment-add', (event) => {
        const attachment = (event as TrixAttachmentEvent).attachment;
        if (!attachment.file) return;

        uploadFile(uploadsApiUrl, attachment.file)
            .then((result) => {
                attachment.setAttributes({url: result.url, href: result.url});
            })
            .catch((error) => {
                setError(getApiErrorMessage(error, trans('js.blog.attachment_failed')));
                attachment.remove();
            });
    });

    /* ---------- visibility note ---------- */
    // 'members' reads differently depending on where the post lives -
    // community.post-new (isCommunityPost, see blog-post-form.twig) sets
    // data-visibility-context="community" so this note (and the select's
    // own option label - see the twig) says community members instead of
    // friends. Everything else about the form is identical either way.
    const isCommunityPost = form.dataset.visibilityContext === 'community';
    const VISIBILITY_NOTES = {
        public: ['bi-globe', trans('js.blog.visibility_public')],
        members: isCommunityPost
            ? ['bi-people', trans('js.blog.visibility_community')]
            : ['bi-people', trans('js.blog.visibility_friends')],
    };
    const syncVisibilityNote = () => {
        if (!visibilityNote) return;
        const note = VISIBILITY_NOTES[visibilitySelect.value as keyof typeof VISIBILITY_NOTES]
            ?? VISIBILITY_NOTES.public;
        visibilityNote.innerHTML = `<i class="bi ${note[0]} me-1"></i>${note[1]}`;
    };
    visibilitySelect.addEventListener('change', syncVisibilityNote);
    syncVisibilityNote();

    /* ---------- cover image ---------- */
    initCoverWidget({
        slot: coverSlot,
        urlInput: coverUrlInput,
        uploadsApiUrl,
        prompt: trans('js.cover.post_prompt'),
        onError: setError,
    });

    /* ---------- track ---------- */
    function clearTrack(): void {
        if (!trackSlot || !trackIdInput || !trackEmpty || !trackAddBtn || !trackFileInput) return;

        trackIdInput.value = '';
        trackSlot.innerHTML = '';
        trackEmpty.classList.remove('d-none');
        trackAddBtn.classList.remove('d-none');
        trackFileInput.value = '';
    }

    function renderTrack(name: string, size: number, id: number): void {
        if (!trackSlot || !trackIdInput || !trackEmpty || !trackAddBtn || !trackFileInput) return;

        const safeName = name.replace(/[<>&]/g, '');

        trackIdInput.value = String(id);
        trackEmpty.classList.add('d-none');
        trackAddBtn.classList.add('d-none');
        trackSlot.innerHTML = `
            <div class="blog-post-track-row">
                <span class="blog-post-track-play"><i class="bi bi-music-note"></i></span>
                <div class="blog-post-track-meta">
                    <div class="blog-post-track-name">${safeName}</div>
                    <div class="blog-post-track-sub">${bytesToLabel(size)}</div>
                </div>
                <button type="button" class="blog-post-track-remove" aria-label="${trans('js.common.remove_aria')}"><i class="bi bi-x-lg"></i></button>
            </div>
        `;
        trackSlot.querySelector('.blog-post-track-remove')?.addEventListener('click', clearTrack);
    }

    trackAddBtn?.addEventListener('click', () => trackFileInput?.click());
    trackFileInput?.addEventListener('change', async () => {
        const file = trackFileInput.files?.[0];
        if (!file) return;

        try {
            const result = await uploadFile(uploadsApiUrl, file);
            renderTrack(file.name, file.size, result.id);
        } catch (error) {
            setError(getApiErrorMessage(error, trans('js.blog.track_failed')));
        }
    });

    // Same reasoning as the cover image above: a server-rendered existing
    // track (edit mode) needs its remove button wired on load too.
    trackSlot?.querySelector('.blog-post-track-remove')?.addEventListener('click', clearTrack);

    /* ---------- tags ---------- */
    function renderTags(): void {
        if (!tagWrap || !tagField) return;

        tagWrap.querySelectorAll('.blog-post-tag-chip').forEach((chip) => chip.remove());

        tags.forEach((tag, index) => {
            const chip = document.createElement('span');
            chip.className = 'blog-post-tag-chip';
            chip.innerHTML = `#${tag.replace(/[<>&]/g, '')} <button type="button" aria-label="${trans('js.common.remove_aria')}">&times;</button>`;
            chip.querySelector('button')?.addEventListener('click', () => {
                tags.splice(index, 1);
                renderTags();
            });
            tagWrap.insertBefore(chip, tagField);
        });
    }

    tagField?.addEventListener('keydown', (event: KeyboardEvent) => {
        if (!tagField) return;

        if ((event.key === 'Enter' || event.key === ',') && tagField.value.trim()) {
            event.preventDefault();
            const value = tagField.value.trim().replace(/^#/, '');

            if (value && tags.length < 8 && !tags.some((t) => t.toLowerCase() === value.toLowerCase())) {
                tags.push(value);
            }

            tagField.value = '';
            renderTags();
        } else if (event.key === 'Backspace' && !tagField.value && tags.length) {
            tags.pop();
            renderTags();
        }
    });

    renderTags(); // draws any tags prefilled from data-initial-tags (edit mode)

    /* ---------- submit ---------- */
    async function submit(visibility: string, button: HTMLButtonElement, isDraft: boolean): Promise<void> {
        if (!titleInput || !contentInput || !btnPublish || !btnDraft) return;

        const title = titleInput.value.trim();
        const content = contentInput.value.trim();
        const hasAttachment = /<figure[ >]/i.test(content);

        if (!title) {
            setError(trans('js.blog.title_required'));
            titleInput.focus();
            return;
        }

        if (!editorPlainText() && !hasAttachment) {
            setError(trans('js.blog.content_required'));
            contentEditor.focus();
            return;
        }

        setError();
        btnPublish.disabled = true;
        btnDraft.disabled = true;
        const originalHtml = button.innerHTML;
        button.innerHTML = `<span class="spinner-border spinner-border-sm me-2"></span>${trans('js.common.saving')}`;

        try {
            const post = await cms.api<{ canonicalUrl?: string | null }>(apiUrl, {
                method,
                data: {
                    title,
                    content,
                    visibility,
                    imageUrl: coverUrlInput?.value || null,
                    trackUploadId: trackIdInput?.value ? Number(trackIdInput.value) : null,
                    tags,
                },
            });

            cms.toast({
                message: trans(isDraft ? 'js.blog.draft_saved' : 'js.blog.published'),
                type: 'success',
            });

            // Editing a draft: nothing to navigate to - the form already
            // shows the saved state, so just leave the person on this page.
            if (isDraft && isEdit) {
                btnPublish.disabled = false;
                btnDraft.disabled = false;
                button.innerHTML = originalHtml;
                return;
            }

            // Publishing (either page) goes to the post itself. Saving a
            // brand-new post as a draft has no "post itself" to show yet
            // (it's unpublished) - go to its edit page instead, one level
            // under the post's own canonical URL (see UsersController's
            // user.post-edit routing: {slug}/edit/).
            const redirectUrl = isDraft && post.canonicalUrl
                ? post.canonicalUrl + 'edit/'
                : post.canonicalUrl;

            // Same 800ms delay as users.ts's initBlogPostDelete() toast
            // before navigating away, so the toast is actually visible.
            setTimeout(() => {
                if (redirectUrl) {
                    window.location.href = redirectUrl;
                } else {
                    window.location.reload();
                }
            }, 800);
        } catch (error) {
            setError(getApiErrorMessage(error, trans('js.blog.save_failed')));
            btnPublish.disabled = false;
            btnDraft.disabled = false;
            button.innerHTML = originalHtml;
        }
    }

    btnPublish.addEventListener('click', () => submit(visibilitySelect.value, btnPublish, false));
    btnDraft.addEventListener('click', () => submit('private', btnDraft, true));
})();

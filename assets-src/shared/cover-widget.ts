/* ==========================================================================
   Cover-image drop zone

   The little "add a picture / here is your picture / remove it" widget that
   appears on the blog-post form, the community-create form and the
   community-manage form. It was copied verbatim into all three, and the
   copies had already drifted:

   - two of them bound the remove button inside `renderCoverFile()`, so a
     cover the *server* rendered had no working remove button; the third
     bound it in the rewiring step, which handles both cases;
   - the blog-post copy patched that with an extra line at init, wiring the
     pre-rendered remove button separately - a fix the other two never got;
   - the prompt text differs between posts and communities, which is the
     only difference that was ever intended.

   This is the third copy's shape - rewire after every render - because it is
   the one that is right in all three situations. The prompt stays an option.
   ========================================================================== */

import { uploadFile } from "./uploads";
import { getApiErrorMessage } from "./api-errors";
import { trans } from "./i18n";

export type CoverWidgetOptions = {
    /** The container the widget owns and re-renders. */
    slot: HTMLElement | null;
    /** Hidden input carrying the uploaded URL into the form's payload. */
    urlInput: HTMLInputElement | null;
    uploadsApiUrl: string;
    /** A post- or community-specific localized prompt. */
    prompt: string;
    /** Where an upload failure is shown - each form has its own error slot. */
    onError: (message: string) => void;
};

/**
 * Wires the slot up and leaves whatever is in it alone.
 *
 * Deliberately not a render: on an edit page the server has already put the
 * "file" variant in the slot, and re-rendering the empty drop zone would
 * throw the existing cover away on load.
 */
export function initCoverWidget(options: CoverWidgetOptions): void {
    const { slot, urlInput, uploadsApiUrl, prompt, onError } = options;

    if (!slot || !urlInput) return;

    function renderDrop(): void {
        slot!.innerHTML = `
            <div class="blog-post-cover-drop" data-cover-drop>
                <i class="bi bi-card-image fs-4"></i>
                <div>
                    <div>${prompt}</div>
                    <div class="small text-body-secondary">${trans('js.cover.formats')}</div>
                </div>
            </div>
            <input type="file" class="d-none" accept="image/*" data-cover-file>
        `;
        urlInput!.value = '';
        wire();
    }

    function renderFile(url: string, name: string): void {
        // Delete-don't-escape, as in the original: `"` and `'` survive it.
        // Safe here because the name lands in text content rather than in an
        // attribute - the URL beside it comes from our own upload endpoint.
        const safeName = name.replace(/[<>&]/g, '');

        slot!.innerHTML = `
            <div class="blog-post-cover-file">
                <div class="blog-post-cover-thumb" style="background-image:url('${url}')"></div>
                <div class="blog-post-cover-fileinfo">
                    <div class="blog-post-cover-filename">${safeName}</div>
                </div>
                <button type="button" class="blog-post-cover-remove" aria-label="${trans('js.common.remove_aria')}"><i class="bi bi-x-lg"></i></button>
            </div>
        `;
        urlInput!.value = url;
        wire();
    }

    /**
     * Re-binds after every render, and covers both variants at once - which
     * is what makes a server-rendered cover removable without the extra
     * init-time line two of the three copies needed.
     */
    function wire(): void {
        const drop = slot!.querySelector<HTMLElement>('[data-cover-drop]');
        const fileInput = slot!.querySelector<HTMLInputElement>('[data-cover-file]');
        const removeBtn = slot!.querySelector<HTMLElement>('.blog-post-cover-remove');

        drop?.addEventListener('click', () => fileInput?.click());

        fileInput?.addEventListener('change', async () => {
            const file = fileInput.files?.[0];
            if (!file) return;

            try {
                const result = await uploadFile(uploadsApiUrl, file);
                renderFile(result.url, file.name);
            } catch (error) {
                onError(getApiErrorMessage(error, trans('js.cover.upload_failed')));
            }
        });

        removeBtn?.addEventListener('click', renderDrop);
    }

    wire();
}

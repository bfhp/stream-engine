import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ui, MODAL_CLOSED } from '../../assets-src/shared/ui';
import { initAlbumManager } from '../../assets-src/pages/album-manager';
import { resetTranslations, setTranslationsForTests } from '../../assets-src/shared/i18n';

let dispose: (() => void) | undefined;
const api = vi.fn();
const button = (selector: string) => document.querySelector<HTMLButtonElement>(selector)!;
const cards = () => Array.from(document.querySelectorAll<HTMLElement>('[data-album-grid] [data-photo-card]'));
const order = () => cards().map(card => card.dataset.slug);
const flush = async () => { await new Promise(resolve => setTimeout(resolve, 0)); };
function card(slug: string) {
    return `<figure data-photo-card data-slug="${slug}" data-title="Photo ${slug}" data-caption="Caption ${slug}">
        <input type="checkbox" data-photo-select><span data-photo-cover-badge hidden></span>
        <a data-photo-link><img alt="Photo ${slug}"></a><a data-photo-name>Photo ${slug}</a>
        <button data-photo-up>Up</button><button data-photo-down>Down</button><button data-photo-set-cover>Cover</button><button data-photo-edit>Edit</button>
        <div data-photo-details hidden><input data-photo-title value="Photo ${slug}"><textarea data-photo-caption>Caption ${slug}</textarea>
        <button data-photo-save>Save photo</button><button data-photo-cancel>Cancel</button></div></figure>`;
}
beforeEach(() => {
    api.mockReset().mockResolvedValue({});
    vi.spyOn(ui.modal, 'open').mockImplementation(el => { el.hidden = false; });
    vi.spyOn(ui.modal, 'close').mockImplementation(el => { el.hidden = true; el.dispatchEvent(new CustomEvent(MODAL_CLOSED)); });
    Object.assign(window, { CMS: { api, confirm: ({ onConfirm }: { onConfirm: () => Promise<void> }) => onConfirm() } });
    setTranslationsForTests({ 'js.common.action_failed': 'Failed', 'js.photos.saved': 'Saved' });
    document.body.innerHTML = `<section data-album-manager data-api-url="/api/album" data-photos-api-url="/api/album/photos">
        <h1 data-album-heading>Summer</h1><p data-album-description>Description</p>
        <button data-album-settings-open>Settings</button>
        <div data-album-settings hidden><form data-album-settings-form>
        <input data-album-title value="Summer" required maxlength="255"><textarea data-album-description-input>Description</textarea><input type="radio" name="visibility" data-album-visibility value="public" checked><input type="radio" name="visibility" data-album-visibility value="private">
        <p data-album-settings-error></p><button type="button" data-photo-album-delete>Delete album</button><button type="submit" data-album-settings-save disabled>Save settings</button><button type="button" data-album-settings-cancel>Cancel</button></form></div>
        <button data-album-save-order disabled>Save order</button><button data-album-select>Select</button><button data-album-reorder>Reorder</button><button data-album-cancel-order hidden>Reset</button>
        <div data-album-selection hidden><input type="checkbox" data-album-select-all><span data-album-selection-count></span>
        <button data-album-move="start">Start</button><button data-album-move="end">End</button><input data-album-position value="1"><button data-album-move="position">Move</button>
        <button data-album-delete-selected>Delete</button></div><p data-album-status></p>
        <div data-album-grid>${card('1')}${card('2')}${card('3')}</div><template data-photo-card-template>${card('')}</template></section>`;
    dispose = initAlbumManager();
});
afterEach(() => { dispose?.(); resetTranslations(); document.body.innerHTML = ''; });

it('saves album parameters independently of the pending photo order', async () => {
    button('[data-album-settings-open]').click();
    const title = document.querySelector<HTMLInputElement>('[data-album-title]')!;
    title.value = 'New title'; title.dispatchEvent(new Event('input', { bubbles: true }));
    button('[data-photo-down]').click();
    document.querySelector<HTMLFormElement>('[data-album-settings-form]')!.requestSubmit(); await flush();
    expect(api).toHaveBeenCalledWith('/api/album', { method: 'PATCH', data: { title: 'New title', description: 'Description', visibility: 'public' } });
    expect(document.querySelector<HTMLElement>('[data-album-settings]')!.hidden).toBe(true);
    expect(document.querySelector('[data-album-heading]')!.textContent).toBe('New title');
    expect(button('[data-album-save-order]').disabled).toBe(false);
    button('[data-album-save-order]').click(); await flush();
    expect(api).toHaveBeenLastCalledWith('/api/album', { method: 'PATCH', data: { order: ['2', '1', '3'] } });
    expect(button('[data-album-save-order]').disabled).toBe(true);
});
it('moves a selection to a numbered position and can restore saved order', () => {
    button('[data-album-reorder]').click();
    cards()[0].querySelector<HTMLInputElement>('[data-photo-select]')!.click();
    document.querySelector<HTMLInputElement>('[data-album-position]')!.value = '3';
    button('[data-album-move="position"]').click();
    expect(order()).toEqual(['2', '3', '1']);
    button('[data-album-cancel-order]').click();
    expect(order()).toEqual(['1', '2', '3']);
    expect(button('[data-album-save-order]').disabled).toBe(true);
});
it('keeps cards and selection after a failed deletion so it can be retried', async () => {
    button('[data-album-select]').click();
    cards()[0].querySelector<HTMLInputElement>('[data-photo-select]')!.click();
    api.mockRejectedValueOnce(new Error('Failed'));
    button('[data-album-delete-selected]').click(); await flush();
    expect(order()).toEqual(['1', '2', '3']);
    expect(cards()[0].querySelector<HTMLInputElement>('[data-photo-select]')!.checked).toBe(true);
    expect(button('[data-album-delete-selected]').disabled).toBe(false);
    button('[data-album-delete-selected]').click(); await flush();
    expect(order()).toEqual(['2', '3']);
    expect(api).toHaveBeenLastCalledWith('/api/album/photos', { method: 'DELETE', data: { photos: ['1'] } });
    expect(button('[data-album-save-order]').disabled).toBe(true);
});
it('adds uploaded photographs without losing unsaved title or order', () => {
    button('[data-photo-down]').click();
    const root = document.querySelector('[data-album-manager]')!;
    const payload = { slug: '4', title: '<New photo>', caption: 'Caption', previewUrl: '/preview/4', redirectUrl: '/photos/4' };
    root.dispatchEvent(new CustomEvent('photo-uploaded', { detail: payload }));
    root.dispatchEvent(new CustomEvent('photo-uploaded', { detail: payload }));
    expect(order()).toEqual(['2', '1', '3', '4']);
    expect(cards()[3].querySelector('[data-photo-name]')!.textContent).toBe('<New photo>');
    expect(cards()[3].querySelector('[data-photo-name]')!.children).toHaveLength(0);
    button('[data-album-cancel-order]').click();
    expect(order()).toEqual(['1', '2', '3', '4']);
});
it('saves captions and covers immediately while preserving album drafts', async () => {
    button('[data-photo-edit]').click();
    const caption = cards()[0].querySelector<HTMLTextAreaElement>('[data-photo-caption]')!;
    caption.value = 'New caption';
    button('[data-photo-save]').click(); await flush();
    expect(api).toHaveBeenLastCalledWith('/api/album', { method: 'PATCH', data: { photo: { slug: '1', title: 'Photo 1', caption: 'New caption' } } });
    expect(cards()[0].dataset.caption).toBe('New caption');
    button('[data-photo-set-cover]').click(); await flush();
    expect(api).toHaveBeenLastCalledWith('/api/album', { method: 'PATCH', data: { cover: '1' } });
    expect(cards()[0].querySelector<HTMLElement>('[data-photo-cover-badge]')!.hidden).toBe(false);
    expect(button('[data-album-save-order]').disabled).toBe(true);
});
it('warns before leaving with unsaved changes and stops after successful save', async () => {
    button('[data-photo-down]').click();
    expect(window.dispatchEvent(new Event('beforeunload', { cancelable: true }))).toBe(false);
    button('[data-album-save-order]').click(); await flush();
    expect(window.dispatchEvent(new Event('beforeunload', { cancelable: true }))).toBe(true);
});

it('discards settings drafts on cancel without losing the photo order', () => {
    button('[data-photo-down]').click();
    button('[data-album-settings-open]').click();
    const title = document.querySelector<HTMLInputElement>('[data-album-title]')!;
    title.value = 'Discard me';
    document.querySelector<HTMLInputElement>('[data-album-visibility][value="private"]')!.click();
    button('[data-album-settings-cancel]').click();
    expect(title.value).toBe('Summer');
    expect(document.querySelector<HTMLInputElement>('[data-album-visibility]:checked')!.value).toBe('public');
    expect(order()).toEqual(['2', '1', '3']);
    expect(api).not.toHaveBeenCalled();
});
it('retains settings drafts and errors after a failed save and allows retry', async () => {
    button('[data-album-settings-open]').click();
    const title = document.querySelector<HTMLInputElement>('[data-album-title]')!;
    title.value = 'Draft'; title.dispatchEvent(new Event('input', { bubbles: true }));
    api.mockRejectedValueOnce(new Error('Failed'));
    document.querySelector<HTMLFormElement>('[data-album-settings-form]')!.requestSubmit(); await flush();
    expect(title.value).toBe('Draft');
    expect(document.querySelector<HTMLElement>('[data-album-settings]')!.hidden).toBe(false);
    expect(document.querySelector('[data-album-settings-error]')!.textContent).toBe('Failed');
    expect(button('[data-album-settings-save]').disabled).toBe(false);
    document.querySelector<HTMLFormElement>('[data-album-settings-form]')!.requestSubmit(); await flush();
    expect(document.querySelector<HTMLElement>('[data-album-settings]')!.hidden).toBe(true);
    expect(document.querySelector('[data-album-heading]')!.textContent).toBe('Draft');
});
it('discards settings drafts on Escape or backdrop closure', () => {
    const title = document.querySelector<HTMLInputElement>('[data-album-title]')!;
    title.value = 'Discard me';
    document.querySelector('[data-album-settings]')!.dispatchEvent(new CustomEvent(MODAL_CLOSED));
    expect(title.value).toBe('Summer');
    expect(window.dispatchEvent(new Event('beforeunload', { cancelable: true }))).toBe(true);
});
it('validates required album fields before sending settings', async () => {
    button('[data-album-settings-open]').click();
    document.querySelector<HTMLInputElement>('[data-album-title]')!.value = '';
    document.querySelector<HTMLFormElement>('[data-album-settings-form]')!.requestSubmit(); await flush();
    expect(api).not.toHaveBeenCalled();
    expect(document.querySelector<HTMLElement>('[data-album-settings]')!.hidden).toBe(false);
});

it('closes album settings before opening the destructive confirmation', () => {
    const confirm = vi.spyOn(window.CMS, 'confirm').mockImplementation(() => {});
    button('[data-album-settings-open]').click();
    button('[data-photo-album-delete]').click();
    expect(document.querySelector<HTMLElement>('[data-album-settings]')!.hidden).toBe(true);
    expect(confirm).toHaveBeenCalledOnce();
    expect(api).not.toHaveBeenCalled();
});

it('replaces upload slots in place and saves their order without committing an existing draft', async () => {
    button('[data-photo-down]').click();
    const root = document.querySelector('[data-album-manager]')!;
    const grid = root.querySelector('[data-album-grid]')!;
    for (const requestId of ['first', 'second']) {
        const placeholder = document.createElement('figure'); placeholder.dataset.photoPlaceholder = ''; placeholder.dataset.requestId = requestId; grid.append(placeholder);
    }
    root.dispatchEvent(new Event('photo-queue-changed', { bubbles: true }));
    expect(button('[data-album-reorder]').disabled).toBe(true);
    for (const [requestId, slug] of [['second', '4'], ['first', '5']]) {
        root.dispatchEvent(new CustomEvent('photo-uploaded', { detail: { requestId, slug, title: slug, caption: '', previewUrl: `/preview/${slug}`, redirectUrl: `/photo/${slug}` } }));
        root.dispatchEvent(new Event('photo-queue-changed', { bubbles: true }));
    }
    await flush();
    expect(order()).toEqual(['2', '1', '3', '5', '4']);
    expect(api).toHaveBeenCalledExactlyOnceWith('/api/album', { method: 'PATCH', data: { order: ['1', '2', '3', '5', '4'] } });
    expect(button('[data-album-save-order]').disabled).toBe(false);
    button('[data-album-save-order]').click(); await flush();
    expect(api).toHaveBeenLastCalledWith('/api/album', { method: 'PATCH', data: { order: ['2', '1', '3', '5', '4'] } });
});

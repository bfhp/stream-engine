import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { initPhotoActions } from '../../assets-src/pages/photo-actions';
import { ui, MODAL_CLOSED } from '../../assets-src/shared/ui';
import { resetTranslations, setTranslationsForTests } from '../../assets-src/shared/i18n';

const request = vi.fn(); const changed = vi.fn(); const removed = vi.fn(); const confirm = vi.fn(); const toast = vi.fn();
let actions: ReturnType<typeof initPhotoActions>;
const flush = async () => { await new Promise(resolve => setTimeout(resolve, 0)); };
const card = (slug: string) => document.querySelector<HTMLElement>(`[data-photo-card][data-slug="${slug}"]`)!;
const button = (selector: string) => document.querySelector<HTMLButtonElement>(selector)!;
const field = (selector: string) => document.querySelector<HTMLInputElement | HTMLTextAreaElement>(selector)!;
function change(selector: string, value: string) { const input = field(selector); input.value = value; input.dispatchEvent(new Event('input', { bubbles: true })); }
function open(slug = '1') { card(slug).querySelector<HTMLButtonElement>('[data-photo-menu-toggle]')!.click(); }
function edit(slug = '1') { open(slug); card(slug).querySelector<HTMLButtonElement>('[data-photo-edit]')!.click(); }
function photo(slug: string) {
    return `<figure data-photo-card data-slug="${slug}" data-title="Photo ${slug}" data-caption="Caption ${slug}">
        <img src="/preview/${slug}" alt="Photo ${slug}"><a data-photo-name>Photo ${slug}</a><input type="checkbox" data-photo-select>
        <span data-photo-cover-badge ${slug === '1' ? '' : 'hidden'}></span>
        <div class="users-album-photo__menu"><button data-photo-menu-toggle aria-expanded="false">⋯</button>
        <div data-photo-menu role="menu" hidden><button role="menuitem" data-photo-edit>Edit</button><button role="menuitem" data-photo-set-cover>Cover</button><button role="menuitem" data-photo-delete>Delete</button></div></div></figure>`;
}
beforeEach(() => {
    request.mockReset().mockResolvedValue({}); changed.mockReset(); removed.mockReset(); confirm.mockReset(); toast.mockReset();
    Object.assign(window, { CMS: { confirm, toast } });
    vi.spyOn(ui.modal, 'open').mockImplementation(el => { el.hidden = false; });
    vi.spyOn(ui.modal, 'close').mockImplementation(el => { el.hidden = true; el.dispatchEvent(new CustomEvent(MODAL_CLOSED)); });
    setTranslationsForTests({ 'js.common.action_failed': 'Failed', 'js.photos.saved': 'Saved', 'js.photos.save_next': 'Save and next', 'js.photos.next_photo': 'Next', 'js.photos.album_cover': 'Album cover', 'js.photos.set_cover': 'Set as cover', 'js.photos.photo_position': '{position}/{total}' });
    document.body.innerHTML = `<section data-album-manager data-photos-api-url="/api/album/photos"><div data-album-grid>${photo('1')}${photo('2')}${photo('3')}</div>
        <div data-photo-editor hidden><form data-photo-editor-form><img data-photo-editor-preview>
        <input data-photo-editor-name required maxlength="255"><textarea data-photo-editor-caption maxlength="2000"></textarea><p data-photo-editor-error></p><p data-photo-editor-status></p>
        <button type="button" data-photo-editor-prev>Previous</button><span data-photo-editor-position></span><button type="button" data-photo-editor-next>Next</button>
        <button type="button" data-photo-editor-close>Cancel</button><button type="submit" data-photo-editor-save>Save</button></form></div></section>`;
    actions = initPhotoActions(document.querySelector('[data-album-manager]')!, { request, changed, removed, isBusy: () => false });
});
afterEach(() => { actions?.dispose(); resetTranslations(); document.body.innerHTML = ''; });

it('opens one compact menu at a time, skips the current cover, and closes with Escape', () => {
    open(); expect(card('1').querySelector<HTMLElement>('[data-photo-menu]')!.hidden).toBe(false);
    expect(document.activeElement).toBe(card('1').querySelector('[data-photo-edit]'));
    expect(card('1').querySelector<HTMLButtonElement>('[data-photo-set-cover]')!.disabled).toBe(true);
    expect(card('1').querySelector('[data-photo-set-cover]')!.textContent).toBe('Album cover');
    document.activeElement!.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
    expect(document.activeElement).toBe(card('1').querySelector('[data-photo-delete]'));
    document.activeElement!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    expect(card('1').querySelector<HTMLElement>('[data-photo-menu]')!.hidden).toBe(true);
    expect(document.activeElement).toBe(card('1').querySelector('[data-photo-menu-toggle]'));
    open('1'); open('2');
    expect(card('1').querySelector<HTMLElement>('[data-photo-menu]')!.hidden).toBe(true);
    window.dispatchEvent(new Event('scroll'));
    expect(card('2').querySelector<HTMLElement>('[data-photo-menu]')!.hidden).toBe(false);
    document.body.click(); expect(card('2').querySelector<HTMLElement>('[data-photo-menu]')!.hidden).toBe(true);
});
it('edits in a dialog and updates the card without adding inline fields', async () => {
    edit(); expect(field('[data-photo-editor-name]').value).toBe('Photo 1');
    expect(button('[data-photo-editor-prev]').disabled).toBe(true);
    change('[data-photo-editor-name]', 'New title'); change('[data-photo-editor-caption]', 'New caption');
    expect(actions!.hasDraft()).toBe(true);
    document.querySelector<HTMLFormElement>('[data-photo-editor-form]')!.requestSubmit(); await flush();
    expect(request).toHaveBeenCalledExactlyOnceWith({ photo: { slug: '1', title: 'New title', caption: 'New caption' } });
    expect(card('1').dataset.caption).toBe('New caption'); expect(card('1').querySelector('[data-photo-name]')!.textContent).toBe('New title');
    expect(card('1').querySelector('img')!.alt).toBe('New title'); expect(card('1').querySelector('input')!.getAttribute('aria-label')).toContain('New title');
    expect(actions!.hasDraft()).toBe(false); expect(button('[data-photo-editor-save]').disabled).toBe(true);
    expect(document.querySelector<HTMLElement>('[data-photo-editor]')!.hidden).toBe(false);
    expect(card('1').querySelector('textarea')).toBeNull();
});
it('saves before moving to the next photo, retaining drafts and position on an error', async () => {
    edit(); change('[data-photo-editor-caption]', 'Draft');
    expect(button('[data-photo-editor-next]').textContent).toBe('Save and next');
    request.mockRejectedValueOnce(new Error('Failed'));
    button('[data-photo-editor-next]').click(); await flush();
    expect(field('[data-photo-editor-caption]').value).toBe('Draft'); expect(field('[data-photo-editor-name]').value).toBe('Photo 1');
    expect(document.querySelector('[data-photo-editor-error]')!.textContent).toBe('Failed');
    button('[data-photo-editor-next]').click(); await flush();
    expect(card('1').dataset.caption).toBe('Draft'); expect(field('[data-photo-editor-name]').value).toBe('Photo 2');
    expect(document.querySelector('[data-photo-editor-position]')!.textContent).toBe('2/3');
    button('[data-photo-editor-prev]').click(); await flush(); expect(field('[data-photo-editor-caption]').value).toBe('Draft');
});
it('discards unsaved changes on close and restores focus to the photo actions button', () => {
    edit(); change('[data-photo-editor-caption]', 'Discard'); button('[data-photo-editor-close]').click();
    expect(actions!.hasDraft()).toBe(false); expect(request).not.toHaveBeenCalled();
    expect(document.activeElement).toBe(card('1').querySelector('[data-photo-menu-toggle]'));
    edit(); expect(field('[data-photo-editor-caption]').value).toBe('Caption 1');
});
it('sets an album cover and updates all cover markers and menu labels', async () => {
    open('2'); card('2').querySelector<HTMLButtonElement>('[data-photo-set-cover]')!.click(); await flush();
    expect(request).toHaveBeenCalledExactlyOnceWith({ cover: '2' });
    expect(card('1').querySelector<HTMLElement>('[data-photo-cover-badge]')!.hidden).toBe(true);
    expect(card('2').querySelector<HTMLElement>('[data-photo-cover-badge]')!.hidden).toBe(false);
    expect(card('2').querySelector<HTMLButtonElement>('[data-photo-set-cover]')!.disabled).toBe(true);
    expect(card('1').querySelector<HTMLButtonElement>('[data-photo-set-cover]')!.disabled).toBe(false);
    expect(changed).toHaveBeenCalledOnce();
});
it('deletes only the confirmed photo and keeps it available after a failed request', async () => {
    open('2'); card('2').querySelector<HTMLButtonElement>('[data-photo-delete]')!.click();
    expect(request).not.toHaveBeenCalled(); expect(card('2')).not.toBeNull();
    request.mockRejectedValueOnce(new Error('Failed'));
    await confirm.mock.calls[0][0].onConfirm(); expect(card('2')).not.toBeNull(); expect(removed).not.toHaveBeenCalled();
    open('2'); card('2').querySelector<HTMLButtonElement>('[data-photo-delete]')!.click(); await confirm.mock.calls[1][0].onConfirm();
    expect(request).toHaveBeenLastCalledWith({ photos: ['2'] }, 'DELETE', '/api/album/photos');
    expect(card('2')).toBeNull(); expect(removed).toHaveBeenCalledExactlyOnceWith('2');
});

it('refreshes navigation as uploads arrive without losing the current caption draft', () => {
    edit('3'); change('[data-photo-editor-caption]', 'Still editing');
    expect(button('[data-photo-editor-next]').disabled).toBe(true);
    document.querySelector('[data-album-grid]')!.insertAdjacentHTML('beforeend', photo('4'));
    document.querySelector('[data-album-manager]')!.dispatchEvent(new Event('photo-queue-changed', { bubbles: true }));
    expect(button('[data-photo-editor-next]').disabled).toBe(false);
    expect(button('[data-photo-editor-next]').textContent).toBe('Save and next');
    expect(field('[data-photo-editor-caption]').value).toBe('Still editing');
    expect(document.querySelector('[data-photo-editor-position]')!.textContent).toBe('3/4');
});

import { expect, it, vi } from 'vitest';
import { initPhotoViewer } from '../../assets-src/pages/photo-viewer';

it('opens real URLs, navigates, and restores the album and opener on Back', async () => {
    history.replaceState(null, '', '/album/?offset=20');
    document.body.innerHTML = '<a data-photo-open href="/photo/1/">Photo</a>';
    vi.stubGlobal('scrollTo', vi.fn());
    Object.defineProperty(window, 'scrollY', { configurable: true, value: 123 });
    HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); };
    HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); this.dispatchEvent(new Event('close')); };
    const html = (n: number) => `<section data-photo-viewer aria-label="Photo ${n}"><a data-photo-viewer-close href="/album/">Close</a><img data-photo-viewer-image src="/image/${n}"><a data-photo-viewer-nav="next" href="/photo/2/">Next</a></section>`;
    const fetch = vi.fn().mockResolvedValueOnce({ ok: true, text: async () => html(1) }).mockResolvedValueOnce({ ok: true, text: async () => html(2) });
    vi.stubGlobal('fetch', fetch);
    initPhotoViewer();
    const opener = document.querySelector<HTMLAnchorElement>('[data-photo-open]')!;
    opener.dispatchEvent(new MouseEvent('click', { button: 0, bubbles: true, cancelable: true }));
    await vi.waitFor(() => expect(location.pathname).toBe('/photo/1/'));
    expect(document.querySelector('dialog[open]')).not.toBeNull();
    expect(fetch.mock.calls[0][1]).toEqual(expect.objectContaining({ cache: 'no-store', credentials: 'same-origin' }));
    document.querySelector<HTMLAnchorElement>('[data-photo-viewer-nav]')!.click();
    await vi.waitFor(() => expect(location.pathname).toBe('/photo/2/'));
    history.back();
    await vi.waitFor(() => expect(document.querySelector('dialog')).toBeNull());
    expect(location.pathname).toBe('/album/'); expect(location.search).toBe('?offset=20');
    expect(document.activeElement).toBe(opener);
    expect(window.scrollTo).toHaveBeenLastCalledWith({ top: 123, behavior: 'instant' });
    vi.unstubAllGlobals();
});

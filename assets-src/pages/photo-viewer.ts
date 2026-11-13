import { ui } from '../shared/ui';
/** Progressive viewer: real photo URLs work without JavaScript or from a shared link. */
export function initPhotoViewer(): void {
    let viewer = document.querySelector<HTMLElement>('[data-photo-viewer]');
    let dialog: HTMLDialogElement | null = null;
    let opener: HTMLElement | null = null;
    let albumUrl = location.href;
    let albumScroll = 0;
    let request: AbortController | null = null;
    let touch: { id: number; x: number; y: number } | null = null;
    const direct = viewer !== null;
    const showLogin = () => { const auth = document.getElementById('authFormModal'); if (auth) ui.modal.open(auth); };
    if (direct && new URL(location.href).searchParams.get('login') === '1' && viewer?.querySelector('[data-photo-login]')) showLogin();
    const safeUrl = (value: string) => { const url = new URL(value, location.href); if (url.origin !== location.origin) throw new Error('Invalid photo URL'); return url; };
    const preload = () => {
        const preview = viewer?.querySelector<HTMLElement>('[data-photo-preview]')?.dataset.photoPreview;
        if (preview) { const next = new Image(); next.decoding = 'async'; next.src = safeUrl(preview).href; }
    };
    const close = () => {
        request?.abort(); request = null;
        if (!dialog) return;
        dialog.close(); dialog.remove(); dialog = null; viewer = null;
        document.body.classList.remove('photo-viewer-open');
        opener?.focus({ preventScroll: true }); window.scrollTo({ top: albumScroll, behavior: 'instant' });
    };
    const load = async (href: string, mode: 'open' | 'navigate' | 'pop') => {
        const url = safeUrl(href); request?.abort(); const controller = new AbortController(); request = controller;
        viewer?.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
            if (!response.ok) throw new Error('Photo unavailable');
            const parsed = new DOMParser().parseFromString(await response.text(), 'text/html');
            const fragment = parsed.querySelector<HTMLElement>('[data-photo-viewer]');
            if (!fragment) throw new Error('Photo unavailable');
            if (controller.signal.aborted) return;
            if (!direct && !dialog) {
                dialog = document.createElement('dialog'); dialog.className = 'users-photo-viewer-dialog';
                dialog.setAttribute('aria-label', fragment.getAttribute('aria-label') ?? ''); document.body.append(dialog);
                dialog.addEventListener('cancel', event => { event.preventDefault(); history.back(); });
                const openedDialog = dialog;
                dialog.addEventListener('close', () => { if (openedDialog.isConnected && history.state?.photoViewer) history.back(); });
                dialog.append(fragment); dialog.showModal(); document.body.classList.add('photo-viewer-open');
            } else viewer?.replaceWith(fragment);
            viewer = fragment; preload();
            if (dialog) dialog.setAttribute('aria-label', fragment.getAttribute('aria-label') ?? '');
            if (mode === 'open') history.pushState({ photoViewer: true, albumUrl, albumScroll }, '', url);
            else if (mode === 'navigate') history.replaceState(history.state, '', url);
            viewer.querySelector<HTMLElement>('[data-photo-viewer-close]')?.focus({ preventScroll: true });
        } catch (error) {
            if (!controller.signal.aborted) location.assign(url.href);
        } finally { if (request === controller) { request = null; viewer?.removeAttribute('aria-busy'); } }
    };
    const navigate = (direction: string) => {
        const link = viewer?.querySelector<HTMLAnchorElement>(`[data-photo-viewer-nav="${direction}"]`);
        if (link) void load(link.href, 'navigate');
    };
    document.addEventListener('click', event => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.altKey || event.shiftKey) return;
        const target = (event.target as HTMLElement).closest<HTMLAnchorElement>('a');
        if (!target) return;
        if (target.matches('[data-photo-login]') && !dialog) { event.preventDefault(); showLogin(); return; }
        if (target.matches('[data-photo-open]') && !direct) {
            if (typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) return;
            event.preventDefault(); opener = target; albumUrl = location.href; albumScroll = window.scrollY; void load(target.href, 'open');
        } else if (target.matches('[data-photo-viewer-nav]')) { event.preventDefault(); void load(target.href, 'navigate'); }
        else if (target.matches('[data-photo-viewer-close]') && dialog) { event.preventDefault(); history.back(); }
    });
    window.addEventListener('popstate', () => {
        if (!direct && history.state?.photoViewer && history.state.albumUrl === albumUrl) void load(location.href, 'pop');
        else if (!direct) close();
    });
    document.addEventListener('keydown', event => {
        if (!viewer || (event.target as HTMLElement).closest('input, textarea, select, [contenteditable="true"], [data-photo-rating]') || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') { event.preventDefault(); navigate(event.key === 'ArrowLeft' ? 'previous' : 'next'); }
        if (event.key === 'Escape' && !dialog) { const link = viewer.querySelector<HTMLAnchorElement>('[data-photo-viewer-close]'); if (link) location.assign(link.href); }
    });
    document.addEventListener('pointerdown', event => { if (event.pointerType !== 'touch') return; if (!event.isPrimary) { touch = null; return; } if ((event.target as HTMLElement).matches('[data-photo-viewer-image]')) touch = { id: event.pointerId, x: event.clientX, y: event.clientY }; });
    document.addEventListener('pointerup', event => { if (!touch || event.pointerId !== touch.id) return; const dx = event.clientX - touch.x; const dy = event.clientY - touch.y; touch = null; if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy) * 1.3) navigate(dx < 0 ? 'next' : 'previous'); });
    document.addEventListener('pointercancel', () => { touch = null; });
    preload();
}

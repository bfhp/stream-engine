import { initPhotoActions } from './photo-actions';
import { trans } from '../shared/i18n';
import { ui } from '../shared/ui';
import { getApiErrorMessage } from '../shared/api-errors';

export function initAlbumManager(root: HTMLElement | null = document.querySelector('[data-album-manager]')): (() => void) | undefined {
    if (!root || root.dataset.ready === 'true') return;
    root.dataset.ready = 'true';
    const cms = window.CMS;
    const grid = root.querySelector<HTMLElement>('[data-album-grid]')!;
    const toolbar = root.querySelector<HTMLElement>('[data-album-toolbar]');
    const title = root.querySelector<HTMLInputElement>('[data-album-title]')!;
    const description = root.querySelector<HTMLTextAreaElement>('[data-album-description-input]')!;
    const settings = root.querySelector<HTMLElement>('[data-album-settings]')!;
    const settingsForm = root.querySelector<HTMLFormElement>('[data-album-settings-form]')!;
    const settingsSave = root.querySelector<HTMLButtonElement>('[data-album-settings-save]')!;
    const settingsError = root.querySelector<HTMLElement>('[data-album-settings-error]')!;
    const orderSave = root.querySelector<HTMLButtonElement>('[data-album-save-order]')!;
    const setStatus = (message: string) => {
        root.querySelectorAll<HTMLElement>('[data-album-status]').forEach(status => status.textContent = message);
    };
    const fields = () => ({ title: title.value, description: description.value, visibility: root.querySelector<HTMLInputElement>('[data-album-visibility]:checked')!.value });
    const cards = () => Array.from(grid.querySelectorAll<HTMLElement>('[data-photo-card]'));
    const order = () => cards().map(card => card.dataset.slug!);
    const selected = () => cards().filter(card => card.querySelector<HTMLInputElement>('[data-photo-select]')!.checked);
    let originalFields = JSON.stringify(fields());
    let originalOrder = order();
    let selecting = false;
    let reordering = false;
    let busy = false;
    let deleted = false;
    let stopDeleteClose: (() => void) | undefined;
    let pointer: { card: HTMLElement; id: number; startX: number; startY: number; x: number; y: number; active: boolean; snapshot: string[] } | null = null;
    let scrollFrame = 0;
    let wasUploading = false;
    let disposed = false;
    let syncingUploads = false;
    let uploadSyncPending = false;
    const uploadedSlugs = new Set<string>();
    const hasPendingUploads = () => root.querySelector('[data-photo-placeholder]') !== null;
    const fieldsDirty = () => JSON.stringify(fields()) !== originalFields;
    const orderDirty = () => JSON.stringify(order()) !== JSON.stringify(originalOrder);
    const dirty = () => fieldsDirty() || orderDirty();
    const restoreFields = () => {
        const saved = JSON.parse(originalFields) as ReturnType<typeof fields>;
        title.value = saved.title; description.value = saved.description;
        root.querySelectorAll<HTMLInputElement>('[data-album-visibility]').forEach(input => input.checked = input.value === saved.visibility);
        settingsError.textContent = ''; update();
    };
    const update = () => {
        settingsSave.disabled = busy || !fieldsDirty();
        const uploading = hasPendingUploads();
        orderSave.disabled = busy || uploading || !orderDirty();
        root.dataset.dirty = String(dirty());
        const orderActions = root.querySelector<HTMLElement>('[data-album-order-actions]');
        const showingOrder = !selecting && !uploading && !syncingUploads && (reordering || orderDirty());
        if (orderActions) { orderActions.hidden = !showingOrder; orderActions.inert = !showingOrder; }
        settings.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLButtonElement>('input, textarea, [data-album-settings-cancel]').forEach(control => control.disabled = busy);
        const photoCount = cards().length;
        const counter = root.querySelector('[data-album-photo-count]');
        if (counter) counter.textContent = String(photoCount);
        const empty = root.querySelector<HTMLElement>('[data-album-empty]');
        if (empty) empty.hidden = photoCount > 0 || uploading;
        const selectButton = root.querySelector<HTMLButtonElement>('[data-album-select]');
        if (selectButton) selectButton.disabled = busy || photoCount === 0;
        const reorderButton = root.querySelector<HTMLButtonElement>('[data-album-reorder]');
        if (reorderButton) reorderButton.disabled = busy || uploading || photoCount < 2;
        const cancelOrder = root.querySelector<HTMLButtonElement>('[data-album-cancel-order]');
        if (cancelOrder) cancelOrder.disabled = busy || uploading;
        root.querySelectorAll<HTMLButtonElement>('[data-photo-album-delete]').forEach(button => button.disabled = busy);
        root.dataset.selecting = String(selecting);
        root.dataset.reordering = String(reordering);
        const hint = root.querySelector<HTMLElement>('[data-album-reorder-hint]');
        if (hint) { hint.hidden = !reordering; hint.setAttribute('aria-hidden', String(!reordering)); }
        for (const card of cards()) {
            if (reordering) { card.tabIndex = 0; card.setAttribute('aria-describedby', 'album-reorder-hint'); }
            else { card.removeAttribute('tabindex'); card.removeAttribute('aria-describedby'); }
            card.querySelectorAll<HTMLAnchorElement>('a').forEach(link => { link.draggable = false; if (reordering) link.tabIndex = -1; else link.removeAttribute('tabindex'); });
        }
        const selection = root.querySelector<HTMLElement>('[data-album-selection]')!;
        selection.hidden = !selecting; selection.inert = !selecting;
        const standardActions = root.querySelector<HTMLElement>('[data-album-standard-actions]');
        if (standardActions) {
            standardActions.hidden = selecting || showingOrder;
            standardActions.inert = selecting || showingOrder;
        }
        const count = selected().length;
        root.querySelector('[data-album-selection-count]')!.textContent = trans('js.photos.selected', { count });
        root.querySelectorAll<HTMLButtonElement>('[data-album-delete-selected]').forEach(button => button.disabled = busy || count === 0);
        root.querySelector('[data-album-select]')?.setAttribute('aria-pressed', String(selecting));
        root.querySelector('[data-album-reorder]')?.setAttribute('aria-pressed', String(reordering));
        const cancel = root.querySelector<HTMLElement>('[data-album-cancel-order]');
        if (cancel) { cancel.hidden = !reordering; cancel.inert = !reordering; }
        const all = root.querySelector<HTMLInputElement>('[data-album-select-all]')!;
        all.checked = count > 0 && count === cards().length;
        all.indeterminate = count > 0 && count < cards().length;
    };
    const api = async (data: unknown, method: 'PATCH' | 'DELETE' = 'PATCH', url = root.dataset.apiUrl!, errorTarget: 'page' | 'album' | 'photo' = 'page') => {
        busy = true; if (errorTarget === 'album') settingsError.textContent = ''; else if (errorTarget === 'page') setStatus(''); update();
        try { return await cms.api<{ redirectUrl?: string }>(url, { method, data }); }
        catch (error) {
            const message = getApiErrorMessage(error, trans('js.common.action_failed'));
            if (errorTarget === 'album') settingsError.textContent = message; else if (errorTarget === 'page') setStatus(message);
            throw error;
        }
        finally { busy = false; update(); setTimeout(() => { void syncUploadOrder(); }, 0); }
    };
    const syncUploadOrder = async () => {
        if (disposed || !uploadSyncPending || busy || syncingUploads || hasPendingUploads()) return;
        uploadSyncPending = false;
        const present = order();
        const target = [...originalOrder.filter(slug => !uploadedSlugs.has(slug)), ...present.filter(slug => uploadedSlugs.has(slug))];
        if (JSON.stringify(target) === JSON.stringify(originalOrder)) return;
        syncingUploads = true;
        try {
            await api({ order: target });
            originalOrder = [...target, ...originalOrder.filter(slug => !target.includes(slug))];
        } catch { /* Keep Save order available if synchronization failed. */ }
        finally { syncingUploads = false; update(); void syncUploadOrder(); }
    };
    const queueChanged = () => {
        const uploading = hasPendingUploads();
        if (uploading && !wasUploading) { uploadedSlugs.clear(); uploadSyncPending = false; }
        wasUploading = uploading; update(); void syncUploadOrder();
    };
    const saveSettings = async () => {
        if (!title.reportValidity() || !description.reportValidity()) return;
        const data = fields();
        await api(data, 'PATCH', root.dataset.apiUrl!, 'album');
        originalFields = JSON.stringify(data);
        root.querySelector('[data-album-heading]')!.textContent = data.title;
        const summary = root.querySelector<HTMLElement>('[data-album-description]')!;
        summary.textContent = data.description; summary.hidden = !data.description;
        setStatus(trans('js.photos.saved')); update();
        ui.modal.close(settings);
    };
    const saveOrder = async () => {
        const sentOrder = order();
        await api({ order: sentOrder });
        originalOrder = [...sentOrder, ...order().filter(slug => !sentOrder.includes(slug))];
        reordering = false; uploadedSlugs.clear(); uploadSyncPending = false;
        setStatus(trans('js.photos.saved')); update();
    };
    const submitSettings = (event: Event) => {
        event.preventDefault();
        if (!busy && fieldsDirty()) void saveSettings().catch(() => {});
    };
    const preventBusyClose = (event: Event) => {
        if (busy) event.preventDefault();
    };
    const preventBusyBackdrop = (event: Event) => {
        if (busy && event.target === settings) { event.preventDefault(); event.stopImmediatePropagation(); }
    };
    const stopClosed = ui.modal.onClosed(settings, () => { if (!busy) restoreFields(); });
    let modeFocusVersion = 0;
    const focusAfterModeChange = (target: HTMLElement | null | undefined) => {
        const version = ++modeFocusVersion;
        const previousFocus = document.activeElement;
        // Wait for hidden/inert panels to settle, without stealing a newer focus choice.
        requestAnimationFrame(() => requestAnimationFrame(() => {
            if (disposed || version !== modeFocusVersion || !target?.isConnected || target.closest('[hidden], [inert]')) return;
            const active = document.activeElement;
            if (active !== previousFocus && active !== document.body && active !== document.documentElement) return;
            target.focus({ preventScroll: true });
        }));
    };
    const click = (event: Event) => {
        const target = event.target as HTMLElement;
        if (reordering && target.closest('[data-photo-card] a')) event.preventDefault();
        const button = target.closest<HTMLButtonElement>('button');
        if (!button || button.disabled || busy) return;
        if (button.matches('[data-photo-album-delete]')) {
            stopDeleteClose?.();
            stopDeleteClose = ui.modal.onClosed(settings, () => {
                stopDeleteClose?.(); stopDeleteClose = undefined;
                cms.confirm({ title: trans('js.photos.delete_title'), message: trans('js.photos.delete_confirm'), onConfirm: async () => {
                    if (busy) return;
                    try {
                        const result = await api(undefined, 'DELETE');
                        deleted = true;
                        window.location.assign(result.redirectUrl!);
                    } catch { /* Keep the page available for retry. */ }
                } });
            });
            ui.modal.close(settings);
            return;
        }
        if (button.matches('[data-album-settings-open]')) { settingsError.textContent = ''; ui.modal.open(settings); return; }
        if (button.matches('[data-album-settings-cancel]')) { restoreFields(); ui.modal.close(settings); return; }
        if (button.matches('[data-album-save-order]')) { void saveOrder().catch(() => {}); return; }
        if (button.matches('[data-open-upload]')) {
            root.querySelector<HTMLInputElement>('[data-photo-files]')?.click();
        }
        if (button.matches('[data-album-select]')) {
            selecting = !selecting; reordering = false; update();
            focusAfterModeChange(root.querySelector<HTMLElement>('[data-album-cancel-selection]')); return;
        }
        if (button.matches('[data-album-cancel-selection]')) {
            selecting = false;
            cards().forEach(card => card.querySelector<HTMLInputElement>('[data-photo-select]')!.checked = false);
            update(); focusAfterModeChange(root.querySelector<HTMLElement>('[data-album-select]')); return;
        }
        if (button.matches('[data-album-reorder]')) { reordering = true; selecting = false; update(); focusAfterModeChange(cards()[0]); return; }
        if (button.matches('[data-album-cancel-order]')) {
            const bySlug = new Map(cards().map(card => [card.dataset.slug, card]));
            for (const slug of originalOrder) { const card = bySlug.get(slug); if (card) grid.append(card); }
            reordering = false; update(); focusAfterModeChange(root.querySelector<HTMLElement>('[data-album-reorder]')); return;
        }
        if (button.matches('[data-album-delete-selected]')) {
            const chosen = selected(); const slugs = chosen.map(card => card.dataset.slug!);
            if (!slugs.length) return;
            cms.confirm({ title: trans('js.photos.delete_selected'), message: trans('js.photos.delete_count', { count: slugs.length }), onConfirm: async () => {
                if (busy) return;
                try {
                    await api({ photos: slugs }, 'DELETE', root.dataset.photosApiUrl!);
                    chosen.forEach(card => card.remove());
                    originalOrder = originalOrder.filter(slug => !slugs.includes(slug));
                    if (cards().length === 0) selecting = false;
                    setStatus(trans('js.photos.deleted_count', { count: slugs.length })); update();
                } catch { /* Keep selection for retry. */ }
            } });
        }
        update();
    };
    const change = (event: Event) => {
        if ((event.target as HTMLElement).matches('[data-album-select-all]')) {
            const checked = (event.target as HTMLInputElement).checked;
            cards().forEach(card => card.querySelector<HTMLInputElement>('[data-photo-select]')!.checked = checked);
        }
        update();
    };
    const uploaded = (event: Event) => {
        const data = (event as CustomEvent).detail;
        if (!data || typeof data.slug !== 'string') return;
        const placeholder = Array.from(root.querySelectorAll<HTMLElement>('[data-photo-placeholder]')).find(item => item.dataset.requestId === data.requestId);
        if (cards().some(card => card.dataset.slug === data.slug)) { placeholder?.remove(); update(); return; }
        const template = root.querySelector<HTMLTemplateElement>('[data-photo-card-template]')!;
        const card = template.content.firstElementChild!.cloneNode(true) as HTMLElement;
        card.dataset.slug = data.slug; card.dataset.title = data.title ?? ''; card.dataset.caption = data.caption ?? '';
        card.querySelector<HTMLImageElement>('img')!.src = data.previewUrl;
        card.querySelector<HTMLImageElement>('img')!.alt = data.title ?? '';
        card.querySelectorAll<HTMLAnchorElement>('a').forEach(link => link.href = data.redirectUrl);
        card.querySelector('[data-photo-name]')!.textContent = data.title;
        card.querySelector<HTMLInputElement>('[data-photo-select]')!.setAttribute('aria-label', `${trans('js.photos.select_photo')} ${data.title ?? ''}`);
        originalOrder.push(data.slug); uploadedSlugs.add(data.slug); uploadSyncPending = true;
        if (placeholder) placeholder.replaceWith(card); else grid.append(card);
        update();
    };
    const announcePosition = (card: HTMLElement) => setStatus(trans('js.photos.moved', { position: cards().indexOf(card) + 1, total: cards().length }));
    const moveAt = (x: number, y: number) => {
        if (!pointer?.active) return;
        const target = document.elementFromPoint(x, y)?.closest<HTMLElement>('[data-photo-card]');
        if (!target || target === pointer.card || !grid.contains(target)) return;
        const list = cards(); const from = list.indexOf(pointer.card); const to = list.indexOf(target);
        const rect = target.getBoundingClientRect(); const sourceRect = pointer.card.getBoundingClientRect();
        const sameRow = Math.abs(sourceRect.top - rect.top) < 5;
        const rtl = getComputedStyle(grid).direction === 'rtl';
        const passedMiddle = sameRow ? (rtl ? x < rect.left + rect.width / 2 : x > rect.left + rect.width / 2) : y > rect.top + rect.height / 2;
        if ((to > from && passedMiddle) || (to < from && !passedMiddle)) {
            grid.insertBefore(pointer.card, to > from ? target.nextSibling : target); pointer.card.focus({ preventScroll: true }); update();
        }
    };
    const autoScroll = () => {
        if (!pointer?.active) return;
        const edge = 80;
        const amount = pointer.y < edge ? -Math.min(20, (edge - pointer.y) / 3) : pointer.y > window.innerHeight - edge ? Math.min(20, (pointer.y - window.innerHeight + edge) / 3) : 0;
        if (amount) { window.scrollBy({ top: amount, behavior: 'instant' }); moveAt(pointer.x, pointer.y); }
        scrollFrame = requestAnimationFrame(autoScroll);
    };
    const finishDrag = (cancel = false) => {
        if (!pointer) return;
        const current = pointer; pointer = null; cancelAnimationFrame(scrollFrame);
        current.card.classList.remove('is-dragging');
        if (grid.hasPointerCapture?.(current.id)) grid.releasePointerCapture(current.id);
        if (cancel) {
            const bySlug = new Map(cards().map(card => [card.dataset.slug, card]));
            for (const slug of current.snapshot) { const card = bySlug.get(slug); if (card) grid.append(card); }
        } else if (current.active) announcePosition(current.card);
        current.card.focus({ preventScroll: true }); update();
    };
    const pointerdown = (event: PointerEvent) => {
        if (!reordering || busy || hasPendingUploads() || pointer || event.button !== 0) return;
        const target = event.target as HTMLElement;
        const card = target.closest<HTMLElement>('[data-photo-card]');
        if (!card || !grid.contains(card) || target.closest('button, input, textarea, select, details, label')) return;
        if (event.pointerType !== 'mouse' && !target.closest('[data-photo-grip]')) return;
        event.preventDefault(); card.focus({ preventScroll: true });
        pointer = { card, id: event.pointerId, startX: event.clientX, startY: event.clientY, x: event.clientX, y: event.clientY, active: false, snapshot: order() };
        // Capture on the stationary grid: moving the card must not lose pointer capture.
        grid.setPointerCapture?.(event.pointerId);
    };
    const pointermove = (event: PointerEvent) => {
        if (!pointer || event.pointerId !== pointer.id) return;
        pointer.x = event.clientX; pointer.y = event.clientY;
        if (!pointer.active && Math.hypot(pointer.x - pointer.startX, pointer.y - pointer.startY) < 5) return;
        event.preventDefault();
        if (!pointer.active) { pointer.active = true; pointer.card.classList.add('is-dragging'); scrollFrame = requestAnimationFrame(autoScroll); }
        moveAt(pointer.x, pointer.y);
    };
    const pointerup = (event: PointerEvent) => { if (event.pointerId === pointer?.id) finishDrag(event.type === 'pointercancel'); };
    const lostCapture = () => finishDrag();
    const keydown = (event: KeyboardEvent) => {
        if (event.key === 'Escape' && pointer) { event.preventDefault(); finishDrag(true); return; }
        if (!reordering || busy || hasPendingUploads() || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
        const card = (event.target as HTMLElement).closest<HTMLElement>('[data-photo-card]');
        if (!card || !grid.contains(card) || event.target !== card) return;
        const list = cards(); const index = list.indexOf(card);
        const firstTop = list[0].getBoundingClientRect().top;
        const columns = list.filter(item => Math.abs(item.getBoundingClientRect().top - firstTop) < 5).length || 1;
        const rtl = getComputedStyle(grid).direction === 'rtl';
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? list.length - 1 : event.key === 'ArrowLeft' ? index + (rtl ? 1 : -1) : event.key === 'ArrowRight' ? index + (rtl ? -1 : 1) : event.key === 'ArrowUp' ? index - columns : event.key === 'ArrowDown' ? index + columns : null;
        if (next === null) return;
        event.preventDefault();
        const position = Math.max(0, Math.min(list.length - 1, next));
        grid.insertBefore(card, list.filter(item => item !== card)[position] ?? null);
        card.focus({ preventScroll: true }); card.scrollIntoView?.({ block: 'nearest', behavior: 'instant' }); announcePosition(card); update();
    };
    const dragstart = (event: DragEvent) => { if (reordering) event.preventDefault(); };
    const dragover = (event: DragEvent) => { if (event.dataTransfer?.types.includes('Files')) event.preventDefault(); };
    const drop = (event: DragEvent) => {
        if (!event.dataTransfer?.files.length || (event.target as HTMLElement).closest('[data-photo-dropzone]')) return;
        const input = root.querySelector<HTMLInputElement>('[data-photo-files]');
        if (input) { event.preventDefault(); input.files = event.dataTransfer.files; input.dispatchEvent(new Event('change')); }
    };
    const photoActions = initPhotoActions(root, {
        request: (data, method = 'PATCH', url = root.dataset.apiUrl!) => api(data, method, url, 'photo'),
        isBusy: () => busy,
        changed: update,
        removed: slug => {
            originalOrder = originalOrder.filter(value => value !== slug);
            if (cards().length === 0) selecting = false;
            update();
        },
    });
    const beforeunload = (event: BeforeUnloadEvent) => {
        const photoDraft = photoActions?.hasDraft() ?? false;
        if (!deleted && (dirty() || photoDraft)) { event.preventDefault(); event.returnValue = ''; }
    };
    const updateToolbarHeight = () => {
        if (toolbar) root.style.setProperty('--album-toolbar-height', `${Math.ceil(toolbar.getBoundingClientRect().height)}px`);
    };
    const toolbarObserver = toolbar && typeof ResizeObserver !== 'undefined' ? new ResizeObserver(updateToolbarHeight) : null;
    if (toolbar) toolbarObserver?.observe(toolbar);
    updateToolbarHeight();
    settingsForm.addEventListener('submit', submitSettings);
    settings.addEventListener('cancel', preventBusyClose);
    settings.addEventListener('hide.bs.modal', preventBusyClose);
    settings.addEventListener('click', preventBusyBackdrop, true);
    root.addEventListener('click', click); root.addEventListener('input', change); root.addEventListener('change', change);
    root.addEventListener('photo-queue-changed', queueChanged);
    root.addEventListener('photo-uploaded', uploaded); root.addEventListener('dragstart', dragstart); root.addEventListener('dragover', dragover); root.addEventListener('drop', drop);
    grid.addEventListener('lostpointercapture', lostCapture);
    window.addEventListener('keydown', keydown);
    root.addEventListener('pointerdown', pointerdown); root.addEventListener('pointermove', pointermove); root.addEventListener('pointerup', pointerup); root.addEventListener('pointercancel', pointerup);
    window.addEventListener('beforeunload', beforeunload); update();
    return () => {
        finishDrag(true);
        disposed = true;
        photoActions?.dispose();
        toolbarObserver?.disconnect();
        stopClosed(); stopDeleteClose?.();
        settingsForm.removeEventListener('submit', submitSettings);
        settings.removeEventListener('cancel', preventBusyClose);
        settings.removeEventListener('hide.bs.modal', preventBusyClose);
        settings.removeEventListener('click', preventBusyBackdrop, true);
        root.removeEventListener('click', click); root.removeEventListener('input', change); root.removeEventListener('change', change);
        root.removeEventListener('photo-queue-changed', queueChanged);
        root.removeEventListener('photo-uploaded', uploaded); root.removeEventListener('dragstart', dragstart); root.removeEventListener('dragover', dragover); root.removeEventListener('drop', drop);
        grid.removeEventListener('lostpointercapture', lostCapture);
        window.removeEventListener('keydown', keydown);
        root.removeEventListener('pointerdown', pointerdown); root.removeEventListener('pointermove', pointermove); root.removeEventListener('pointerup', pointerup); root.removeEventListener('pointercancel', pointerup);
        window.removeEventListener('beforeunload', beforeunload);
    };
}

import { trans } from '../shared/i18n';
import { ui } from '../shared/ui';
import { getApiErrorMessage } from '../shared/api-errors';

export function initAlbumManager(root: HTMLElement | null = document.querySelector('[data-album-manager]')): (() => void) | undefined {
    if (!root || root.dataset.ready === 'true') return;
    root.dataset.ready = 'true';
    const cms = window.CMS;
    const grid = root.querySelector<HTMLElement>('[data-album-grid]')!;
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
    let dragged: HTMLElement | null = null;
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
        orderSave.disabled = busy || !orderDirty();
        root.dataset.dirty = String(dirty());
        const orderActions = root.querySelector<HTMLElement>('[data-album-order-actions]');
        if (orderActions) orderActions.hidden = !reordering && !orderDirty();
        settings.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLButtonElement>('input, textarea, [data-album-settings-cancel]').forEach(control => control.disabled = busy);
        const photoCount = cards().length;
        const counter = root.querySelector('[data-album-photo-count]');
        if (counter) counter.textContent = String(photoCount);
        const empty = root.querySelector<HTMLElement>('[data-album-empty]');
        if (empty) empty.hidden = photoCount > 0;
        const toolbar = root.querySelector<HTMLElement>('[data-album-toolbar]');
        if (toolbar) toolbar.hidden = photoCount === 0;
        root.querySelectorAll<HTMLButtonElement>('[data-photo-album-delete], [data-photo-save], [data-photo-set-cover]').forEach(button => button.disabled = busy);
        root.dataset.selecting = String(selecting);
        root.dataset.reordering = String(reordering);
        const selection = root.querySelector<HTMLElement>('[data-album-selection]')!;
        selection.hidden = !selecting;
        const count = selected().length;
        const actions = root.querySelector<HTMLElement>('[data-album-selection-actions]');
        if (actions) actions.hidden = count === 0;
        root.querySelector('[data-album-selection-count]')!.textContent = trans('js.photos.selected', { count });
        root.querySelectorAll<HTMLButtonElement>('[data-album-move], [data-album-delete-selected]').forEach(button => button.disabled = busy || count === 0);
        root.querySelector('[data-album-select]')?.setAttribute('aria-pressed', String(selecting));
        root.querySelector('[data-album-reorder]')?.setAttribute('aria-pressed', String(reordering));
        const cancel = root.querySelector<HTMLElement>('[data-album-cancel-order]');
        if (cancel) cancel.hidden = !reordering;
        const all = root.querySelector<HTMLInputElement>('[data-album-select-all]')!;
        all.checked = count > 0 && count === cards().length;
        all.indeterminate = count > 0 && count < cards().length;
    };
    const api = async (data: unknown, method: 'PATCH' | 'DELETE' = 'PATCH', url = root.dataset.apiUrl!, inSettings = false) => {
        busy = true; if (inSettings) settingsError.textContent = ''; else setStatus(''); update();
        try { return await cms.api<{ redirectUrl?: string }>(url, { method, data }); }
        catch (error) {
            const message = getApiErrorMessage(error, trans('js.common.action_failed'));
            if (inSettings) settingsError.textContent = message; else setStatus(message);
            throw error;
        }
        finally { busy = false; update(); }
    };
    const saveSettings = async () => {
        if (!title.reportValidity() || !description.reportValidity()) return;
        const data = fields();
        await api(data, 'PATCH', root.dataset.apiUrl!, true);
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
        reordering = false;
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
    const move = (target: string) => {
        const chosen = selected(); const rest = cards().filter(card => !chosen.includes(card));
        const position = root.querySelector<HTMLInputElement>('[data-album-position]')!;
        const index = target === 'start' ? 0 : target === 'end' ? rest.length : Math.max(0, Math.min(rest.length, (Number(position.value) || 1) - 1));
        grid.append(...rest.slice(0, index), ...chosen, ...rest.slice(index)); update();
    };
    const click = (event: Event) => {
        const target = event.target as HTMLElement;
        const button = target.closest<HTMLButtonElement>('button');
        if (!button || busy) return;
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
        if (button.matches('[data-album-select]')) { selecting = !selecting; update(); }
        if (button.matches('[data-album-reorder]')) { reordering = !reordering; selecting = reordering || selecting; update(); }
        if (button.matches('[data-album-cancel-order]')) {
            const bySlug = new Map(cards().map(card => [card.dataset.slug, card]));
            for (const slug of originalOrder) { const card = bySlug.get(slug); if (card) grid.append(card); }
            reordering = false; update();
        }
        if (button.dataset.albumMove) move(button.dataset.albumMove);
        if (button.matches('[data-album-delete-selected]')) {
            const chosen = selected(); const slugs = chosen.map(card => card.dataset.slug!);
            if (!slugs.length) return;
            cms.confirm({ title: trans('js.photos.delete_selected'), message: trans('js.photos.delete_count', { count: slugs.length }), onConfirm: async () => {
                if (busy) return;
                try {
                    await api({ photos: slugs }, 'DELETE', root.dataset.photosApiUrl!);
                    chosen.forEach(card => card.remove());
                    originalOrder = originalOrder.filter(slug => !slugs.includes(slug));
                    setStatus(trans('js.photos.deleted_count', { count: slugs.length })); update();
                } catch { /* Keep selection for retry. */ }
            } });
        }
        const card = button.closest<HTMLElement>('[data-photo-card]');
        if (!card) return;
        if (button.matches('[data-photo-up]') && card.previousElementSibling) grid.insertBefore(card, card.previousElementSibling);
        if (button.matches('[data-photo-down]') && card.nextElementSibling) grid.insertBefore(card.nextElementSibling, card);
        if (button.matches('[data-photo-edit]')) card.querySelector<HTMLElement>('[data-photo-details]')!.hidden = false;
        if (button.matches('[data-photo-cancel]')) {
            card.querySelector<HTMLInputElement>('[data-photo-title]')!.value = card.dataset.title!;
            card.querySelector<HTMLTextAreaElement>('[data-photo-caption]')!.value = card.dataset.caption!;
            card.querySelector<HTMLElement>('[data-photo-details]')!.hidden = true;
        }
        if (button.matches('[data-photo-save]')) {
            const data = { slug: card.dataset.slug!, title: card.querySelector<HTMLInputElement>('[data-photo-title]')!.value, caption: card.querySelector<HTMLTextAreaElement>('[data-photo-caption]')!.value };
            void api({ photo: data }).then(() => {
                card.dataset.title = data.title; card.dataset.caption = data.caption;
                card.querySelector('[data-photo-name]')!.textContent = data.title;
                card.querySelector('img')!.alt = data.title;
                card.querySelector<HTMLElement>('[data-photo-details]')!.hidden = true;
                setStatus(trans('js.photos.saved')); update();
            }).catch(() => {});
        }
        if (button.matches('[data-photo-set-cover]')) {
            void api({ cover: card.dataset.slug }).then(() => {
                cards().forEach(item => item.querySelector<HTMLElement>('[data-photo-cover-badge]')!.hidden = item !== card);
                setStatus(trans('js.photos.saved')); update();
            }).catch(() => {});
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
        if (!data || typeof data.slug !== 'string' || cards().some(card => card.dataset.slug === data.slug)) return;
        const template = root.querySelector<HTMLTemplateElement>('[data-photo-card-template]')!;
        const card = template.content.firstElementChild!.cloneNode(true) as HTMLElement;
        card.dataset.slug = data.slug; card.dataset.title = data.title ?? ''; card.dataset.caption = data.caption ?? '';
        card.querySelector<HTMLImageElement>('img')!.src = data.previewUrl;
        card.querySelector<HTMLImageElement>('img')!.alt = data.title ?? '';
        card.querySelectorAll<HTMLAnchorElement>('a').forEach(link => link.href = data.redirectUrl);
        card.querySelector('[data-photo-name]')!.textContent = data.title;
        card.querySelector<HTMLInputElement>('[data-photo-select]')!.setAttribute('aria-label', `${trans('js.photos.select_photo')} ${data.title ?? ''}`);
        card.querySelector<HTMLInputElement>('[data-photo-title]')!.value = data.title ?? '';
        card.querySelector<HTMLTextAreaElement>('[data-photo-caption]')!.value = data.caption ?? '';
        originalOrder.push(data.slug); grid.append(card); update();
    };
    const dragstart = (event: DragEvent) => {
        if (!reordering || busy || !(event.target as HTMLElement).closest('[data-photo-grip]')) return;
        dragged = (event.target as HTMLElement).closest('[data-photo-card]');
        event.dataTransfer?.setData('text/plain', dragged?.dataset.slug ?? '');
    };
    const dragover = (event: DragEvent) => {
        if (event.dataTransfer?.types.includes('Files')) {
            event.preventDefault();
        } else if (dragged) event.preventDefault();
    };
    const drop = (event: DragEvent) => {
        if (event.dataTransfer?.files.length && !(event.target as HTMLElement).closest('[data-photo-dropzone]')) {
            const input = root.querySelector<HTMLInputElement>('[data-photo-files]');
            if (input) { event.preventDefault(); input.files = event.dataTransfer.files; input.dispatchEvent(new Event('change')); }
            return;
        }
        const target = (event.target as HTMLElement).closest<HTMLElement>('[data-photo-card]');
        if (dragged && target && target !== dragged && grid.contains(target)) { event.preventDefault(); const rect = target.getBoundingClientRect(); grid.insertBefore(dragged, event.clientY > rect.top + rect.height / 2 ? target.nextSibling : target); update(); }
        dragged = null;
    };
    let touchCard: HTMLElement | null = null;
    const pointerdown = (event: PointerEvent) => {
        const handle = (event.target as HTMLElement).closest<HTMLElement>('[data-photo-grip]');
        if (!handle || !reordering || busy || event.pointerType === 'mouse') return;
        touchCard = handle.closest('[data-photo-card]'); handle.setPointerCapture?.(event.pointerId);
    };
    const pointermove = (event: PointerEvent) => {
        if (!touchCard) return;
        event.preventDefault();
        const target = document.elementFromPoint(event.clientX, event.clientY)?.closest<HTMLElement>('[data-photo-card]');
        if (target && target !== touchCard && grid.contains(target)) {
            const rect = target.getBoundingClientRect();
            grid.insertBefore(touchCard, event.clientY > rect.top + rect.height / 2 ? target.nextSibling : target); update();
        }
    };
    const pointerup = () => { touchCard = null; dragged = null; };
    const beforeunload = (event: BeforeUnloadEvent) => {
        const photoDraft = cards().some(card => card.querySelector<HTMLInputElement>('[data-photo-title]')!.value !== card.dataset.title || card.querySelector<HTMLTextAreaElement>('[data-photo-caption]')!.value !== card.dataset.caption);
        if (!deleted && (dirty() || photoDraft)) { event.preventDefault(); event.returnValue = ''; }
    };
    settingsForm.addEventListener('submit', submitSettings);
    settings.addEventListener('cancel', preventBusyClose);
    settings.addEventListener('hide.bs.modal', preventBusyClose);
    settings.addEventListener('click', preventBusyBackdrop, true);
    root.addEventListener('click', click); root.addEventListener('input', change); root.addEventListener('change', change);
    root.addEventListener('photo-uploaded', uploaded); root.addEventListener('dragstart', dragstart); root.addEventListener('dragover', dragover); root.addEventListener('drop', drop);
    root.addEventListener('pointerdown', pointerdown); root.addEventListener('pointermove', pointermove); root.addEventListener('pointerup', pointerup); root.addEventListener('pointercancel', pointerup);
    window.addEventListener('beforeunload', beforeunload); update();
    return () => {
        stopClosed(); stopDeleteClose?.();
        settingsForm.removeEventListener('submit', submitSettings);
        settings.removeEventListener('cancel', preventBusyClose);
        settings.removeEventListener('hide.bs.modal', preventBusyClose);
        settings.removeEventListener('click', preventBusyBackdrop, true);
        root.removeEventListener('click', click); root.removeEventListener('input', change); root.removeEventListener('change', change);
        root.removeEventListener('photo-uploaded', uploaded); root.removeEventListener('dragstart', dragstart); root.removeEventListener('dragover', dragover); root.removeEventListener('drop', drop);
        root.removeEventListener('pointerdown', pointerdown); root.removeEventListener('pointermove', pointermove); root.removeEventListener('pointerup', pointerup); root.removeEventListener('pointercancel', pointerup);
        window.removeEventListener('beforeunload', beforeunload);
    };
}

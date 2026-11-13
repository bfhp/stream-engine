import { ui } from '../shared/ui';
import { trans } from '../shared/i18n';
import { getApiErrorMessage } from '../shared/api-errors';

interface PhotoActionsOptions {
    request(data: unknown, method?: 'PATCH' | 'DELETE', url?: string): Promise<unknown>;
    isBusy(): boolean;
    changed(): void;
    removed(slug: string): void;
}

export function initPhotoActions(root: HTMLElement, options: PhotoActionsOptions): { hasDraft(): boolean; dispose(): void } | undefined {
    const dialog = root.querySelector<HTMLElement>('[data-photo-editor]');
    if (!dialog) return;
    const form = dialog.querySelector<HTMLFormElement>('[data-photo-editor-form]')!;
    const name = dialog.querySelector<HTMLInputElement>('[data-photo-editor-name]')!;
    const caption = dialog.querySelector<HTMLTextAreaElement>('[data-photo-editor-caption]')!;
    const preview = dialog.querySelector<HTMLImageElement>('[data-photo-editor-preview]')!;
    const error = dialog.querySelector<HTMLElement>('[data-photo-editor-error]')!;
    const status = dialog.querySelector<HTMLElement>('[data-photo-editor-status]')!;
    const save = dialog.querySelector<HTMLButtonElement>('[data-photo-editor-save]')!;
    const previous = dialog.querySelector<HTMLButtonElement>('[data-photo-editor-prev]')!;
    const next = dialog.querySelector<HTMLButtonElement>('[data-photo-editor-next]')!;
    const cards = () => Array.from(root.querySelectorAll<HTMLElement>('[data-album-grid] [data-photo-card]'));
    let current: HTMLElement | null = null;
    let openMenu: HTMLElement | null = null;
    let opener: HTMLButtonElement | null = null;
    let pending = false;
    let lastField: HTMLInputElement | HTMLTextAreaElement = name;
    const hasDraft = () => current !== null && (name.value !== current.dataset.title || caption.value !== current.dataset.caption);
    const updateEditor = () => {
        const index = current ? cards().indexOf(current) : -1;
        save.disabled = pending || !hasDraft();
        previous.disabled = pending || index <= 0;
        next.disabled = pending || index < 0 || index >= cards().length - 1;
        previous.textContent = trans(hasDraft() ? 'js.photos.save_previous' : 'js.photos.previous_photo');
        next.textContent = trans(hasDraft() ? 'js.photos.save_next' : 'js.photos.next_photo');
        dialog.querySelector('[data-photo-editor-position]')!.textContent = trans('js.photos.photo_position', { position: index + 1, total: cards().length });
        dialog.querySelectorAll<HTMLInputElement | HTMLTextAreaElement | HTMLButtonElement>('input, textarea, [data-photo-editor-close]').forEach(control => control.disabled = pending);
    };
    const updateCovers = () => {
        for (const card of cards()) {
            const cover = !card.querySelector<HTMLElement>('[data-photo-cover-badge]')!.hidden;
            const button = card.querySelector<HTMLButtonElement>('[data-photo-set-cover]')!;
            button.disabled = cover; button.textContent = trans(cover ? 'js.photos.album_cover' : 'js.photos.set_cover');
        }
    };
    const closeMenu = (restoreFocus = false) => {
        if (openMenu) openMenu.hidden = true;
        opener?.setAttribute('aria-expanded', 'false');
        if (restoreFocus) opener?.focus({ preventScroll: true });
        openMenu = null; opener = null;
    };
    const loadPhoto = (card: HTMLElement) => {
        current = card; name.value = card.dataset.title ?? ''; caption.value = card.dataset.caption ?? '';
        preview.src = card.querySelector<HTMLImageElement>('img')!.src; preview.alt = card.dataset.title ?? '';
        error.textContent = ''; status.textContent = ''; updateEditor();
    };
    const savePhoto = async (): Promise<boolean> => {
        if (!current || !name.reportValidity() || !caption.reportValidity()) return false;
        if (!hasDraft()) return true;
        const card = current;
        const data = { slug: card.dataset.slug!, title: name.value.trim(), caption: caption.value };
        pending = true; error.textContent = ''; status.textContent = ''; updateEditor();
        try {
            await options.request({ photo: data });
            card.dataset.title = data.title; card.dataset.caption = data.caption;
            card.querySelector('[data-photo-name]')!.textContent = data.title;
            card.querySelector<HTMLImageElement>('img')!.alt = data.title;
            card.querySelector('[data-photo-select]')!.setAttribute('aria-label', `${trans('js.photos.select_photo')} ${data.title}`);
            name.value = data.title; preview.alt = data.title;
            status.textContent = trans('js.photos.saved'); options.changed(); return true;
        } catch (reason) { error.textContent = getApiErrorMessage(reason, trans('js.common.action_failed')); return false; }
        finally { pending = false; updateEditor(); }
    };
    const navigate = async (direction: number) => {
        if (!current || pending || options.isBusy()) return;
        const target = cards()[cards().indexOf(current) + direction];
        if (!target || (hasDraft() && !await savePhoto())) return;
        loadPhoto(target); lastField.focus({ preventScroll: true });
    };
    const focusName = () => name.focus({ preventScroll: true });
    const positionMenu = () => {
        if (!openMenu || !opener) return;
        const toolbarBottom = root.querySelector('[data-album-toolbar]')?.getBoundingClientRect().bottom ?? 0;
        const above = opener.getBoundingClientRect().top - openMenu.getBoundingClientRect().height - 4;
        opener.parentElement!.classList.toggle('is-below', above < Math.max(8, toolbarBottom + 8));
    };
    const toggleMenu = (button: HTMLButtonElement) => {
        const card = button.closest<HTMLElement>('[data-photo-card]')!;
        const menu = card.querySelector<HTMLElement>('[data-photo-menu]')!;
        const closing = menu === openMenu; closeMenu(); if (closing) return;
        updateCovers();
        menu.id = `photo-actions-${card.dataset.slug}`; button.setAttribute('aria-controls', menu.id);
        button.setAttribute('aria-expanded', 'true'); menu.hidden = false; opener = button; openMenu = menu;
        positionMenu();
        menu.querySelector<HTMLButtonElement>('button:not(:disabled)')?.focus({ preventScroll: true });
    };
    const click = (event: Event) => {
        const button = (event.target as HTMLElement).closest<HTMLButtonElement>('button');
        if (!button || button.disabled || pending || options.isBusy()) return;
        if (button.matches('[data-photo-menu-toggle]')) { toggleMenu(button); return; }
        if (button.matches('[data-photo-editor-close]')) { ui.modal.close(dialog); return; }
        if (button.matches('[data-photo-editor-prev]')) { void navigate(-1); return; }
        if (button.matches('[data-photo-editor-next]')) { void navigate(1); return; }
        const card = button.closest<HTMLElement>('[data-photo-card]');
        if (!card) return;
        if (button.matches('[data-photo-edit]')) {
            closeMenu(); loadPhoto(card); lastField = name; card.querySelector<HTMLButtonElement>('[data-photo-menu-toggle]')?.focus({ preventScroll: true }); ui.modal.open(dialog);
            if (dialog instanceof HTMLDialogElement) focusName();
        }
        if (button.matches('[data-photo-set-cover]')) {
            closeMenu(); pending = true;
            void options.request({ cover: card.dataset.slug }).then(() => {
                cards().forEach(item => item.querySelector<HTMLElement>('[data-photo-cover-badge]')!.hidden = item !== card);
                updateCovers(); options.changed(); window.CMS.toast({ message: trans('js.photos.cover_saved'), type: 'success' });
            }).catch(reason => window.CMS.toast({ message: getApiErrorMessage(reason, trans('js.common.action_failed')), type: 'danger' })).finally(() => { pending = false; });
        }
        if (button.matches('[data-photo-delete]')) {
            closeMenu();
            window.CMS.confirm({ title: trans('js.photos.delete_photo_title'), message: trans('js.photos.delete_photo_confirm', { title: card.dataset.title ?? '' }), onConfirm: async () => {
                if (pending || options.isBusy() || !card.isConnected) return;
                pending = true;
                try {
                    const index = cards().indexOf(card); const neighbour = cards()[index + 1] ?? cards()[index - 1];
                    await options.request({ photos: [card.dataset.slug!] }, 'DELETE', root.dataset.photosApiUrl!);
                    card.remove(); options.removed(card.dataset.slug!);
                    neighbour?.querySelector<HTMLButtonElement>('[data-photo-menu-toggle]')?.focus({ preventScroll: true });
                    window.CMS.toast({ message: trans('js.photos.photo_deleted'), type: 'success' });
                } catch (reason) { window.CMS.toast({ message: getApiErrorMessage(reason, trans('js.common.action_failed')), type: 'danger' }); }
                finally { pending = false; }
            } });
        }
    };
    const submit = (event: Event) => { event.preventDefault(); if (!pending && !options.isBusy()) void savePhoto(); };
    const input = () => { error.textContent = ''; status.textContent = ''; updateEditor(); };
    const focus = (event: Event) => { if (event.target === name || event.target === caption) lastField = event.target as HTMLInputElement | HTMLTextAreaElement; };
    const preventBusyClose = (event: Event) => { if (pending) event.preventDefault(); };
    const preventBusyBackdrop = (event: Event) => { if (pending && event.target === dialog) { event.preventDefault(); event.stopImmediatePropagation(); } };
    const stopClosed = ui.modal.onClosed(dialog, () => {
        const returnFocus = current?.querySelector<HTMLButtonElement>('[data-photo-menu-toggle]');
        current = null; name.value = ''; caption.value = ''; preview.removeAttribute('src'); error.textContent = ''; status.textContent = '';
        returnFocus?.focus({ preventScroll: true });
    });
    const outside = (event: Event) => { if (openMenu && !(event.target as HTMLElement).closest('.users-album-photo__menu')) closeMenu(); };
    const scroll = () => positionMenu();
    const keydown = (event: KeyboardEvent) => {
        if (!openMenu) {
            const toggle = (event.target as HTMLElement).closest<HTMLButtonElement>('[data-photo-menu-toggle]');
            if (toggle && ['ArrowDown', 'ArrowUp'].includes(event.key) && !pending && !options.isBusy()) {
                event.preventDefault(); toggleMenu(toggle);
                if (event.key === 'ArrowUp') toggle.parentElement!.querySelector<HTMLButtonElement>('[data-photo-delete]')?.focus({ preventScroll: true });
            }
            return;
        }
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeMenu(true); return; }
        if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
        const buttons = Array.from(openMenu.querySelectorAll<HTMLButtonElement>('button:not(:disabled)'));
        const index = buttons.indexOf(document.activeElement as HTMLButtonElement);
        const nextIndex = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length;
        event.preventDefault(); buttons[nextIndex]?.focus({ preventScroll: true });
    };
    const queueChanged = () => { if (current) updateEditor(); };
    root.addEventListener('photo-queue-changed', queueChanged);
    root.addEventListener('click', click); root.addEventListener('keydown', keydown);
    form.addEventListener('submit', submit); form.addEventListener('input', input); form.addEventListener('focusin', focus);
    dialog.addEventListener('cancel', preventBusyClose); dialog.addEventListener('hide.bs.modal', preventBusyClose); dialog.addEventListener('shown.bs.modal', focusName); dialog.addEventListener('click', preventBusyBackdrop, true);
    document.addEventListener('click', outside); document.addEventListener('focusin', outside); window.addEventListener('scroll', scroll, { passive: true });
    updateCovers();
    return { hasDraft, dispose() {
        stopClosed(); closeMenu();
        root.removeEventListener('photo-queue-changed', queueChanged);
        root.removeEventListener('click', click); root.removeEventListener('keydown', keydown);
        form.removeEventListener('submit', submit); form.removeEventListener('input', input); form.removeEventListener('focusin', focus);
        dialog.removeEventListener('cancel', preventBusyClose); dialog.removeEventListener('hide.bs.modal', preventBusyClose); dialog.removeEventListener('shown.bs.modal', focusName); dialog.removeEventListener('click', preventBusyBackdrop, true);
        document.removeEventListener('click', outside); document.removeEventListener('focusin', outside); window.removeEventListener('scroll', scroll);
    } };
}

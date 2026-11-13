import { csrfToken } from '../shared/csrf';
import { getApiErrorMessage } from '../shared/api-errors';
import { trans } from '../shared/i18n';

type State = 'waiting' | 'uploading' | 'processing' | 'done' | 'error';
interface QueueItem {
    file: File | null;
    requestId: string;
    state: State;
    row: HTMLElement;
    status: HTMLElement;
    indicator: HTMLElement;
    retry: HTMLButtonElement;
    remove: HTMLButtonElement;
}

function requestId(): string {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** Placeholders share the album grid; files keep their retry identity until published. */
export function initPhotoUpload(root: HTMLElement | null = document.querySelector('[data-photo-uploader]')): (() => void) | undefined {
    if (!root || root.dataset.ready === 'true' || !root.dataset.apiUrl) return;
    root.dataset.ready = 'true';
    const input = root.querySelector<HTMLInputElement>('[data-photo-files]')!;
    const manager = root.closest('[data-album-manager]')!;
    const grid = manager.querySelector<HTMLElement>('[data-album-grid]')!;
    const summary = root.querySelector<HTMLElement>('[data-photo-summary]')!;
    const controls = root.querySelector<HTMLElement>('[data-photo-queue-controls]')!;
    const pause = root.querySelector<HTMLButtonElement>('[data-photo-pause]')!;
    const retryAll = root.querySelector<HTMLButtonElement>('[data-photo-retry-all]')!;
    const dropzone = root.querySelector<HTMLElement>('[data-photo-dropzone]')!;
    const items = new Set<QueueItem>();
    const active = new Map<QueueItem, XMLHttpRequest>();
    let paused = false;
    let destroyed = false;
    let notified = true;

    const update = (): void => {
        const done = [...items].filter(item => item.state === 'done');
        const failed = [...items].filter(item => item.state === 'error').length;
        const waiting = [...items].some(item => item.state === 'waiting');
        if (!waiting && active.size === 0 && failed === 0) paused = false;
        summary.textContent = trans('js.photos.batch_summary', { done: done.length, total: items.size, failed })
            + (paused ? ` · ${trans('js.photos.batch_paused')}` : '');
        controls.hidden = items.size === 0;
        pause.hidden = !waiting && active.size === 0;
        pause.textContent = trans(paused ? 'js.photos.batch_resume' : 'js.photos.batch_pause');
        retryAll.hidden = failed === 0;
        root.dispatchEvent(new CustomEvent('photo-queue-changed', { bubbles: true }));
        if (!waiting && active.size === 0 && !notified && items.size > 0) {
            notified = true;
            for (const item of done) items.delete(item);
            controls.hidden = items.size === 0;
            const message = failed ? trans('js.photos.batch_finished_errors', { done: done.length, failed }) : trans('js.photos.batch_finished', { count: done.length });
            const albumUrl = root.dataset.albumUrl;
            if (albumUrl?.startsWith('/') && !albumUrl.startsWith('//')) {
                const body = document.createElement('span'); body.textContent = message;
                const link = document.createElement('a'); link.href = albumUrl; link.className = 'users-photo-toast-link'; link.textContent = trans('js.photos.view_album');
                body.append(' ', link);
                window.CMS.toast({ message: body.outerHTML, html: true, type: failed ? 'warning' : 'success' });
            } else window.CMS.toast({ message, type: failed ? 'warning' : 'success' });
        }
    };
    const setState = (item: QueueItem, state: State, message?: string): void => {
        item.state = state;
        item.row.dataset.state = state;
        const uploading = state === 'uploading' || state === 'processing';
        item.row.setAttribute('aria-busy', String(uploading));
        const image = item.indicator.parentElement!;
        image.setAttribute('role', uploading ? 'progressbar' : 'group');
        if (uploading) {
            image.setAttribute('aria-valuemin', '0'); image.setAttribute('aria-valuemax', '100');
            image.setAttribute('aria-valuetext', trans(`js.photos.batch_${state}`));
        } else {
            for (const attribute of ['aria-valuemin', 'aria-valuemax', 'aria-valuetext']) image.removeAttribute(attribute);
        }
        if (state === 'uploading') image.setAttribute('aria-valuenow', '0'); else image.removeAttribute('aria-valuenow');
        item.status.textContent = message ?? trans(`js.photos.batch_${state}`);
        item.indicator.textContent = state === 'error' ? '!' : state === 'uploading' ? '0%' : '…';
        item.retry.hidden = state !== 'error';
        item.remove.hidden = state !== 'waiting' && state !== 'error';
    };
    const pump = (): void => {
        if (destroyed || paused) return;
        while (!destroyed && !paused && active.size < 2) {
            const item = [...items].find(candidate => candidate.state === 'waiting');
            if (!item) break;
            start(item);
        }
        update();
    };
    const start = (item: QueueItem): void => {
        const xhr = new XMLHttpRequest();
        active.set(item, xhr);
        setState(item, 'uploading');
        const fail = (reason: unknown, stopQueue = false): void => {
            if (destroyed || !active.has(item)) return;
            active.delete(item);
            if (stopQueue) paused = true;
            setState(item, 'error', getApiErrorMessage(reason, trans('js.common.action_failed')));
            update(); pump();
        };
        xhr.upload.onprogress = event => {
            if (destroyed || !active.has(item) || !event.lengthComputable) return;
            const percent = Math.min(100, Math.round(event.loaded / event.total * 100));
            item.indicator.textContent = `${percent}%`;
            item.indicator.parentElement!.setAttribute('aria-valuenow', String(percent));
            item.indicator.parentElement!.setAttribute('aria-valuetext', `${percent}%`);
            if (percent === 100) setState(item, 'processing');
        };
        xhr.onload = () => {
            if (destroyed || !active.has(item)) return;
            let payload: { slug?: string; redirectUrl?: string; code?: string; title?: string; caption?: string; previewUrl?: string };
            try { payload = JSON.parse(xhr.responseText); }
            catch { fail(null, true); return; }
            if (xhr.status < 200 || xhr.status >= 300 || typeof payload?.slug !== 'string' || !/^[1-9][0-9]*$/.test(payload.slug)) {
                fail(payload, [401, 403, 404, 429].includes(xhr.status) || xhr.status >= 500 || payload?.code === 'storage_limit_exceeded');
                return;
            }
            active.delete(item);
            setState(item, 'done');
            item.file = null;
            root.dispatchEvent(new CustomEvent('photo-uploaded', { bubbles: true, detail: { ...payload, requestId: item.requestId } }));
            item.row.remove();
            update(); pump();
        };
        xhr.onerror = () => fail(null, true);
        xhr.ontimeout = () => fail(null, true);
        xhr.onabort = () => fail(null, true);
        try {
            xhr.open('POST', root.dataset.apiUrl!);
            xhr.setRequestHeader('X-CSRF-Token', csrfToken());
            xhr.timeout = 120000;
            const data = new FormData();
            data.set('file', item.file!); data.set('requestId', item.requestId);
            xhr.send(data);
        } catch (error) { fail(error, true); }
    };
    const retry = (item: QueueItem): void => {
        if (item.state !== 'error' || !item.file) return;
        notified = false;
        setState(item, 'waiting');
    };
    const addFiles = (files: Iterable<File>): void => {
        if (destroyed) return;
        const fragment = document.createDocumentFragment();
        let firstAdded: HTMLElement | null = null;
        for (const file of files) {
            notified = false;
            const row = document.createElement('figure');
            row.className = 'ui-card users-panel users-album-placeholder';
            firstAdded ??= row;
            row.dataset.photoPlaceholder = ''; row.dataset.requestId = requestId();
            const image = document.createElement('div'); image.className = 'users-album-placeholder__image';
            image.setAttribute('aria-label', trans('js.photos.batch_file_progress', { name: file.name }));
            const indicator = document.createElement('span'); indicator.className = 'users-album-placeholder__indicator'; indicator.setAttribute('aria-hidden', 'true');
            const status = document.createElement('span'); status.className = 'users-album-placeholder__status';
            image.append(indicator, status);
            const name = document.createElement('figcaption'); name.className = 'users-album-placeholder__name'; name.textContent = file.name; name.title = file.name;
            const actions = document.createElement('div'); actions.className = 'users-actions';
            const retryButton = document.createElement('button'); retryButton.type = 'button'; retryButton.className = 'ui-button ui-button--secondary ui-button--sm';
            retryButton.textContent = trans('js.photos.batch_retry'); retryButton.setAttribute('aria-label', trans('js.photos.batch_retry_file', { name: file.name }));
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'ui-button ui-button--secondary ui-button--sm';
            remove.textContent = trans('js.photos.batch_remove'); remove.setAttribute('aria-label', trans('js.photos.batch_remove_file', { name: file.name }));
            const item: QueueItem = { file, requestId: row.dataset.requestId, state: 'waiting', row, status, indicator, retry: retryButton, remove };
            retryButton.addEventListener('click', () => { retry(item); paused = false; pump(); });
            remove.addEventListener('click', () => {
                if (item.state !== 'waiting' && item.state !== 'error') return;
                items.delete(item); item.file = null; row.remove(); update();
            });
            actions.append(retryButton, remove); row.append(image, name, actions); fragment.append(row); items.add(item); setState(item, 'waiting');
        }
        grid.append(fragment);
        update(); pump();
        firstAdded?.scrollIntoView?.({ behavior: 'instant', block: 'start' });
    };
    input.addEventListener('change', () => { addFiles(Array.from(input.files ?? [])); input.value = ''; });
    pause.addEventListener('click', () => { paused = !paused; update(); pump(); });
    retryAll.addEventListener('click', () => { for (const item of items) retry(item); paused = false; pump(); });
    dropzone.addEventListener('dragover', event => { event.preventDefault(); dropzone.classList.add('is-dragging'); });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('is-dragging'));
    dropzone.addEventListener('drop', event => { event.preventDefault(); dropzone.classList.remove('is-dragging'); addFiles(Array.from(event.dataTransfer?.files ?? [])); });
    const warnBeforeLeaving = (event: BeforeUnloadEvent): void => {
        if (active.size || [...items].some(item => item.state === 'waiting' || item.state === 'error')) { event.preventDefault(); event.returnValue = ''; }
    };
    window.addEventListener('beforeunload', warnBeforeLeaving);
    return () => {
        destroyed = true; window.removeEventListener('beforeunload', warnBeforeLeaving);
        for (const xhr of active.values()) xhr.abort();
        active.clear(); for (const item of items) item.row.remove(); items.clear();
    };
}

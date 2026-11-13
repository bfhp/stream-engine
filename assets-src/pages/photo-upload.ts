import { csrfToken } from '../shared/csrf';
import { getApiErrorMessage } from '../shared/api-errors';
import { trans } from '../shared/i18n';

type State = 'waiting' | 'uploading' | 'processing' | 'done' | 'error';
interface QueueItem {
    file: File | null;
    requestId: string;
    state: State;
    percent: number;
    row: HTMLLIElement;
    name: HTMLAnchorElement;
    status: HTMLElement;
    progress: HTMLProgressElement;
    retry: HTMLButtonElement;
    remove: HTMLButtonElement;
}

function requestId(): string {
    // randomUUID is unavailable on some mobile HTTP origins; getRandomValues is.
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

/** One queue for all selection/drop batches; each file has its own retry identity. */
export function initPhotoUpload(root: HTMLElement | null = document.querySelector('[data-photo-uploader]')): (() => void) | undefined {
    if (!root || root.dataset.ready === 'true' || !root.dataset.apiUrl) return;
    root.dataset.ready = 'true';
    const input = root.querySelector<HTMLInputElement>('[data-photo-files]')!;
    const list = root.querySelector<HTMLOListElement>('[data-photo-queue]')!;
    const summary = root.querySelector<HTMLElement>('[data-photo-summary]')!;
    const overall = root.querySelector<HTMLProgressElement>('[data-photo-total-progress]')!;
    const pause = root.querySelector<HTMLButtonElement>('[data-photo-pause]')!;
    const retryAll = root.querySelector<HTMLButtonElement>('[data-photo-retry-all]')!;
    const clear = root.querySelector<HTMLButtonElement>('[data-photo-clear-done]')!;
    const dropzone = root.querySelector<HTMLElement>('[data-photo-dropzone]')!;
    const items = new Set<QueueItem>();
    const active = new Map<QueueItem, XMLHttpRequest>();
    let paused = false;
    let destroyed = false;

    const updateProgress = (): void => {
        let value = 0;
        for (const item of items) value += item.state === 'done' || item.state === 'error' ? 100 : item.percent;
        overall.value = items.size ? value / items.size : 0;
    };
    const update = (): void => {
        const done = [...items].filter(item => item.state === 'done').length;
        const failed = [...items].filter(item => item.state === 'error').length;
        const waiting = [...items].some(item => item.state === 'waiting');
        if (!waiting && active.size === 0 && failed === 0) paused = false;
        summary.textContent = trans('js.photos.batch_summary', { done, total: items.size, failed })
            + (paused ? ` · ${trans('js.photos.batch_paused')}` : '');
        const panel = root.querySelector<HTMLElement>('[data-photo-queue-panel]');
        if (panel) panel.hidden = items.size === 0;
        overall.hidden = items.size === 0;
        pause.hidden = !waiting && active.size === 0;
        pause.textContent = trans(paused ? 'js.photos.batch_resume' : 'js.photos.batch_pause');
        retryAll.hidden = failed === 0;
        clear.hidden = done === 0;
        updateProgress();
    };
    const setState = (item: QueueItem, state: State, message?: string): void => {
        item.state = state;
        item.row.dataset.state = state;
        item.status.textContent = message ?? trans(`js.photos.batch_${state}`);
        item.retry.hidden = state !== 'error';
        item.remove.hidden = state !== 'waiting' && state !== 'error';
        if (state === 'done') item.percent = 100;
        item.progress.value = item.percent;
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
        item.percent = 0;
        setState(item, 'uploading');
        const fail = (reason: unknown, stopQueue = false): void => {
            if (destroyed || !active.has(item)) return;
            active.delete(item);
            if (stopQueue) paused = true;
            setState(item, 'error', getApiErrorMessage(reason, trans('js.common.action_failed')));
            update();
            pump();
        };
        xhr.upload.onprogress = event => {
            if (destroyed || !active.has(item) || !event.lengthComputable) return;
            item.percent = Math.min(100, Math.round(event.loaded / event.total * 100));
            item.progress.value = item.percent;
            item.status.textContent = `${item.percent}%`;
            if (item.percent === 100) setState(item, 'processing');
            updateProgress();
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
            root.dispatchEvent(new CustomEvent('photo-uploaded', { bubbles: true, detail: payload }));
            if (payload.redirectUrl?.startsWith('/') && !payload.redirectUrl.startsWith('//')) item.name.href = payload.redirectUrl;
            update();
            pump();
        };
        xhr.onerror = () => fail(null, true);
        xhr.ontimeout = () => fail(null, true);
        xhr.onabort = () => fail(null, true);
        try {
            xhr.open('POST', root.dataset.apiUrl!);
            xhr.setRequestHeader('X-CSRF-Token', csrfToken());
            xhr.timeout = 120000;
            const data = new FormData();
            data.set('file', item.file!);
            data.set('requestId', item.requestId);
            xhr.send(data);
        } catch (error) { fail(error, true); }
    };
    const retry = (item: QueueItem): void => {
        if (item.state !== 'error' || !item.file) return;
        item.percent = 0;
        setState(item, 'waiting');
    };
    const addFiles = (files: Iterable<File>): void => {
        const fragment = document.createDocumentFragment();
        for (const file of files) {
            const row = document.createElement('li');
            row.className = 'users-photo-queue__item';
            const name = document.createElement('a');
            name.className = 'users-photo-queue__name';
            name.textContent = file.name;
            const status = document.createElement('span');
            status.className = 'users-photo-queue__status';
            const progress = document.createElement('progress');
            progress.max = 100;
            progress.setAttribute('aria-label', trans('js.photos.batch_file_progress', { name: file.name }));
            const retryButton = document.createElement('button');
            retryButton.type = 'button';
            retryButton.className = 'ui-button ui-button--secondary ui-button--sm';
            retryButton.textContent = trans('js.photos.batch_retry');
            retryButton.setAttribute('aria-label', trans('js.photos.batch_retry_file', { name: file.name }));
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'ui-button ui-button--secondary ui-button--sm';
            remove.textContent = trans('js.photos.batch_remove');
            remove.setAttribute('aria-label', trans('js.photos.batch_remove_file', { name: file.name }));
            const item: QueueItem = { file, requestId: requestId(), state: 'waiting', percent: 0, row, name, status, progress, retry: retryButton, remove };
            retryButton.addEventListener('click', () => { retry(item); paused = false; pump(); });
            remove.addEventListener('click', () => {
                if (item.state !== 'waiting' && item.state !== 'error') return;
                items.delete(item);
                item.file = null;
                row.remove();
                update();
            });
            row.append(name, status, progress, retryButton, remove);
            fragment.append(row);
            items.add(item);
            setState(item, 'waiting');
        }
        list.append(fragment);
        update();
        pump();
    };
    input.addEventListener('change', () => {
        addFiles(Array.from(input.files ?? []));
        input.value = '';
    });
    pause.addEventListener('click', () => { paused = !paused; update(); pump(); });
    retryAll.addEventListener('click', () => {
        for (const item of items) retry(item);
        paused = false;
        pump();
    });
    clear.addEventListener('click', () => {
        for (const item of items) {
            if (item.state === 'done') { item.row.remove(); items.delete(item); }
        }
        update();
    });
    dropzone.addEventListener('dragover', event => { event.preventDefault(); dropzone.classList.add('is-dragging'); });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('is-dragging'));
    dropzone.addEventListener('drop', event => {
        event.preventDefault();
        dropzone.classList.remove('is-dragging');
        addFiles(Array.from(event.dataTransfer?.files ?? []));
    });
    const warnBeforeLeaving = (event: BeforeUnloadEvent): void => {
        if (active.size || [...items].some(item => item.state === 'waiting' || item.state === 'error')) {
            event.preventDefault();
            event.returnValue = '';
        }
    };
    window.addEventListener('beforeunload', warnBeforeLeaving);
    return () => {
        destroyed = true;
        window.removeEventListener('beforeunload', warnBeforeLeaving);
        for (const xhr of active.values()) xhr.abort();
        active.clear();
        items.clear();
    };
}

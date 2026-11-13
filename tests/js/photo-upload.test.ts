import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initPhotoUpload } from '../../assets-src/pages/photo-upload';
import { resetTranslations, setTranslationsForTests } from '../../assets-src/shared/i18n';

class UploadRequest {
    static requests: UploadRequest[] = [];
    static maxActive = 0;
    upload = { onprogress: null as null | ((event: { loaded: number; total: number; lengthComputable: boolean }) => void) };
    onload: (() => void) | null = null;
    onerror: (() => void) | null = null;
    ontimeout: (() => void) | null = null;
    onabort: (() => void) | null = null;
    headers: Record<string, string> = {};
    status = 0;
    responseText = '';
    data: FormData | null = null;
    settled = false;
    method = '';
    url = '';
    constructor() { UploadRequest.requests.push(this); }
    open(method: string, url: string) { this.method = method; this.url = url; }
    setRequestHeader(key: string, value: string) { this.headers[key] = value; }
    send(data: FormData) {
        this.data = data;
        UploadRequest.maxActive = Math.max(UploadRequest.maxActive, UploadRequest.requests.filter(item => item.data && !item.settled).length);
    }
    respond(status: number, payload: unknown) {
        this.settled = true;
        this.status = status;
        this.responseText = JSON.stringify(payload);
        this.onload?.();
    }
    networkError() { this.settled = true; this.onerror?.(); }
    abort() { if (!this.settled) { this.settled = true; this.onabort?.(); } }
}

let dispose: (() => void) | undefined;
const button = (selector: string) => document.querySelector<HTMLButtonElement>(selector)!;
const rows = (state: string) => document.querySelectorAll(`[data-photo-queue] [data-state="${state}"]`);
const success = (index: number) => UploadRequest.requests[index].respond(201, { id: index + 1, redirectUrl: `/photos/${index + 1}/` });
function select(...files: File[]) {
    const input = document.querySelector<HTMLInputElement>('[data-photo-files]')!;
    Object.defineProperty(input, 'files', { configurable: true, value: files });
    input.dispatchEvent(new Event('change'));
}
function file(name = 'Photo.jpg') { return new File(['image'], name, { type: 'image/jpeg' }); }

beforeEach(() => {
    UploadRequest.requests = [];
    UploadRequest.maxActive = 0;
    vi.stubGlobal('XMLHttpRequest', UploadRequest);
    document.cookie = 'csrfToken=test-token';
    setTranslationsForTests({
        'js.photos.batch_summary': '{done}/{total}; failed {failed}',
        'js.photos.batch_paused': 'Paused', 'js.photos.batch_pause': 'Pause', 'js.photos.batch_resume': 'Resume',
        'js.photos.batch_processing': 'Processing', 'js.photos.batch_done': 'Uploaded',
        'js.common.action_failed': 'Upload failed',
    });
    document.body.innerHTML = `<section data-photo-uploader data-api-url="/api/v1/photo-albums/9/32/photos">
        <div data-photo-dropzone><input type="file" data-photo-files multiple></div>
        <ol data-photo-queue></ol><p data-photo-summary></p><progress data-photo-total-progress max="100" hidden></progress>
        <button data-photo-pause hidden></button><button data-photo-retry-all hidden></button><button data-photo-clear-done hidden></button>
    </section>`;
    dispose = initPhotoUpload();
});

afterEach(() => { dispose?.(); resetTranslations(); vi.unstubAllGlobals(); document.body.innerHTML = ''; });

describe('batch photo uploader', () => {
    it('uploads 200 photos with two requests at a time and no per-file form fields', () => {
        select(...Array.from({ length: 200 }, (_, i) => file(`${i}.jpg`)));
        expect(UploadRequest.requests).toHaveLength(2);
        const ids = new Set();
        for (let i = 0; i < 200; i++) {
            const request = UploadRequest.requests[i];
            expect(request.data?.has('title')).toBe(false);
            expect(request.data?.has('caption')).toBe(false);
            expect(request.headers['X-CSRF-Token']).toBe('test-token');
            expect(request.url).toBe('/api/v1/photo-albums/9/32/photos');
            ids.add(request.data?.get('requestId'));
            success(i);
        }
        expect(ids.size).toBe(200);
        expect(UploadRequest.maxActive).toBe(2);
        expect(rows('done')).toHaveLength(200);
        expect(document.querySelector('[data-photo-summary]')?.textContent).toBe('200/200; failed 0');
    });

    it('retries only failed files with the original UUID and preserves successes', () => {
        select(file('first.jpg'), file('second.jpg'), file('third.jpg'));
        const id = UploadRequest.requests[0].data?.get('requestId');
        UploadRequest.requests[0].respond(400, { error: 'Invalid image' });
        success(1); success(2);
        expect(rows('done')).toHaveLength(2);
        expect(rows('error')).toHaveLength(1);
        button('[data-photo-retry-all]').click();
        expect(UploadRequest.requests).toHaveLength(4);
        expect(UploadRequest.requests[3].data?.get('requestId')).toBe(id);
        success(3);
        expect(rows('done')).toHaveLength(3);
    });

    it('pauses scheduling while allowing active requests to finish', () => {
        select(file(), file(), file());
        button('[data-photo-pause]').click();
        success(0); success(1);
        expect(UploadRequest.requests).toHaveLength(2);
        expect(rows('waiting')).toHaveLength(1);
        button('[data-photo-pause]').click();
        expect(UploadRequest.requests).toHaveLength(3);
    });

    it('pauses on a network failure and safely resumes the failed file and remaining queue', () => {
        select(file(), file(), file());
        const id = UploadRequest.requests[0].data?.get('requestId');
        UploadRequest.requests[0].networkError();
        success(1);
        expect(UploadRequest.requests).toHaveLength(2);
        expect(rows('waiting')).toHaveLength(1);
        button('[data-photo-retry-all]').click();
        expect(UploadRequest.requests).toHaveLength(4);
        expect(UploadRequest.requests[2].data?.get('requestId')).toBe(id);
    });

    it('pauses the remaining queue when quota is exhausted', () => {
        select(file(), file(), file());
        UploadRequest.requests[0].respond(413, { code: 'storage_limit_exceeded', error: 'Storage full' });
        success(1);
        expect(UploadRequest.requests).toHaveLength(2);
        expect(rows('waiting')).toHaveLength(1);
        expect(rows('error')[0].textContent).toContain('Storage full');
    });

    it('accepts another dropped batch while uploading and can remove a waiting file', () => {
        select(file('1.jpg'), file('2.jpg'));
        const event = new Event('drop', { bubbles: true, cancelable: true });
        Object.defineProperty(event, 'dataTransfer', { value: { files: [file('3.jpg'), file('4.jpg')] } });
        document.querySelector('[data-photo-dropzone]')!.dispatchEvent(event);
        expect(rows('waiting')).toHaveLength(2);
        (rows('waiting')[0].querySelector('button:last-child') as HTMLButtonElement).click();
        success(0); success(1); success(2);
        expect(rows('done')).toHaveLength(3);
        expect(UploadRequest.requests).toHaveLength(3);
    });

    it('does not declare success at 100% transport progress before publication responds', () => {
        select(file());
        UploadRequest.requests[0].upload.onprogress?.({ loaded: 100, total: 100, lengthComputable: true });
        expect(rows('processing')).toHaveLength(1);
        expect(rows('done')).toHaveLength(0);
        success(0);
        expect(rows('done')).toHaveLength(1);
    });

    it('renders names and server errors as text, and clears completed rows only', () => {
        select(file('<img src=x>.jpg'), file('second.jpg'));
        success(0);
        UploadRequest.requests[1].respond(400, { error: '<script>bad()</script>' });
        expect(document.querySelector('img')).toBeNull();
        expect(document.querySelector('script')).toBeNull();
        expect(rows('error')[0].textContent).toContain('<script>bad()</script>');
        button('[data-photo-clear-done]').click();
        expect(rows('done')).toHaveLength(0);
        expect(rows('error')).toHaveLength(1);
    });

    it('warns before leaving with unfinished uploads and stops warning once complete', () => {
        select(file());
        const pending = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(pending);
        expect(pending.defaultPrevented).toBe(true);
        success(0);
        const done = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(done);
        expect(done.defaultPrevented).toBe(false);
    });
});

import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { initPhotoComments } from '../../assets-src/pages/photo-comments';

const comment = (id = '20', parent = '10', reply = false) => `<article data-photo-comment data-id="${id}" data-comment-parent="${parent}" data-author="Alice"><div data-photo-comment-body>Hello<br>world</div>${!reply ? '<button data-photo-comment-reply>Reply</button>' : ''}<button data-photo-comment-edit>Edit</button><button data-photo-comment-delete>Delete</button><div data-photo-comment-replies></div></article>`;
const thread = (more = false) => `<div data-photo-comment-thread data-parent-id="10"><form data-photo-comment-form><p data-photo-comment-context hidden></p><textarea></textarea><button type="submit">Send</button><button type="button" data-photo-comment-cancel hidden>Cancel</button></form><p data-photo-comment-error></p><div data-photo-comment-list>${comment()}</div>${more ? '<button data-photo-comments-more data-parent="10" data-cursor="next">More</button>' : ''}</div>`;
const viewer = (slug = '1', loaded = true, more = false) => `<section data-photo-viewer data-url="/photos/${slug}/"><a data-photo-comments-toggle href="/photos/${slug}/?discussion=1"><span data-photo-comment-count>1</span></a><aside data-photo-discussion ${loaded ? '' : 'hidden'}><button data-photo-comments-close>Close</button><div data-photo-discussion-content>${loaded ? thread(more) : ''}</div></aside></section>`;
const click = (selector: string) => document.querySelector<HTMLButtonElement>(selector)!.click();
let dispose: () => void;
let api: ReturnType<typeof vi.fn>;
let fetcher: ReturnType<typeof vi.fn>;
beforeEach(() => {
    document.body.innerHTML = viewer();
    api = vi.fn().mockResolvedValue({}); Object.assign(window, { CMS: { api } });
    fetcher = vi.fn().mockImplementation(async () => ({ ok: true, text: async () => viewer() }));
    vi.stubGlobal('fetch', fetcher); dispose = initPhotoComments();
});
afterEach(() => { dispose(); vi.unstubAllGlobals(); });
const submit = () => document.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
const text = () => document.querySelector<HTMLTextAreaElement>('textarea')!;

it('loads discussion only on opening and allows retry after failure', async () => {
    document.body.innerHTML = viewer('1', false);
    fetcher.mockRejectedValueOnce(new Error('Offline'));
    click('[data-photo-comments-toggle]');
    await vi.waitFor(() => expect(document.querySelector('[data-photo-discussion-content]')!.textContent).not.toBe(''));
    click('[data-photo-comments-close]'); click('[data-photo-comments-toggle]');
    await vi.waitFor(() => expect(document.querySelector('form')).not.toBeNull());
    expect(String(fetcher.mock.calls[1][0])).toContain('discussion=1');
    expect(document.querySelector('[data-photo-discussion]')!.hasAttribute('hidden')).toBe(false);
});

it('sends a reply, edits via PATCH and retains text on failed save', async () => {
    click('[data-photo-comment-reply]'); text().value = 'Reply';
    submit(); await vi.waitFor(() => expect(text().disabled).toBe(false));
    expect(api).toHaveBeenCalledWith('/api/v1/comments/20', { method: 'POST', data: { content: 'Reply' } });
    expect(text().value).toBe('');
    click('[data-photo-comment-edit]'); expect(text().value).toBe('Hello\nworld');
    api.mockRejectedValueOnce(new Error('Offline')); text().value = 'Changed'; submit();
    await vi.waitFor(() => expect(text().disabled).toBe(false));
    expect(api).toHaveBeenLastCalledWith('/api/v1/comments/10/20', { method: 'PATCH', data: { content: 'Changed' } });
    expect(text().value).toBe('Changed');
    expect(document.querySelector('[data-photo-comment-error]')!.textContent).not.toBe('');
    click('[data-photo-comment-cancel]'); expect(text().value).toBe('');
});

it('restores drafts for each photo, without overwriting a composition when reply is clicked', () => {
    text().value = 'Unsent'; click('[data-photo-comment-edit]'); expect(text().value).toBe('Unsent');
    document.querySelector('[data-photo-viewer]')!.dispatchEvent(new Event('photo-viewer-changing', { bubbles: true }));
    document.body.innerHTML = viewer('2');
    document.querySelector('[data-photo-viewer]')!.dispatchEvent(new Event('photo-viewer-ready', { bubbles: true }));
    expect(text().value).toBe('');
    document.body.innerHTML = viewer('1');
    document.querySelector('[data-photo-viewer]')!.dispatchEvent(new Event('photo-viewer-ready', { bubbles: true }));
    expect(text().value).toBe('Unsent');
});

it('does not replace another photo panel when a pending save finishes', async () => {
    let resolve!: (value: unknown) => void;
    api.mockImplementationOnce(() => new Promise(done => { resolve = done; }));
    text().value = 'Photo one'; submit();
    document.body.innerHTML = viewer('2');
    document.querySelector('[data-photo-viewer]')!.dispatchEvent(new Event('photo-viewer-ready', { bubbles: true }));
    text().value = 'Photo two'; resolve({});
    await vi.waitFor(() => expect(api).toHaveBeenCalledOnce());
    await Promise.resolve(); expect(fetcher).not.toHaveBeenCalled(); expect(text().value).toBe('Photo two');
});

it('requires inline confirmation before deleting and preserves the composition', async () => {
    text().value = 'Draft'; click('[data-photo-comment-delete]'); expect(api).not.toHaveBeenCalled();
    click('[data-photo-comment-confirm] button');
    await vi.waitFor(() => expect(fetcher).toHaveBeenCalledOnce());
    expect(api).toHaveBeenCalledWith('/api/v1/comments/10/20', { method: 'DELETE' });
    await vi.waitFor(() => expect(text().disabled).toBe(false)); expect(text().value).toBe('Draft');
});

it('appends paginated replies into their own thread', async () => {
    const replies = document.querySelector('[data-photo-comment-replies]')!;
    replies.innerHTML = '<button data-photo-comments-more data-parent="20" data-cursor="older">More</button>';
    fetcher.mockResolvedValueOnce({ ok: true, text: async () => `<div data-photo-comment-thread><div data-photo-comment-list>${comment('21', '20', true)}</div></div>` });
    click('[data-photo-comments-more]');
    await vi.waitFor(() => expect(replies.querySelector('[data-id="21"]')).not.toBeNull());
    expect(String(fetcher.mock.calls[0][0])).toContain('comment_parent=20');
    expect(replies.querySelector('[data-photo-comments-more]')).toBeNull();
});

it('keeps the composer compact until requested, and folds it after cancel', () => {
    dispose();
    document.body.innerHTML = viewer();
    const form = document.querySelector('form')!;
    const prompt = document.createElement('button'); prompt.type = 'button'; prompt.dataset.photoComposeOpen = ''; form.prepend(prompt);
    const actions = document.createElement('div'); actions.dataset.photoComposeActions = ''; form.append(actions);
    dispose = initPhotoComments();
    expect(text().hidden).toBe(true); expect(prompt.hidden).toBe(false); expect(actions.hidden).toBe(true);
    prompt.click(); expect(text().hidden).toBe(false); expect(actions.hidden).toBe(false);
    expect(document.activeElement).toBe(text());
    click('[data-photo-comment-cancel]'); expect(text().hidden).toBe(true); expect(document.activeElement).toBe(prompt);
    click('[data-photo-comment-reply]'); expect(text().hidden).toBe(false);
    expect(document.querySelector('[data-photo-comment-context]')!.hasAttribute('hidden')).toBe(false);
});

it('opens the desktop column automatically, remembers hiding it, and restores it on request', async () => {
    dispose();
    vi.stubGlobal('matchMedia', () => ({ matches: true, addEventListener: vi.fn() }));
    document.body.innerHTML = viewer('1', false);
    dispose = initPhotoComments();
    await vi.waitFor(() => expect(document.querySelector('form')).not.toBeNull());
    expect(document.querySelector<HTMLElement>('[data-photo-discussion]')!.hidden).toBe(false);
    click('[data-photo-comments-close]');
    document.body.innerHTML = viewer('2', false);
    document.querySelector('[data-photo-viewer]')!.dispatchEvent(new Event('photo-viewer-ready', { bubbles: true }));
    expect(document.querySelector<HTMLElement>('[data-photo-discussion]')!.hidden).toBe(true);
    click('[data-photo-comments-toggle]');
    await vi.waitFor(() => expect(document.querySelector('form')).not.toBeNull());
    expect(document.querySelector<HTMLElement>('[data-photo-discussion]')!.hidden).toBe(false);
});

it('switches between mobile details and discussion, expands the sheet and closes it with Escape', () => {
    const panel = document.querySelector<HTMLElement>('[data-photo-discussion]')!; panel.hidden = true;
    const toggle = document.querySelector<HTMLElement>('[data-photo-comments-toggle]')!;
    const ratingToggle = toggle.cloneNode(true) as HTMLElement; ratingToggle.dataset.photoShowDetails = ''; toggle.after(ratingToggle);
    const expand = document.createElement('button'); expand.dataset.photoCommentsExpand = ''; panel.append(expand);
    toggle.click(); expect(panel.dataset.showDetails).toBe('false');
    ratingToggle.click(); expect(panel.hidden).toBe(false); expect(panel.dataset.showDetails).toBe('true');
    expand.click(); expect(panel.dataset.expanded).toBe('true'); expect(expand.getAttribute('aria-pressed')).toBe('true');
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    expect(panel.hidden).toBe(true); expect(expand.getAttribute('aria-pressed')).toBe('false');
});

it('shows one floating actions menu, supports keyboard focus and closes it before the discussion', async () => {
    const list = document.querySelector('[data-photo-comment-list]')!;
    const markup = '<details data-photo-comment-menu><summary>Actions</summary><div><button data-photo-comment-edit>Edit</button><button data-photo-comment-delete>Delete</button></div></details>';
    list.innerHTML = markup + markup;
    const menus = [...list.querySelectorAll<HTMLDetailsElement>('details')];
    menus[0].open = true;
    await vi.waitFor(() => expect(menus[0].querySelector('div')!.style.position).toBe('fixed'));
    menus[1].open = true;
    await vi.waitFor(() => expect(menus[0].open).toBe(false));
    menus[1].querySelector('summary')!.focus();
    document.activeElement!.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
    expect(document.activeElement).toBe(menus[1].querySelector('button'));
    document.activeElement!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    expect(menus[1].open).toBe(false);
    expect(document.activeElement).toBe(menus[1].querySelector('summary'));
    expect(document.querySelector<HTMLElement>('[data-photo-discussion]')!.hidden).toBe(false);
    menus[1].open = true;
    document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    expect(menus[1].open).toBe(false);
});

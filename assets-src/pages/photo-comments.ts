import { trans } from '../shared/i18n';
import { getApiErrorMessage } from '../shared/api-errors';

type Draft = { text: string; parent: string; edit?: string; context: string; expanded?: boolean };

/** Photo discussion uses the regular comments API and server-rendered permission controls. */
export function initPhotoComments(): () => void {
    const drafts = new Map<string, Draft>();
    const pending = new Set<string>();
    const events = new AbortController();
    const options = { signal: events.signal };
    const desktop = window.matchMedia?.('(min-width: 48rem)');
    let columnHidden = false;
    const current = () => document.querySelector<HTMLElement>('[data-photo-viewer]');
    const formOf = (viewer: HTMLElement) => viewer.querySelector<HTMLFormElement>('[data-photo-comment-form]');
    const textOf = (form: HTMLFormElement) => form.querySelector<HTMLTextAreaElement>('textarea')!;
    const report = (viewer: HTMLElement, reason: unknown) => {
        const error = viewer.querySelector<HTMLElement>('[data-photo-comment-error]');
        if (error) error.textContent = getApiErrorMessage(reason, trans('js.common.action_failed'));
    };
    const snapshot = (viewer: HTMLElement) => {
        const form = formOf(viewer);
        if (!form) return;
        drafts.set(viewer.dataset.url!, { text: textOf(form).value, parent: form.dataset.parent!, edit: form.dataset.edit,
            context: form.querySelector('[data-photo-comment-context]')?.textContent ?? '', expanded: form.dataset.expanded === 'true' });
    };
    const restore = (viewer: HTMLElement) => {
        const form = formOf(viewer);
        if (!form) return;
        const root = viewer.querySelector<HTMLElement>('[data-photo-comment-thread]')!.dataset.parentId!;
        const draft = drafts.get(viewer.dataset.url!) ?? { text: '', parent: root, context: '' };
        form.dataset.parent = draft.parent || root;
        if (draft.edit) form.dataset.edit = draft.edit; else delete form.dataset.edit;
        textOf(form).value = draft.text;
        const context = form.querySelector<HTMLElement>('[data-photo-comment-context]')!;
        context.textContent = draft.context; context.hidden = !draft.context;
        const expanded = Boolean(draft.expanded || draft.text || draft.context);
        form.dataset.expanded = String(expanded);
        const prompt = form.querySelector<HTMLElement>('[data-photo-compose-open]');
        if (prompt) { prompt.hidden = expanded; textOf(form).hidden = !expanded; }
        const actions = form.querySelector<HTMLElement>('[data-photo-compose-actions]'); if (actions) actions.hidden = !expanded;
        form.querySelector<HTMLElement>('[data-photo-comment-cancel]')!.hidden = !expanded;
        form.querySelectorAll<HTMLButtonElement | HTMLTextAreaElement>('button, textarea').forEach(el => el.disabled = pending.has(viewer.dataset.url!));
    };
    const page = async (viewer: HTMLElement, parent?: string, cursor?: string) => {
        const url = new URL(viewer.dataset.url!, location.href);
        url.searchParams.set('discussion', '1');
        if (parent) url.searchParams.set('comment_parent', parent);
        if (cursor) url.searchParams.set('comment_cursor', cursor);
        const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: events.signal });
        if (!response.ok) throw new Error(trans('js.common.action_failed'));
        return new DOMParser().parseFromString(await response.text(), 'text/html');
    };
    const refresh = async (viewer: HTMLElement) => {
        const parsed = await page(viewer);
        if (!viewer.isConnected) return;
        const content = parsed.querySelector('[data-photo-discussion-content]');
        if (!content) throw new Error(trans('js.common.action_failed'));
        viewer.querySelector('[data-photo-discussion-content]')!.replaceChildren(...Array.from(content.childNodes));
        const count = parsed.querySelector('[data-photo-comment-count]')?.textContent;
        if (count !== undefined) viewer.querySelectorAll('[data-photo-comment-count]').forEach(el => el.textContent = count);
        restore(viewer);
    };
    const open = async (viewer: HTMLElement) => {
        const panel = viewer.querySelector<HTMLElement>('[data-photo-discussion]')!;
        panel.hidden = false;
        viewer.querySelectorAll('[data-photo-comments-toggle]').forEach(el => el.setAttribute('aria-expanded', 'true'));
        const content = viewer.querySelector<HTMLElement>('[data-photo-discussion-content]')!;
        if (!content.querySelector('[data-photo-comment-thread]') && !content.hasAttribute('aria-busy')) {
            content.setAttribute('aria-busy', 'true');
            const spinner = document.createElement('span'); spinner.className = 'ui-spinner'; spinner.setAttribute('role', 'status');
            spinner.setAttribute('aria-label', trans('js.common.loading')); content.replaceChildren(spinner);
            try { await refresh(viewer); }
            catch (reason) {
                // Keep a retry available even when the initial panel request fails.
                if (viewer.isConnected) { content.textContent = getApiErrorMessage(reason, trans('js.common.action_failed')); }
            } finally { content.removeAttribute('aria-busy'); }
        }
    };
    const ready = (viewer: HTMLElement) => {
        restore(viewer);
        if (desktop?.matches && !columnHidden) void open(viewer);
    };
    desktop?.addEventListener('change', () => {
        const viewer = current(); if (!viewer) return;
        if (desktop.matches && !columnHidden) void open(viewer);
        else {
            viewer.querySelector<HTMLElement>('[data-photo-discussion]')!.hidden = true;
            viewer.querySelectorAll('[data-photo-comments-toggle]').forEach(el => el.setAttribute('aria-expanded', 'false'));
        }
    }, options);
    document.addEventListener('keydown', event => {
        const viewer = current();
        if (event.key === 'Escape' && viewer?.querySelector<HTMLElement>('[data-photo-discussion]')?.hidden === false) {
            event.preventDefault(); event.stopImmediatePropagation();
            viewer.querySelector<HTMLButtonElement>('[data-photo-comments-close]')?.click();
        }
    }, { ...options, capture: true });
    document.addEventListener('photo-viewer-changing', event => snapshot(event.target as HTMLElement), options);
    document.addEventListener('photo-viewer-ready', event => ready(event.target as HTMLElement), options);
    document.addEventListener('focusin', event => {
        const target = event.target as HTMLElement;
        const viewer = target.closest<HTMLElement>('[data-photo-viewer]');
        if (!desktop?.matches && viewer && target.matches('[data-photo-comment-form] textarea')) {
            viewer.querySelector<HTMLElement>('[data-photo-discussion]')!.dataset.expanded = 'true';
            viewer.querySelector('[data-photo-comments-expand]')?.setAttribute('aria-pressed', 'true');
        }
    }, options);
    document.addEventListener('input', event => {
        const viewer = (event.target as HTMLElement).closest<HTMLElement>('[data-photo-viewer]');
        if (viewer && (event.target as HTMLElement).closest('[data-photo-comment-form]')) snapshot(viewer);
    }, options);
    document.addEventListener('click', event => {
        const target = (event.target as HTMLElement).closest<HTMLElement>('button, a');
        const viewer = target?.closest<HTMLElement>('[data-photo-viewer]');
        if (!target || !viewer || (target as HTMLButtonElement).disabled) return;
        const panel = viewer.querySelector<HTMLElement>('[data-photo-discussion]');
        if (target.matches('[data-photo-comments-toggle]')) {
            if (event instanceof MouseEvent && (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey)) return;
            event.preventDefault();
            const showDetails = String(target.hasAttribute('data-photo-show-details') || target.classList.contains('users-photo-viewer__details-toggle'));
            const switchSection = !desktop?.matches && !panel?.hidden && panel?.dataset.showDetails !== showDetails;
            if (panel) panel.dataset.showDetails = showDetails;
            if (panel?.hidden) { columnHidden = false; void open(viewer); panel?.focus({ preventScroll: true }); } else if (!switchSection) viewer.querySelector<HTMLButtonElement>('[data-photo-comments-close]')?.click();
            return;
        }
        if (target.matches('[data-photo-comments-close]')) {
            panel!.hidden = true; delete panel!.dataset.expanded;
            viewer.querySelector('[data-photo-comments-expand]')?.setAttribute('aria-pressed', 'false');
            if (desktop?.matches) columnHidden = true;
            viewer.querySelectorAll('[data-photo-comments-toggle]').forEach(el => el.setAttribute('aria-expanded', 'false'));
            const toggles = [...viewer.querySelectorAll<HTMLElement>('[data-photo-comments-toggle]')];
            (toggles.find(el => el.getClientRects().length) ?? toggles[0])?.focus(); return;
        }
        if (target.matches('[data-photo-comments-expand]')) {
            const expanded = panel!.dataset.expanded !== 'true'; panel!.dataset.expanded = String(expanded);
            target.setAttribute('aria-pressed', String(expanded)); return;
        }
        const form = formOf(viewer);
        const comment = target.closest<HTMLElement>('[data-photo-comment]');
        const key = viewer.dataset.url!;
        if (pending.has(key)) return;
        if (form && target.matches('[data-photo-compose-open]')) {
            snapshot(viewer); drafts.get(key)!.expanded = true; restore(viewer); textOf(form).focus(); return;
        }
        if (target.matches('[data-photo-comment-cancel]') && form) {
            drafts.delete(key); restore(viewer); form.querySelector<HTMLElement>('[data-photo-compose-open]')?.focus(); return;
        }
        if (form && comment && target.matches('[data-photo-comment-reply], [data-photo-comment-edit]')) {
            // Preserve an existing composition instead of silently replacing its text.
            if (textOf(form).value.trim()) { textOf(form).focus(); return; }
            const edit = target.matches('[data-photo-comment-edit]');
            const body = comment.querySelector('[data-photo-comment-body]')!.cloneNode(true) as HTMLElement;
            body.querySelectorAll('br').forEach(br => br.replaceWith('\n'));
            drafts.set(key, { text: edit ? body.textContent ?? '' : '',
                parent: edit ? comment.dataset.commentParent! : comment.dataset.id!, edit: edit ? comment.dataset.id : undefined,
                expanded: true, context: edit ? trans('js.photos.comment_edit') : trans('js.photos.comment_reply', { author: comment.dataset.author! }) });
            target.closest('details')?.removeAttribute('open');
            restore(viewer); textOf(form).focus(); return;
        }
        if (comment && target.matches('[data-photo-comment-delete]')) {
            target.closest('details')?.removeAttribute('open');
            const confirmation = comment.querySelector<HTMLElement>(':scope > [data-photo-comment-confirm]');
            if (confirmation) { confirmation.remove(); return; }
            const box = document.createElement('div'); box.dataset.photoCommentConfirm = ''; box.className = 'users-photo-comment__confirm';
            const label = document.createElement('p'); label.textContent = trans('js.photos.comment_delete'); box.append(label);
            const yes = document.createElement('button'); yes.type = 'button'; yes.className = 'ui-button ui-button--danger ui-button--sm';
            yes.textContent = target.textContent; box.append(yes);
            const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'ui-button ui-button--secondary ui-button--sm';
            cancel.textContent = trans('js.common.cancel'); box.append(cancel);
            cancel.addEventListener('click', () => { box.remove(); target.focus(); });
            yes.addEventListener('click', async () => {
                if (pending.has(key)) return;
                snapshot(viewer); pending.add(key); restore(viewer); yes.disabled = true;
                try {
                    await window.CMS.api(`/api/v1/comments/${comment.dataset.commentParent}/${comment.dataset.id}`, { method: 'DELETE' });
                    const draft = drafts.get(key);
                    if (draft?.edit === comment.dataset.id || draft?.parent === comment.dataset.id) drafts.delete(key);
                    const active = current(); if (active?.dataset.url === key) await refresh(active);
                } catch (reason) { report(viewer, reason); }
                finally { pending.delete(key); yes.disabled = false; const active = current(); if (active?.dataset.url === key) restore(active); }
            });
            comment.append(box); yes.focus(); return;
        }
        if (target.matches('[data-photo-comments-more]')) {
            const button = target as HTMLButtonElement; button.disabled = true;
            void page(viewer, button.dataset.parent, button.dataset.cursor).then(parsed => {
                if (!viewer.isConnected) return;
                const thread = parsed.querySelector('[data-photo-comment-thread]');
                if (!thread) throw new Error(trans('js.common.action_failed'));
                const list = thread.querySelector('[data-photo-comment-list]')!;
                const container = button.closest('[data-photo-comment-replies]') ?? viewer.querySelector('[data-photo-comment-list]')!;
                const items = Array.from(list.children).filter(el => el.matches('[data-photo-comment]'));
                if (button.parentElement === container) button.before(...items); else container.append(...items);
                const next = thread.querySelector(':scope > [data-photo-comments-more]');
                if (next) button.replaceWith(next); else button.remove();
            }).catch(reason => report(viewer, reason)).finally(() => { button.disabled = false; });
        }
    }, options);
    document.addEventListener('submit', event => {
        const form = event.target as HTMLFormElement;
        if (!form.matches('[data-photo-comment-form]')) return;
        event.preventDefault();
        const viewer = form.closest<HTMLElement>('[data-photo-viewer]')!;
        const key = viewer.dataset.url!;
        if (pending.has(key) || !textOf(form).value.trim()) return;
        snapshot(viewer); pending.add(key); restore(viewer);
        const error = viewer.querySelector('[data-photo-comment-error]'); if (error) error.textContent = '';
        const draft = drafts.get(key)!;
        void window.CMS.api(`/api/v1/comments/${draft.parent}${draft.edit ? `/${draft.edit}` : ''}`, {
            method: draft.edit ? 'PATCH' : 'POST', data: { content: draft.text.trim() },
        }).then(async () => {
            drafts.delete(key);
            // A newly opened copy of this photo may exist while the original request finishes.
            const active = current();
            if (active?.dataset.url === key) await refresh(active);
        }).catch(reason => { const active = current(); if (active?.dataset.url === key) report(active, reason); })
            .finally(() => { pending.delete(key); const active = current(); if (active?.dataset.url === key) restore(active); });
    }, options);
    window.addEventListener('beforeunload', event => {
        const viewer = current(); if (viewer) snapshot(viewer);
        if ([...drafts.values()].some(draft => draft.text.trim())) { event.preventDefault(); event.returnValue = ''; }
    }, options);
    const viewer = current(); if (viewer) ready(viewer);
    return () => events.abort();
}

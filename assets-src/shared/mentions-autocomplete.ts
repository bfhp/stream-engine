type MentionCandidate = {
    id: number;
    username: string;
    displayName: string;
    avatarUrl?: string;
};

type CandidateResponse = { data?: MentionCandidate[] };

const ELIGIBLE_SELECTOR = 'textarea[name="content"], textarea[data-message-input]';
const TOKEN_AT_CARET = /(^|[^A-Za-z0-9_@./\\-])@([A-Za-z0-9_-]{1,30})$/;

/**
 * One delegated autocomplete for comments, forums and messages. It inserts
 * plain @username text only; parsing and identity resolution remain server
 * responsibilities.
 */
export function initMentionAutocomplete(): void {
    let textarea: HTMLTextAreaElement | null = null;
    let tokenStart = -1;
    let selected = 0;
    let candidates: MentionCandidate[] = [];
    let timer: number | null = null;
    let request: AbortController | null = null;

    const popup = document.createElement('div');
    popup.className = 'mention-autocomplete';
    popup.setAttribute('role', 'listbox');
    popup.hidden = true;
    document.body.append(popup);

    const close = () => {
        popup.hidden = true;
        popup.replaceChildren();
        textarea?.removeAttribute('aria-activedescendant');
        candidates = [];
        selected = 0;
        tokenStart = -1;
        request?.abort();
        request = null;
    };

    const position = () => {
        if (!textarea) return;
        const rect = textarea.getBoundingClientRect();
        popup.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - 288))}px`;
        popup.style.top = `${Math.min(rect.bottom + 4, window.innerHeight - popup.offsetHeight - 8)}px`;
        popup.style.width = `${Math.max(240, Math.min(rect.width, 360))}px`;
    };

    const render = () => {
        popup.replaceChildren(...candidates.map((candidate, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.id = `mention-option-${candidate.id}`;
            option.className = 'mention-autocomplete__option';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', index === selected ? 'true' : 'false');
            option.dataset.index = String(index);

            const name = document.createElement('span');
            name.className = 'mention-autocomplete__name';
            name.textContent = candidate.displayName;
            const username = document.createElement('span');
            username.className = 'mention-autocomplete__username';
            username.textContent = `@${candidate.username}`;
            option.append(name, username);
            return option;
        }));

        popup.hidden = candidates.length === 0;
        if (!popup.hidden && textarea) {
            textarea.setAttribute('aria-activedescendant', `mention-option-${candidates[selected].id}`);
            position();
        }
    };

    const choose = (index: number) => {
        if (!textarea || tokenStart < 0 || !candidates[index]) return;
        const candidate = candidates[index];
        const caret = textarea.selectionStart;
        const insertion = `@${candidate.username} `;
        textarea.setRangeText(insertion, tokenStart, caret, 'end');
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        close();
        textarea.focus();
    };

    const lookup = (target: HTMLTextAreaElement) => {
        textarea = target;
        const caret = target.selectionStart;
        const before = target.value.slice(0, caret);
        const match = before.match(TOKEN_AT_CARET);
        if (!match) {
            close();
            return;
        }

        tokenStart = caret - match[2].length - 1;
        request?.abort();
        request = new AbortController();
        const activeRequest = request;

        fetch(`/api/v1/users?username=${encodeURIComponent(match[2])}`, {
            credentials: 'include',
            signal: activeRequest.signal,
            headers: { Accept: 'application/json' },
        })
            .then(response => response.ok ? response.json() as Promise<CandidateResponse> : Promise.reject())
            .then(payload => {
                if (request !== activeRequest || textarea !== target) return;
                candidates = Array.isArray(payload.data) ? payload.data.slice(0, 8) : [];
                selected = 0;
                render();
            })
            .catch(error => {
                if (error?.name !== 'AbortError') close();
            });
    };

    document.addEventListener('input', event => {
        const target = event.target;
        if (!(target instanceof HTMLTextAreaElement) || !target.matches(ELIGIBLE_SELECTOR)) return;
        if (timer !== null) window.clearTimeout(timer);
        timer = window.setTimeout(() => lookup(target), 150);
    });

    document.addEventListener('keydown', event => {
        if (popup.hidden || event.target !== textarea) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const delta = event.key === 'ArrowDown' ? 1 : -1;
            selected = (selected + delta + candidates.length) % candidates.length;
            render();
        } else if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            choose(selected);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            close();
        }
    });

    popup.addEventListener('mousedown', event => {
        event.preventDefault();
        const option = (event.target as HTMLElement).closest<HTMLElement>('[data-index]');
        if (option) choose(Number(option.dataset.index));
    });

    document.addEventListener('mousedown', event => {
        if (event.target !== textarea && !popup.contains(event.target as Node)) close();
    });
    window.addEventListener('resize', position);
    window.addEventListener('scroll', position, true);
}

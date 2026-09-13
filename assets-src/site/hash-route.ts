/* ==========================================================================
   Hash route

   One shared owner of the address bar's fragment, so several bits of the
   page can each keep their own piece of state in the URL without fighting
   over `location.hash` (whoever assigned it last would otherwise wipe
   everyone else's). The fragment is treated as a query string:

       #c=Xk3f9&tab=media

   Reads are `get('c')`, writes are `set('c', 'Xk3f9')`, and each write
   leaves a history entry, so Back/Forward walk through them. Listeners get
   told which kind of change it was, letting a feature ignore its own writes
   and react only to real navigation.

   Note this deliberately does NOT re-render anything by itself: it reports
   changes, consumers decide what (if anything) to do with them.
   ========================================================================== */

export type HashParams = Record<string, string>;

export type HashChangeSource =
    // Back/Forward, or an external change (the visitor edited the URL, a
    // link with a different hash was followed).
    | 'navigation'
    // One of set()/patch()/clear() on this page.
    | 'programmatic';

export type HashChangeEvent = {
    params: HashParams;
    previous: HashParams;
    source: HashChangeSource;
};

export type HashChangeListener = (event: HashChangeEvent) => void;

type WriteOptions = {
    /**
     * Overwrite the current history entry instead of adding one. Use for
     * changes the visitor shouldn't have to press Back through - restoring
     * state on load, normalizing a hand-typed hash, and such.
     */
    replace?: boolean;
};

const listeners = new Set<HashChangeListener>();

let started = false;

// Serialized form of the hash as this module last saw it. Both the dedupe
// key (Back over a hash-only entry fires popstate AND hashchange - the same
// change must not be announced twice) and the way an outside change is
// noticed at all.
let knownHash = '';

function readRawHash(): string {
    // location.hash keeps the '#'; an empty fragment and no fragment at all
    // are the same thing here.
    return window.location.hash.replace(/^#/, '');
}

function parse(raw: string): HashParams {
    const params: HashParams = {};
    if (raw === '') return params;

    new URLSearchParams(raw).forEach((value, key) => {
        params[key] = value;
    });

    return params;
}

function serialize(params: HashParams): string {
    const search = new URLSearchParams();

    Object.keys(params).forEach((key) => {
        const value = params[key];
        if (value === '' || value === null || value === undefined) return;
        search.set(key, value);
    });

    // URLSearchParams percent-encodes far more than a fragment needs;
    // leaving these readable keeps the URL sane to look at and is still
    // valid in a fragment.
    return search.toString().replace(/%2F/gi, '/').replace(/%3A/gi, ':');
}

function emit(previous: HashParams, params: HashParams, source: HashChangeSource): void {
    const event: HashChangeEvent = { params, previous, source };

    // Copy first: a listener may unsubscribe (or subscribe) while we're
    // iterating, and a Set mutated mid-iteration would visit the new entry.
    Array.from(listeners).forEach((listener) => {
        try {
            listener(event);
        } catch (e) {
            console.error('Hash route listener failed', e);
        }
    });
}

function handleExternalChange(): void {
    const raw = readRawHash();
    if (raw === knownHash) return;

    const previous = parse(knownHash);
    knownHash = raw;

    emit(previous, parse(raw), 'navigation');
}

/**
 * Idempotent, and called from every public entry point rather than only
 * from main.ts - a page bundle may reach the router before (or without) the
 * site bundle's DOMContentLoaded hook having run.
 */
function ensureStarted(): void {
    if (started) return;
    started = true;

    knownHash = readRawHash();

    // Writes go through the history API, which does not fire hashchange -
    // so anything these two hear is by definition someone else's doing:
    // Back/Forward, or the visitor editing the URL.
    window.addEventListener('popstate', handleExternalChange);
    window.addEventListener('hashchange', handleExternalChange);
}

function write(next: HashParams, options: WriteOptions = {}): void {
    ensureStarted();

    const raw = serialize(next);
    if (raw === knownHash) return;

    const previous = parse(knownHash);
    knownHash = raw;

    // history.pushState with an empty fragment leaves no trailing '#',
    // unlike `location.hash = ''`.
    const url = window.location.pathname
        + window.location.search
        + (raw === '' ? '' : `#${raw}`);

    if (options.replace) {
        window.history.replaceState(window.history.state, '', url);
    } else {
        window.history.pushState(window.history.state, '', url);
    }

    emit(previous, parse(raw), 'programmatic');
}

/* ===============================
   Public API
=============================== */

export function initHashRoute(): void {
    ensureStarted();
}

export function getAll(): HashParams {
    ensureStarted();
    return parse(readRawHash());
}

export function get(key: string): string | null {
    return getAll()[key] ?? null;
}

/**
 * Sets one key, leaving the rest of the fragment alone. A null/empty value
 * removes the key.
 */
export function set(key: string, value: string | null, options: WriteOptions = {}): void {
    const next = getAll();

    if (value === null || value === '') {
        delete next[key];
    } else {
        next[key] = value;
    }

    write(next, options);
}

/**
 * Merges several keys in one history entry. A null value removes its key.
 */
export function patch(values: Record<string, string | null>, options: WriteOptions = {}): void {
    const next = getAll();

    Object.keys(values).forEach((key) => {
        const value = values[key];
        if (value === null || value === '') {
            delete next[key];
        } else {
            next[key] = value;
        }
    });

    write(next, options);
}

export function clear(options: WriteOptions = {}): void {
    write({}, options);
}

/**
 * Returns an unsubscribe function.
 */
export function onChange(listener: HashChangeListener): () => void {
    ensureStarted();
    listeners.add(listener);

    return () => {
        listeners.delete(listener);
    };
}

export const hashRoute = {
    init: initHashRoute,
    get,
    getAll,
    set,
    patch,
    clear,
    onChange,
};

export default hashRoute;

import { trans } from "./i18n";

/*
 * Trix renders every toolbar button with tabindex="-1", so the toolbar
 * cannot be reached with Tab at all (WCAG 2.1.1); only the editor's own
 * shortcuts work. This turns it into an ARIA toolbar: one Tab stop, the
 * arrow keys (and Home/End) move between buttons, and focus skips buttons
 * that are disabled or hidden. Tab from the toolbar goes on to the editor.
 */

const BUTTON_SELECTOR = ".trix-button-row button";
const bound = new WeakSet<HTMLElement>();

function isAvailable(button: HTMLButtonElement): boolean {
    return !button.disabled && button.getClientRects().length > 0;
}

function buttonsOf(toolbar: HTMLElement): HTMLButtonElement[] {
    return [...toolbar.querySelectorAll<HTMLButtonElement>(BUTTON_SELECTOR)];
}

/** Keeps exactly one available button in the Tab order: the last one used,
 *  or the first available one when that is disabled or gone. */
export function syncToolbarTabStop(toolbar: HTMLElement, preferred?: HTMLButtonElement | null): void {
    const buttons = buttonsOf(toolbar);
    const current = preferred && buttons.includes(preferred) && isAvailable(preferred)
        ? preferred
        : buttons.find(button => button.tabIndex === 0 && isAvailable(button)) ?? buttons.find(isAvailable);
    buttons.forEach(button => { button.tabIndex = button === current ? 0 : -1; });
}

/*
 * Trix shows an applied format (bold, a heading, a list) only by the
 * `trix-active` class, so a screen reader cannot tell it is on (WCAG 4.1.2).
 * Format buttons (data-trix-attribute) become toggle buttons with
 * aria-pressed; action buttons (undo, attach) are left as they are.
 */
export function syncPressedState(toolbar: HTMLElement): void {
    toolbar.querySelectorAll<HTMLButtonElement>(`${BUTTON_SELECTOR}[data-trix-attribute]`).forEach((button) => {
        const pressed = String(button.classList.contains("trix-active"));
        if (button.getAttribute("aria-pressed") !== pressed) button.setAttribute("aria-pressed", pressed);
    });
}

export function enableToolbarKeyboard(toolbar: HTMLElement): void {
    if (bound.has(toolbar)) return;
    bound.add(toolbar);

    const row = toolbar.querySelector<HTMLElement>(".trix-button-row");
    if (row) {
        row.setAttribute("role", "toolbar");
        if (!row.hasAttribute("aria-label")) row.setAttribute("aria-label", trans("js.editor.toolbar"));
    }

    syncToolbarTabStop(toolbar);

    toolbar.addEventListener("keydown", (event) => {
        const target = event.target as HTMLElement;
        if (!(target instanceof HTMLButtonElement) || !target.matches(BUTTON_SELECTOR)) return;
        // Trix acts on mousedown and cancels the click, so Enter and Space
        // (which only click) did nothing. Hand Trix the event it listens for;
        // it then applies the format and returns focus to the editor.
        if (event.key === "Enter" || event.key === " ") {
            if (target.disabled) return;
            event.preventDefault();
            target.dispatchEvent(new MouseEvent("mousedown", { bubbles: true, cancelable: true }));
            return;
        }

        const available = buttonsOf(toolbar).filter(isAvailable);
        const index = available.indexOf(target);
        if (index < 0) return;

        const rtl = getComputedStyle(toolbar).direction === "rtl";
        const next = rtl ? "ArrowLeft" : "ArrowRight";
        const previous = rtl ? "ArrowRight" : "ArrowLeft";
        let destination: HTMLButtonElement | undefined;
        if (event.key === next) destination = available[(index + 1) % available.length];
        else if (event.key === previous) destination = available[(index - 1 + available.length) % available.length];
        else if (event.key === "Home") destination = available[0];
        else if (event.key === "End") destination = available[available.length - 1];
        if (!destination) return;

        event.preventDefault();
        syncToolbarTabStop(toolbar, destination);
        destination.focus();
    });

    toolbar.addEventListener("focusin", (event) => {
        const target = event.target as HTMLElement;
        if (target instanceof HTMLButtonElement && target.matches(BUTTON_SELECTOR)) syncToolbarTabStop(toolbar, target);
    });

    syncPressedState(toolbar);

    // Trix disables Undo/Redo and toggles other buttons as the selection
    // changes; keep the Tab stop on a button that can take focus, and the
    // pressed state of format buttons in step with their highlight.
    new MutationObserver(() => {
        syncToolbarTabStop(toolbar);
        syncPressedState(toolbar);
    }).observe(toolbar, { subtree: true, attributes: true, attributeFilter: ["disabled", "hidden", "class"] });
}

type TrixEditorElement = HTMLElement & { toolbarElement?: HTMLElement | null };

function toolbarOf(editor: TrixEditorElement): HTMLElement | null {
    if (editor.toolbarElement) return editor.toolbarElement;
    const id = editor.getAttribute("toolbar");
    return id ? document.getElementById(id) : null;
}

/** Applies to every Trix editor on the page, now and as they initialize. */
export function enableTrixToolbarKeyboard(root: ParentNode = document): void {
    root.querySelectorAll<TrixEditorElement>("trix-editor").forEach((editor) => {
        const toolbar = toolbarOf(editor);
        if (toolbar) enableToolbarKeyboard(toolbar);
    });
}

let listening = false;

export function installTrixToolbarKeyboard(): void {
    if (listening) return;
    listening = true;
    document.addEventListener("trix-initialize", (event) => {
        const toolbar = toolbarOf(event.target as TrixEditorElement);
        if (toolbar) enableToolbarKeyboard(toolbar);
    });
    enableTrixToolbarKeyboard();
}

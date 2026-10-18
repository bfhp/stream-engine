/*
 * A button that is disabled while its request runs ("Save", "Send") loses
 * keyboard focus: the browser moves it to <body>, so when the button comes
 * back the focus ring is gone and the next Tab starts from wherever the
 * browser guesses (WCAG 2.4.3, 2.4.7). This puts focus back on the control
 * once it is enabled again, provided nothing else took focus meanwhile.
 */

const FOCUSABLE = "button, input, select, textarea";

let lastFocused: HTMLElement | null = null;
const waiting = new WeakSet<HTMLElement>();
let installed = false;

function focusIsLost(): boolean {
    const active = document.activeElement;
    return active === null || active === document.body || active === document.documentElement;
}

export function installFocusKeeper(root: Document = document): void {
    if (installed) return;
    installed = true;

    root.addEventListener("focusin", (event) => {
        lastFocused = event.target instanceof HTMLElement ? event.target : null;
    });

    new MutationObserver((records) => {
        for (const record of records) {
            const target = record.target;
            if (!(target instanceof HTMLElement) || !target.matches(FOCUSABLE)) continue;
            const disabled = target.hasAttribute("disabled");
            if (disabled && target === lastFocused) {
                waiting.add(target);
            } else if (!disabled && waiting.has(target)) {
                waiting.delete(target);
                if (target.isConnected && focusIsLost()) target.focus({ preventScroll: true });
            }
        }
    }).observe(root.documentElement, { subtree: true, attributes: true, attributeFilter: ["disabled"] });
}

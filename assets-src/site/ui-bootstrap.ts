/* ==========================================================================
   Bootstrap UI adapter

   The only place outside vendor CSS that imports Bootstrap's JS. Importing
   the package also installs Bootstrap's data API (data-bs-toggle,
   data-bs-dismiss, ...), which the current templates still rely on.

   Built as its own entry (/assets/js/ui-bootstrap.js) and listed in the
   `bootstrap` theme's manifest; `default` uses the native adapter. Never
   import this file from another bundle: loading Bootstrap twice installs
   its data API twice.

   Uses Bootstrap's package ESM entry: bootstrap/dist/js/bootstrap.bundle is
   UMD/CJS and makes Rolldown emit a _commonjsHelpers chunk once more than
   one entry touches it.
   ========================================================================== */

import { Modal, Toast, Tooltip } from "bootstrap";
import { MODAL_CLOSED, TAB_SHOWN, TOAST_DELAY_MS, toastStaysOpen, ui, type UiAdapter } from "../shared/ui";

const bound = new WeakSet<HTMLElement>();

/*
 * Bootstrap returns focus to the opener only for data-bs-toggle triggers; a
 * modal shown from script (CMS.confirm()) left focus on <body> when it
 * closed. Remember the opener and restore it (WCAG 2.4.3).
 */
const openers = new WeakMap<HTMLElement, HTMLElement>();

/**
 * Focuses the opener or, when it sits in a dropdown that has closed since
 * (a menu item such as "Sign out"), the toggle that labels that dropdown.
 */
function focusOpener(opener: HTMLElement): void {
    if (opener.getClientRects().length > 0) {
        opener.focus();
        return;
    }
    const toggleId = opener.closest("[aria-labelledby]")?.getAttribute("aria-labelledby");
    const toggle = toggleId ? document.getElementById(toggleId) : null;
    (toggle ?? opener).focus();
}

function bindClosed(el: HTMLElement): void {
    if (bound.has(el)) return;
    bound.add(el);
    // Bootstrap fires hidden.bs.modal on every close: OK, Escape, backdrop,
    // data-bs-dismiss. Translate it into the adapter-neutral event.
    el.addEventListener("hidden.bs.modal", () => {
        const opener = openers.get(el);
        openers.delete(el);
        // Bootstrap leaves focus on the now hidden .modal itself, and the
        // browser then drops it to <body>.
        const active = document.activeElement;
        if (opener?.isConnected && (!active || active === document.body || el.contains(active))) {
            focusOpener(opener);
        }
        el.dispatchEvent(new CustomEvent(MODAL_CLOSED));
    });
}

export const bootstrapAdapter: UiAdapter = {
    name: "bootstrap",

    modalOpen(el) {
        bindClosed(el);
        if (document.activeElement instanceof HTMLElement && document.activeElement !== document.body) {
            openers.set(el, document.activeElement);
        }
        Modal.getOrCreateInstance(el).show();
    },

    modalClose(el) {
        bindClosed(el);
        Modal.getOrCreateInstance(el).hide();
    },

    toastShow(el, type) {
        el.className = "toast align-items-center border-0 text-bg-" + type;
        // Same timing rule as the framework-free adapter (shared/ui.ts);
        // Bootstrap pauses the timer on hover and focus itself. The options
        // only apply to a new instance, so the old one goes first.
        Toast.getInstance(el)?.dispose();
        new Toast(el, { autohide: !toastStaysOpen(type), delay: TOAST_DELAY_MS }).show();
    },

    tooltipInit(root) {
        root.querySelectorAll<HTMLElement>("[data-ui-tooltip], [data-bs-toggle=\"tooltip\"]").forEach((el) => {
            if (Tooltip.getInstance(el)) return;
            if (el.dataset.uiTooltip && !el.dataset.bsTitle) el.dataset.bsTitle = el.dataset.uiTooltip;
            new Tooltip(el, { placement: "top", trigger: "hover focus" });
        });
    },
};

ui.register(bootstrapAdapter);

// Bootstrap tabs report a switch as shown.bs.tab; re-emit it as the
// framework-neutral event scripts listen for (see TAB_SHOWN in shared/ui.ts).
document.addEventListener("shown.bs.tab", (event) => {
    const tab = event.target as HTMLElement;
    const selector = tab.dataset.bsTarget;
    const pane = selector ? document.querySelector(selector) : null;
    tab.dispatchEvent(new CustomEvent(TAB_SHOWN, { bubbles: true, detail: { pane } }));
});

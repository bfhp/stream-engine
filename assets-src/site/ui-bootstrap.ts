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
import { MODAL_CLOSED, TAB_SHOWN, ui, type UiAdapter } from "../shared/ui";

const bound = new WeakSet<HTMLElement>();

function bindClosed(el: HTMLElement): void {
    if (bound.has(el)) return;
    bound.add(el);
    // Bootstrap fires hidden.bs.modal on every close: OK, Escape, backdrop,
    // data-bs-dismiss. Translate it into the adapter-neutral event.
    el.addEventListener("hidden.bs.modal", () => {
        el.dispatchEvent(new CustomEvent(MODAL_CLOSED));
    });
}

export const bootstrapAdapter: UiAdapter = {
    name: "bootstrap",

    modalOpen(el) {
        bindClosed(el);
        Modal.getOrCreateInstance(el).show();
    },

    modalClose(el) {
        bindClosed(el);
        Modal.getOrCreateInstance(el).hide();
    },

    toastShow(el, type) {
        el.className = "toast align-items-center border-0 text-bg-" + type;
        Toast.getOrCreateInstance(el, { delay: 5000 }).show();
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

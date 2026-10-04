/* ==========================================================================
   UI adapter: framework-specific behavior behind one interface

   Scripts never import a UI framework (Bootstrap, ...) directly. They call
   `ui.modal.open(el)`, `ui.toast.show(el, type)`, `ui.tooltip.init(root)`,
   and whichever adapter the active theme registered does the work. See
   docs/ADR-001-JS-THEME-MARKUP.md and the "JS surface" section of
   docs/THEME_CONTRACT.md.

   - The native adapter below is the fallback and the one the framework-free
     `default` theme will use: <dialog>, a timed toast, `title` tooltips.
   - A theme registers its own with `ui.register(adapter)` (or
     `window.CMS.ui.register(adapter)` from a plain theme script; theme
     scripts load after the core bundle, so `window.CMS` exists by then).
   - The adapter is looked up at call time, so registration order relative
     to other bundles does not matter.

   Closing contract: however a modal closes (OK button, Escape, backdrop,
   a dismiss button), the adapter must dispatch MODAL_CLOSED on the modal
   element. `ui.modal.onClosed()` is the only way callers should listen.
   ========================================================================== */

export const MODAL_CLOSED = "ui:modal-closed";

export interface UiAdapter {
    /** Short identifier, for debugging only. */
    name: string;
    modalOpen(el: HTMLElement): void;
    modalClose(el: HTMLElement): void;
    /** `type` is a semantic level: success, danger, warning, info. */
    toastShow(el: HTMLElement, type: string): void;
    tooltipInit(root: ParentNode): void;
}

const TOAST_DELAY_MS = 5000;
const TOOLTIP_SELECTOR = "[data-ui-tooltip], [data-bs-toggle=\"tooltip\"]";

function dispatchClosed(el: HTMLElement): void {
    el.dispatchEvent(new CustomEvent(MODAL_CLOSED));
}

const nativeBound = new WeakSet<HTMLElement>();
const toastTimers = new WeakMap<HTMLElement, ReturnType<typeof setTimeout>>();

function bindNativeModal(el: HTMLElement): void {
    if (nativeBound.has(el)) return;
    nativeBound.add(el);

    if (el instanceof HTMLDialogElement) {
        // <dialog> reports every way of closing (close(), Escape, form
        // method="dialog") through one event.
        el.addEventListener("close", () => dispatchClosed(el));
    } else {
        el.addEventListener("keydown", (event) => {
            if (event.key === "Escape") nativeAdapter.modalClose(el);
        });
    }

    el.addEventListener("click", (event) => {
        const target = event.target as Element | null;
        if (target?.closest("[data-ui-dismiss=\"modal\"], [data-bs-dismiss=\"modal\"]")) {
            nativeAdapter.modalClose(el);
        }
    });
}

export const nativeAdapter: UiAdapter = {
    name: "native",

    modalOpen(el) {
        bindNativeModal(el);
        if (el instanceof HTMLDialogElement && typeof el.showModal === "function") {
            if (!el.open) el.showModal();
            return;
        }
        el.hidden = false;
        el.setAttribute("data-ui-open", "");
    },

    modalClose(el) {
        if (el instanceof HTMLDialogElement && typeof el.close === "function") {
            if (el.open) el.close();
            return;
        }
        if (!el.hasAttribute("data-ui-open")) return;
        el.hidden = true;
        el.removeAttribute("data-ui-open");
        dispatchClosed(el);
    },

    toastShow(el, type) {
        el.dataset.uiType = type;
        el.hidden = false;
        el.setAttribute("data-ui-open", "");

        clearTimeout(toastTimers.get(el));
        const hide = () => {
            el.hidden = true;
            el.removeAttribute("data-ui-open");
        };
        toastTimers.set(el, setTimeout(hide, TOAST_DELAY_MS));

        el.querySelectorAll<HTMLElement>("[data-ui-dismiss=\"toast\"], [data-bs-dismiss=\"toast\"]")
            .forEach((button) => { button.onclick = hide; });
    },

    tooltipInit(root) {
        // Native tooltips are the `title` attribute; copy the text over from
        // the attribute a framework theme would read.
        root.querySelectorAll<HTMLElement>(TOOLTIP_SELECTOR).forEach((el) => {
            const text = el.dataset.uiTooltip || el.dataset.bsTitle;
            if (text && !el.title) el.title = text;
        });
    },
};

let adapter: UiAdapter = nativeAdapter;

export const ui = {
    /** Replaces the active adapter. The last registration wins. */
    register(next: UiAdapter): void {
        adapter = next;
    },

    adapterName(): string {
        return adapter.name;
    },

    modal: {
        open(el: HTMLElement): void {
            adapter.modalOpen(el);
        },
        close(el: HTMLElement): void {
            adapter.modalClose(el);
        },
        /** Fires on every close, however it happened. Returns an unsubscribe. */
        onClosed(el: HTMLElement, handler: () => void): () => void {
            el.addEventListener(MODAL_CLOSED, handler);
            return () => el.removeEventListener(MODAL_CLOSED, handler);
        },
    },

    toast: {
        show(el: HTMLElement, type = "success"): void {
            adapter.toastShow(el, type);
        },
    },

    tooltip: {
        init(root: ParentNode = document): void {
            adapter.tooltipInit(root);
        },
    },
};

export type Ui = typeof ui;

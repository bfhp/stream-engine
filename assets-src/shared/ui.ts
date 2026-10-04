/* ==========================================================================
   UI adapter: framework-specific behavior behind one interface

   Scripts never import a UI framework (Bootstrap, ...) directly. They call
   `ui.modal.open(el)`, `ui.toast.show(el, type)`, `ui.tooltip.init(root)`,
   and whichever adapter the active theme registered does the work. See the
   "JS surface" section of docs/THEME_CONTRACT.md.

   - The native adapter below is the fallback and the one the framework-free
     `default` theme uses: <dialog>, a timed toast, `title` tooltips.
   - A theme registers its own with `ui.register(adapter)` (or
     `window.CMS.ui.register(adapter)` from a plain theme script; theme
     scripts load after the core bundle, so `window.CMS` exists by then).
   - The adapter is looked up at call time, so registration order relative
     to other bundles does not matter.

   Closing contract: however a modal closes (OK button, Escape, backdrop,
   a dismiss button), the adapter must dispatch MODAL_CLOSED on the modal
   element. `ui.modal.onClosed()` is the only way callers should listen.

   Besides the adapter, this module owns the framework-neutral bits every
   theme can rely on:
   - declarative toggles: data-ui-toggle="modal|collapse|tab" with
     data-ui-target, and data-ui-dismiss="modal";
   - theme templates: clone(name) / fill(el, data) for <template data-ui>
     elements (see views/themes/default/partials/js-templates.twig).
   ========================================================================== */

export const MODAL_CLOSED = "ui:modal-closed";
export const TAB_SHOWN = "ui:tab-shown";

export interface UiAdapter {
    /** Short identifier, for debugging only. */
    name: string;
    modalOpen(el: HTMLElement): void;
    modalClose(el: HTMLElement): void;
    /** `type` is a semantic level: success, danger, warning, info. */
    toastShow(el: HTMLElement, type: string): void;
    tooltipInit(root: ParentNode): void;
}

export type SlotData = Record<string, string | number | null | undefined>;

const TOAST_DELAY_MS = 5000;
const TOOLTIP_SELECTOR = "[data-ui-tooltip], [data-bs-toggle=\"tooltip\"]";
const OPEN_MODAL_SELECTOR = "dialog[open], [data-ui-open], .modal";

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
        // A click on the dialog element itself is a click on its backdrop.
        el.addEventListener("click", (event) => {
            if (event.target === el) nativeAdapter.modalClose(el);
        });
    } else {
        el.addEventListener("keydown", (event) => {
            if (event.key === "Escape") nativeAdapter.modalClose(el);
        });
    }

    // Markup not yet converted to data-ui-dismiss (see the delegated handler
    // below for the current attribute).
    el.addEventListener("click", (event) => {
        const target = event.target as Element | null;
        if (target?.closest("[data-bs-dismiss=\"modal\"]")) {
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

function targetOf(trigger: HTMLElement): HTMLElement | null {
    const selector = trigger.dataset.uiTarget;
    if (!selector) return null;
    try {
        return document.querySelector<HTMLElement>(selector);
    } catch {
        return null;
    }
}

function paneIsActive(pane: Element): boolean {
    return pane.hasAttribute("data-ui-active") || pane.classList.contains("active");
}

function showTab(tab: HTMLElement): void {
    const list = tab.closest("[role=\"tablist\"]") ?? tab.parentElement;
    const shown = targetOf(tab);
    list?.querySelectorAll<HTMLElement>("[data-ui-toggle=\"tab\"]").forEach((other) => {
        const on = other === tab;
        other.setAttribute("aria-selected", on ? "true" : "false");
        const pane = targetOf(other);
        if (pane) {
            pane.hidden = !on;
            pane.toggleAttribute("data-ui-active", on);
        }
    });
    tab.dispatchEvent(new CustomEvent(TAB_SHOWN, { bubbles: true, detail: { pane: shown } }));
}

function toggleCollapse(trigger: HTMLElement): void {
    const target = targetOf(trigger);
    if (!target) return;
    target.hidden = !target.hidden;
    trigger.setAttribute("aria-expanded", target.hidden ? "false" : "true");
}

/** Delegated handler for the declarative data-ui-* attributes. */
function onDocumentClick(event: Event): void {
    const origin = event.target as Element | null;
    if (!origin || typeof origin.closest !== "function") return;

    const dismiss = origin.closest<HTMLElement>("[data-ui-dismiss=\"modal\"]");
    if (dismiss) {
        const modal = dismiss.closest<HTMLElement>(OPEN_MODAL_SELECTOR);
        if (modal) ui.modal.close(modal);
        return;
    }

    const trigger = origin.closest<HTMLElement>("[data-ui-toggle]");
    if (!trigger) return;

    switch (trigger.dataset.uiToggle) {
        case "modal": {
            const target = targetOf(trigger);
            if (target) {
                event.preventDefault();
                ui.modal.open(target);
            }
            break;
        }
        case "collapse":
            event.preventDefault();
            toggleCollapse(trigger);
            break;
        case "tab":
            event.preventDefault();
            showTab(trigger);
            break;
    }
}

if (typeof document !== "undefined") {
    document.addEventListener("click", onDocumentClick);
}

function slotValue(value: unknown): string {
    return value === null || value === undefined ? "" : String(value);
}

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

    tabs: {
        /** Whether a tab pane is the visible one, in any theme. */
        isActive: paneIsActive,
        /** Shows a data-ui-toggle="tab" trigger's pane. */
        show: showTab,
    },

    /**
     * A copy of the first element inside <template data-ui="name">, or null
     * when the theme does not provide that template.
     */
    clone(name: string): HTMLElement | null {
        const template = document.querySelector<HTMLTemplateElement>(`template[data-ui="${name}"]`);
        const first = template?.content.firstElementChild;
        return first ? first.cloneNode(true) as HTMLElement : null;
    },

    /**
     * Puts values into an element and its descendants:
     * - data-slot="key": text content;
     * - data-slot-html="key": inner HTML, for server-sanitized markup only;
     * - data-slot-attr="attr:key attr2:key2": attribute values, removed when
     *   the value is empty;
     * - data-slot-optional="key": the element is removed when the value is
     *   empty.
     * Keys absent from `data` leave their slot untouched.
     */
    fill<T extends HTMLElement>(root: T, data: SlotData): T {
        const nodes: HTMLElement[] = [
            root,
            ...root.querySelectorAll<HTMLElement>("[data-slot], [data-slot-html], [data-slot-attr], [data-slot-optional]"),
        ];

        for (const el of nodes) {
            const optional = el.dataset.slotOptional;
            if (optional && !slotValue(data[optional]).trim()) {
                if (el !== root) el.remove();
                continue;
            }

            const text = el.dataset.slot;
            if (text && text in data) el.textContent = slotValue(data[text]);

            const html = el.dataset.slotHtml;
            if (html && html in data) el.innerHTML = slotValue(data[html]);

            for (const pair of (el.dataset.slotAttr ?? "").split(/\s+/)) {
                const [attr, key] = pair.split(":");
                if (!attr || !key || !(key in data)) continue;
                const value = slotValue(data[key]);
                if (value === "") el.removeAttribute(attr);
                else el.setAttribute(attr, value);
            }
        }

        return root;
    },
};

export type Ui = typeof ui;

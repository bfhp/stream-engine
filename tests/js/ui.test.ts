import { afterEach, describe, expect, it, vi } from "vitest";
import { MODAL_CLOSED, nativeAdapter, ui, type UiAdapter } from "../../assets-src/shared/ui";

afterEach(() => {
    ui.register(nativeAdapter);
    vi.useRealTimers();
    document.body.innerHTML = "";
});

describe("ui registry", () => {
    it("uses the native adapter until a theme registers one", () => {
        expect(ui.adapterName()).toBe("native");
    });

    it("delegates to the adapter registered last, looked up at call time", () => {
        const calls: string[] = [];
        const fake: UiAdapter = {
            name: "fake",
            modalOpen: () => calls.push("open"),
            modalClose: () => calls.push("close"),
            toastShow: (_el, type) => calls.push(`toast:${type}`),
            tooltipInit: () => calls.push("tooltip"),
        };
        const el = document.createElement("div");

        ui.register(fake);
        ui.modal.open(el);
        ui.modal.close(el);
        ui.toast.show(el, "danger");
        ui.tooltip.init(document);

        expect(ui.adapterName()).toBe("fake");
        expect(calls).toEqual(["open", "close", "toast:danger", "tooltip"]);
    });
});

describe("native adapter", () => {
    it("opens and closes a non-dialog modal and reports the close once", () => {
        document.body.innerHTML = `<div id="m" hidden><button data-ui-dismiss="modal">x</button></div>`;
        const el = document.getElementById("m") as HTMLElement;
        const closed = vi.fn();
        ui.modal.onClosed(el, closed);

        ui.modal.open(el);
        expect(el.hidden).toBe(false);

        (el.querySelector("button") as HTMLButtonElement).click();
        expect(el.hidden).toBe(true);
        expect(closed).toHaveBeenCalledTimes(1);

        ui.modal.close(el);
        expect(closed).toHaveBeenCalledTimes(1);
    });

    it("closes on Escape and honours the legacy data-bs-dismiss", () => {
        document.body.innerHTML = `<div id="m" hidden><button data-bs-dismiss="modal">x</button></div>`;
        const el = document.getElementById("m") as HTMLElement;
        const closed = vi.fn();
        ui.modal.onClosed(el, closed);

        ui.modal.open(el);
        el.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
        ui.modal.open(el);
        (el.querySelector("button") as HTMLButtonElement).click();

        expect(closed).toHaveBeenCalledTimes(2);
    });

    it("onClosed returns an unsubscribe", () => {
        const el = document.createElement("div");
        const closed = vi.fn();
        const off = ui.modal.onClosed(el, closed);

        off();
        el.dispatchEvent(new CustomEvent(MODAL_CLOSED));

        expect(closed).not.toHaveBeenCalled();
    });

    it("shows a toast with its type and hides it after the delay", () => {
        vi.useFakeTimers();
        const el = document.createElement("div");
        el.hidden = true;

        ui.toast.show(el, "danger");
        expect(el.hidden).toBe(false);
        expect(el.dataset.uiType).toBe("danger");

        vi.advanceTimersByTime(5000);
        expect(el.hidden).toBe(true);
    });

    it("turns tooltip text into a native title without overwriting one", () => {
        document.body.innerHTML = `
            <span id="a" data-ui-tooltip="Hello"></span>
            <span id="b" data-bs-toggle="tooltip" data-bs-title="Legacy"></span>
            <span id="c" data-ui-tooltip="Ignored" title="Kept"></span>
        `;

        ui.tooltip.init(document);

        expect(document.getElementById("a")?.title).toBe("Hello");
        expect(document.getElementById("b")?.title).toBe("Legacy");
        expect(document.getElementById("c")?.title).toBe("Kept");
    });
});

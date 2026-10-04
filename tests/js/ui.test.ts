import { afterEach, describe, expect, it, vi } from "vitest";
import { MODAL_CLOSED, TAB_SHOWN, nativeAdapter, ui, type UiAdapter } from "../../assets-src/shared/ui";

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

    it("closes the open modal from a data-ui-dismiss button", () => {
        document.body.innerHTML = `<div id="m" hidden><button data-ui-dismiss="modal">x</button></div>`;
        const el = document.getElementById("m") as HTMLElement;

        ui.modal.open(el);
        (el.querySelector("button") as HTMLButtonElement).click();

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

describe("declarative toggles", () => {
    it("opens a modal from data-ui-toggle", () => {
        document.body.innerHTML = `
            <button data-ui-toggle="modal" data-ui-target="#m">open</button>
            <div id="m" hidden></div>
        `;

        (document.querySelector("button") as HTMLButtonElement).click();

        expect((document.getElementById("m") as HTMLElement).hidden).toBe(false);
    });

    it("toggles a collapse target and its aria-expanded", () => {
        document.body.innerHTML = `
            <button data-ui-toggle="collapse" data-ui-target="#menu" aria-expanded="false">menu</button>
            <div id="menu" hidden></div>
        `;
        const button = document.querySelector("button") as HTMLButtonElement;
        const menu = document.getElementById("menu") as HTMLElement;

        button.click();
        expect(menu.hidden).toBe(false);
        expect(button.getAttribute("aria-expanded")).toBe("true");

        button.click();
        expect(menu.hidden).toBe(true);
        expect(button.getAttribute("aria-expanded")).toBe("false");
    });

    it("switches tabs and reports the shown pane", () => {
        document.body.innerHTML = `
            <div role="tablist">
                <button id="t1" data-ui-toggle="tab" data-ui-target="#p1" aria-selected="true">1</button>
                <button id="t2" data-ui-toggle="tab" data-ui-target="#p2" aria-selected="false">2</button>
            </div>
            <div id="p1" data-ui-active></div>
            <div id="p2" hidden></div>
        `;
        const shown = vi.fn();
        document.addEventListener(TAB_SHOWN, shown);
        const p1 = document.getElementById("p1") as HTMLElement;
        const p2 = document.getElementById("p2") as HTMLElement;

        (document.getElementById("t2") as HTMLButtonElement).click();
        document.removeEventListener(TAB_SHOWN, shown);

        expect(p1.hidden).toBe(true);
        expect(ui.tabs.isActive(p1)).toBe(false);
        expect(p2.hidden).toBe(false);
        expect(ui.tabs.isActive(p2)).toBe(true);
        expect(document.getElementById("t2")?.getAttribute("aria-selected")).toBe("true");
        expect(shown).toHaveBeenCalledTimes(1);
        expect((shown.mock.calls[0][0] as CustomEvent).detail.pane).toBe(p2);
    });

    it("treats Bootstrap's .active pane as active too", () => {
        const pane = document.createElement("div");
        pane.className = "tab-pane active";

        expect(ui.tabs.isActive(pane)).toBe(true);
    });
});

describe("theme templates", () => {
    it("returns null when the theme has no such template", () => {
        expect(ui.clone("missing")).toBeNull();
    });

    it("clones a template and fills text, html, attributes and optional slots", () => {
        document.body.innerHTML = `
            <template data-ui="item"><article data-id="" data-slot-attr="data-id:id">
                <img data-slot-attr="src:avatar alt:author">
                <b data-slot="author">placeholder</b>
                <time data-slot="date" data-slot-optional="date"> </time>
                <div data-slot-html="content"></div>
                <span data-slot="untouched">kept</span>
            </article></template>
        `;

        const el = ui.clone("item") as HTMLElement;
        ui.fill(el, { id: 7, avatar: "/a.png", author: "<Ann>", date: "", content: "<p>Hi</p>" });

        expect(el.dataset.id).toBe("7");
        expect(el.querySelector("img")?.getAttribute("src")).toBe("/a.png");
        expect(el.querySelector("img")?.getAttribute("alt")).toBe("<Ann>");
        expect(el.querySelector("b")?.textContent).toBe("<Ann>");
        expect(el.querySelector("b")?.children.length).toBe(0);
        expect(el.querySelector("time")).toBeNull();
        expect(el.querySelector("div p")?.textContent).toBe("Hi");
        expect(el.querySelector("span")?.textContent).toBe("kept");
    });

    it("removes an attribute whose value is empty", () => {
        const el = document.createElement("time");
        el.setAttribute("title", "old");
        el.dataset.slotAttr = "title:dateTitle";

        ui.fill(el, { dateTitle: "" });

        expect(el.hasAttribute("title")).toBe(false);
    });
});

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { enableToolbarKeyboard, syncToolbarTabStop } from "../../assets-src/shared/trix-toolbar";

/*
 * Trix renders its toolbar buttons with tabindex="-1"; the module makes the
 * toolbar one Tab stop with arrow-key movement (WCAG 2.1.1). jsdom has no
 * layout, so every element counts as rendered unless it carries `hidden`.
 */

function toolbar(): HTMLElement {
    document.body.innerHTML = `
        <trix-toolbar id="tb">
            <div class="trix-button-row">
                <span class="trix-button-group">
                    <button type="button" tabindex="-1" data-name="bold">B</button>
                    <button type="button" tabindex="-1" data-name="italic">I</button>
                </span>
                <span class="trix-button-group">
                    <button type="button" tabindex="-1" data-name="hidden" hidden>H</button>
                    <button type="button" tabindex="-1" data-name="undo" disabled>U</button>
                    <button type="button" tabindex="-1" data-name="link">L</button>
                </span>
            </div>
        </trix-toolbar>`;
    return document.getElementById("tb") as HTMLElement;
}

const button = (name: string) => document.querySelector<HTMLButtonElement>(`[data-name="${name}"]`)!;
const tabStops = () => [...document.querySelectorAll<HTMLButtonElement>("button")].filter(b => b.tabIndex === 0).map(b => b.dataset.name);

beforeEach(() => {
    vi.spyOn(HTMLElement.prototype, "getClientRects").mockImplementation(function (this: HTMLElement) {
        return (this.hidden ? [] : [{}]) as unknown as DOMRectList;
    });
});

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = "";
});

describe("Trix toolbar keyboard", () => {
    it("makes the toolbar one Tab stop and names it", () => {
        const tb = toolbar();
        enableToolbarKeyboard(tb);

        expect(tabStops()).toEqual(["bold"]);
        const row = tb.querySelector(".trix-button-row")!;
        expect(row.getAttribute("role")).toBe("toolbar");
        expect(row.getAttribute("aria-label")).toBe("Formatting");
    });

    it("moves with the arrow keys, Home and End, skipping disabled and hidden buttons", () => {
        const tb = toolbar();
        enableToolbarKeyboard(tb);
        button("bold").focus();

        const press = (key: string) => (document.activeElement as HTMLElement)
            .dispatchEvent(new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true }));

        press("ArrowRight");
        expect(document.activeElement).toBe(button("italic"));
        press("ArrowRight");
        expect(document.activeElement).toBe(button("link"));
        press("ArrowRight");
        expect(document.activeElement).toBe(button("bold"));
        press("ArrowLeft");
        expect(document.activeElement).toBe(button("link"));
        press("Home");
        expect(document.activeElement).toBe(button("bold"));
        press("End");
        expect(document.activeElement).toBe(button("link"));
        expect(tabStops()).toEqual(["link"]);
    });

    it("activates a button with Enter and Space through the mousedown Trix listens for", () => {
        const tb = toolbar();
        enableToolbarKeyboard(tb);
        const mousedown = vi.fn();
        button("bold").addEventListener("mousedown", mousedown);
        button("bold").focus();

        for (const key of ["Enter", " "]) {
            const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true });
            button("bold").dispatchEvent(event);
            expect(event.defaultPrevented).toBe(true);
        }
        expect(mousedown).toHaveBeenCalledTimes(2);
    });

    it("moves the Tab stop off a button that becomes disabled", () => {
        const tb = toolbar();
        enableToolbarKeyboard(tb);
        button("bold").disabled = true;
        syncToolbarTabStop(tb);

        expect(tabStops()).toEqual(["italic"]);
    });
});

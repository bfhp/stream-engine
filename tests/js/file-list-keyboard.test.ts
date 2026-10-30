import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { moveInFileList } from "../../assets-src/admin/lib/file-list-keyboard";

/*
 * jsdom has no layout: each file gets a rectangle from its data-x / data-y
 * (column and row, 100 x 50 px cells), and every file counts as rendered.
 */
function mount(cells: Array<[col: number, row: number]>, dir = "ltr"): HTMLElement {
    document.body.innerHTML = `<div id="root" dir="${dir}"><div role="list">${cells.map(([x, y], i) => `
        <div role="listitem" data-chonky-context-file-id="f${i}" data-x="${x}" data-y="${y}">
            <input type="checkbox" aria-label="File ${i}"><span>File ${i}</span><button type="button">Menu</button>
        </div>`).join("")}</div></div>`;
    return document.getElementById("root")!;
}

beforeEach(() => {
    vi.spyOn(HTMLElement.prototype, "getClientRects").mockImplementation(() => [{}] as unknown as DOMRectList);
    vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (this: HTMLElement) {
        const x = Number(this.dataset.x ?? 0) * 100;
        const y = Number(this.dataset.y ?? 0) * 50;
        return { left: x, right: x + 100, top: y, bottom: y + 50, width: 100, height: 50, x, y, toJSON: () => ({}) } as DOMRect;
    });
});

const box = (i: number) => document.querySelector<HTMLInputElement>(`[data-chonky-context-file-id="f${i}"] input`)!;

function press(root: HTMLElement, key: string, init: KeyboardEventInit = {}): boolean {
    const target = document.activeElement as HTMLElement;
    const event = new KeyboardEvent("keydown", { key, bubbles: true, cancelable: true, ...init });
    Object.defineProperty(event, "target", { value: target });
    return moveInFileList(event, root);
}

afterEach(() => { document.body.innerHTML = ""; });

describe("file list arrow keys", () => {
    it("moves up and down a list, and to the ends with Home and End", () => {
        const root = mount([[0, 0], [0, 1], [0, 2], [0, 3]]);
        box(0).focus();

        expect(press(root, "ArrowDown")).toBe(true);
        expect(document.activeElement).toBe(box(1));
        press(root, "ArrowDown");
        press(root, "ArrowUp");
        expect(document.activeElement).toBe(box(1));
        press(root, "End");
        expect(document.activeElement).toBe(box(3));
        press(root, "Home");
        expect(document.activeElement).toBe(box(0));
        // At the edge focus stays, and the page does not scroll.
        expect(press(root, "ArrowUp")).toBe(true);
        expect(document.activeElement).toBe(box(0));
    });

    it("moves by rows and columns in a grid", () => {
        // 0 1 2
        // 3 4
        const root = mount([[0, 0], [1, 0], [2, 0], [0, 1], [1, 1]]);
        box(1).focus();

        press(root, "ArrowDown");
        expect(document.activeElement).toBe(box(4));
        press(root, "ArrowRight");
        expect(document.activeElement).toBe(box(4));
        press(root, "ArrowLeft");
        expect(document.activeElement).toBe(box(3));
        press(root, "ArrowUp");
        expect(document.activeElement).toBe(box(0));
        // The nearest file in the next row when there is none right below.
        box(2).focus();
        press(root, "ArrowDown");
        expect(document.activeElement).toBe(box(4));
    });

    it("mirrors Left and Right in a right-to-left page", () => {
        const root = mount([[0, 0], [1, 0]], "rtl");
        box(0).focus();
        press(root, "ArrowLeft");
        expect(document.activeElement).toBe(box(1));
    });

    it("leaves the arrows alone outside a file's own focus target and with modifiers", () => {
        const root = mount([[0, 0], [0, 1]]);
        const menu = document.querySelector<HTMLButtonElement>("[data-chonky-context-file-id=\"f0\"] button")!;
        menu.focus();
        expect(press(root, "ArrowDown")).toBe(false);

        box(0).focus();
        expect(press(root, "ArrowDown", { shiftKey: true })).toBe(false);
        expect(press(root, "a")).toBe(false);
        expect(document.activeElement).toBe(box(0));
    });
});

import { afterEach, describe, expect, it, vi } from "vitest";

import { initStickyOffset } from "../../assets-src/site/sticky-offset";

function header(className: string, position: string, height: number): HTMLElement {
    const node = document.createElement("header");
    node.className = className;
    node.style.position = position;
    vi.spyOn(node, "getBoundingClientRect").mockReturnValue({ height } as DOMRect);
    document.body.prepend(node);

    return node;
}

describe("initStickyOffset() (WCAG 2.4.11)", () => {
    afterEach(() => {
        document.body.innerHTML = "";
        document.documentElement.style.removeProperty("--ui-sticky-offset");
        vi.restoreAllMocks();
    });

    it("reserves the height of the default theme's sticky header", () => {
        header("site-header", "sticky", 63.4);
        initStickyOffset();

        expect(document.documentElement.style.getPropertyValue("--ui-sticky-offset")).toBe("64px");
    });

    it("recognises the bootstrap theme's sticky-top header", () => {
        header("sticky-top shadow-sm", "sticky", 72);
        initStickyOffset();

        expect(document.documentElement.style.getPropertyValue("--ui-sticky-offset")).toBe("72px");
    });

    it("reserves nothing when a theme makes the header scroll away", () => {
        header("site-header", "static", 80);
        initStickyOffset();

        expect(document.documentElement.style.getPropertyValue("--ui-sticky-offset")).toBe("0px");
    });
});

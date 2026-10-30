import { describe, expect, it, vi } from "vitest";

const config = vi.hoisted(() => ({ blockAttributes: { heading1: { tagName: "h1" } } }));
vi.mock("trix", () => ({ default: { config } }));

import { demoteTopHeadings, useSectionHeadings } from "../../assets-src/shared/trix-headings";

describe("Trix headings", () => {
    it("makes the heading button write <h2>", () => {
        useSectionHeadings();
        expect(config.blockAttributes.heading1.tagName).toBe("h2");
    });

    it("turns <h1> into <h2>, attributes and all, and leaves the rest", () => {
        expect(demoteTopHeadings('<h1>Intro</h1><p>x</p><H1 class="a">Next</H1><h10>no</h10><h2>kept</h2>'))
            .toBe('<h2>Intro</h2><p>x</p><h2 class="a">Next</h2><h10>no</h10><h2>kept</h2>');
    });

    it("rewrites an editor's input before Trix reads it", () => {
        useSectionHeadings();
        document.body.innerHTML = '<input id="body" type="hidden" value="<h1>Old</h1>"><trix-editor input="body"></trix-editor>';
        document.querySelector("trix-editor")!.dispatchEvent(new Event("trix-before-initialize", { bubbles: true }));
        expect((document.getElementById("body") as HTMLInputElement).value).toBe("<h2>Old</h2>");
    });
});

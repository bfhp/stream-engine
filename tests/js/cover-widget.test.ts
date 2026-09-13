import { beforeEach, describe, expect, it, vi } from "vitest";

const { uploadFile } = vi.hoisted(() => ({ uploadFile: vi.fn() }));

vi.mock("../../assets-src/shared/uploads", () => ({ uploadFile }));

import { initCoverWidget } from "../../assets-src/shared/cover-widget";

/**
 * The cover-image drop zone, now one implementation instead of three.
 *
 * The copies had drifted, and the drift is what these tests pin: two of them
 * bound the remove button while *rendering* a file, which left a cover the
 * server had already rendered with a dead button - the blog-post copy patched
 * that with an extra line at init, and the community-create copy simply never
 * hit the case. Rewiring after every render, and at init, covers all three
 * situations with one rule.
 */
const onError = vi.fn();

function mount(html = ""): void {
    document.body.innerHTML = `
        <div data-cover-slot>${html}</div>
        <input type="hidden" id="coverUrl">
    `;

    initCoverWidget({
        slot: document.querySelector<HTMLElement>("[data-cover-slot]"),
        urlInput: document.querySelector<HTMLInputElement>("#coverUrl"),
        uploadsApiUrl: "/api/v1/uploads",
        prompt: "Добавьте иллюстрацию",
        onError,
    });
}

/** What the server renders on an edit page that already has a cover. */
const SERVER_RENDERED_FILE = `
    <div class="blog-post-cover-file">
        <div class="blog-post-cover-thumb" style="background-image:url('/uploads/old.webp')"></div>
        <div class="blog-post-cover-fileinfo">
            <div class="blog-post-cover-filename">старая.webp</div>
        </div>
        <button type="button" class="blog-post-cover-remove"></button>
    </div>
`;

const slot = () => document.querySelector<HTMLElement>("[data-cover-slot]")!;
const urlInput = () => document.querySelector<HTMLInputElement>("#coverUrl")!;
const fileInput = () => slot().querySelector<HTMLInputElement>("[data-cover-file]");
const removeBtn = () => slot().querySelector<HTMLElement>(".blog-post-cover-remove");

/** Puts a file on the input and fires the change the widget listens for. */
async function choose(name = "картинка.webp"): Promise<void> {
    const input = fileInput()!;
    Object.defineProperty(input, "files", {
        configurable: true,
        value: [new File(["x"], name, { type: "image/webp" })],
    });

    input.dispatchEvent(new Event("change"));
    await vi.waitFor(() => {});
}

beforeEach(() => {
    uploadFile.mockReset().mockResolvedValue({ url: "/uploads/new.webp", id: 7 });
    onError.mockReset();
});

/* ===============================
   What it does on arrival
=============================== */

describe("mounting", () => {
    it("leaves an empty slot empty rather than rendering over it", () => {
        // The drop zone is server-rendered on the create page; a widget that
        // rendered its own on init would throw away whatever markup the twig
        // chose.
        mount();

        expect(slot().innerHTML.trim()).toBe("");
    });

    it("leaves a server-rendered cover in place", () => {
        mount(SERVER_RENDERED_FILE);

        expect(slot().textContent).toContain("старая.webp");
    });

    /**
     * The bug the drift produced. Two of the three copies bound the remove
     * button only while rendering a file themselves, so on an edit page the
     * button the *server* rendered did nothing at all.
     */
    it("wires the remove button on a server-rendered cover", () => {
        mount(SERVER_RENDERED_FILE);

        removeBtn()!.click();

        expect(slot().querySelector("[data-cover-drop]")).not.toBeNull();
        expect(slot().textContent).not.toContain("старая.webp");
    });

    it("does nothing at all without a slot or a url input", () => {
        document.body.innerHTML = "";

        expect(() => initCoverWidget({
            slot: null,
            urlInput: null,
            uploadsApiUrl: "/api/v1/uploads",
            prompt: "…",
            onError,
        })).not.toThrow();
    });
});

/* ===============================
   Uploading
=============================== */

describe("choosing a file", () => {
    beforeEach(() => {
        mount('<div class="blog-post-cover-drop" data-cover-drop></div><input type="file" data-cover-file>');
    });

    it("opens the file picker from the drop zone", () => {
        const click = vi.fn();
        fileInput()!.click = click;

        slot().querySelector<HTMLElement>("[data-cover-drop]")!.click();

        expect(click).toHaveBeenCalledTimes(1);
    });

    it("uploads and swaps in the file variant", async () => {
        await choose("обложка.webp");

        expect(uploadFile).toHaveBeenCalledWith("/api/v1/uploads", expect.any(File));
        expect(slot().querySelector(".blog-post-cover-file")).not.toBeNull();
        expect(slot().textContent).toContain("обложка.webp");
    });

    it("puts the uploaded url where the form will read it", async () => {
        await choose();

        // The hidden input is the whole point - it is what the submit
        // handler sends as `imageUrl`.
        expect(urlInput().value).toBe("/uploads/new.webp");
    });

    it("does nothing when the picker was dismissed", async () => {
        const input = fileInput()!;
        Object.defineProperty(input, "files", { configurable: true, value: [] });

        input.dispatchEvent(new Event("change"));
        await vi.waitFor(() => {});

        expect(uploadFile).not.toHaveBeenCalled();
    });

    it("reports a failed upload through the caller's own error slot", async () => {
        // Each form shows errors differently - one writes into an error div,
        // the other into a status line - which is why this is a callback.
        uploadFile.mockRejectedValue({ error: "Файл слишком большой" });

        await choose();

        expect(onError).toHaveBeenCalledWith("Файл слишком большой");
        // And the drop zone stays, so the visitor can pick another file.
        expect(slot().querySelector("[data-cover-drop]")).not.toBeNull();
    });

    it("falls back to a generic message when the failure carries none", async () => {
        uploadFile.mockRejectedValue({});

        await choose();

        expect(onError).toHaveBeenCalledWith("Не удалось загрузить изображение");
    });
});

/* ===============================
   Removing
=============================== */

describe("removing", () => {
    beforeEach(() => {
        mount('<div class="blog-post-cover-drop" data-cover-drop></div><input type="file" data-cover-file>');
    });

    it("goes back to the drop zone and clears the url", async () => {
        await choose();
        expect(urlInput().value).not.toBe("");

        removeBtn()!.click();

        expect(slot().querySelector("[data-cover-drop]")).not.toBeNull();
        expect(urlInput().value).toBe("");
    });

    it("uses the prompt the caller asked for", async () => {
        removeBtn(); // no cover yet
        await choose();
        removeBtn()!.click();

        expect(slot().textContent).toContain("Добавьте иллюстрацию");
    });

    it("can upload again after removing", async () => {
        await choose();
        removeBtn()!.click();

        uploadFile.mockResolvedValue({ url: "/uploads/second.webp", id: 8 });
        await choose("вторая.webp");

        // The re-render has to rebind the new file input, or the widget is
        // one-shot.
        expect(urlInput().value).toBe("/uploads/second.webp");
        expect(slot().textContent).toContain("вторая.webp");
    });

    it("can be removed and re-added repeatedly", async () => {
        for (let i = 0; i < 3; i++) {
            await choose();
            expect(urlInput().value).toBe("/uploads/new.webp");
            removeBtn()!.click();
            expect(urlInput().value).toBe("");
        }

        expect(uploadFile).toHaveBeenCalledTimes(3);
    });
});

/* ===============================
   The file name
=============================== */

describe("the rendered file name", () => {
    beforeEach(() => {
        mount('<div class="blog-post-cover-drop" data-cover-drop></div><input type="file" data-cover-file>');
    });

    it("strips the characters that would break out of the markup", async () => {
        await choose("<img onerror=alert(1)>.webp");

        expect(slot().querySelector("img")).toBeNull();
        expect(slot().innerHTML).not.toContain("<img");
    });

    /**
     * Recorded rather than fixed: this is delete-don't-escape, so quotes
     * survive. Harmless where the name lands - inside a text node - but it is
     * not an escaper and should not be reused as one.
     */
    it("does not escape quotes, because it deletes rather than escapes", async () => {
        await choose('a"b\'c.webp');

        expect(slot().textContent).toContain('a"b\'c.webp');
    });
});

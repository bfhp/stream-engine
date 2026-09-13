import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";

/**
 * bindProfileForm()'s dirty tracking, observed through the status element it
 * writes to - the function isn't exported, but it is applied to every
 * `[data-profile-form]` on DOMContentLoaded, and its status text is a faithful
 * read-out of the `dirty` flag that `beforeunload` guards on.
 *
 * One module instance for the whole file, fresh DOM per test - see
 * another delegated-event test for why re-importing per test would double the handlers.
 */
const api = vi.fn();

beforeAll(async () => {
    (window as any).CMS = {
        api,
        toast: vi.fn(),
        confirm: vi.fn(),
        escapeHtml: (value: unknown) => String(value ?? ""),
        initDirectMessage: vi.fn(),
    };

    await import("../../assets-src/pages/profile");
});

afterEach(() => {
    api.mockReset();
});

const DIRTY = "Есть несохранённые изменения.";

type Refs = {
    form: HTMLFormElement;
    status: HTMLElement;
    boxes: HTMLInputElement[];
    nick: HTMLInputElement;
};

/**
 * A personal form is required (the module bails without one), so the fixture
 * provides the one under test plus the avatar plumbing it looks for.
 */
function setup(): Refs {
    document.body.innerHTML = `
        <form data-profile-personal-form action="/api/v1/profile">
            <input name="nick" value="Аня">
            <input type="checkbox" name="interests" value="a" checked>
            <input type="checkbox" name="interests" value="b" checked>
            <input type="checkbox" name="interests" value="c">
            <div data-profile-personal-status></div>
            <button type="submit">Сохранить</button>
        </form>
    `;

    document.dispatchEvent(new Event("DOMContentLoaded"));

    return {
        form: document.querySelector("[data-profile-personal-form]") as HTMLFormElement,
        status: document.querySelector("[data-profile-personal-status]") as HTMLElement,
        boxes: Array.from(document.querySelectorAll<HTMLInputElement>('input[name="interests"]')),
        nick: document.querySelector('input[name="nick"]') as HTMLInputElement,
    };
}

function edit(el: HTMLElement) {
    el.dispatchEvent(new Event("input", { bubbles: true }));
    el.dispatchEvent(new Event("change", { bubbles: true }));
}

beforeEach(() => {
    document.body.innerHTML = "";
});

describe("profile form dirty tracking", () => {
    it("starts clean", () => {
        const { status } = setup();

        expect(status.textContent).toBe("");
    });

    it("goes dirty on an edit and clean again when the value is put back", () => {
        // A value diff, not an event flag - otherwise beforeunload nags about
        // edits the visitor already undid.
        const { status, nick } = setup();

        nick.value = "Анна";
        edit(nick);
        expect(status.textContent).toBe(DIRTY);

        nick.value = "Аня";
        edit(nick);
        expect(status.textContent).toBe("");
    });

    /**
     * The reason isDirty() compares getAll() rather than get(). With get() -
     * which returns only the *first* value for a key - unchecking the second of
     * these three boxes left the first entry ("a") in place, the form read as
     * clean, and the edit was dropped on navigation with no warning.
     */
    it("notices a change in a multi-value field whose first value is unchanged", () => {
        const { status, boxes } = setup();

        boxes[1].checked = false;
        edit(boxes[1]);

        expect(status.textContent).toBe(DIRTY);
    });

    it("notices a multi-value field growing, not just shrinking", () => {
        const { status, boxes } = setup();

        boxes[2].checked = true;
        edit(boxes[2]);

        expect(status.textContent).toBe(DIRTY);
    });

    it("treats a restored multi-value selection as clean", () => {
        const { status, boxes } = setup();

        boxes[1].checked = false;
        edit(boxes[1]);
        expect(status.textContent).toBe(DIRTY);

        boxes[1].checked = true;
        edit(boxes[1]);
        expect(status.textContent).toBe("");
    });

    it("re-snapshots after a successful save, so nothing is left pending", async () => {
        api.mockResolvedValue({});
        const { form, status, nick } = setup();

        nick.value = "Анна";
        edit(nick);
        expect(status.textContent).toBe(DIRTY);

        form.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
        await vi.waitFor(() => expect(status.textContent).toBe("Сохранено."));

        // The new value is the baseline now - re-checking must not report dirty.
        edit(nick);
        expect(status.textContent).toBe("");
    });

    it("stays dirty when the save fails", async () => {
        api.mockRejectedValue({ error: "Ник занят" });
        const { form, status, nick } = setup();

        nick.value = "Анна";
        edit(nick);

        form.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
        await vi.waitFor(() => expect(status.textContent).toBe("Ник занят"));

        // Still a pending edit, so beforeunload must still warn.
        edit(nick);
        expect(status.textContent).toBe(DIRTY);
    });
});

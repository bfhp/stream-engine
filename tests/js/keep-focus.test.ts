import { describe, expect, it } from "vitest";
import { installFocusKeeper } from "../../assets-src/shared/keep-focus";

/* MutationObserver callbacks run as microtasks. */
const settle = () => new Promise(resolve => setTimeout(resolve, 0));

describe("installFocusKeeper()", () => {
    it("returns focus to a button that was disabled while it had focus", async () => {
        installFocusKeeper();
        document.body.innerHTML = '<button id="save">Save</button><input id="other">';
        const save = document.getElementById("save") as HTMLButtonElement;
        save.focus();

        // What the browser does to a control that gets disabled: focus goes
        // to the page (jsdom does not, and will not blur a disabled one).
        save.blur();
        save.disabled = true;
        await settle();
        expect(document.activeElement).toBe(document.body);

        save.disabled = false;
        await settle();
        expect(document.activeElement).toBe(save);
    });

    it("leaves focus alone when the person moved on", async () => {
        installFocusKeeper();
        document.body.innerHTML = '<button id="save">Save</button><input id="other">';
        const save = document.getElementById("save") as HTMLButtonElement;
        const other = document.getElementById("other") as HTMLInputElement;
        save.focus();
        save.disabled = true;
        await settle();
        other.focus();

        save.disabled = false;
        await settle();
        expect(document.activeElement).toBe(other);
    });
});

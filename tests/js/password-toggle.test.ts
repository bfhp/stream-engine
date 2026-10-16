import { afterEach, describe, expect, it } from "vitest";

import { initPasswordToggles } from "../../assets-src/shared/password-toggle";

function setup(): { input: HTMLInputElement; button: HTMLButtonElement; form: HTMLFormElement } {
    document.body.innerHTML = `
        <form>
            <label for="pw">Password</label>
            <input id="pw" type="password" name="password">
        </form>
    `;
    initPasswordToggles();

    return {
        input: document.querySelector("#pw") as HTMLInputElement,
        button: document.querySelector(".password-field__toggle") as HTMLButtonElement,
        form: document.querySelector("form") as HTMLFormElement,
    };
}

describe("show password (WCAG 3.3.2, 3.3.8)", () => {
    afterEach(() => {
        document.body.innerHTML = "";
    });

    it("adds one named toggle button that controls the field", () => {
        const { input, button } = setup();

        expect(button.type).toBe("button");
        expect(button.getAttribute("aria-label")).toBe("Show password");
        expect(button.getAttribute("aria-controls")).toBe(input.id);
        expect(button.getAttribute("aria-pressed")).toBe("false");
        expect(input.closest(".password-field")).not.toBeNull();
    });

    it("shows and hides the password, keeping focus in the field", () => {
        const { input, button } = setup();

        button.click();
        expect(input.type).toBe("text");
        expect(button.getAttribute("aria-pressed")).toBe("true");
        expect(document.activeElement).toBe(input);

        button.click();
        expect(input.type).toBe("password");
        expect(button.getAttribute("aria-pressed")).toBe("false");
    });

    it("hides the password again when the form is submitted", () => {
        const { input, button, form } = setup();

        button.click();
        form.dispatchEvent(new Event("submit", { cancelable: true }));

        expect(input.type).toBe("password");
        expect(button.getAttribute("aria-pressed")).toBe("false");
    });

    it("is idempotent, so a second call adds no second button", () => {
        setup();
        initPasswordToggles();

        expect(document.querySelectorAll(".password-field__toggle")).toHaveLength(1);
    });
});

import { trans } from "./i18n";

/*
 * "Show password" for every password field on the site (WCAG 3.3.2, 3.3.8).
 *
 * Seeing what was typed is what lets a person catch a typo, so the forms
 * ask for a new password once instead of twice. The button keeps one name
 * and reports its state with aria-pressed, which screen readers announce as
 * "Show password, toggle button, pressed / not pressed".
 *
 * The field goes back to hidden when its form is submitted, so a password
 * manager sees an ordinary password field and the value is not left on
 * screen afterwards.
 */
let sequence = 0;

export function initPasswordToggles(root: ParentNode = document): void {
    root.querySelectorAll<HTMLInputElement>('input[type="password"]:not([data-password-toggle])')
        .forEach(addToggle);
}

function addToggle(input: HTMLInputElement): void {
    input.dataset.passwordToggle = "";
    if (!input.id) {
        input.id = `password-field-${++sequence}`;
    }

    const wrapper = document.createElement("span");
    wrapper.className = "password-field";
    input.before(wrapper);
    wrapper.append(input);

    const label = trans("js.auth.show_password");
    const button = document.createElement("button");
    button.type = "button";
    button.className = "password-field__toggle";
    button.setAttribute("aria-controls", input.id);
    button.setAttribute("aria-label", label);
    button.title = label;
    const icon = document.createElement("i");
    icon.setAttribute("aria-hidden", "true");
    button.append(icon);
    wrapper.append(button);

    const show = (visible: boolean): void => {
        input.type = visible ? "text" : "password";
        button.setAttribute("aria-pressed", String(visible));
        icon.className = visible ? "bi bi-eye-slash" : "bi bi-eye";
    };

    show(false);
    button.addEventListener("click", () => {
        show(input.type === "password");
        input.focus();
    });
    input.form?.addEventListener("submit", () => show(false));
}

import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";

/**
 * The "Email and password" tab: what the status says after a save, and that
 * passwords do not stay in the form. One module instance for the file, a
 * fresh DOM per test (see profile-dirty.test.ts).
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

beforeEach(() => {
    api.mockReset();
});

function setup() {
    document.body.innerHTML = `
        <form data-profile-personal-form action="/api/v1/my">
            <div data-profile-personal-status></div>
            <button type="submit">Save</button>
        </form>
        <form data-profile-form data-profile-security-form action="/api/v1/my/security">
            <input name="email" type="email" value="user@example.com" data-profile-reset-on-save>
            <input name="password" type="password" data-profile-clear-on-save>
            <input name="current_password" type="password" data-profile-clear-on-save>
            <span role="status" data-profile-status></span>
            <button type="submit">Update</button>
        </form>
    `;
    document.dispatchEvent(new Event("DOMContentLoaded"));

    const form = document.querySelector("[data-profile-security-form]") as HTMLFormElement;
    const field = (name: string) => form.elements.namedItem(name) as HTMLInputElement;

    return { form, field, status: form.querySelector("[data-profile-status]") as HTMLElement };
}

async function save(form: HTMLFormElement, status: HTMLElement): Promise<void> {
    form.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
    await vi.waitFor(() => expect(status.textContent).not.toBe("Saving..."));
    await vi.waitFor(() => expect(api).toHaveBeenCalled());
}

describe("profile security tab", () => {
    it("sends the current password with the changes", async () => {
        api.mockResolvedValue({ success: true, passwordChanged: true, emailChangeRequestedFor: null });
        const { form, field, status } = setup();

        field("password").value = "new-password-123";
        field("current_password").value = "current-password-1";
        await save(form, status);

        const [url, options] = api.mock.calls[0] as [string, { data: Record<string, string> }];
        expect(url.endsWith("/api/v1/my/security")).toBe(true);
        expect(options.data).toEqual({
            email: "user@example.com",
            password: "new-password-123",
            current_password: "current-password-1",
        });
    });

    it("says what changed and clears both passwords", async () => {
        api.mockResolvedValue({ success: true, passwordChanged: true, emailChangeRequestedFor: "new@example.com" });
        const { form, field, status } = setup();

        field("email").value = "new@example.com";
        field("password").value = "new-password-123";
        field("current_password").value = "current-password-1";
        await save(form, status);

        await vi.waitFor(() => expect(status.textContent).toBe(
            "Password changed. Other devices have been signed out. We sent a confirmation link to new@example.com."
        ));
        expect(field("password").value).toBe("");
        expect(field("current_password").value).toBe("");
        // The new address is pending, so the field shows the one in use.
        expect(field("email").value).toBe("user@example.com");
    });

    it("keeps what was typed when the save fails", async () => {
        api.mockRejectedValue({ error: "The current password is incorrect." });
        const { form, field, status } = setup();

        field("password").value = "new-password-123";
        field("current_password").value = "wrong";
        await save(form, status);

        await vi.waitFor(() => expect(status.textContent).toBe("The current password is incorrect."));
        expect(field("password").value).toBe("new-password-123");
    });
});

import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";

/**
 * register.js and feedback.js both re-enabled their submit button in a
 * `finally`, while the success path still had a navigation `setTimeout`
 * pending - so the page stayed interactive with a live button for 800ms
 * (register) or 1500ms (feedback) after a request that had already succeeded,
 * and a second click sent it again. Re-enable only after failure; these
 * tests pin the submit handlers to that behaviour.
 *
 * The second half of that fix is the `if (submitBtn.disabled) return;` guard
 * both handlers gained: `disabled` stops clicks but not implicit submission, so
 * Enter in a field re-entered the handler even with the button greyed out.
 * Dispatching a synthetic `submit` event, as these tests do, is exactly that
 * path - which is why the "no second request" assertions below are meaningful
 * rather than a restatement of the disabled attribute.
 *
 * Structure notes, both learned the hard way:
 *
 * - These bundles are IIFEs that capture their form and button **at import
 *   time**. So the fixture has to be in the document before the import, and
 *   the import has to happen exactly once - `vi.resetModules()` plus a
 *   re-import per test would leave later tests bound to a form that is no
 *   longer in the document. Hence one `beforeAll` that builds all forms
 *   and loads all three modules, with per-test resetting of field values
 *   rather than of the DOM.
 * - No fake timers. The success path schedules a navigation 800/1500ms out and
 *   the assertion is precisely that the button is still disabled while that is
 *   pending, so the timer simply must not run - and never advancing a real one
 *   is the simplest way to guarantee that. It also keeps
 *   `window.location.href` from being assigned, which jsdom would complain
 *   about.
 *
 * Both register.js and feedback.js read a **bare** `CMS` global rather than
 * `window.CMS`, so the stub goes on globalThis too.
 */
const api = vi.fn<(url: string, options?: unknown) => Promise<unknown>>();
const toast = vi.fn();

const FORMS = `
    <form id="registerForm" action="/api/v1/auth/register">
        <input name="login" value="nicky">
        <input name="email" value="nicky@example.com">
        <input name="password" value="0123456789">
        <button type="submit">Зарегистрироваться</button>
    </form>

    <form id="feedbackForm" action="/api/v1/feedback">
        <input name="full_name" value="Аня">
        <input name="email" value="anya@example.com">
        <textarea name="message"></textarea>
        <input name="website" value="">
        <input name="form_time" value="0">
        <input name="form_hash" value="deadbeef">
        <button type="submit">Отправить</button>
    </form>

    <form id="resetPasswordForm" action="/api/v1/retrieve">
        <input name="token" value="reset-token">
        <input name="password" value="0123456789">
        <input name="password2" value="0123456789">
        <button type="submit">Сохранить пароль</button>
    </form>

    <form id="forgotPasswordForm" action="/api/v1/retrieve">
        <!--
          No "value" attribute on purpose: form.reset() restores each control to
          its default, which IS the attribute - so a fixture that set one would
          make the success path look like it left the address on screen. The
          value is assigned as a property in beforeEach instead, which is what a
          visitor typing into an empty field actually produces.
        -->
        <input name="email">
        <button type="submit">Отправить ссылку</button>
    </form>
`;

/**
 * The handlers read fields via `form.elements.namedItem(...)`, so a fixture
 * missing one blows up inside an async handler as an unhandled
 * "Cannot read properties of undefined (reading 'value')", several frames from
 * the cause. Probing here turns that into a setup error naming the field.
 *
 * Worth knowing why the sources read fields that way at all: jsdom (27.4.0)
 * does not implement the form's own named access, so `form.login` is
 * `undefined` there while it works in every browser. register.js and
 * feedback.js used to rely on it and were untestable as a result.
 */
function assertFields(id: string, names: string[]) {
    const el = document.getElementById(id) as HTMLFormElement | null;

    if (!el) {
        throw new Error(`fixture: #${id} is not in the document`);
    }

    const missing = names.filter(name => el.elements.namedItem(name) === null);

    if (missing.length > 0) {
        throw new Error(
            `fixture: #${id} is missing ${missing.join(", ")} `
            + `(present: ${Array.from(el.elements)
                .map(control => (control as HTMLInputElement).name || "(unnamed)")
                .join(", ")})`
        );
    }
}

beforeAll(async () => {
    document.body.innerHTML = FORMS;

    assertFields("registerForm", ["login", "email", "password"]);
    assertFields("feedbackForm", [
        "form_time",
        "form_hash",
        "full_name",
        "email",
        "website",
        "message",
    ]);
    assertFields("resetPasswordForm", ["token", "password", "password2"]);
    assertFields("forgotPasswordForm", ["email"]);

    const cms = { api, toast, escapeHtml: (value: unknown) => String(value ?? "") };
    (globalThis as any).CMS = cms;
    (window as any).CMS = cms;

    // Imported here, outside any fake-timer window: the module loader is not
    // something to run against a patched clock.
    await import("../../assets-src/pages/register");
    await import("../../assets-src/pages/feedback");
    await import("../../assets-src/pages/retrieve");

    // Some handlers wire up on DOMContentLoaded; the others on
    // import.
    document.dispatchEvent(new Event("DOMContentLoaded"));
});

afterAll(() => {
    delete (globalThis as any).CMS;
    delete (window as any).CMS;
});

function form(id: string): HTMLFormElement {
    const el = document.getElementById(id);

    // The bundles capture their form at import time, so a helper handing back a
    // *different* element than the handler is bound to would produce a dispatch
    // that silently does nothing. Fail loudly instead.
    if (!el) {
        throw new Error(`fixture: #${id} left the document mid-run`);
    }

    return el as HTMLFormElement;
}

function button(id: string): HTMLButtonElement {
    return form(id).querySelector('button[type="submit"]') as HTMLButtonElement;
}

/**
 * Submits and lets the awaited api() call settle, without advancing the clock -
 * so the redirect the success path schedules 800/1500ms out never runs. That is
 * both the state under test and a way to keep jsdom from complaining about an
 * unimplemented navigation.
 */
async function submit(id: string) {
    form(id).dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
    await vi.advanceTimersByTimeAsync(0);
}

beforeEach(() => {
    // Fake timers only now, after beforeAll's imports.
    vi.useFakeTimers();

    api.mockReset();
    toast.mockReset();

    // The forms persist across tests (see the note above), so restore the state
    // each one expects: valid input, and an enabled button - a preceding
    // success deliberately leaves it disabled.
    (form("registerForm").elements.namedItem("password") as HTMLInputElement).value = "0123456789";
    (form("feedbackForm").elements.namedItem("message") as HTMLTextAreaElement).value = "д".repeat(40);
    (form("resetPasswordForm").elements.namedItem("password") as HTMLInputElement).value = "0123456789";
    (form("resetPasswordForm").elements.namedItem("password2") as HTMLInputElement).value = "0123456789";
    (form("forgotPasswordForm").elements.namedItem("email") as HTMLInputElement).value = "anya@example.com";

    ["registerForm", "feedbackForm", "resetPasswordForm", "forgotPasswordForm"]
        .forEach(id => {
            button(id).disabled = false;
        });

    return () => vi.useRealTimers();
});

describe("register.ts", () => {
    it("keeps the button disabled after a successful submit", async () => {
        api.mockResolvedValue({});

        await submit("registerForm");

        // Asserted before the call count: if this is false the handler never
        // ran at all, which is a different problem from the request not going
        // out (a broken CMS stub, say).
        expect(button("registerForm").disabled).toBe(true);
        expect(api).toHaveBeenCalledTimes(1);

        // Still disabled with the redirect 800ms out - this is the regression.
        await submit("registerForm");
        expect(api).toHaveBeenCalledTimes(1);
    });

    it("hands the button back after a failed submit", async () => {
        api.mockRejectedValue({ error: "Такой email уже занят" });

        await submit("registerForm");

        expect(button("registerForm").disabled).toBe(false);
        expect(toast).toHaveBeenCalledWith(
            expect.objectContaining({ type: "danger", message: "Такой email уже занят" })
        );

        // And a retry actually goes out.
        api.mockResolvedValue({});
        await submit("registerForm");
        expect(api).toHaveBeenCalledTimes(2);
    });

    it("rejects a password under 10 characters without calling the API", async () => {
        (form("registerForm").elements.namedItem("password") as HTMLInputElement).value = "123456789";

        await submit("registerForm");

        expect(api).not.toHaveBeenCalled();
        expect(button("registerForm").disabled).toBe(false);
    });
});

describe("feedback.ts", () => {
    it("keeps the button disabled after a successful submit", async () => {
        api.mockResolvedValue({});

        await submit("feedbackForm");

        expect(button("feedbackForm").disabled).toBe(true);
        expect(api).toHaveBeenCalledTimes(1);

        // The window here was 1500ms, nearly twice register.js's.
        await submit("feedbackForm");
        expect(api).toHaveBeenCalledTimes(1);
    });

    it("hands the button back after a failed submit", async () => {
        api.mockRejectedValue({ error: "Не удалось отправить" });

        await submit("feedbackForm");

        expect(button("feedbackForm").disabled).toBe(false);
    });

    it("rejects a message under 30 characters without calling the API", async () => {
        (form("feedbackForm").elements.namedItem("message") as HTMLTextAreaElement).value = "коротко";

        await submit("feedbackForm");

        expect(api).not.toHaveBeenCalled();
        expect(button("feedbackForm").disabled).toBe(false);
    });
});

/**
 * retrieve.ts had no re-entry guard at all until now - it was the one form
 * family the earlier fix missed, and the "forgot password" half is where it
 * mattered most: that endpoint *sends an email*, so every extra submission is
 * another message to an address the sender chooses.
 */
describe("retrieve.ts - the reset form", () => {
    it("keeps the button disabled after a successful reset", async () => {
        api.mockResolvedValue({});

        await submit("resetPasswordForm");

        expect(button("resetPasswordForm").disabled).toBe(true);
        expect(api).toHaveBeenCalledTimes(1);

        // The redirect is 1000ms out; a second submit in that window used to
        // fire the PUT again, and the second one fails because the first
        // consumed the token - so the visitor saw an error toast land on top
        // of the success one.
        await submit("resetPasswordForm");
        expect(api).toHaveBeenCalledTimes(1);
    });

    it("sends the token and the password, and nothing else", async () => {
        api.mockResolvedValue({});

        await submit("resetPasswordForm");

        // `form.action` resolves to an absolute URL - in jsdom and in every
        // browser - so that is what reaches api(). Asserted on the tail rather
        // than the whole string, since the origin is the test host's.
        const [url, options] = api.mock.calls[0] as [string, { method: string; data: unknown }];

        expect(url.endsWith("/api/v1/retrieve")).toBe(true);
        expect(options.method).toBe("PUT");
        // password2 is a client-side confirmation and has no business being
        // sent; the server would ignore it, but it would sit in the request log.
        expect(options.data).toEqual({ token: "reset-token", password: "0123456789" });
    });

    it("hands the button back after a failure so the reset can be retried", async () => {
        api.mockRejectedValue({ error: "Ссылка устарела" });

        await submit("resetPasswordForm");

        expect(button("resetPasswordForm").disabled).toBe(false);
        expect(toast).toHaveBeenCalledWith(
            expect.objectContaining({ type: "danger", message: "Ссылка устарела" })
        );
    });

    it("refuses mismatched passwords without calling the API", async () => {
        (form("resetPasswordForm").elements.namedItem("password2") as HTMLInputElement).value = "другой";

        await submit("resetPasswordForm");

        expect(api).not.toHaveBeenCalled();
        // And the button is left alone, since nothing was sent.
        expect(button("resetPasswordForm").disabled).toBe(false);
    });
});

describe("retrieve.ts - the forgot form", () => {
    it("sends one email however many times submit fires", async () => {
        api.mockImplementation(() => new Promise(() => {}));

        await submit("forgotPasswordForm");
        await submit("forgotPasswordForm");
        await submit("forgotPasswordForm");

        // The request never settles here on purpose: holding Enter re-enters
        // the handler while the first POST is still in flight, which is
        // exactly the window the guard closes.
        expect(api).toHaveBeenCalledTimes(1);
    });

    it("hands the button back once the request finishes", async () => {
        api.mockResolvedValue({});

        await submit("forgotPasswordForm");

        // Unlike the reset form there is no redirect to wait for, and someone
        // who mistyped their address has to be able to try again.
        expect(button("forgotPasswordForm").disabled).toBe(false);
    });

    it("hands the button back after a failure too", async () => {
        api.mockRejectedValue({ error: "Сервис недоступен" });

        await submit("forgotPasswordForm");

        expect(button("forgotPasswordForm").disabled).toBe(false);
    });

    it("says the same thing whether or not the account exists", async () => {
        // The whole point of the endpoint's design: a different answer for a
        // known and an unknown address turns this form into a way to ask
        // whether somebody is registered here. The server always answers 200,
        // so the client must not add a distinction of its own.
        api.mockResolvedValue({});

        await submit("forgotPasswordForm");

        expect(toast).toHaveBeenCalledWith(
            expect.objectContaining({
                type: "success",
                message: "Если аккаунт существует, мы отправили письмо со ссылкой для сброса пароля.",
            })
        );
    });

    it("clears the field on success so the address is not left on screen", async () => {
        api.mockResolvedValue({});

        await submit("forgotPasswordForm");

        expect((form("forgotPasswordForm").elements.namedItem("email") as HTMLInputElement).value).toBe("");
    });

    it("leaves the field alone on failure so the retry keeps the address", async () => {
        api.mockRejectedValue({ error: "нет" });

        await submit("forgotPasswordForm");

        expect((form("forgotPasswordForm").elements.namedItem("email") as HTMLInputElement).value)
            .toBe("anya@example.com");
    });
});

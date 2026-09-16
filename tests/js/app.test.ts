import { beforeEach, describe, expect, it, vi } from "vitest";

/**
 * app.ts pulls in Bootstrap at module scope purely to construct
 * Toast/Modal/Tooltip instances. None of that is under test here, and the
 * real Modal brings a lot of jsdom-unfriendly weight with it, so it is
 * stubbed. Everything else in app.ts is exercised for real.
 */
vi.mock("bootstrap", () => {
    class Stub {
        static getInstance() { return null; }
        show() {}
        hide() {}
    }

    /**
     * Modal needs slightly more than a no-op: confirm()'s dismissal hook keys
     * off Bootstrap's `hidden.bs.modal`, which real Bootstrap emits for *every*
     * close - including the one confirm() itself triggers after OK. Emitting it
     * from hide() is what lets the tests below tell the two apart.
     */
    class ModalStub {
        constructor(private readonly el: HTMLElement) {}

        static getInstance() { return null; }

        show() {}

        hide() {
            this.el.dispatchEvent(new Event("hidden.bs.modal"));
        }
    }

    return { Toast: Stub, Modal: ModalStub, Tooltip: Stub };
});

// Safe below vi.mock(): Vitest hoists the mock above every import in the file.
import CMS from "../../assets-src/site/app";

/**
 * A stand-in for fetch's Response. Built by hand rather than with the real
 * constructor so a test can describe exactly the combination it cares about
 * (a 204 that still advertises Content-Type: application/json, a 200 whose
 * body is not valid JSON) without the constructor normalizing it away.
 */
function fakeResponse({
    status = 200,
    contentType = "application/json",
    json = async () => ({}),
}: {
    status?: number;
    contentType?: string | null;
    json?: () => Promise<any>;
} = {}) {
    return {
        status,
        ok: status >= 200 && status < 300,
        headers: { get: (name: string) => (name.toLowerCase() === "content-type" ? contentType : null) },
        json: vi.fn(json),
    };
}

/** Lets an optimistic-UI handler's floating promise settle before the test ends. */
function flush() {
    return new Promise(resolve => setTimeout(resolve, 0));
}

function mockFetch(response: ReturnType<typeof fakeResponse>) {
    const fetchMock = vi.fn(async () => response);
    vi.stubGlobal("fetch", fetchMock);

    return fetchMock;
}

beforeEach(() => {
    document.body.innerHTML = "";
    delete document.body.dataset.auth;
    document.cookie = "csrfToken=; max-age=0; path=/";
    vi.unstubAllGlobals();
});

describe("api()", () => {
    it("sends the CSRF cookie back as a header and omits a body on GET", async () => {
        document.cookie = "other=x; path=/";
        document.cookie = "csrfToken=tok-123; path=/";

        const fetchMock = mockFetch(fakeResponse({ json: async () => ({ items: [] }) }));

        await CMS.api("/api/v1/comments/7", { data: { ignored: true } });

        const [url, options] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];

        expect(url).toBe("/api/v1/comments/7");
        expect(options.method).toBe("GET");
        expect(options.credentials).toBe("include");
        expect((options.headers as Record<string, string>)["X-CSRF-Token"]).toBe("tok-123");
        // GET carries no body even when data is passed - see the
        // `data && method !== 'GET'` guard.
        expect(options.body).toBeUndefined();
    });

    it("serializes data on a non-GET request", async () => {
        const fetchMock = mockFetch(fakeResponse({ json: async () => ({ id: 1 }) }));

        await CMS.api("/api/v1/conversations", { method: "POST", data: { user_id: 42 } });

        const [, options] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];

        expect(options.method).toBe("POST");
        expect(options.body).toBe(JSON.stringify({ user_id: 42 }));
    });

    it("does not parse a 204, even when it claims to be JSON", async () => {
        // APIController::callApi() sets Content-Type: application/json up
        // front for every action, including the DELETE branches that reply
        // with an empty 204 - parsing that body throws.
        const response = fakeResponse({ status: 204 });
        mockFetch(response);

        await expect(CMS.api("/api/v1/feeds/9/favorite", { method: "DELETE" })).resolves.toBeNull();
        expect(response.json).not.toHaveBeenCalled();
    });

    it("throws the parsed payload - not an Error - on a failed response", async () => {
        const payload = { error: "Комментарий слишком длинный" };
        mockFetch(fakeResponse({ status: 422, json: async () => payload }));

        // Callers (submitCommentForm, submitRating, ...) run the rejection
        // through getApiErrorMessage(), which expects the server's shape.
        await expect(CMS.api("/api/v1/comments/7", { method: "POST", data: {} })).rejects.toEqual(payload);
    });

    it("reports malformed JSON as such", async () => {
        mockFetch(fakeResponse({
            json: async () => {
                throw new SyntaxError("Unexpected token <");
            },
        }));

        await expect(CMS.api("/api/v1/comments/7")).rejects.toThrow("Invalid JSON response");
    });
});

describe("escapeHtml()", () => {
    it("escapes both quote characters, not just the markup three", () => {
        // Callers interpolate into quoted attributes (alt="...") as much as
        // into text, and nicks are stored exactly as typed.
        expect(CMS.escapeHtml(`Ann "The <b>Boss</b>" O'Hara & co`))
            .toBe("Ann &quot;The &lt;b&gt;Boss&lt;/b&gt;&quot; O&#39;Hara &amp; co");
    });

    it("turns a missing value into an empty string", () => {
        expect(CMS.escapeHtml(null)).toBe("");
        expect(CMS.escapeHtml(undefined)).toBe("");
        expect(CMS.escapeHtml(0)).toBe("0");
    });
});

describe("toast()", () => {
    function fixture() {
        document.body.innerHTML = `
            <div id="globalToast"><div id="globalToastBody"></div></div>
        `;

        return {
            el: document.getElementById("globalToast") as HTMLElement,
            body: document.getElementById("globalToastBody") as HTMLElement,
        };
    }

    /**
     * This was an unconditional `innerHTML = message`, which made it an HTML sink
     * for server-controlled strings - getApiErrorMessage() output lands here, and
     * so does messenger-global.ts's DM preview, where the text is server-
     * sanitized HTML that deliberately permits `<a href>`.
     */
    it("renders the message as text by default", () => {
        const { body } = fixture();

        CMS.toast({ message: '<a href="https://evil.example">клик</a>' });

        expect(body.querySelector("a")).toBeNull();
        expect(body.textContent).toBe('<a href="https://evil.example">клик</a>');
    });

    it("renders markup only when the caller asks for it", () => {
        // The one opt-in on the site: profile/page.twig's "Email подтверждён"
        // toast, which uses a <br>.
        const { body } = fixture();

        CMS.toast({ message: "Первая строка<br>вторая", html: true });

        expect(body.querySelector("br")).not.toBeNull();
    });

    it("survives a non-string message", () => {
        const { body } = fixture();

        CMS.toast({ message: undefined });

        expect(body.textContent).toBe("");
    });

    it("puts the type in the class and bails without the markup", () => {
        const { el } = fixture();

        CMS.toast({ message: "готово", type: "danger" });
        expect(el.className).toContain("text-bg-danger");

        document.body.innerHTML = "";
        expect(() => CMS.toast({ message: "никуда" })).not.toThrow();
    });
});

describe("confirm()", () => {
    function fixture() {
        document.body.innerHTML = `
            <div id="globalConfirmModal">
                <h5 id="globalConfirmTitle"></h5>
                <div id="globalConfirmBody"></div>
                <button id="globalConfirmOk" type="button">OK</button>
            </div>
        `;

        return {
            modal: document.getElementById("globalConfirmModal") as HTMLElement,
            ok: document.getElementById("globalConfirmOk") as HTMLButtonElement,
            title: document.getElementById("globalConfirmTitle") as HTMLElement,
            body: document.getElementById("globalConfirmBody") as HTMLElement,
        };
    }

    /** Escape or a backdrop click, as real Bootstrap reports them. */
    function dismiss(modal: HTMLElement) {
        modal.dispatchEvent(new Event("hidden.bs.modal"));
    }

    it("runs only the latest call's onConfirm", () => {
        // Regression test: with addEventListener, a modal dismissed without
        // confirming left its handler attached, so the next confirm() for a
        // different action added a second one and OK fired both - two books
        // leaving favorites on one confirmed click.
        const { modal, ok } = fixture();
        const first = vi.fn();
        const second = vi.fn();

        CMS.confirm({ title: "A", message: "A?", onConfirm: first });
        dismiss(modal);
        CMS.confirm({ title: "B", message: "B?", onConfirm: second });
        ok.click();

        expect(first).not.toHaveBeenCalled();
        expect(second).toHaveBeenCalledTimes(1);
    });

    it("puts title and body in as text, not markup", () => {
        const { title, body } = fixture();

        CMS.confirm({ title: "<b>T</b>", message: "<i>M</i>", onConfirm: () => {} });

        expect(title.querySelector("b")).toBeNull();
        expect(title.textContent).toBe("<b>T</b>");
        expect(body.textContent).toBe("<i>M</i>");
    });

    it("calls onDismiss when the dialog closes without a confirmation", () => {
        const { modal } = fixture();
        const onConfirm = vi.fn();
        const onDismiss = vi.fn();

        CMS.confirm({ title: "T", message: "M?", onConfirm, onDismiss });
        dismiss(modal);

        expect(onDismiss).toHaveBeenCalledTimes(1);
        expect(onConfirm).not.toHaveBeenCalled();
    });

    it("does not call onDismiss when OK was clicked", () => {
        // confirm() hides the modal itself after OK, which fires the same
        // `hidden.bs.modal` - so this is the case the `confirmed` flag exists
        // for. Getting it wrong would re-enable users.ts's Save button behind a
        // save that's already running.
        const { ok } = fixture();
        const onConfirm = vi.fn();
        const onDismiss = vi.fn();

        CMS.confirm({ title: "T", message: "M?", onConfirm, onDismiss });
        ok.click();

        expect(onConfirm).toHaveBeenCalledTimes(1);
        expect(onDismiss).not.toHaveBeenCalled();
    });

    it("does not leak one call's onDismiss into the next dialog", () => {
        const { modal } = fixture();
        const first = vi.fn();
        const second = vi.fn();

        CMS.confirm({ title: "A", message: "A?", onConfirm: () => {}, onDismiss: first });
        dismiss(modal);

        CMS.confirm({ title: "B", message: "B?", onConfirm: () => {}, onDismiss: second });
        dismiss(modal);

        expect(first).toHaveBeenCalledTimes(1);
        expect(second).toHaveBeenCalledTimes(1);
    });

    it("bails silently when the modal markup isn't on the page", () => {
        document.body.innerHTML = "";
        const onConfirm = vi.fn();

        expect(() => CMS.confirm({ title: "T", message: "M?", onConfirm })).not.toThrow();
        expect(onConfirm).not.toHaveBeenCalled();
    });
});

describe("initFavorite()", () => {
    /**
     * @param favorited what the widget currently is - "1" means a click removes
     * @param removeOnUnfavorite the favourites-listing variant, where removal
     *        makes the card disappear and so asks first
     */
    function fixture(favorited: "0" | "1", removeOnUnfavorite: boolean) {
        document.body.innerHTML = `
            <div id="globalConfirmModal">
                <h5 id="globalConfirmTitle"></h5>
                <div id="globalConfirmBody"></div>
                <button id="globalConfirmOk" type="button">OK</button>
            </div>
            <div data-favorite-feed
                 data-feed-id="58"
                 data-favorited="${favorited}"
                 ${removeOnUnfavorite ? "data-remove-on-unfavorite" : ""}>
                <button type="button" data-favorite-toggle>
                    <i class="bi-heart"></i>
                    <span data-favorite-label></span>
                </button>
            </div>
        `;

        CMS.initFavorite();

        return {
            toggle: document.querySelector("[data-favorite-toggle]") as HTMLButtonElement,
            confirmTitle: document.getElementById("globalConfirmTitle") as HTMLElement,
        };
    }

    /**
     * The bug: the dialog was gated on the widget having
     * data-remove-on-unfavorite at all, not on the direction of the click - so
     * the same button asked "Убрать эту книгу из избранного?" when it was about
     * to *add* one.
     */
    it("does not confirm when the click adds to favourites", async () => {
        const fetchMock = mockFetch(fakeResponse({ status: 204, contentType: null }));
        const { toggle, confirmTitle } = fixture("0", true);

        toggle.click();
        await flush();

        expect(confirmTitle.textContent).toBe("");
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect((fetchMock.mock.calls[0] as unknown as [string, RequestInit])[1].method).toBe("POST");
    });

    it("confirms before removing on a favourites listing", () => {
        const fetchMock = mockFetch(fakeResponse({ status: 204, contentType: null }));
        const { toggle, confirmTitle } = fixture("1", true);

        toggle.click();

        expect(confirmTitle.textContent).toBe("Remove from the elect");
        // Nothing sent until the dialog is answered.
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("never confirms on an ordinary in-place toggle", async () => {
        const fetchMock = mockFetch(fakeResponse({ status: 204, contentType: null }));
        const { toggle, confirmTitle } = fixture("1", false);

        toggle.click();
        await flush();

        expect(confirmTitle.textContent).toBe("");
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect((fetchMock.mock.calls[0] as unknown as [string, RequestInit])[1].method).toBe("DELETE");
    });
});

describe("isAuthenticated()", () => {
    it("reads data-auth off the body, treating anything but '1' as anonymous", () => {
        expect(CMS.isAuthenticated()).toBe(false);

        document.body.dataset.auth = "0";
        expect(CMS.isAuthenticated()).toBe(false);

        document.body.dataset.auth = "1";
        expect(CMS.isAuthenticated()).toBe(true);
    });
});

describe("initDirectMessage()", () => {
    it("does not create a conversation when the messages page URL is unsafe", async () => {
        document.body.innerHTML = `
            <button type="button"
                    data-message-user="42"
                    data-messages-url="https://example.invalid/inbox/">Message</button>
        `;
        const fetchMock = mockFetch(fakeResponse({ json: async () => ({ conversation_id: 73 }) }));

        CMS.initDirectMessage();
        (document.querySelector("[data-message-user]") as HTMLButtonElement).click();
        await flush();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("opens the conversation on the messages page URL supplied by the DOM", async () => {
        window.history.replaceState(null, "", "/custom-inbox/?source=profile");
        document.body.innerHTML = `
            <button type="button"
                    data-message-user="42"
                    data-messages-url="/custom-inbox/?source=profile">Message</button>
        `;
        mockFetch(fakeResponse({ json: async () => ({ conversation_id: 73 }) }));

        CMS.initDirectMessage();
        (document.querySelector("[data-message-user]") as HTMLButtonElement).click();
        await flush();

        expect(window.location.pathname).toBe("/custom-inbox/");
        expect(window.location.search).toBe("?source=profile");
        expect(window.location.hash).toBe(`#c=${CMS.encodeId(73)}`);
    });
});

describe("opaque ids on the CMS surface", () => {
    it("round-trips every id it accepts", () => {
        for (const id of [1, 2, 42, 1000, 65535, 65536, 0xffffffff]) {
            const code = CMS.encodeId(id);

            expect(code, `id ${id} should encode`).not.toBeNull();
            expect(CMS.decodeId(code)).toBe(id);
        }
    });

    it("is a bijection: a run of ids never collides", () => {
        // The whole point of the Feistel construction - no lookup table, no
        // chance of two ids sharing a code.
        const codes = new Set<string>();

        for (let id = 1; id <= 500; id++) {
            const code = CMS.encodeId(id)!;

            expect(code).toHaveLength(7);
            expect(CMS.decodeId(code)).toBe(id);
            codes.add(code);
        }

        expect(codes.size).toBe(500);
    });

    it("rejects ids and codes it cannot represent", () => {
        expect(CMS.encodeId(0)).toBeNull();
        expect(CMS.encodeId(-1)).toBeNull();
        expect(CMS.encodeId(1.5)).toBeNull();
        expect(CMS.encodeId(0x1_0000_0000)).toBeNull();

        expect(CMS.decodeId(null)).toBeNull();
        expect(CMS.decodeId("")).toBeNull();
        expect(CMS.decodeId("not a code!")).toBeNull();
        expect(CMS.decodeId("zzzzzzzzz")).toBeNull();
    });
});

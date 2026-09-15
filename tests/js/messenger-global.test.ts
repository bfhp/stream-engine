import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

// vi.hoisted, because vi.mock is lifted above every other statement in the
// file - a plain `const api = vi.fn()` above it is still in the temporal dead
// zone by the time the factory runs.
const { api, toast, isAuthenticated } = vi.hoisted(() => ({
    api: vi.fn(),
    toast: vi.fn(),
    isAuthenticated: vi.fn(),
}));

/**
 * `app.ts` is stubbed rather than loaded: this module only needs three
 * functions off it, and importing the real one drags in Bootstrap. The
 * notification rules it also imports are left real, so the badge and toast
 * assertions below go through the actual code.
 */
vi.mock("../../assets-src/site/app", () => ({
    default: { api, toast, isAuthenticated },
}));

import { initMessengerGlobal, MessengerGlobal } from "../../assets-src/site/messenger-global";

/**
 * The polling shell around the badge and the toast rules (those are tested in
 * `messenger-notifications.test.ts`; this is the interval and the
 * visibilitychange wiring).
 *
 * It runs on **every page of the site** for every signed-in visitor, which is
 * what makes the parts below worth pinning: a guard that stops working means
 * two pollers hitting the same endpoint, and a visibilitychange handler that
 * doesn't tear down means a backgrounded tab keeps asking forever.
 *
 * `initMessengerGlobal()` returns a handle now, which is the change that made
 * this testable at all - before it, the interval could not be stopped from
 * outside and the module-level guard could not be reset without
 * `vi.resetModules()`.
 */
describe("initMessengerGlobal()", () => {
    let handle: MessengerGlobal | null = null;

    /** Puts the header link in the document so the badge has somewhere to go. */
    function fixture(): void {
        document.body.innerHTML = '<a href="/custom-inbox/" data-messages-link class="position-relative">Сообщения</a>';
    }

    function badge(): Element | null {
        return document.querySelector('[data-messages-link] .badge');
    }

    function setHidden(hidden: boolean): void {
        Object.defineProperty(document, "hidden", { configurable: true, get: () => hidden });
        document.dispatchEvent(new Event("visibilitychange"));
    }

    beforeEach(() => {
        vi.useFakeTimers();
        fixture();

        api.mockReset().mockResolvedValue([]);
        toast.mockReset();
        isAuthenticated.mockReset().mockReturnValue(true);
    });

    afterEach(() => {
        handle?.stop();
        handle = null;

        Object.defineProperty(document, "hidden", { configurable: true, get: () => false });
        vi.useRealTimers();
    });

    /* ===============================
       Who it runs for
    =============================== */

    it("does nothing at all for a guest", async () => {
        isAuthenticated.mockReturnValue(false);

        handle = initMessengerGlobal();
        await vi.advanceTimersByTimeAsync(20_000);

        // Not one request - a signed-out visitor has no conversations, and
        // this would be a five-second poll on every page of the site.
        expect(handle).toBeNull();
        expect(api).not.toHaveBeenCalled();
    });

    it("polls immediately rather than waiting out the first interval", async () => {
        handle = initMessengerGlobal();
        await vi.advanceTimersByTimeAsync(0);

        // The badge has to be right when the page appears, not five seconds
        // later.
        expect(api).toHaveBeenCalledTimes(1);
    });

    it("keeps polling on a five-second interval", async () => {
        handle = initMessengerGlobal();

        await vi.advanceTimersByTimeAsync(0);
        expect(api).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(5000);
        expect(api).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(10_000);
        expect(api).toHaveBeenCalledTimes(4);
    });

    /* ===============================
       Called twice
    =============================== */

    it("is idempotent - a second call does not start a second poller", async () => {
        handle = initMessengerGlobal();
        const second = initMessengerGlobal();

        await vi.advanceTimersByTimeAsync(5000);

        // Two pollers would double every request and, worse, keep two copies
        // of the seen-message watermark - so the same arrival would toast
        // twice.
        expect(second).toBe(handle);
        expect(api).toHaveBeenCalledTimes(2);
    });

    it("can be started again after being stopped", async () => {
        handle = initMessengerGlobal();
        await vi.advanceTimersByTimeAsync(0);
        handle!.stop();

        api.mockClear();
        handle = initMessengerGlobal();
        await vi.advanceTimersByTimeAsync(0);

        expect(handle).not.toBeNull();
        expect(api).toHaveBeenCalledTimes(1);
    });

    /* ===============================
       Tab visibility
    =============================== */

    it("stops polling while the tab is hidden", async () => {
        handle = initMessengerGlobal();
        await vi.advanceTimersByTimeAsync(0);
        api.mockClear();

        setHidden(true);
        await vi.advanceTimersByTimeAsync(30_000);

        // A backgrounded tab can sit for hours; six requests a minute for a
        // page nobody is looking at is the whole reason this handler exists.
        expect(api).not.toHaveBeenCalled();
    });

    it("polls at once on coming back, not after another five seconds", async () => {
        handle = initMessengerGlobal();
        await vi.advanceTimersByTimeAsync(0);
        setHidden(true);
        await vi.advanceTimersByTimeAsync(30_000);
        api.mockClear();

        setHidden(false);
        await vi.advanceTimersByTimeAsync(0);

        expect(api).toHaveBeenCalledTimes(1);
    });

    it("does not stack intervals when the tab is shown twice", async () => {
        handle = initMessengerGlobal();
        await vi.advanceTimersByTimeAsync(0);

        setHidden(false);
        setHidden(false);
        await vi.advanceTimersByTimeAsync(0);
        api.mockClear();

        await vi.advanceTimersByTimeAsync(5000);

        // `start()` returns early when an interval is already running; without
        // that guard every focus would add another one.
        expect(api).toHaveBeenCalledTimes(1);
    });

    /* ===============================
       stop()
    =============================== */

    it("stops polling", async () => {
        handle = initMessengerGlobal();
        await vi.advanceTimersByTimeAsync(0);

        handle!.stop();
        api.mockClear();
        await vi.advanceTimersByTimeAsync(30_000);

        expect(api).not.toHaveBeenCalled();
    });

    it("unsubscribes, so becoming visible again does not restart it", async () => {
        handle = initMessengerGlobal();
        await vi.advanceTimersByTimeAsync(0);

        handle!.stop();
        api.mockClear();

        setHidden(false);
        await vi.advanceTimersByTimeAsync(10_000);

        // The listener has to go with the interval - otherwise a stopped
        // poller comes back to life the next time the tab is focused.
        expect(api).not.toHaveBeenCalled();
    });

    it("can be stopped twice without complaint", () => {
        handle = initMessengerGlobal();

        expect(() => {
            handle!.stop();
            handle!.stop();
        }).not.toThrow();
    });

    /* ===============================
       What it does with a response
    =============================== */

    it("puts the unread count on the header link", async () => {
        api.mockResolvedValue([
            { id: 1, unread_count: 2 },
            { id: 2, unread_count: 3 },
        ]);

        handle = initMessengerGlobal();
        await vi.advanceTimersByTimeAsync(0);

        expect(badge()?.textContent).toBe("5");
    });

    it("toasts an arrival, but not on the first poll", async () => {
        const conversation = (lastId: number) => ([{
            id: 1,
            unread_count: 1,
            last_message: { id: lastId, text: "привет", is_own: false },
        }]);

        api.mockResolvedValue(conversation(100));

        handle = initMessengerGlobal();
        await vi.advanceTimersByTimeAsync(0);

        // First sighting: no toast, or every page load would announce the
        // whole inbox.
        expect(toast).not.toHaveBeenCalled();

        api.mockResolvedValue(conversation(101));
        await vi.advanceTimersByTimeAsync(5000);

        expect(toast).toHaveBeenCalledTimes(1);
        expect(toast).toHaveBeenCalledWith(
            expect.objectContaining({ type: "info", message: "Новое сообщение: привет" })
        );
    });

    /* ===============================
       Failure
    =============================== */

    it("keeps polling after a failed request", async () => {
        const error = vi.spyOn(console, "error").mockImplementation(() => {});
        api.mockRejectedValue(new Error("offline"));

        handle = initMessengerGlobal();
        await vi.advanceTimersByTimeAsync(0);
        await vi.advanceTimersByTimeAsync(5000);

        // A dropped connection must not silently end notifications for the
        // rest of the session.
        expect(api).toHaveBeenCalledTimes(2);
        expect(error).toHaveBeenCalled();
    });

    it("leaves the badge alone when a poll fails", async () => {
        vi.spyOn(console, "error").mockImplementation(() => {});

        api.mockResolvedValue([{ id: 1, unread_count: 4 }]);
        handle = initMessengerGlobal();
        await vi.advanceTimersByTimeAsync(0);

        api.mockRejectedValue(new Error("offline"));
        await vi.advanceTimersByTimeAsync(5000);

        // Better a slightly stale count than one that drops to nothing
        // because the network blinked.
        expect(badge()?.textContent).toBe("4");
    });
});

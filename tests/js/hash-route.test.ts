import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

type HashRoute = typeof import("../../assets-src/site/hash-route");

/**
 * The fragment's single owner.
 *
 * The whole point of the module is that several features can each keep a piece
 * of state in the URL without wiping each other's - before it, whoever assigned
 * `location.hash` last won. So the properties worth pinning are the ones that
 * make sharing safe: a write touches one key and leaves the rest, a change is
 * announced exactly once however it arrived, and a listener that throws or
 * unsubscribes mid-emit does not take the others with it.
 *
 * The module keeps its state at module scope (`started`, `knownHash`, the
 * listener set), so each test gets a fresh copy via resetModules(). That is
 * safe here precisely because nothing is captured at import time - the DOM
 * listeners are attached lazily by ensureStarted(), which is also why every
 * public entry point calls it.
 */
describe("hash-route", () => {
    let route: HashRoute;

    /**
     * Puts a fragment in the address bar *without* firing anything.
     * history.replaceState is how the tests distinguish "the URL changed" from
     * "the module was told about it" - which is the distinction the dedupe
     * logic lives on.
     */
    function setUrl(hash: string): void {
        const url = "/forums/magiya/" + (hash === "" ? "" : `#${hash}`);
        window.history.replaceState(null, "", url);
    }

    /** What Back/Forward looks like from inside the page. */
    function navigateTo(hash: string): void {
        setUrl(hash);
        window.dispatchEvent(new Event("popstate"));
    }

    beforeEach(async () => {
        vi.resetModules();
        setUrl("");
        route = await import("../../assets-src/site/hash-route");
    });

    afterEach(() => {
        setUrl("");
    });

    /* ===============================
       Reading
    =============================== */

    describe("getAll()", () => {
        it("reads the fragment as a query string", () => {
            setUrl("c=Xk3f9&tab=media");

            expect(route.getAll()).toEqual({ c: "Xk3f9", tab: "media" });
        });

        it("treats an absent fragment and an empty one as the same thing", () => {
            setUrl("");
            expect(route.getAll()).toEqual({});

            window.history.replaceState(null, "", "/forums/magiya/#");
            expect(route.getAll()).toEqual({});
        });

        it("decodes percent-encoded values", () => {
            setUrl("q=%D0%BC%D0%B0%D0%B3%D0%B8%D1%8F");

            expect(route.getAll().q).toBe("магия");
        });

        it("keeps a bare key as an empty value rather than dropping it", () => {
            // `#tab` with no `=` is what a hand-typed URL looks like; the
            // parser reports it rather than pretending the key isn't there.
            setUrl("tab");

            expect(route.getAll()).toEqual({ tab: "" });
        });
    });

    describe("get()", () => {
        it("returns the value for a key that is present", () => {
            setUrl("c=Xk3f9");

            expect(route.get("c")).toBe("Xk3f9");
        });

        it("returns null, not undefined, for a key that is not", () => {
            // Callers branch on `=== null`; undefined would still be falsy but
            // would fail a strict check.
            setUrl("c=Xk3f9");

            expect(route.get("tab")).toBeNull();
        });
    });

    /* ===============================
       Writing
    =============================== */

    describe("set()", () => {
        it("adds a key without disturbing the others", () => {
            setUrl("tab=media");

            route.set("c", "Xk3f9");

            expect(route.getAll()).toEqual({ tab: "media", c: "Xk3f9" });
        });

        it("replaces a key it already holds", () => {
            setUrl("c=Xk3f9");

            route.set("c", "Zz1qq");

            expect(route.get("c")).toBe("Zz1qq");
        });

        it("removes the key when given null", () => {
            setUrl("c=Xk3f9&tab=media");

            route.set("c", null);

            expect(route.getAll()).toEqual({ tab: "media" });
        });

        it("removes the key when given an empty string", () => {
            // Same as null on purpose: a cleared input and an absent one mean
            // the same thing to every consumer.
            setUrl("c=Xk3f9&tab=media");

            route.set("tab", "");

            expect(route.getAll()).toEqual({ c: "Xk3f9" });
        });

        it("leaves the path and the query string alone", () => {
            window.history.replaceState(null, "", "/forums/magiya/?page=2");

            route.set("c", "Xk3f9");

            expect(window.location.pathname).toBe("/forums/magiya/");
            expect(window.location.search).toBe("?page=2");
            expect(window.location.hash).toBe("#c=Xk3f9");
        });
    });

    describe("patch()", () => {
        it("merges several keys at once", () => {
            setUrl("tab=media");

            route.patch({ c: "Xk3f9", sort: "new" });

            expect(route.getAll()).toEqual({ tab: "media", c: "Xk3f9", sort: "new" });
        });

        it("adds and removes in the same write", () => {
            setUrl("c=Xk3f9&tab=media");

            route.patch({ c: null, sort: "new" });

            expect(route.getAll()).toEqual({ tab: "media", sort: "new" });
        });

        it("is one history entry, not one per key", () => {
            // The reason patch() exists at all: three set() calls would make
            // the visitor press Back three times to undo one action.
            const push = vi.spyOn(window.history, "pushState");

            route.patch({ c: "Xk3f9", tab: "media", sort: "new" });

            expect(push).toHaveBeenCalledTimes(1);
        });
    });

    describe("clear()", () => {
        it("empties the fragment", () => {
            setUrl("c=Xk3f9&tab=media");

            route.clear();

            expect(route.getAll()).toEqual({});
        });

        it("leaves no trailing '#' behind", () => {
            // The documented reason writes go through the history API instead
            // of `location.hash = ''`, which leaves a bare '#' in the bar.
            setUrl("c=Xk3f9");

            route.clear();

            expect(window.location.hash).toBe("");
            expect(window.location.href.endsWith("#")).toBe(false);
        });
    });

    /* ===============================
       History
    =============================== */

    describe("history entries", () => {
        it("pushes one per write, so Back walks through them", () => {
            const push = vi.spyOn(window.history, "pushState");

            route.set("c", "Xk3f9");
            route.set("tab", "media");

            expect(push).toHaveBeenCalledTimes(2);
        });

        it("replaces instead when asked", () => {
            // For changes the visitor shouldn't have to press Back through:
            // restoring state on load, normalizing a hand-typed hash.
            const push = vi.spyOn(window.history, "pushState");
            const replace = vi.spyOn(window.history, "replaceState");

            route.set("c", "Xk3f9", { replace: true });

            expect(push).not.toHaveBeenCalled();
            expect(replace).toHaveBeenCalledTimes(1);
        });

        it("writes nothing when the value is already what was asked for", () => {
            setUrl("c=Xk3f9");

            const push = vi.spyOn(window.history, "pushState");
            const listener = vi.fn();
            route.onChange(listener);

            route.set("c", "Xk3f9");

            // No entry and no announcement: a feature re-applying its own state
            // must not fill the history or wake its neighbours.
            expect(push).not.toHaveBeenCalled();
            expect(listener).not.toHaveBeenCalled();
        });

        it("preserves whatever state object the page already had", () => {
            window.history.replaceState({ mine: true }, "", "/forums/magiya/");

            route.set("c", "Xk3f9");

            expect(window.history.state).toEqual({ mine: true });
        });
    });

    /* ===============================
       Serialization
    =============================== */

    describe("serialization", () => {
        it("keeps slashes and colons readable", () => {
            // URLSearchParams percent-encodes far more than a fragment needs;
            // these two are common in the values features store and are still
            // valid unencoded in a fragment.
            route.set("path", "a/b:c");

            expect(window.location.hash).toBe("#path=a/b:c");
        });

        it("still encodes what a fragment cannot hold literally", () => {
            route.set("q", "магия и всё");

            expect(window.location.hash).toContain("%");
            // And it round-trips.
            expect(route.get("q")).toBe("магия и всё");
        });

        it("round-trips a value containing the separators it uses", () => {
            route.set("q", "a=b&c");

            expect(route.get("q")).toBe("a=b&c");
        });
    });

    /* ===============================
       Listeners
    =============================== */

    describe("onChange()", () => {
        it("reports a write as programmatic, with the previous params", () => {
            setUrl("tab=media");
            const listener = vi.fn();
            route.onChange(listener);

            route.set("c", "Xk3f9");

            expect(listener).toHaveBeenCalledTimes(1);
            expect(listener.mock.calls[0][0]).toEqual({
                params: { tab: "media", c: "Xk3f9" },
                previous: { tab: "media" },
                // What lets a feature ignore its own writes.
                source: "programmatic",
            });
        });

        it("reports Back/Forward as navigation", () => {
            setUrl("c=Xk3f9");
            const listener = vi.fn();
            route.onChange(listener);

            navigateTo("c=Zz1qq");

            expect(listener).toHaveBeenCalledTimes(1);
            expect(listener.mock.calls[0][0]).toEqual({
                params: { c: "Zz1qq" },
                previous: { c: "Xk3f9" },
                source: "navigation",
            });
        });

        it("announces a single change once even though two events fire", () => {
            // Back over a hash-only entry fires popstate *and* hashchange. A
            // consumer that scrolls to a comment would otherwise do it twice.
            setUrl("c=Xk3f9");
            const listener = vi.fn();
            route.onChange(listener);

            setUrl("c=Zz1qq");
            window.dispatchEvent(new Event("popstate"));
            window.dispatchEvent(new Event("hashchange"));

            expect(listener).toHaveBeenCalledTimes(1);
        });

        it("says nothing when navigation lands on the same fragment", () => {
            setUrl("c=Xk3f9");
            const listener = vi.fn();
            route.onChange(listener);

            navigateTo("c=Xk3f9");

            expect(listener).not.toHaveBeenCalled();
        });

        it("notifies every listener", () => {
            const first = vi.fn();
            const second = vi.fn();
            route.onChange(first);
            route.onChange(second);

            route.set("c", "Xk3f9");

            expect(first).toHaveBeenCalledTimes(1);
            expect(second).toHaveBeenCalledTimes(1);
        });

        it("returns an unsubscribe that actually unsubscribes", () => {
            const listener = vi.fn();
            const off = route.onChange(listener);

            off();
            route.set("c", "Xk3f9");

            expect(listener).not.toHaveBeenCalled();
        });

        it("keeps going when one listener throws", () => {
            // Features are independent; one of them failing must not silence
            // the rest, which is what a bare forEach over the set would do.
            const error = vi.spyOn(console, "error").mockImplementation(() => {});
            const broken = vi.fn(() => {
                throw new Error("boom");
            });
            const healthy = vi.fn();

            route.onChange(broken);
            route.onChange(healthy);

            route.set("c", "Xk3f9");

            expect(broken).toHaveBeenCalledTimes(1);
            expect(healthy).toHaveBeenCalledTimes(1);
            expect(error).toHaveBeenCalled();
        });

        it("lets a listener unsubscribe from inside its own callback", () => {
            // The reason emit() iterates a copy: mutating the Set mid-iteration
            // would skip or revisit entries.
            const second = vi.fn();
            let off: () => void = () => {};

            const first = vi.fn(() => {
                off();
            });

            off = route.onChange(first);
            route.onChange(second);

            route.set("c", "Xk3f9");

            expect(second).toHaveBeenCalledTimes(1);

            route.set("tab", "media");

            expect(first).toHaveBeenCalledTimes(1);
            expect(second).toHaveBeenCalledTimes(2);
        });

        it("does not deliver to a listener subscribed during the same emit", () => {
            const late = vi.fn();
            const first = vi.fn(() => {
                route.onChange(late);
            });

            route.onChange(first);
            route.set("c", "Xk3f9");

            expect(late).not.toHaveBeenCalled();

            route.set("tab", "media");

            expect(late).toHaveBeenCalledTimes(1);
        });
    });

    /* ===============================
       Starting up
    =============================== */

    describe("ensureStarted()", () => {
        it("attaches its window listeners only once", () => {
            const add = vi.spyOn(window, "addEventListener");

            route.initHashRoute();
            route.initHashRoute();
            route.getAll();
            route.set("c", "Xk3f9");

            expect(add.mock.calls.filter(([type]) => type === "popstate")).toHaveLength(1);
            expect(add.mock.calls.filter(([type]) => type === "hashchange")).toHaveLength(1);
        });

        it("adopts a fragment that was already in the URL on load", () => {
            // A shared link arrives with its state already set; the first read
            // must see it rather than start from empty and then "change" to it.
            setUrl("c=Xk3f9");

            const listener = vi.fn();
            route.onChange(listener);

            expect(route.getAll()).toEqual({ c: "Xk3f9" });
            expect(listener).not.toHaveBeenCalled();
        });
    });

    /* ===============================
       The bundled object
    =============================== */

    it("exposes the same functions through the default export", () => {
        // Consumers import either shape; they have to be the same functions or
        // one of them would talk to a different module instance.
        expect(route.default.get).toBe(route.get);
        expect(route.default.set).toBe(route.set);
        expect(route.default.patch).toBe(route.patch);
        expect(route.default.clear).toBe(route.clear);
        expect(route.default.getAll).toBe(route.getAll);
        expect(route.default.onChange).toBe(route.onChange);
        expect(route.default.init).toBe(route.initHashRoute);
    });
});

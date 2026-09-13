import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("bootstrap/dist/js/bootstrap.bundle", () => {
    class Stub {
        static getInstance() { return null; }
        show() {}
        hide() {}
    }

    return { Toast: Stub, Modal: Stub, Tooltip: Stub };
});

import CMS from "../../assets-src/site/app";

/**
 * The visitor's timezone, written to a cookie the server reads to render every
 * timestamp in local time.
 *
 * It runs on **every page load**, and the whole point of the function is that
 * it should almost never do anything: the browser's timezone changes when
 * someone travels or fixes their clock, not between two page views. So the
 * behaviour worth pinning is the *absence* of a write - a version that always
 * assigned would set a cookie on every request, and `Set-Cookie` on every
 * response is the kind of thing that quietly disables a CDN's caching.
 *
 * Kept out of app.test.ts because it needs `document.cookie` replaced with an
 * accessor that records writes, which is too blunt an instrument to leave
 * installed for that file's other tests.
 */
describe("CMS.checkAndSetTimezoneCookie()", () => {
    let jar: string;
    let writes: string[];

    /**
     * Replaces document.cookie with a recording accessor. jsdom implements it
     * on Document.prototype, so an own property shadows it and `delete` puts
     * the real one back.
     */
    function installCookieJar(initial: string): void {
        jar = initial;
        writes = [];

        Object.defineProperty(document, "cookie", {
            configurable: true,
            get: () => jar,
            set: (value: string) => {
                writes.push(value);
                // Good enough for these tests: the code never reads back what
                // it just wrote, and a real jar's merge semantics would only
                // hide a bug rather than reveal one.
                jar = jar === "" ? value.split(";")[0] : `${jar}; ${value.split(";")[0]}`;
            },
        });
    }

    function setBrowserTimezone(timeZone: string): void {
        vi.stubGlobal("Intl", {
            ...Intl,
            DateTimeFormat: () => ({ resolvedOptions: () => ({ timeZone }) }),
        });
    }

    beforeEach(() => {
        setBrowserTimezone("Europe/Moscow");
    });

    afterEach(() => {
        // Removing the own property puts jsdom's prototype accessor back.
        delete (document as Partial<Document>).cookie;
        vi.unstubAllGlobals();
    });

    /* ===============================
       When it writes
    =============================== */

    it("writes the cookie when there is none", () => {
        installCookieJar("");

        CMS.checkAndSetTimezoneCookie();

        expect(writes).toHaveLength(1);
        expect(writes[0]).toContain("tz=Europe%2FMoscow");
    });

    it("writes when the stored zone is a different one", () => {
        installCookieJar("tz=America%2FNew_York");

        CMS.checkAndSetTimezoneCookie();

        expect(writes).toHaveLength(1);
        expect(writes[0]).toContain("tz=Europe%2FMoscow");
    });

    it("sends the cookie site-wide and for a year", () => {
        installCookieJar("");

        CMS.checkAndSetTimezoneCookie();

        // path=/ because every page renders timestamps, not just the one the
        // visitor happened to land on first.
        expect(writes[0]).toContain("path=/");
        expect(writes[0]).toContain("max-age=31536000");
    });

    it("encodes the zone, since a timezone name contains a slash", () => {
        installCookieJar("");

        CMS.checkAndSetTimezoneCookie();

        expect(writes[0]).toContain("Europe%2FMoscow");
        expect(writes[0]).not.toContain("Europe/Moscow");
    });

    /* ===============================
       When it doesn't - the actual point
    =============================== */

    it("writes nothing when the stored zone already matches", () => {
        // The stored value is encoded and the comparison decodes it, so this is
        // also the test that the two sides are compared in the same alphabet.
        installCookieJar("tz=Europe%2FMoscow");

        CMS.checkAndSetTimezoneCookie();

        expect(writes).toEqual([]);
    });

    it("writes nothing on a second call in the same page", () => {
        installCookieJar("");

        CMS.checkAndSetTimezoneCookie();
        CMS.checkAndSetTimezoneCookie();

        // The first call's write went into the jar; the second must see it.
        expect(writes).toHaveLength(1);
    });

    it("finds the cookie when it is not the first one in the jar", () => {
        // The `(^| )` in the pattern is what makes this work - the separator
        // in a Cookie header is "; ", so every cookie but the first has a
        // leading space.
        installCookieJar("csrfToken=abc; tz=Europe%2FMoscow; theme=dark");

        CMS.checkAndSetTimezoneCookie();

        expect(writes).toEqual([]);
    });

    /* ===============================
       Near-miss cookie names
    =============================== */

    it("is not fooled by a cookie whose name ends in tz", () => {
        // `atz=...` contains "tz=" but is a different cookie. Without the
        // `(^| )` anchor this would read as a matching timezone and suppress
        // the write, leaving the server guessing forever.
        installCookieJar("atz=Europe%2FMoscow");

        CMS.checkAndSetTimezoneCookie();

        expect(writes).toHaveLength(1);
        expect(writes[0]).toContain("tz=Europe%2FMoscow");
    });

    it("is not fooled by a cookie whose name merely starts with tz", () => {
        installCookieJar("tzOffset=180");

        CMS.checkAndSetTimezoneCookie();

        expect(writes).toHaveLength(1);
    });

    /* ===============================
       Odd stored values
    =============================== */

    it("overwrites a stored value that is not a timezone at all", () => {
        installCookieJar("tz=");

        CMS.checkAndSetTimezoneCookie();

        // An empty value doesn't match `([^;]+)`, so it reads as absent -
        // which is the right answer either way.
        expect(writes).toHaveLength(1);
    });

    it("handles a browser that reports a different zone after travel", () => {
        installCookieJar("tz=Europe%2FMoscow");
        setBrowserTimezone("Asia/Tbilisi");

        CMS.checkAndSetTimezoneCookie();

        expect(writes).toHaveLength(1);
        expect(writes[0]).toContain("tz=Asia%2FTbilisi");
    });

    it("does not rewrite a zone whose name needs no encoding", () => {
        // UTC has no slash, so the stored and current forms are identical
        // without decoding - the case a decode-free implementation would also
        // pass, which is why the encoded ones above matter.
        installCookieJar("tz=UTC");
        setBrowserTimezone("UTC");

        CMS.checkAndSetTimezoneCookie();

        expect(writes).toEqual([]);
    });
});

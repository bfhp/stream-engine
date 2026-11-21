import { afterEach, beforeEach, describe, expect, it } from "vitest";

import { initCookieNotice } from "../../assets-src/site/cookie-notice";

/**
 * The cookie notice is rendered hidden into every page while the setting is
 * on; the script alone decides whether this visitor sees it, so the HTML can
 * be the same for everyone.
 */
describe("initCookieNotice()", () => {
    let jar: string[];

    beforeEach(() => {
        jar = [];
        Object.defineProperty(document, "cookie", {
            configurable: true,
            get: () => jar.map(entry => entry.split(";")[0]).join("; "),
            set: (value: string) => { jar.push(value); },
        });
        document.body.innerHTML = `
            <main id="main-content" tabindex="-1"></main>
            <section id="cookieNotice" aria-label="Cookie notice" hidden>
                <p>We use cookies.</p>
                <button type="button" data-cookie-notice-accept>Got it</button>
            </section>
        `;
    });

    afterEach(() => {
        delete (document as unknown as { cookie?: string }).cookie;
        document.body.innerHTML = "";
        document.documentElement.classList.remove("has-cookie-notice");
        document.documentElement.style.removeProperty("--ui-cookie-notice-offset");
    });

    it("shows the notice to a visitor who has not dismissed it", () => {
        initCookieNotice();

        const notice = document.getElementById("cookieNotice") as HTMLElement;
        expect(notice.hidden).toBe(false);
        expect(document.documentElement.classList.contains("has-cookie-notice")).toBe(true);
        expect(document.documentElement.style.getPropertyValue("--ui-cookie-notice-offset")).toMatch(/^\d+px$/);
    });

    it("removes the notice without showing it once it was dismissed", () => {
        jar.push("tz=Europe/Nicosia", "cookie_notice=1; path=/");

        initCookieNotice();

        expect(document.getElementById("cookieNotice")).toBeNull();
        expect(document.documentElement.classList.contains("has-cookie-notice")).toBe(false);
    });

    it("remembers the dismissal for a year and keeps focus in the page", () => {
        initCookieNotice();
        const button = document.querySelector<HTMLButtonElement>("[data-cookie-notice-accept]")!;
        button.focus();

        button.click();

        expect(jar).toHaveLength(1);
        expect(jar[0]).toMatch(/^cookie_notice=1; path=\/; max-age=31536000; SameSite=Lax/);
        expect(document.getElementById("cookieNotice")).toBeNull();
        expect(document.documentElement.classList.contains("has-cookie-notice")).toBe(false);
        expect(document.documentElement.style.getPropertyValue("--ui-cookie-notice-offset")).toBe("");
        expect(document.activeElement?.id).toBe("main-content");
    });

    it("does nothing on a page without the notice (setting switched off)", () => {
        document.body.innerHTML = "";

        expect(() => initCookieNotice()).not.toThrow();
        expect(jar).toEqual([]);
    });
});

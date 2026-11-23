import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

/**
 * initUsersList() (pages/users.ts): the list stays aria-busy - and, through
 * users.css, a screen tall - until the first page or an error replaces the
 * loading message, so the footer is never pushed down under the reader.
 */
const MARKUP = `
<div data-users-list data-api-url="/api/v1/users" data-page-url="/users/" aria-busy="true">
    <ul data-users-grid></ul>
    <div data-users-pager></div>
    <div role="status" data-users-message>Loading...</div>
</div>
<template data-ui="user-card"><li><a href="#" data-slot="name" data-slot-attr="href:url"></a></li></template>`;

async function settle(): Promise<void> {
    for (let i = 0; i < 5; i++) await Promise.resolve();
    await new Promise(resolve => setTimeout(resolve, 0));
}

describe("users list", () => {
    beforeEach(async () => {
        vi.resetModules();
        document.body.innerHTML = MARKUP;
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.body.innerHTML = "";
    });

    async function start(response: Promise<Response>): Promise<HTMLElement> {
        vi.stubGlobal("fetch", vi.fn(() => response));
        await import("../../assets-src/pages/users");
        document.dispatchEvent(new Event("DOMContentLoaded"));

        return document.querySelector<HTMLElement>("[data-users-list]")!;
    }

    it("stays busy while the first page loads and clears once it is shown", async () => {
        let resolve!: (response: Response) => void;
        const root = await start(new Promise<Response>(r => { resolve = r; }));

        expect(root.getAttribute("aria-busy")).toBe("true");

        resolve(new Response(JSON.stringify({
            data: [{ id: 1, displayName: "Ada", username: "ada", url: "/users/ada/", avatarUrl: null, createdAt: 0 }],
            pagination: { page: 1, pages: 1, total: 1 },
        })));
        await settle();

        expect(root.hasAttribute("aria-busy")).toBe(false);
        expect(root.querySelectorAll("[data-users-grid] li")).toHaveLength(1);
    });

    it("clears busy when loading fails too", async () => {
        vi.spyOn(console, "error").mockImplementation(() => {});
        const root = await start(Promise.resolve(new Response("", { status: 500 })));
        await settle();

        expect(root.hasAttribute("aria-busy")).toBe(false);
    });
});

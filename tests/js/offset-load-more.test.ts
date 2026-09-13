import { beforeEach, describe, expect, it, vi } from "vitest";

import { initOffsetLoadMore } from "../../assets-src/pages/offset-load-more";

/**
 * The "Показать ещё" pager, now one implementation instead of the four
 * near-identical closures `users.ts` carried.
 *
 * The rule most worth having a test for is the failure path: a rejected
 * request has to leave `data-offset` **unchanged**, so the retry asks for the
 * same page rather than skipping it. That one is invisible in manual testing -
 * the retry usually succeeds, and nobody counts the rows afterwards.
 */
const api = vi.fn();
const toast = vi.fn();

function mount({ offset = "10", apiUrl = "/api/v1/posts" }: { offset?: string | null; apiUrl?: string | null } = {}) {
    document.body.innerHTML = `
        <div id="feed" ${apiUrl === null ? "" : `data-posts-api-url="${apiUrl}"`}>
            <ul id="feed-items"><li>первая</li></ul>
            <div data-load-more-wrapper>
                <button class="load-more" ${offset === null ? "" : `data-offset="${offset}"`}>Показать ещё</button>
            </div>
        </div>
    `;

    initOffsetLoadMore<{ title: string }>({
        containerId: "feed",
        readyFlag: "feedReady",
        apiUrlKey: "postsApiUrl",
        buttonSelector: ".load-more",
        listId: "feed-items",
        wrapperSelector: "[data-load-more-wrapper]",
        render: (item) => `<li>${item.title}</li>`,
        errorMessage: "Не удалось загрузить записи",
    });
}

const button = () => document.querySelector<HTMLButtonElement>(".load-more");
const items = () => Array.from(document.querySelectorAll("#feed-items li")).map(li => li.textContent);
const wrapper = () => document.querySelector("[data-load-more-wrapper]");

async function click(): Promise<void> {
    button()?.click();
    await vi.waitFor(() => {});
}

beforeEach(() => {
    api.mockReset().mockResolvedValue({ items: [], meta: { nextOffset: null } });
    toast.mockReset();
    (window as any).CMS = { api, toast };
});

/* ===============================
   The request
=============================== */

describe("the request", () => {
    it("appends the button's offset to the endpoint", async () => {
        mount({ offset: "20" });

        await click();

        expect(api).toHaveBeenCalledWith("/api/v1/posts?offset=20");
    });

    it("keeps a query string the endpoint already had", async () => {
        // `searchParams.set` on a parsed URL, not string concatenation - the
        // community endpoints carry an id.
        mount({ offset: "20", apiUrl: "/api/v1/communities/7/posts?type=blog-post" });

        await click();

        const url = String(api.mock.calls[0][0]);
        expect(url).toContain("type=blog-post");
        expect(url).toContain("offset=20");
    });

    it("sends a path, not an absolute url", async () => {
        mount();

        await click();

        expect(String(api.mock.calls[0][0]).startsWith("/api/")).toBe(true);
    });

    it("ignores a button with no offset", async () => {
        // `data-offset` is the whole cursor; without it the element is not a
        // pager and the click is somebody else's.
        mount({ offset: null });

        await click();

        expect(api).not.toHaveBeenCalled();
    });

    it("does not wire a container with no endpoint", async () => {
        mount({ apiUrl: null });

        await click();

        expect(api).not.toHaveBeenCalled();
    });

    it("ignores clicks that are not on the button", async () => {
        mount();

        document.getElementById("feed-items")!.click();
        await vi.waitFor(() => {});

        expect(api).not.toHaveBeenCalled();
    });

    it("works when the click lands on something inside the button", async () => {
        // Delegated via closest(), so an icon inside the button still counts.
        mount();
        button()!.innerHTML = '<i class="bi bi-arrow-down"></i>';

        button()!.querySelector("i")!.click();
        await vi.waitFor(() => {});

        expect(api).toHaveBeenCalledTimes(1);
    });
});

/* ===============================
   A successful page
=============================== */

describe("a page of results", () => {
    it("appends the rendered rows after the ones already there", async () => {
        api.mockResolvedValue({
            items: [{ title: "вторая" }, { title: "третья" }],
            meta: { nextOffset: 30 },
        });

        mount();
        await click();

        expect(items()).toEqual(["первая", "вторая", "третья"]);
    });

    it("advances the cursor for the next click", async () => {
        api.mockResolvedValue({ items: [], meta: { nextOffset: 30 } });

        mount({ offset: "20" });
        await click();

        expect(button()!.dataset.offset).toBe("30");

        await click();
        expect(api).toHaveBeenLastCalledWith("/api/v1/posts?offset=30");
    });

    it("re-enables the button while there is more", async () => {
        api.mockResolvedValue({ items: [], meta: { nextOffset: 30 } });

        mount();
        await click();

        expect(button()!.disabled).toBe(false);
    });

    it("renders nothing, and does not throw, when items are missing", async () => {
        api.mockResolvedValue({ meta: { nextOffset: 30 } });

        mount();
        await click();

        expect(items()).toEqual(["первая"]);
    });

    it("survives the list element having been removed", async () => {
        api.mockResolvedValue({ items: [{ title: "вторая" }], meta: { nextOffset: 30 } });

        mount();
        document.getElementById("feed-items")!.remove();

        await expect(click()).resolves.toBeUndefined();
    });
});

/* ===============================
   The last page
=============================== */

describe("the last page", () => {
    it("removes the whole wrapper, not just the button", async () => {
        // Otherwise the toolbar's padding and border stay behind as an empty
        // strip under the list.
        api.mockResolvedValue({ items: [{ title: "последняя" }], meta: { nextOffset: null } });

        mount();
        await click();

        expect(wrapper()).toBeNull();
        expect(items()).toEqual(["первая", "последняя"]);
    });

    it("treats a missing nextOffset the same as an explicit null", async () => {
        api.mockResolvedValue({ items: [], meta: {} });

        mount();
        await click();

        expect(wrapper()).toBeNull();
    });

    it("treats a missing meta the same way", async () => {
        api.mockResolvedValue({ items: [] });

        mount();
        await click();

        expect(wrapper()).toBeNull();
    });

    it("keeps a zero offset, which is a page rather than an ending", async () => {
        // `0` is falsy; only null and undefined mean "no more".
        api.mockResolvedValue({ items: [], meta: { nextOffset: 0 } });

        mount();
        await click();

        expect(wrapper()).not.toBeNull();
        expect(button()!.dataset.offset).toBe("0");
    });
});

/* ===============================
   Failure
=============================== */

describe("a failed request", () => {
    /**
     * The one that motivated extracting this at all. If the offset advanced
     * before the request settled - or in a `finally` - a failed page would be
     * skipped and the visitor would silently never see those rows.
     */
    it("leaves the cursor where it was, so the retry asks for the same page", async () => {
        api.mockRejectedValue(new Error("offline"));

        mount({ offset: "20" });
        await click();

        expect(button()!.dataset.offset).toBe("20");

        api.mockResolvedValue({ items: [{ title: "вторая" }], meta: { nextOffset: 30 } });
        await click();

        expect(api).toHaveBeenLastCalledWith("/api/v1/posts?offset=20");
        expect(items()).toEqual(["первая", "вторая"]);
    });

    it("says so and hands the button back", async () => {
        api.mockRejectedValue(new Error("offline"));

        mount();
        await click();

        expect(toast).toHaveBeenCalledWith(
            expect.objectContaining({ type: "danger", message: "Не удалось загрузить записи" })
        );
        expect(button()!.disabled).toBe(false);
    });

    it("keeps the wrapper, so there is something to retry with", async () => {
        api.mockRejectedValue(new Error("offline"));

        mount();
        await click();

        expect(wrapper()).not.toBeNull();
    });
});

/* ===============================
   Wiring
=============================== */

describe("initialisation", () => {
    it("marks the container so a second init does not double-bind", async () => {
        mount();

        expect(document.getElementById("feed")!.dataset.feedReady).toBe("1");

        initOffsetLoadMore<{ title: string }>({
            containerId: "feed",
            readyFlag: "feedReady",
            apiUrlKey: "postsApiUrl",
            buttonSelector: ".load-more",
            listId: "feed-items",
            wrapperSelector: "[data-load-more-wrapper]",
            render: (item) => `<li>${item.title}</li>`,
            errorMessage: "…",
        });

        await click();

        // Two listeners would fire two requests for one click, and the second
        // would use the offset the first had already advanced past.
        expect(api).toHaveBeenCalledTimes(1);
    });

    it("does nothing on a page without the container", () => {
        document.body.innerHTML = "";

        expect(() => initOffsetLoadMore<{ title: string }>({
            containerId: "feed",
            readyFlag: "feedReady",
            apiUrlKey: "postsApiUrl",
            buttonSelector: ".load-more",
            listId: "feed-items",
            wrapperSelector: "[data-load-more-wrapper]",
            render: () => "",
            errorMessage: "…",
        })).not.toThrow();
    });

    it("disables the button while the request is in flight", async () => {
        api.mockImplementation(() => new Promise(() => {}));

        mount();
        button()!.click();

        // Nothing settles here, so this is the state a second click meets -
        // and `disabled` is what stops it.
        expect(button()!.disabled).toBe(true);
    });
});

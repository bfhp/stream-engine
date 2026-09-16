import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

/** Each page starts one search from data-search, then loads more on demand. */
const fetchMock = vi.fn();
const toast = vi.fn();
function results(): HTMLElement {
    return document.getElementById("searchResults")!;
}

/** Renders the server's markup and runs the module over it. */
async function load(query: string | null): Promise<void> {
    const search = query === null
        ? '<div id="searchResults"></div>'
        : `<div id="searchResults" data-search="${query}"></div>`;
    document.body.innerHTML = search;

    vi.resetModules();
    await import("../../assets-src/pages/search");
    // The autoload's promise settles a microtask later.
    await vi.waitFor(() => {});
}

function respondWith(items: Array<{
    id?: number | null;
    type?: string | null;
    title?: string | null;
    description?: string | null;
    content?: string | null;
    canonicalUrl?: string | null;
    authorDisplayName?: string | null;
    createdAtLabel?: string | null;
    createdAtTitle?: string | null;
    containerType?: string | null;
}>) {
    fetchMock.mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => ({ data: items }),
    });
}

beforeEach(() => {
    fetchMock.mockReset();
    toast.mockReset();

    vi.stubGlobal("fetch", fetchMock);
    (window as any).CMS = { toast };
});

afterEach(() => {
    vi.unstubAllGlobals();
    delete (window as any).CMS;
});

/* ===============================
   When it runs at all
=============================== */

describe("autoload", () => {
    it("searches for the query the server rendered", async () => {
        respondWith([]);

        await load("магия");

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0][0]))
            .toBe("/api/v1/feeds?search=%D0%BC%D0%B0%D0%B3%D0%B8%D1%8F");
    });

    it("does nothing without a query", async () => {
        await load(null);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("does nothing for an empty query", async () => {
        await load("");

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("does nothing at all on a page with no results container", async () => {
        // Every other page of the site loads this bundle's siblings; a missing
        // container has to be a quiet return rather than a throw.
        document.body.innerHTML = "";

        vi.resetModules();
        await expect(import("../../assets-src/pages/search")).resolves.toBeTruthy();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("refuses a query under three characters", async () => {
        await load("ма");

        expect(fetchMock).not.toHaveBeenCalled();
        expect(results().textContent).toContain("Too short a request.");
    });

    it("trims before measuring", async () => {
        await load("  ма  ");

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("searches at exactly three characters", async () => {
        respondWith([]);

        await load("маг");

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it("sends credentials, since results are ACL-filtered per user", async () => {
        respondWith([]);

        await load("магия");

        expect(fetchMock.mock.calls[0][1]).toMatchObject({ credentials: "include" });
    });
});

/* ===============================
   Rendering
=============================== */

describe("the results", () => {
    it("renders a row per feed", async () => {
        respondWith([
            { type: "article", title: "Магия", canonicalUrl: "/articles/magiya/" },
            { type: "article", title: "Таро", canonicalUrl: "/articles/taro/" },
        ]);

        await load("магия");

        const links = results().querySelectorAll("a");
        expect(links).toHaveLength(2);
        expect(links[0].getAttribute("href")).toBe("/articles/magiya/");
        expect(links[0].textContent?.trim()).toBe("Магия");
        expect(results().textContent).toContain("Статья");
    });

    it("renders type, author, date, and a description snippet", async () => {
        respondWith([{
            type: "article",
            title: "История Таро",
            description: "Краткое описание материала",
            canonicalUrl: "/articles/taro/",
            authorDisplayName: "Алиса",
            createdAtLabel: "2 дня назад",
            createdAtTitle: "1 сентября 2026 г. в 10:00",
        }]);

        await load("таро");

        expect(results().textContent).toContain("Статья");
        expect(results().textContent).toContain("Алиса");
        expect(results().textContent).toContain("2 дня назад");
        expect(results().textContent).toContain("Краткое описание материала");
        expect(results().querySelector("[title]")?.getAttribute("title"))
            .toBe("1 сентября 2026 г. в 10:00");
    });

    it("names community posts differently from personal posts", async () => {
        respondWith([{
            type: "blog-post",
            containerType: "community",
            title: "Общий сбор",
            canonicalUrl: "/community/posts/obschij-sbor/",
        }]);

        await load("сбор");

        expect(results().textContent).toContain("Community recording");
    });

    it("uses comment text as the label when there is no title", async () => {
        respondWith([{
            id: 42,
            type: "comment",
            title: null,
            content: "<p>Очень полезный комментарий\nс продолжением.</p>",
            canonicalUrl: "/posts/topic/#comment-42",
        }]);

        await load("комментарий");

        expect(results().querySelector("a")?.textContent?.trim())
            .toBe("Очень полезный комментарий с продолжением.");
        expect(results().textContent).toContain("Комментарий");
    });

    it("does not repeat untitled body text as both label and snippet", async () => {
        respondWith([{
            id: 42,
            type: "comment",
            title: null,
            content: "<p>Комментарий без отдельного заголовка, зато с понятным текстом.</p>",
            canonicalUrl: "/posts/topic/#comment-42",
        }]);

        await load("комментарий");

        expect(results().querySelector(".search-result-snippet")).toBeNull();
    });

    it("falls back to the feed id when neither title nor body can label it", async () => {
        respondWith([{ id: 42, type: "comment", title: null, content: "", canonicalUrl: "/x/" }]);

        await load("комментарий");

        expect(results().querySelector("a")?.textContent?.trim()).toBe("Material #42");
    });

    it("replaces the server's loading spinner", async () => {
        respondWith([{ title: "Магия", canonicalUrl: "/articles/magiya/" }]);

        await load("магия");

        // The twig renders a spinner inside the container; the module owns the
        // contents from here on.
        expect(results().textContent).not.toContain("Идет поиск");
    });

    it("says so when there is nothing", async () => {
        respondWith([]);

        await load("несуществующее");

        expect(results().textContent).toContain("Nothing found.");
    });

    it("treats a malformed payload as nothing found", async () => {
        fetchMock.mockResolvedValue({ ok: true, status: 200, json: async () => ({}) });

        await load("магия");

        expect(results().textContent).toContain("Nothing found.");
    });

    it("escapes a title", async () => {
        respondWith([{ title: '<img src=x onerror="alert(1)">', canonicalUrl: "/x/" }]);

        await load("магия");

        expect(results().innerHTML).toContain("&lt;img");
        expect(results().querySelector("img")).toBeNull();
    });

    it("strips markup from snippets before rendering them", async () => {
        respondWith([{
            title: "Безопасный сниппет",
            content: '<strong>Жирный</strong> текст <img src=x onerror="alert(1)">',
            canonicalUrl: "/x/",
        }]);

        await load("магия");

        expect(results().textContent).toContain("Жирный текст");
        expect(results().querySelector("strong")).toBeNull();
        expect(results().querySelector("img")).toBeNull();
    });

    it("escapes the url and falls back to a hash", async () => {
        respondWith([{ title: "Без ссылки", canonicalUrl: null }]);

        await load("магия");

        expect(results().querySelector("a")!.getAttribute("href")).toBe("#");
    });

    it("renders a missing title as empty rather than as the word null", async () => {
        respondWith([{ title: null, canonicalUrl: "/x/" }]);

        await load("магия");

        expect(results().textContent).not.toContain("null");
    });
});

/* ===============================
   Failures
=============================== */

describe("failures", () => {
    it("names rate limiting specifically", async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 429, json: async () => ({}) });

        await load("магия");

        expect(toast).toHaveBeenCalledWith(
            expect.objectContaining({ type: "warning", message: "Too many requests." })
        );
    });

    it("falls back to a generic message for anything else", async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 500, json: async () => ({}) });

        await load("магия");

        expect(toast).toHaveBeenCalledWith(
            expect.objectContaining({ type: "danger", message: "Search error" })
        );
    });

    it("offers retry instead of leaving the loading placeholder after failure", async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 500 });
        await load("магия");
        expect(results().textContent).toContain("Unable to download the results");
        expect(document.querySelector("button")?.textContent).toBe("Try again");
    });

    it("reports a network failure", async () => {
        const logged = vi.spyOn(console, "error").mockImplementation(() => {});
        fetchMock.mockRejectedValue(new TypeError("Failed to fetch"));

        await load("магия");

        expect(toast).toHaveBeenCalledWith(
            expect.objectContaining({ type: "danger", message: "Network error" })
        );
        expect(logged).toHaveBeenCalled();
    });

    it("says nothing when the failure is an abort", async () => {
        const logged = vi.spyOn(console, "error").mockImplementation(() => {});
        const abort = new Error("aborted");
        abort.name = "AbortError";
        fetchMock.mockRejectedValue(abort);

        await load("магия");

        // Unreachable from this page today - nothing issues a second search -
        // but the branch is the same one the site's quick search relies on,
        // and it must stay silent rather than toasting on a cancellation.
        expect(toast).not.toHaveBeenCalled();
        expect(logged).not.toHaveBeenCalled();
    });
});


describe("pagination", () => {
    function page(title: string, cursor: string | null) {
        return { ok: true, json: async () => ({
            data: [{ title }], meta: { has_more: cursor !== null, next_cursor: cursor },
        }) };
    }

    it("appends pages using each cursor and hides the button after the last page", async () => {
        fetchMock.mockResolvedValueOnce(page("Первый", "a+/="))
            .mockResolvedValueOnce(page("Второй", "next"))
            .mockResolvedValueOnce(page("Третий", null));
        await load("магия");
        const button = document.querySelector("button")!;
        expect(button.hidden).toBe(false);
        button.click();
        await vi.waitFor(() => expect(results().querySelectorAll("a")).toHaveLength(2));
        expect(String(fetchMock.mock.calls[1][0])).toContain("&cursor=a%2B%2F%3D");
        button.click();
        await vi.waitFor(() => expect(results().querySelectorAll("a")).toHaveLength(3));
        expect(String(fetchMock.mock.calls[2][0])).toContain("&cursor=next");
        expect(button.hidden).toBe(true);
    });

    it("preserves results and retries the same cursor after an error", async () => {
        fetchMock.mockResolvedValueOnce(page("Первый", "next"))
            .mockResolvedValueOnce({ ok: false, status: 500 })
            .mockResolvedValueOnce(page("Второй", null));
        await load("магия");
        const button = document.querySelector("button")!;
        button.click();
        await vi.waitFor(() => expect(button.textContent).toBe("Try again"));
        expect(results().querySelectorAll("a")).toHaveLength(1);
        button.click();
        await vi.waitFor(() => expect(results().querySelectorAll("a")).toHaveLength(2));
        expect(fetchMock.mock.calls[1][0]).toBe(fetchMock.mock.calls[2][0]);
    });

    it("prevents duplicate requests while loading and keeps rows on an empty final page", async () => {
        let finish!: (value: unknown) => void;
        fetchMock.mockResolvedValueOnce(page("Первый", "next"))
            .mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
        await load("магия");
        const button = document.querySelector("button")!;
        button.click();
        button.click();
        expect(button.disabled).toBe(true);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        finish({ ok: true, json: async () => ({ data: [], meta: { has_more: false } }) });
        await vi.waitFor(() => expect(button.hidden).toBe(true));
        expect(results().querySelectorAll("a")).toHaveLength(1);
    });
});

describe("loading indicator", () => {
    it("shows progress, explains a slow search, and clears the indicator on completion", async () => {
        let finish!: (value: unknown) => void;
        const timer = vi.spyOn(window, "setTimeout");
        const clearTimer = vi.spyOn(window, "clearTimeout");
        try {
            fetchMock.mockImplementationOnce(() => new Promise(resolve => { finish = resolve; }));
            await load("магия");
            const status = document.querySelector<HTMLElement>('[role="status"]')!;
            expect(status.hidden).toBe(false);
            expect(status.querySelector('.spinner-border')).not.toBeNull();
            expect(status.textContent).toContain('Looking for materials');
            const timerIndex = timer.mock.calls.findIndex(call => call[1] === 3000);
            expect(timerIndex).toBeGreaterThanOrEqual(0);
            (timer.mock.calls[timerIndex][0] as () => void)();
            expect(status.textContent).toContain('Please wait');
            finish({ ok: true, json: async () => ({ data: [] }) });
            await vi.waitFor(() => expect(status.hidden).toBe(true));
            expect(clearTimer).toHaveBeenCalledWith(timer.mock.results[timerIndex].value);
            expect(results().getAttribute('aria-busy')).toBe('false');
        } finally {
            timer.mockRestore();
            clearTimer.mockRestore();
        }
    });
});

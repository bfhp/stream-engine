import { beforeEach, describe, expect, it, vi } from "vitest";

import { csrfToken, uploadFile, uploadHeaders } from "../../assets-src/shared/uploads";

function clearCookies() {
    document.cookie
        .split("; ")
        .map(row => row.split("=")[0])
        .filter(name => name !== "")
        .forEach(name => {
            document.cookie = `${name}=; max-age=0; path=/`;
        });
}

function fakeResponse({ status = 200, json = async () => ({}) } = {}) {
    return {
        status,
        ok: status >= 200 && status < 300,
        json: vi.fn(json),
    };
}

beforeEach(() => {
    clearCookies();
    vi.unstubAllGlobals();
});

describe("csrfToken()", () => {
    it("returns an empty string when the cookie is absent", () => {
        // Not a throw: a request with no token earns a clean
        // ValidationException from the server, which beats a TypeError in the
        // middle of a file picker.
        expect(csrfToken()).toBe("");
    });

    it("picks its own cookie out of several", () => {
        document.cookie = "tz=Europe%2FMoscow; path=/";
        document.cookie = "csrfToken=abc123; path=/";
        document.cookie = "SE_VISIT=whatever; path=/";

        expect(csrfToken()).toBe("abc123");
    });

    it("does not truncate a value containing '='", () => {
        // The implementation this replaced (app.ts's own getCsrfToken) did
        // `.split('=')[1]`, which silently dropped everything past the first
        // '=' - harmless for a hex token, wrong the moment the format changes.
        document.cookie = "csrfToken=a=b=c; path=/";

        expect(csrfToken()).toBe("a=b=c");
    });

    it("does not match a cookie whose name merely ends with the same text", () => {
        document.cookie = "xcsrfToken=nope; path=/";

        expect(csrfToken()).toBe("");
    });
});

describe("uploadHeaders()", () => {
    it("carries the token", () => {
        document.cookie = "csrfToken=tok-1; path=/";

        expect(uploadHeaders()).toEqual({ "X-CSRF-Token": "tok-1" });
    });

    it("does not set Content-Type", () => {
        // For a FormData body the browser must write Content-Type itself so it
        // can include the multipart boundary; setting it by hand produces a
        // body the server can't parse.
        expect(Object.keys(uploadHeaders())).not.toContain("Content-Type");
    });
});

describe("uploadFile()", () => {
    it("posts the file as FormData with the token and credentials", async () => {
        document.cookie = "csrfToken=tok-2; path=/";

        const fetchMock = vi.fn(async () => fakeResponse({ json: async () => ({ id: 7, url: "/uploads/a.png" }) }));
        vi.stubGlobal("fetch", fetchMock);

        const file = new File(["x"], "a.png", { type: "image/png" });

        await expect(uploadFile("/api/v1/uploads", file)).resolves.toMatchObject({ id: 7 });

        const [url, options] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];

        expect(url).toBe("/api/v1/uploads");
        expect(options.method).toBe("POST");
        expect(options.credentials).toBe("include");
        expect((options.headers as Record<string, string>)["X-CSRF-Token"]).toBe("tok-2");

        expect(options.body).toBeInstanceOf(FormData);
        expect((options.body as FormData).get("file")).toBe(file);
    });

    it("preserves a query string on the url", async () => {
        // profile.ts's avatar upload relies on ?variant=avatar surviving.
        const fetchMock = vi.fn(async () => fakeResponse({ json: async () => ({}) }));
        vi.stubGlobal("fetch", fetchMock);

        await uploadFile("/api/v1/uploads?variant=avatar", new File(["x"], "a.png"));

        // The same cast the other tests use: vi.fn() around a zero-arg callback
        // types its recorded calls as [], so the arguments need naming.
        const [url] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];

        expect(url).toBe("/api/v1/uploads?variant=avatar");
    });

    it("rejects with the parsed payload, not an Error", async () => {
        // Matches CMS.api()'s contract - callers hand the rejection to
        // getApiErrorMessage(), which expects the server's shape.
        const payload = { error: "Файл слишком большой" };
        vi.stubGlobal("fetch", vi.fn(async () => fakeResponse({ status: 413, json: async () => payload })));

        await expect(uploadFile("/api/v1/uploads", new File(["x"], "a.png"))).rejects.toEqual(payload);
    });

    it("sends an empty token rather than failing when there is no cookie", async () => {
        const fetchMock = vi.fn(async () => fakeResponse());
        vi.stubGlobal("fetch", fetchMock);

        await uploadFile("/api/v1/uploads", new File(["x"], "a.png"));

        const [, options] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
        expect((options.headers as Record<string, string>)["X-CSRF-Token"]).toBe("");
    });
});

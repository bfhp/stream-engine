import { afterEach, describe, expect, it, vi } from "vitest";
import { initMentionAutocomplete } from "../../assets-src/shared/mentions-autocomplete";

describe("mention autocomplete", () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
        document.body.innerHTML = "";
    });

    it("does one prefix lookup and inserts plain @username text", async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                data: [{ id: 42, username: "alice", displayName: "Alice" }],
            }),
        });
        vi.stubGlobal("fetch", fetchMock);
        document.body.innerHTML = '<textarea name="content">Hi @ali</textarea>';
        const textarea = document.querySelector("textarea")!;
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);
        initMentionAutocomplete();

        textarea.dispatchEvent(new Event("input", { bubbles: true }));
        await vi.advanceTimersByTimeAsync(150);
        await Promise.resolve();
        await Promise.resolve();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe("/api/v1/users?username=ali");

        textarea.dispatchEvent(new KeyboardEvent("keydown", { key: "Enter", bubbles: true }));
        expect(textarea.value).toBe("Hi @alice ");
        expect(textarea.querySelector("a")).toBeNull();
    });
});

import { describe, expect, it } from "vitest";

import { resolveSameOriginUrl } from "../../assets-src/shared/navigation-url";

describe("resolveSameOriginUrl()", () => {
    it("resolves root-relative, path-relative, and same-origin absolute URLs", () => {
        window.history.replaceState(null, "", "/account/profile/");

        expect(resolveSameOriginUrl("/join/?success=1#done"))
            .toBe(`${window.location.origin}/join/?success=1#done`);
        expect(resolveSameOriginUrl("settings/"))
            .toBe(`${window.location.origin}/account/profile/settings/`);
        expect(resolveSameOriginUrl(`${window.location.origin}/inbox/`))
            .toBe(`${window.location.origin}/inbox/`);
    });

    it.each([
        ["missing", undefined],
        ["empty", "   "],
        ["malformed", "http://["],
        ["javascript", "javascript:alert(1)"],
        ["data", "data:text/html,unsafe"],
        ["external", "https://example.invalid/path"],
        ["credentials", `${window.location.protocol}//user:password@${window.location.host}/path`],
        ["protocol-relative external", "//example.invalid/path"],
        ["protocol-relative same-origin", `//${window.location.host}/path`],
    ])("rejects %s URLs", (_label, value) => {
        expect(() => resolveSameOriginUrl(value)).toThrow("Navigation URL is not configured safely");
    });
});

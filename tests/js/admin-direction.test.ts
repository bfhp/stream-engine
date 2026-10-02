import { afterEach, describe, expect, it } from "vitest";

import { isRtl } from "../../assets-src/admin/lib/direction";

describe("admin direction", () => {
    afterEach(() => document.documentElement.removeAttribute("dir"));

    it("is left-to-right unless the server rendered dir=rtl", () => {
        expect(isRtl()).toBe(false);
        document.documentElement.dir = "ltr";
        expect(isRtl()).toBe(false);
    });

    it("detects right-to-left documents", () => {
        document.documentElement.dir = "rtl";
        expect(isRtl()).toBe(true);
    });
});

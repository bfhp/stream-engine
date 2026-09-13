import { describe, expect, it } from "vitest";
import { parsePageSettings } from "../../assets-src/admin/lib/page-settings";

describe("page settings", () => {
    it.each([undefined, null, "", "null"])("handles empty settings: %s", value => {
        expect(parsePageSettings(value)).toEqual({});
    });

    it("preserves other settings when toggling and serializing share buttons", () => {
        const original = parsePageSettings('{"type":"num-life","shareButtons":true,"commentsEnabled":true,"extra":{"count":2}}');
        const saved = JSON.stringify({ ...original, shareButtons: false });
        expect(parsePageSettings(saved)).toEqual({
            type: "num-life", shareButtons: false, commentsEnabled: true, extra: { count: 2 }
        });
    });

    it.each(["{broken", "[]", "true", "42", '"text"'])("rejects invalid settings without discarding them: %s", value => {
        expect(() => parsePageSettings(value)).toThrow();
    });
});

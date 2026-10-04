import { describe, expect, it } from "vitest";
import {
    feedContentWidth,
    parsePageSettings,
    supportsFeedContentWidth
} from "../../assets-src/admin/lib/page-settings";

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

    it.each([
        [{}, "contained"],
        [{ feedContentWidth: "contained" }, "contained"],
        [{ feedContentWidth: "full" }, "full"],
        [{ feedContentWidth: "wide" }, "contained"],
    ] as const)("normalizes feed content width", (settings, expected) => {
        expect(feedContentWidth(parsePageSettings(JSON.stringify(settings)))).toBe(expected);
    });

    it.each([
        ["article.show-id", true],
        ["article.show-slug", true],
        ["articles.list", false],
        ["sections.list", false],
    ])("scopes feed content width to article pages: %s", (action, expected) => {
        expect(supportsFeedContentWidth(action)).toBe(expected);
    });

    it.each(["{broken", "[]", "true", "42", '"text"'])("rejects invalid settings without discarding them: %s", value => {
        expect(() => parsePageSettings(value)).toThrow();
    });
});

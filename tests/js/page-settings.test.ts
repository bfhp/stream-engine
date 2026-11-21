import { describe, expect, it } from "vitest";
import {
    feedContentWidth,
    parsePageSettings,
    supportsFeedContentWidth,
    supportsRelatedArticles,
    visibleWidgets,
    WIDGET_PLACEMENTS,
    withVisibleWidgets
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
        ["articles.list-slug", false],
        ["articles.index", false],
        ["articles.by-term", false],
        ["sections.list", false],
    ])("scopes feed content width to article pages: %s", (action, expected) => {
        expect(supportsFeedContentWidth(action)).toBe(expected);
    });

    it.each([
        ["article.show-id", true],
        ["article.show-slug", true],
        ["articles.list-id", false],
        ["articles.index", false],
    ])("scopes related articles to article pages: %s", (action, expected) => {
        expect(supportsRelatedArticles(action)).toBe(expected);
    });

    it.each(["{broken", "[]", "true", "42", '"text"'])("rejects invalid settings without discarding them: %s", value => {
        expect(() => parsePageSettings(value)).toThrow();
    });

    it("shows every widget placement when nothing is hidden", () => {
        expect(visibleWidgets({})).toEqual([...WIDGET_PLACEMENTS]);
        expect(visibleWidgets({ hiddenWidgets: "sidebar_top" as never })).toEqual([...WIDGET_PLACEMENTS]);
    });

    it("stores unchecked placements as hidden and keeps other settings", () => {
        const visible = WIDGET_PLACEMENTS.filter(p => p !== "sidebar_top" && p !== "footer_legal");
        const saved = withVisibleWidgets({ commentsEnabled: true }, visible);

        expect(saved).toEqual({ commentsEnabled: true, hiddenWidgets: ["sidebar_top", "footer_legal"] });
        expect(visibleWidgets(saved)).toEqual(visible);
    });

    it("drops hiddenWidgets once every placement is checked again", () => {
        expect(withVisibleWidgets({ shareButtons: true, hiddenWidgets: ["head"] }, WIDGET_PLACEMENTS))
            .toEqual({ shareButtons: true });
    });
});

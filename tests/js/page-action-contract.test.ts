import { describe, expect, it } from "vitest";
import {
    buildFeedTypeOptions,
    fieldDescription,
    isPageActionFieldDisabled,
    routeConfigurationWarnings,
    type PageAction,
    validatePageActionConfiguration
} from "../../assets-src/admin/lib/page-action-contract";

function action(overrides: Partial<PageAction> = {}): PageAction {
    return {
        action: "article.show-slug",
        label: "Show an article from the route slug",
        module: "Article",
        fields: {
            feedId: { status: "unsupported" },
            feedType: { status: "required", values: ["article"] },
            listFeedType: { status: "unsupported" },
            termVocabulary: { status: "unsupported" }
        },
        requirements: [],
        ...overrides
    };
}

function idAction(): PageAction {
    return action({
        action: "article.show-id",
        label: "Show a fixed article",
        fields: {
            feedId: { status: "required", feedTypes: ["article"] },
            feedType: { status: "unsupported" },
            listFeedType: { status: "unsupported" },
            termVocabulary: { status: "unsupported" }
        }
    });
}

describe("page action configuration contracts", () => {
    it("reports unsupported, required and constrained field errors", () => {
        expect(validatePageActionConfiguration(action(), {
            feedId: "",
            feedType: "forum",
            listFeedType: "article",
            termVocabulary: ""
        })).toEqual({
            feedType: "Feed type must be one of: article.",
            listFeedType: "List feed type is not used by article.show-slug. Clear it before saving."
        });

        expect(validatePageActionConfiguration(action(), {
            feedId: "",
            feedType: "",
            listFeedType: "",
            termVocabulary: ""
        })).toEqual({
            feedType: "Feed type is required by article.show-slug."
        });

        expect(validatePageActionConfiguration(idAction(), {
            feedId: "",
            feedType: "article",
            listFeedType: "",
            termVocabulary: ""
        })).toEqual({
            feedId: "Feed ID is required by article.show-id.",
            feedType: "Feed type is not used by article.show-id. Clear it before saving."
        });
    });

    it("describes required, unsupported and legacy fields", () => {
        expect(fieldDescription(idAction(), "feedId")).toContain("Required by article.show-id");
        expect(fieldDescription(idAction(), "feedId")).toContain("referenced feed must have type: article");
        expect(fieldDescription(action(), "feedType")).toContain("Required by article.show-slug");
        expect(fieldDescription(action(), "termVocabulary")).toBe("Not used by article.show-slug.");
        expect(fieldDescription(undefined, "feedType")).toContain("legacy values");
    });

    it("filters constrained selectors while retaining an invalid current value", () => {
        const labels = { article: "Article", forum: "Forum" };

        expect(buildFeedTypeOptions(labels, action(), "feedType", "forum")).toEqual([
            { value: "forum", label: "forum (not allowed)" },
            { value: "article", label: "Article (article)" }
        ]);
        expect(buildFeedTypeOptions(labels, action(), "feedType", "legacy")[0])
            .toEqual({ value: "legacy", label: "legacy (unknown type)" });
        expect(buildFeedTypeOptions(labels, action(), "listFeedType", "forum"))
            .toEqual([{ value: "forum", label: "forum (not supported)" }]);
    });

    it("locks legacy fields but lets an unsupported current value be cleared", () => {
        expect(isPageActionFieldDisabled(undefined, "feedType", "legacy")).toBe(true);
        expect(isPageActionFieldDisabled(action(), "termVocabulary", "")).toBe(true);
        expect(isPageActionFieldDisabled(action(), "termVocabulary", "tag")).toBe(false);
    });

    it("recognizes any named route placeholder, not only slug", () => {
        expect(routeConfigurationWarnings({ pattern: "{username}", feedId: "", feedType: "blog" })).toEqual([]);
        expect(routeConfigurationWarnings({ pattern: "{slug:[a-z-]+}", feedId: "", feedType: "article" })).toEqual([]);
    });

    it("warns about suspicious fixed and dynamic route combinations", () => {
        expect(routeConfigurationWarnings({ pattern: "articles", feedId: "", feedType: "article" }))
            .toEqual(["Feed type usually needs a route placeholder such as {slug} (or {username})."]);
        expect(routeConfigurationWarnings({ pattern: "{slug}", feedId: 10, feedType: "" }))
            .toEqual(["Feed ID pins one feed, so the route placeholder does not select a feed dynamically."]);
        expect(routeConfigurationWarnings({ pattern: "{slug}", feedId: 10, feedType: "article" }))
            .toEqual(["Feed ID takes precedence when loading content; verify that this route is intentionally pinned."]);
        expect(routeConfigurationWarnings({ pattern: "", feedId: 10, feedType: "article" })).toEqual([]);
    });
});

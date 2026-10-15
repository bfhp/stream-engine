import { describe, expect, it } from "vitest";
import {
    boundPagesByFeed,
    buildFeedSearchQueries,
    feedDisplayTitle,
    feedIdFromQuery,
    isFeedTypeAllowed,
    mergeFeedResults,
    pagePath,
    pagesBoundToFeed
} from "../../assets-src/admin/lib/feed-picker";

describe("feed picker queries", () => {
    it("treats numeric input as a direct ID lookup", () => {
        expect(feedIdFromQuery("#42")).toBe(42);
        expect(feedIdFromQuery(" 7 ")).toBe(7);
        expect(feedIdFromQuery("0")).toBeNull();
        expect(feedIdFromQuery("news 2")).toBeNull();
        expect(buildFeedSearchQueries("#42", ["article"])).toEqual(["id=42&limit=20"]);
    });

    it("filters by every allowed type and by title", () => {
        expect(buildFeedSearchQueries("", ["article"])).toEqual(["type=article&limit=20"]);
        expect(buildFeedSearchQueries(" About ", ["blog-post", "community"])).toEqual([
            "type=blog-post&limit=20&title=About",
            "type=community&limit=20&title=About"
        ]);
    });

    it("uses full-text search only for long enough input without a type restriction", () => {
        expect(buildFeedSearchQueries("ab", undefined)).toEqual([]);
        expect(buildFeedSearchQueries("", [])).toEqual([]);
        expect(buildFeedSearchQueries("news", undefined)).toEqual(["search=news&limit=20"]);
    });

    it("merges results without duplicates, newest first", () => {
        expect(mergeFeedResults([[{ id: 1 }, { id: 5 }], [{ id: 5 }, { id: 3 }]]).map(feed => feed.id))
            .toEqual([5, 3, 1]);
    });

    it("checks feed types and labels untitled feeds by ID", () => {
        expect(isFeedTypeAllowed({ id: 1, type: "article" }, ["article"])).toBe(true);
        expect(isFeedTypeAllowed({ id: 1, type: "forum" }, ["article"])).toBe(false);
        expect(isFeedTypeAllowed({ id: 1, type: "forum" }, undefined)).toBe(true);
        expect(feedDisplayTitle({ id: 9, title: "  " })).toBe("#9");
        expect(feedDisplayTitle({ id: 9, title: " About\n us " })).toBe("About us");
    });
});

describe("feed bindings", () => {
    const pages = [
        { id: 1, parentId: null, pattern: "", feedId: 10 },
        { id: 2, parentId: 1, pattern: "blog", pageName: "Blog", feedId: 20 },
        { id: 3, parentId: 2, pattern: "/{slug}/", feedId: null },
        { id: 4, parentId: 1, pattern: "about", feedId: 30 }
    ];

    it("builds page paths from ancestor patterns", () => {
        expect(pagePath(pages[0], pages)).toBe("/");
        expect(pagePath(pages[2], pages)).toBe("/blog/{slug}");
    });

    it("maps bound feeds to their pages, excluding the page being edited", () => {
        const bound = boundPagesByFeed(pages, 4);

        expect(bound.get(20)).toEqual({ id: 2, path: "/blog", pageName: "Blog" });
        expect(bound.has(30)).toBe(false);
        expect(pagesBoundToFeed(30, pages)).toEqual([{ id: 4, path: "/about", pageName: "" }]);
        expect(pagesBoundToFeed(99, pages)).toEqual([]);
    });
});

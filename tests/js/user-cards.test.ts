import { beforeEach, describe, expect, it } from "vitest";

import { FeedPostCard, postCardData, renderFeedPostCard } from "../../assets-src/pages/user-cards";
import { pageLink, paginationData, renderPagination } from "../../assets-src/pages/user-pagination";

/**
 * The users/feed listings' client half. Cards and the pager are copies of
 * the theme's templates (components/users/post-card.twig, post-tag.twig,
 * users-pagination.twig), so what is asserted here is the mapping onto their
 * fill points and the paginator's links - the two places that fail quietly:
 * a card that drops the wrong piece only looks wrong after "show more", and
 * a paginator that drops the filter shows a different set on page two.
 */

/* The default theme's templates in template mode (asTemplate: true), kept in
   step with the Twig files by hand. */
const TEMPLATES = `
<template data-ui="post-card"><article class="ui-card post-card">
    <div class="post-card__media">
        <img src="" alt="" data-slot-attr="src:image" data-slot-optional="image">
        <div class="post-card__placeholder" data-slot-optional="noImage"><i class="bi bi-image"></i></div>
    </div>
    <div class="post-card__main">
        <div class="post-card__meta" data-slot-optional="hasMeta">
            <i class="bi bi-calendar3" data-slot-optional="date"></i><span data-slot="date" data-slot-optional="date"></span>
            <span data-slot-optional="dateAndRead">&middot;</span>
            <span data-slot="readTime" data-slot-optional="readTime"></span>
        </div>
        <h3><a href="#" class="post-card__link" data-slot="title" data-slot-attr="href:url"></a></h3>
        <p class="post-card__excerpt" data-slot="excerpt" data-slot-optional="excerpt"></p>
        <div class="post-card__tags" data-post-tags data-slot-optional="hasTags"></div>
        <div class="post-card__stats">
            <span><i class="bi bi-chat-left-text"></i><span data-slot="comments">0</span></span>
            <span data-slot-optional="rating"><i class="bi bi-star-fill"></i><span data-slot="rating">0.0</span></span>
            <span data-slot-optional="noRating"><i class="bi bi-star"></i>no evaluations</span>
            <button type="button" data-audio-play-track-id="" data-slot-optional="audioTrackId" data-slot-attr="data-audio-play-track-id:audioTrackId">Listen.</button>
        </div>
    </div>
</article></template>
<template data-ui="post-card-tag"><span class="ui-badge post-card__tag" data-slot="tag">#</span></template>
<template data-ui="users-pagination"><nav class="users-pagination">
    <a href="#" data-slot-attr="href:prevUrl" data-slot-optional="prevUrl">Previous</a>
    <span aria-disabled="true" data-slot-optional="noPrev">Previous</span>
    <span data-slot="label"></span>
    <a href="#" data-slot-attr="href:nextUrl" data-slot-optional="nextUrl">Next</a>
    <span aria-disabled="true" data-slot-optional="noNext">Next</span>
</nav></template>`;

beforeEach(() => {
    document.body.innerHTML = TEMPLATES;
});

function card(overrides: Partial<FeedPostCard> = {}): FeedPostCard {
    return {
        title: "Заголовок",
        url: "/blog/post/",
        imageUrl: null,
        dateLabel: "5 марта",
        readTimeLabel: "3 мин",
        excerpt: null,
        tags: [],
        commentCount: 0,
        ratingAverage: 0,
        ratingCount: 0,
        ...overrides,
    };
}

function render(item: FeedPostCard): HTMLElement {
    const el = renderFeedPostCard(item);
    expect(el).not.toBeNull();
    return el!;
}

/* ===============================
   Rating
=============================== */

describe("rating", () => {
    /**
     * An average of zero out of zero votes is not a rating. Rendering it as
     * "0.0" makes every new post look badly reviewed rather than unreviewed.
     */
    it("says there are no ratings rather than showing zero", () => {
        const el = render(card({ ratingCount: 0, ratingAverage: 0 }));

        expect(el.textContent).toContain("no evaluations");
        expect(el.textContent).not.toContain("0.0");
        expect(el.querySelector(".bi-star-fill")).toBeNull();
    });

    it("shows one decimal once anyone has rated", () => {
        expect(postCardData(card({ ratingCount: 3, ratingAverage: 4.25 })).rating).toBe("4.3");
    });

    it("coerces a string average, which is what JSON gives back", () => {
        // MySQL hands an AVG() back as a string; `"4".toFixed` would throw.
        expect(postCardData(card({ ratingCount: 1, ratingAverage: "4" as unknown as number })).rating).toBe("4.0");
    });

    it("uses the filled star only when there is a rating", () => {
        const el = render(card({ ratingCount: 1, ratingAverage: 5 }));

        expect(el.querySelector(".bi-star-fill")).not.toBeNull();
        expect(el.textContent).not.toContain("no evaluations");
    });
});

/* ===============================
   Tags
=============================== */

describe("tags", () => {
    it("drops the tag row entirely when there are no tags", () => {
        // An empty wrapper would add a gap under every untagged card.
        expect(render(card({ tags: [] })).querySelector("[data-post-tags]")).toBeNull();
    });

    it("survives tags being absent from the payload", () => {
        expect(render(card({ tags: undefined as unknown as string[] })).querySelector("[data-post-tags]")).toBeNull();
    });

    it("adds one tag copy per tag, prefixed with a hash", () => {
        const tags = render(card({ tags: ["магия", "таро"] })).querySelectorAll(".post-card__tag");

        expect(Array.from(tags, (tag) => tag.textContent)).toEqual(["#магия", "#таро"]);
    });

    it("puts a tag in as text", () => {
        const el = render(card({ tags: ['<img onerror="alert(1)">'] }));

        expect(el.querySelector("img[onerror]")).toBeNull();
        expect(el.querySelector(".post-card__tag")?.textContent).toBe('#<img onerror="alert(1)">');
    });
});

/* ===============================
   Meta line
=============================== */

describe("meta line", () => {
    it("drops the line when a card has neither date nor read time", () => {
        // Otherwise an empty line with a stray separator in it.
        expect(postCardData(card({ dateLabel: null, readTimeLabel: null })).hasMeta).toBe("");
        expect(render(card({ dateLabel: null, readTimeLabel: null })).querySelector(".post-card__meta")).toBeNull();
    });

    it("drops the separator when only one of the two is present", () => {
        expect(render(card({ readTimeLabel: null })).textContent).not.toContain("·");
        expect(render(card({ dateLabel: null })).textContent).not.toContain("·");
        expect(render(card()).textContent).toContain("·");
    });

    it("maps the author byline for a community card", () => {
        const data = postCardData(card({ authorName: "Иван", authorUrl: "/users/ivan/", authorAvatarUrl: "/a.webp" }));

        expect(data.authorName).toBe("Иван");
        expect(data.authorUrl).toBe("/users/ivan/");
        expect(data.authorAvatar).toBe("/a.webp");
        expect(data.authorText).toBe("");
    });

    it("asks for the unlinked author when there is no profile to link to", () => {
        const data = postCardData(card({ authorName: "Иван", authorUrl: null }));

        expect(data.authorUrl).toBe("");
        expect(data.authorText).toBe("1");
    });
});

/* ===============================
   The card
=============================== */

describe("renderFeedPostCard()", () => {
    it("returns null when the page has no card template", () => {
        document.body.innerHTML = "";

        expect(renderFeedPostCard(card())).toBeNull();
    });

    it("shows the placeholder when there is no image", () => {
        const el = render(card({ imageUrl: null }));

        expect(el.querySelector(".bi-image")).not.toBeNull();
        expect(el.querySelector("img")).toBeNull();
    });

    it("sets the image as an attribute, never as markup", () => {
        const el = render(card({ imageUrl: '/img.jpg" onerror="alert(1)' }));

        expect(el.querySelector("img")?.getAttribute("src")).toBe('/img.jpg" onerror="alert(1)');
        expect(el.querySelector("[onerror]")).toBeNull();
    });

    it("renders empty link text for a card with no title", () => {
        const link = render(card({ title: null })).querySelector<HTMLAnchorElement>(".post-card__link")!;

        expect(link.textContent).toBe("");
    });

    it("falls back to a hash href when there is no url", () => {
        expect(render(card({ url: null })).querySelector(".post-card__link")?.getAttribute("href")).toBe("#");
    });

    it("puts a title in as text and the url as an attribute", () => {
        const el = render(card({ title: '<b>"жирный"</b> & co', url: '/p/"onmouseover="alert(1)/' }));
        const link = el.querySelector(".post-card__link")!;

        expect(link.textContent).toBe('<b>"жирный"</b> & co');
        expect(link.querySelector("b")).toBeNull();
        expect(link.getAttribute("href")).toBe('/p/"onmouseover="alert(1)/');
        expect(el.querySelector("[onmouseover]")).toBeNull();
    });

    it("drops the excerpt paragraph when there is none", () => {
        expect(render(card({ excerpt: null })).querySelector(".post-card__excerpt")).toBeNull();
        expect(render(card({ excerpt: "Начало текста" })).querySelector(".post-card__excerpt")?.textContent).toBe("Начало текста");
    });

    it("coerces a missing comment count to zero", () => {
        const el = render(card({ commentCount: undefined as unknown as number }));

        expect(el.querySelector('[data-slot="comments"]')?.textContent).toBe("0");
        expect(el.textContent).not.toContain("undefined");
    });

    it("keeps the play button only when the card has an audio track", () => {
        const withTrack = render(card({ audioTrackId: "post-10-track-25" }));

        expect(withTrack.querySelector("button")?.getAttribute("data-audio-play-track-id")).toBe("post-10-track-25");
        expect(render(card()).querySelector("button")).toBeNull();
    });
});

/* ===============================
   pageLink
=============================== */

describe("pageLink()", () => {
    /**
     * The classic silent bug: a paginator that composes a fresh query string
     * drops the search the visitor typed, and page two quietly shows every
     * user instead of the matches.
     */
    it("keeps the active filters", () => {
        expect(pageLink("/users/", "?q=иван&sort=name&direction=asc", 2))
            .toBe("/users/?q=%D0%B8%D0%B2%D0%B0%D0%BD&sort=name&direction=asc&page=2");
    });

    it("deletes the parameter for page one instead of writing page=1", () => {
        // One canonical URL for the first page rather than two.
        expect(pageLink("/users/", "?q=x&page=3", 1)).toBe("/users/?q=x");
    });

    it("replaces an existing page rather than appending a second", () => {
        expect(pageLink("/users/", "?page=3", 2)).toBe("/users/?page=2");
    });

    it("returns a path, not an absolute url", () => {
        const link = pageLink("/users/", "", 2);

        expect(link.startsWith("/")).toBe(true);
        expect(link).not.toContain("http");
    });

    it("produces a bare path when there is nothing to carry", () => {
        expect(pageLink("/users/", "", 1)).toBe("/users/");
    });
});

/* ===============================
   Pagination
=============================== */

describe("pagination", () => {
    it("renders nothing when everything fits on one page", () => {
        expect(paginationData({ currentPage: 1, totalPages: 1 }, "/users/", "")).toBeNull();
        expect(paginationData({ currentPage: 1, totalPages: 0 }, "/users/", "")).toBeNull();
        expect(renderPagination({ currentPage: 1, totalPages: 1 }, "/users/", "")).toBeNull();
    });

    it("treats missing meta as a single page", () => {
        expect(paginationData(undefined, "/users/", "")).toBeNull();
        expect(paginationData({}, "/users/", "")).toBeNull();
    });

    it("disables the previous control on the first page", () => {
        const nav = renderPagination({ currentPage: 1, totalPages: 3 }, "/users/", "")!;

        // A span, not a disabled anchor - a disabled `<a>` is still
        // clickable, and page 0 is a 404.
        expect(nav.querySelectorAll("a")).toHaveLength(1);
        expect(nav.querySelector('span[aria-disabled="true"]')?.textContent).toBe("Previous");
        expect(nav.querySelector("a")?.getAttribute("href")).toBe("/users/?page=2");
    });

    it("disables the next control on the last page", () => {
        const nav = renderPagination({ currentPage: 3, totalPages: 3 }, "/users/", "")!;

        expect(nav.querySelector('span[aria-disabled="true"]')?.textContent).toBe("Next");
        expect(nav.querySelector("a")?.getAttribute("href")).toBe("/users/?page=2");
    });

    it("links both ways in the middle", () => {
        const nav = renderPagination({ currentPage: 2, totalPages: 3 }, "/users/", "?q=x")!;

        expect(Array.from(nav.querySelectorAll("a"), (a) => a.getAttribute("href")))
            .toEqual(["/users/?q=x", "/users/?q=x&page=3"]);
        expect(nav.querySelector('[aria-disabled="true"]')).toBeNull();
    });

    it("names the current position", () => {
        expect(paginationData({ currentPage: 2, totalPages: 7 }, "/users/", "")?.label).toBe("Page 2 of 7");
    });

    it("carries the filter into both links", () => {
        const data = paginationData({ currentPage: 3, totalPages: 5 }, "/users/", "?q=иван")!;

        expect(data.prevUrl).toBe("/users/?q=%D0%B8%D0%B2%D0%B0%D0%BD&page=2");
        expect(data.nextUrl).toBe("/users/?q=%D0%B8%D0%B2%D0%B0%D0%BD&page=4");
    });
});

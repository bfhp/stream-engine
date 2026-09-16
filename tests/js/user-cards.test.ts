import { describe, expect, it } from "vitest";

import {
    FeedPostCard,
    renderFeedCardMeta,
    renderFeedCardRating,
    renderFeedCardTags,
    renderFeedCardThumbnail,
    renderFeedPostCard,
} from "../../assets-src/pages/user-cards";
import { pageLink, pagination } from "../../assets-src/pages/user-pagination";

/**
 * The two halves of the users/feed listings that are hand-synced with
 * something else: the card renderers mirror `components/users/post-card.twig`
 * card-for-card, and the paginator has to preserve whatever the visitor was
 * filtering by.
 *
 * Both fail quietly. A card that drifts from the partial only looks wrong
 * after "Показать ещё"; a paginator that drops the filter shows a different
 * set on page two and nobody reads the URL.
 */

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

/* ===============================
   renderFeedCardRating
=============================== */

describe("renderFeedCardRating()", () => {
    /**
     * An average of zero out of zero votes is not a rating. Rendering it as
     * "0.0" makes every new post look badly reviewed rather than unreviewed.
     */
    it("says there are no ratings rather than showing zero", () => {
        const html = renderFeedCardRating(card({ ratingCount: 0, ratingAverage: 0 }));

        expect(html).toContain("no evaluations");
        expect(html).not.toContain("0.0");
    });

    it("shows one decimal once anyone has rated", () => {
        expect(renderFeedCardRating(card({ ratingCount: 3, ratingAverage: 4.25 })))
            .toContain("4.3");
    });

    it("coerces a string average, which is what JSON gives back", () => {
        // MySQL hands an AVG() back as a string; `"4".toFixed` would throw.
        const html = renderFeedCardRating(card({
            ratingCount: 1,
            ratingAverage: "4" as unknown as number,
        }));

        expect(html).toContain("4.0");
    });

    it("uses the filled star only when there is a rating", () => {
        expect(renderFeedCardRating(card({ ratingCount: 1, ratingAverage: 5 })))
            .toContain("bi-star-fill");
        expect(renderFeedCardRating(card({ ratingCount: 0 })))
            .not.toContain("bi-star-fill");
    });
});

/* ===============================
   renderFeedCardTags
=============================== */

describe("renderFeedCardTags()", () => {
    it("renders nothing at all when there are no tags", () => {
        // Empty string, not an empty wrapper - the wrapper carries `mb-3`
        // and would add a gap under every untagged card.
        expect(renderFeedCardTags([])).toBe("");
    });

    it("survives tags being absent from the payload", () => {
        expect(renderFeedCardTags(undefined as unknown as string[])).toBe("");
    });

    it("prefixes each tag with a hash", () => {
        const html = renderFeedCardTags(["магия", "таро"]);

        expect(html).toContain("#магия");
        expect(html).toContain("#таро");
        expect((html.match(/badge/g) ?? []).length).toBe(2);
    });

    it("escapes a tag", () => {
        const html = renderFeedCardTags(['<img onerror="alert(1)">']);

        expect(html).toContain("&lt;img");
        expect(html).not.toContain("<img");
    });
});

/* ===============================
   renderFeedCardMeta
=============================== */

describe("renderFeedCardMeta()", () => {
    it("renders nothing when a profile card has neither date nor read time", () => {
        // Otherwise an empty line with a stray separator in it.
        expect(renderFeedCardMeta(card({ dateLabel: null, readTimeLabel: null }), false)).toBe("");
    });

    it("omits the separator when only one of the two is present", () => {
        const dateOnly = renderFeedCardMeta(card({ readTimeLabel: null }), false);
        const timeOnly = renderFeedCardMeta(card({ dateLabel: null }), false);

        expect(dateOnly).not.toContain("&middot;");
        expect(timeOnly).not.toContain("&middot;");
        expect(renderFeedCardMeta(card(), false)).toContain("&middot;");
    });

    it("shows the author byline on a community card", () => {
        const html = renderFeedCardMeta(
            card({ authorName: "Иван", authorUrl: "/users/ivan/", authorAvatarUrl: "/a.webp" }),
            true,
        );

        expect(html).toContain('href="/users/ivan/"');
        expect(html).toContain("Иван");
        expect(html).toContain('src="/a.webp"');
    });

    it("renders an unlinked author when there is no profile to link to", () => {
        const html = renderFeedCardMeta(card({ authorName: "Иван", authorUrl: null }), true);

        expect(html).toContain("Иван");
        expect(html).not.toContain("<a ");
    });

    it("escapes the author name and both urls", () => {
        const html = renderFeedCardMeta(
            card({
                authorName: '<img onerror="alert(1)">',
                authorUrl: '/users/"onmouseover="alert(1)/',
                authorAvatarUrl: '"onerror="alert(1)',
            }),
            true,
        );

        expect(html).not.toContain("<img onerror");
        expect(html).not.toContain('="alert(1)"');
        expect(html).toContain("&quot;");
    });

    it("still shows the byline when the community card has no date", () => {
        // The author is the point of that variant; the date is optional.
        const html = renderFeedCardMeta(
            card({ authorName: "Иван", dateLabel: null, readTimeLabel: null }),
            true,
        );

        expect(html).toContain("Иван");
        expect(html).not.toBe("");
    });
});

/* ===============================
   renderFeedCardThumbnail
=============================== */

describe("renderFeedCardThumbnail()", () => {
    it("renders a placeholder when there is no image", () => {
        const html = renderFeedCardThumbnail(null);

        expect(html).toContain("bi-image");
        expect(html).not.toContain("<img");
    });

    it("escapes the image url", () => {
        const html = renderFeedCardThumbnail('/img.jpg" onerror="alert(1)');

        expect(html).toContain("&quot;");
        expect(html).not.toContain('onerror="alert(1)"');
    });
});

/* ===============================
   renderFeedPostCard
=============================== */

describe("renderFeedPostCard()", () => {
    it("renders empty link text for a card with no title", () => {
        // `escapeHtml(null)` is "" rather than "null" - the difference
        // between a blank link and one that says "null".
        const html = renderFeedPostCard(card({ title: null }), false);

        expect(html).not.toContain("null");
        expect(html).toMatch(/stretched-link">\s*<\/a>/);
    });

    it("falls back to a hash href when there is no url", () => {
        const html = renderFeedPostCard(card({ url: null }), false);

        expect(html).toContain('href="#"');
    });

    it("escapes a title in both the text and the href", () => {
        const html = renderFeedPostCard(
            card({ title: '<b>"жирный"</b> & co', url: '/p/"onmouseover="alert(1)/' }),
            false,
        );

        expect(html).toContain("&lt;b&gt;");
        expect(html).toContain("&amp;");
        expect(html).toContain("&quot;");
        expect(html).not.toContain("<b>");
    });

    it("omits the excerpt paragraph entirely when there is none", () => {
        expect(renderFeedPostCard(card({ excerpt: null }), false)).not.toContain("card-text");
        expect(renderFeedPostCard(card({ excerpt: "Начало текста" }), false)).toContain("Начало текста");
    });

    it("coerces a missing comment count to zero", () => {
        const html = renderFeedPostCard(
            card({ commentCount: undefined as unknown as number }),
            false,
        );

        expect(html).toContain(">0<");
        expect(html).not.toContain("undefined");
    });

    it("renders a play button when the card has an audio track", () => {
        const html = renderFeedPostCard(card({ audioTrackId: "post-10-track-25" }), false);

        expect(html).toContain('data-audio-play-track-id="post-10-track-25"');
        expect(html).toContain("Listen.");
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
   pagination
=============================== */

describe("pagination()", () => {
    it("renders nothing when everything fits on one page", () => {
        expect(pagination({ currentPage: 1, totalPages: 1 }, "/users/", "")).toBe("");
        expect(pagination({ currentPage: 1, totalPages: 0 }, "/users/", "")).toBe("");
    });

    it("treats missing meta as a single page", () => {
        expect(pagination(undefined, "/users/", "")).toBe("");
        expect(pagination({}, "/users/", "")).toBe("");
    });

    it("disables the previous control on the first page", () => {
        const html = pagination({ currentPage: 1, totalPages: 3 }, "/users/", "");

        // A span, not a disabled anchor - a disabled `<a>` is still
        // clickable, and page 0 is a 404.
        expect(html).toMatch(/<span[^>]*disabled[^>]*>Previous<\/span>/);
        expect(html).toContain('href="/users/?page=2"');
    });

    it("disables the next control on the last page", () => {
        const html = pagination({ currentPage: 3, totalPages: 3 }, "/users/", "");

        expect(html).toMatch(/<span[^>]*disabled[^>]*>Next<\/span>/);
        expect(html).toContain('href="/users/?page=2"');
    });

    it("links both ways in the middle", () => {
        const html = pagination({ currentPage: 2, totalPages: 3 }, "/users/", "?q=x");

        expect(html).toContain('href="/users/?q=x"');
        expect(html).toContain('href="/users/?q=x&amp;page=3"');
        expect(html).not.toContain("disabled");
    });

    it("names the current position", () => {
        expect(pagination({ currentPage: 2, totalPages: 7 }, "/users/", ""))
            .toContain("Page 2 of 7");
    });

    it("carries the filter into both links", () => {
        const html = pagination({ currentPage: 3, totalPages: 5 }, "/users/", "?q=иван");

        // Prev goes to page 2 with the query; next to page 4 with it.
        expect(html).toContain("page=2");
        expect(html).toContain("page=4");
        expect((html.match(/q=%D0%B8%D0%B2%D0%B0%D0%BD/g) ?? []).length).toBe(2);
    });

    /**
     * The `&` joining query parameters is escaped in the attribute. HTML5
     * resolves a *named* entity without its semicolon inside an attribute
     * value, so an unescaped `&sect=x` would parse as `§=x` - the link would
     * silently lose the filter. None of today's parameters hit that list,
     * which is precisely why it would be found late.
     */
    it("escapes the ampersands joining the query parameters", () => {
        const html = pagination({ currentPage: 2, totalPages: 3 }, "/users/", "?q=x");

        expect(html).toContain('href="/users/?q=x&amp;page=3"');
        expect(html).not.toContain('href="/users/?q=x&page=3"');
    });
});

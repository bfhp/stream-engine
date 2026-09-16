import { describe, expect, it } from "vitest";

import {
    buildQuoteBlock,
    escapeCommentText,
    renderCommentPreviewHtml,
} from "../../assets-src/shared/comment-quotes";

/**
 * The quick-reply preview, which is two things at once: an escaping boundary
 * and a parser that mirrors `FeedService::extractLeadingQuotes()`.
 *
 * The parser half is the one that fails quietly - a divergence from the server
 * doesn't throw, it just means the preview shows something other than what
 * gets stored, and only for drafts that happen to contain a quote.
 */
describe("comment quotes", () => {
    const quote = (html: string) => (html.match(/<blockquote/g) ?? []).length;

    /* ===============================
       The round trip
    =============================== */

    /**
     * The highest-signal assertion available: what "Цитировать" writes into
     * the textarea is exactly what the preview parses back out. Both sides
     * are in this file, so a change to either that breaks the pair fails
     * here rather than in production.
     */
    it("parses back exactly what the quote button writes", () => {
        const draft = buildQuoteBlock("Иван", "первая строка\nвторая строка") + "мой ответ";

        const html = renderCommentPreviewHtml(draft);

        expect(quote(html)).toBe(1);
        expect(html).toContain("Иван wrote:");
        expect(html).toContain("первая строка<br>вторая строка");
        expect(html.endsWith("мой ответ")).toBe(true);
        // The quoted lines lose their "> " markers - that is the whole point
        // of rendering rather than showing the raw draft.
        expect(html).not.toContain("&gt; первая");
    });

    it("round-trips a single-line quote", () => {
        const html = renderCommentPreviewHtml(buildQuoteBlock("Иван", "привет") + "ответ");

        expect(quote(html)).toBe(1);
        expect(html).toContain("привет");
        expect(html.endsWith("ответ")).toBe(true);
    });

    it("renders a quote with no reply under it", () => {
        // Pressing "Цитировать" and previewing before typing anything.
        const html = renderCommentPreviewHtml(buildQuoteBlock("Иван", "привет"));

        expect(quote(html)).toBe(1);
        expect(html.endsWith("</blockquote>")).toBe(true);
    });

    it("parses stacked quotes in order", () => {
        const draft = buildQuoteBlock("Иван", "первый")
            + buildQuoteBlock("Пётр", "второй")
            + "мой ответ";

        const html = renderCommentPreviewHtml(draft);

        expect(quote(html)).toBe(2);
        expect(html.indexOf("Иван")).toBeLessThan(html.indexOf("Пётр"));
        expect(html.endsWith("мой ответ")).toBe(true);
    });

    /* ===============================
       What is not a quote
    =============================== */

    /**
     * A header with no `> ` body under it is just a line that happens to end
     * in "писал(а):". Emitting a blockquote for it would swallow the line.
     */
    it("leaves a header with no quoted body as plain text", () => {
        const html = renderCommentPreviewHtml("> Иван wrote:\nне цитата");

        expect(quote(html)).toBe(0);
        expect(html).toContain("&gt; Иван wrote:");
    });

    it("leaves a quote that is not at the start as plain text", () => {
        // Matches the server: someone quoting inline, mid-post, gets what
        // they typed rather than a surprise blockquote in the middle.
        const html = renderCommentPreviewHtml("сначала мой текст\n" + buildQuoteBlock("Иван", "привет"));

        expect(quote(html)).toBe(0);
        expect(html).toContain("&gt; Иван wrote:");
        expect(html).toContain("&gt; привет");
    });

    it("leaves ordinary quoted lines with no header alone", () => {
        const html = renderCommentPreviewHtml("> просто цитата\n> ещё строка");

        expect(quote(html)).toBe(0);
        expect(html).toContain("&gt; просто цитата");
    });

    it("needs the author name to be non-empty", () => {
        // `(.+)` - "> писал(а):" names nobody and is not a quote header.
        const html = renderCommentPreviewHtml("> писал(а):\n> привет");

        expect(quote(html)).toBe(0);
    });

    /* ===============================
       The blank line
    =============================== */

    it("consumes the single blank line the quote block leaves behind", () => {
        const html = renderCommentPreviewHtml(buildQuoteBlock("Иван", "привет") + "ответ");

        // No leading <br> before the reply - the separator is part of the
        // quote format, not of the visitor's text.
        expect(html).toMatch(/<\/blockquote>ответ$/);
    });

    it("keeps a second blank line, because that one is the visitor's", () => {
        const html = renderCommentPreviewHtml(buildQuoteBlock("Иван", "привет") + "\n\nответ");

        // One consumed by the parser, the rest trimmed off the front of the
        // body - so the reply still starts cleanly.
        expect(html).toMatch(/<\/blockquote>ответ$/);
    });

    it("keeps blank lines inside the reply", () => {
        const html = renderCommentPreviewHtml(buildQuoteBlock("Иван", "привет") + "первый\n\nвторой");

        expect(html).toContain("первый<br><br>второй");
    });

    /* ===============================
       Line endings
    =============================== */

    it("splits on CRLF and on a lone CR as well as on LF", () => {
        // A textarea normalizes to LF in every modern browser, but a draft
        // pasted from elsewhere need not - and a parser that only knew LF
        // would treat the whole quote as one unmatched line.
        for (const eol of ["\n", "\r\n", "\r"]) {
            const draft = `> Иван wrote:${eol}> привет${eol}${eol}ответ`;
            const html = renderCommentPreviewHtml(draft);

            expect(quote(html), `line ending ${JSON.stringify(eol)}`).toBe(1);
            expect(html.endsWith("ответ")).toBe(true);
        }
    });

    /* ===============================
       Escaping
    =============================== */

    it("escapes the reply body", () => {
        const html = renderCommentPreviewHtml('<img src=x onerror="alert(1)">');

        expect(html).toBe("&lt;img src=x onerror=&quot;alert(1)&quot;&gt;");
        expect(html).not.toContain("<img");
    });

    it("escapes the quoted text", () => {
        const html = renderCommentPreviewHtml(buildQuoteBlock("Иван", "<script>alert(1)</script>"));

        expect(html).toContain("&lt;script&gt;");
        expect(html).not.toContain("<script>");
    });

    it("escapes the author name, which is the least obvious sink", () => {
        // The name comes off a data attribute on the post being quoted, and
        // it is interpolated straight into the blockquote header.
        const html = renderCommentPreviewHtml(buildQuoteBlock('<img onerror="alert(1)">', "привет"));

        expect(html).toContain("&lt;img onerror=&quot;alert(1)&quot;&gt;");
        expect(html).not.toContain("<img");
    });

    it("turns newlines into breaks only after escaping", () => {
        // The other order would escape the <br> it had just inserted.
        const html = renderCommentPreviewHtml("первая\n<b>вторая</b>");

        expect(html).toBe("первая<br>&lt;b&gt;вторая&lt;/b&gt;");
    });

    describe("escapeCommentText()", () => {
        it("escapes the five characters htmlspecialchars() does", () => {
            expect(escapeCommentText(`&<>"'`)).toBe("&amp;&lt;&gt;&quot;&#039;");
        });

        it("escapes the ampersand first, so nothing is double-escaped", () => {
            // `&lt;` in the source must survive as `&amp;lt;`, not become
            // `&lt;` again - which is what happens if `&` is replaced last.
            expect(escapeCommentText("&lt;")).toBe("&amp;lt;");
        });

        /**
         * The divergence worth writing down: this emits `&#039;` while
         * `app.ts`'s `escapeHtml()` - a DOM round-trip - emits `&#39;`. Both
         * render as an apostrophe, so it only shows in a string comparison.
         * This side is the one that matches PHP's `htmlspecialchars()`, which
         * is the right thing for a preview of what the server will store.
         */
        it("uses the PHP spelling of the apostrophe entity", () => {
            expect(escapeCommentText("don't")).toBe("don&#039;t");
            expect(escapeCommentText("don't")).not.toContain("&#39;t");
        });

        it("leaves ordinary text alone", () => {
            expect(escapeCommentText("привет, мир")).toBe("привет, мир");
        });
    });

    /* ===============================
       buildQuoteBlock
    =============================== */

    describe("buildQuoteBlock()", () => {
        it("prefixes every line and ends with a blank one", () => {
            expect(buildQuoteBlock("Иван", "первая\nвторая"))
                .toBe("> Иван wrote:\n> первая\n> вторая\n\n");
        });

        it("keeps a blank line inside the quoted text as a bare marker", () => {
            // "> " with nothing after it - which `startsWith("> ")` still
            // matches, so the quote does not end early on an empty line.
            const block = buildQuoteBlock("Иван", "первая\n\nвторая");

            expect(block).toContain("> первая\n> \n> вторая");
            expect(quote(renderCommentPreviewHtml(block))).toBe(1);
        });

        it("does not escape - that happens at render time", () => {
            // It writes into a textarea, where markup is text already.
            expect(buildQuoteBlock("<b>", "<i>")).toBe("> <b> wrote:\n> <i>\n\n");
        });
    });
});

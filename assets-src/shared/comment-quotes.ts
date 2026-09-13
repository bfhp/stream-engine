/* ==========================================================================
   Comment quotes

   The client-side mirror of `FeedService::extractLeadingQuotes()` +
   `renderQuoteBlock()` + `normalizeCommentContent()`, used by the forum's
   quick-reply preview toggle to show what a draft will look like
   without a server round trip.

   Being a mirror is the point and the risk: if the two drift, the preview
   lies about what gets stored. Two things are deliberately *not* mirrored,
   and both make the preview an approximation rather than a promise:

   - no HTMLPurifier pass, so the preview cannot show the server dropping a
     tag;
   - `escapeCommentText()` emits `&#039;` for an apostrophe where
     `app.ts`'s `escapeHtml()` (a DOM round-trip) emits `&#39;`. Both are
     valid and render identically; the difference only shows in a string
     comparison. PHP's `htmlspecialchars()` emits `&#039;`, so this side is
     the one that matches the server.

   Extracted from `forums.ts` because that module imports Bootstrap and Trix
   at module scope - importing it to test four string functions means
   importing a megabyte of editor.
   ========================================================================== */

import { trans } from "./i18n";

/**
 * Escapes the same five characters `cms.escapeHtml()` does, but by plain
 * string replacement rather than a DOM round-trip - so it stays a pure
 * string transform that newline markup can then be injected into, mirroring
 * `normalizeCommentContent()`'s own htmlspecialchars-then-nl2br order.
 * That ordering is the only reason it isn't just the shared helper.
 */
export function escapeCommentText(value: string): string {
    return value
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

/**
 * The plain-text form a quote takes inside the textarea, produced when the
 * visitor presses the quote action. `renderCommentPreviewHtml()` parses exactly
 * this shape back out, and so does the server.
 *
 * The trailing blank line is part of the format: it separates the quote from
 * the reply the visitor is about to type, and the parser consumes it.
 */
export function buildQuoteBlock(author: string, content: string): string {
    const quotedLines = content.split("\n").map((line) => `> ${line}`).join("\n");

    return `> ${trans("js.comment.quote_header", { author })}\n${quotedLines}\n\n`;
}

/** The `.comment-quote` blockquote the server renders for a parsed quote. */
export function renderCommentQuoteBlockHtml(author: string, quotedText: string): string {
    const safeAuthor = escapeCommentText(author);
    const safeText = escapeCommentText(quotedText).replace(/\n/g, "<br>");

    return `<blockquote class="comment-quote mb-3 px-3 py-2 rounded-2">`
        + `<div class="text-body-secondary mb-1 comment-quote-author"><i class="bi bi-quote me-1"></i>${trans("js.comment.quote_header", { author: safeAuthor })}</div>`
        + `<div class="text-body-secondary comment-quote-text">${safeText}</div>`
        + `</blockquote>`;
}

/**
 * Peels zero or more *leading* quote blocks off a draft and renders them as
 * blockquotes, escaping whatever is left as the reply body.
 *
 * "Leading" is load-bearing and matches the server: a quote further down is
 * left as literal `> ` text, so someone quoting inline in the middle of a
 * post gets what they typed rather than a surprise blockquote.
 *
 * A header line with no `> ` body under it is not a quote either - it is
 * just a line that happens to end in the localized quote marker, and is escaped like any
 * other text.
 */
export function renderCommentPreviewHtml(content: string): string {
    const lines = content.split(/\r\n|\r|\n/);
    const count = lines.length;
    let index = 0;
    let quotesHtml = "";

    while (index < count) {
        const marker = "\u0000";
        const [headerPrefix, headerSuffix = ""] = trans("js.comment.quote_header", { author: marker }).split(marker);
        const line = lines[index];
        if (!line.startsWith(`> ${headerPrefix}`) || !line.endsWith(headerSuffix)) break;

        const author = line.slice(2 + headerPrefix.length, headerSuffix ? -headerSuffix.length : undefined);
        if (!author) break;

        const bodyStart = index + 1;
        let bodyEnd = bodyStart;
        while (bodyEnd < count && lines[bodyEnd].startsWith("> ")) bodyEnd++;

        if (bodyEnd === bodyStart) break;

        const quotedLines = lines.slice(bodyStart, bodyEnd).map((line) => line.slice(2));
        quotesHtml += renderCommentQuoteBlockHtml(author, quotedLines.join("\n"));

        index = bodyEnd;

        // The single blank line buildQuoteBlock() leaves behind. Only one -
        // a second is the visitor's own spacing and survives into the body.
        if (index < count && lines[index].trim() === "") index++;
    }

    const rest = lines.slice(index).join("\n").trim();
    const body = escapeCommentText(rest).replace(/\n/g, "<br>");

    return quotesHtml + body;
}

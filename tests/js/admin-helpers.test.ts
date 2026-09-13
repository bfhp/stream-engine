import { describe, expect, it } from "vitest";

import { makeFeedLabel, normalizeText, stripHtml } from "../../assets-src/admin/lib/feed-label";
import { formatSize, formatTimestamp } from "../../assets-src/admin/lib/format";

/**
 * The admin's display helpers.
 *
 * All five used to be module-private inside page components,
 * which made them unreachable from a test for a silly reason: importing a
 * page pulls in Mantine's barrel, which takes long enough that vitest times the
 * import out. None of the five is React, so they now live in
 * `assets-src/admin/lib/` and the pages import them.
 *
 * What they have in common is that each renders a value which may legitimately
 * be missing - a feed with no title, a sync that never ran - and the interesting
 * behaviour is what they show instead. "0" or "1 Jan 1970" would read as data.
 */
describe("makeFeedLabel()", () => {
    it("uses the title when there is one", () => {
        expect(makeFeedLabel({ id: 5, title: "Свеча гаснет", content: "тело" }))
            .toBe("Свеча гаснет");
    });

    it("falls back to the body for the rows that have no title", () => {
        // Forum replies and comments are a body and nothing else - the large
        // majority of rows in the list, so this is the normal path, not an edge.
        expect(makeFeedLabel({ id: 5, title: null, content: "<p>Ответ в теме</p>" }))
            .toBe("Ответ в теме");
    });

    it("falls back to the id when there is neither", () => {
        expect(makeFeedLabel({ id: 42, title: null, content: null })).toBe("Feed #42");
    });

    it("falls back to the id when the body is markup with no text in it", () => {
        // An empty <p> or a stray <br> is not a label.
        expect(makeFeedLabel({ id: 42, title: "", content: "<p></p><br>" })).toBe("Feed #42");
    });

    it("treats a whitespace-only title as no title at all", () => {
        expect(makeFeedLabel({ id: 42, title: "   \n  ", content: "Текст" })).toBe("Текст");
    });

    it("truncates a long body and marks it as truncated", () => {
        const label = makeFeedLabel({ id: 5, title: null, content: "я".repeat(300) });

        // 140 characters plus the ellipsis - the cell is line-clamped anyway,
        // but the `title` attribute shows the whole string, so it has to end
        // in a way that says "there is more".
        expect(label).toHaveLength(143);
        expect(label.endsWith("...")).toBe(true);
    });

    it("leaves a body that fits exactly alone", () => {
        const label = makeFeedLabel({ id: 5, title: null, content: "я".repeat(140) });

        expect(label).toHaveLength(140);
        expect(label.endsWith("...")).toBe(false);
    });

    it("does not leave a dangling space before the ellipsis", () => {
        // The cut lands mid-word often enough; `trimEnd()` is what stops
        // "... слово ..." from reading as two ellipses.
        const label = makeFeedLabel({ id: 5, title: null, content: `${"я".repeat(139)} слово` });

        expect(label.endsWith(" ...")).toBe(false);
        expect(label).toBe(`${"я".repeat(139)}...`);
    });

    it("collapses the newlines a stored body is full of", () => {
        expect(makeFeedLabel({ id: 5, title: null, content: "<p>Первый</p>\n\n<p>Второй</p>" }))
            .toBe("Первый Второй");
    });

    it("runs two blocks together when nothing separates them", () => {
        // textContent concatenates without inserting anything at a block
        // boundary, so the space in the previous case comes from the newlines
        // in the markup, not from the paragraphs. Minified content therefore
        // reads as one word - acceptable in a 140-character preview, and
        // written down so the previous test isn't mistaken for a guarantee.
        expect(makeFeedLabel({ id: 5, title: null, content: "<p>Первый</p><p>Второй</p>" }))
            .toBe("ПервыйВторой");
    });

    it("handles the fields being absent rather than null", () => {
        // The API omits them rather than sending null for some feed types.
        expect(makeFeedLabel({ id: 7 })).toBe("Feed #7");
    });
});

describe("stripHtml()", () => {
    it("returns the text and not the markup", () => {
        expect(stripHtml("<p>Абзац <b>жирный</b></p>")).toBe("Абзац жирный");
    });

    it("does not execute or inline anything it parses", () => {
        // The body goes through innerHTML to get at its text. That is only
        // safe because textContent is read back - nothing from the parse
        // reaches the page. A <script> contributes its source as text and
        // an <img onerror> contributes nothing at all.
        expect(stripHtml('<img src=x onerror="alert(1)">')).toBe("");
        expect(stripHtml("<script>alert(1)</script>")).toBe("alert(1)");
    });

    it("decodes entities, because they are text once parsed", () => {
        expect(stripHtml("&quot;Зикр&quot; &amp; Co")).toBe('"Зикр" & Co');
    });

    it("stops parsing after the first few thousand characters", () => {
        // A feed body can be a whole article; only 140 characters of it are
        // ever shown, so handing megabytes to innerHTML once per row would be
        // work thrown away. The cut is at 5000.
        const hidden = "КОНЕЦ";
        const text = stripHtml(`<p>${"я".repeat(6000)}${hidden}</p>`);

        expect(text).not.toContain(hidden);
        expect(text.length).toBeLessThanOrEqual(5000);
    });

    it("is empty for empty input", () => {
        expect(stripHtml("")).toBe("");
    });
});

describe("normalizeText()", () => {
    it("collapses runs of whitespace and trims the ends", () => {
        expect(normalizeText("  Мастер   и\n\tМаргарита  ")).toBe("Мастер и Маргарита");
    });

    it("leaves a single-spaced string alone", () => {
        expect(normalizeText("Мастер и Маргарита")).toBe("Мастер и Маргарита");
    });

    it("reduces whitespace-only input to nothing", () => {
        // Which is what lets makeFeedLabel treat a blank title as absent.
        expect(normalizeText(" \n\t ")).toBe("");
    });
});

describe("formatTimestamp()", () => {
    it("renders a real timestamp as a local date and time", () => {
        const formatted = formatTimestamp(1_700_000_000);

        // The locale is the admin's own browser, so the exact string is
        // theirs - what matters is that it is a date rather than a number.
        expect(formatted).not.toBe("-");
        expect(formatted).toContain("2023");
    });

    it("reads seconds, not milliseconds", () => {
        // The API sends Unix seconds. Off by a factor of 1000 the label would
        // land in 1970 and look plausible enough to ship.
        expect(formatTimestamp(1_700_000_000))
            .toBe(new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeStyle: "short" })
                .format(new Date(1_700_000_000_000)));
    });

    it("shows a dash for a sync that has never run", () => {
        expect(formatTimestamp(null)).toBe("-");
    });

    it("shows a dash for a zero timestamp too", () => {
        // The guard is falsy, not `=== null`, and that is right here: the
        // epoch is not a time anything synced at.
        expect(formatTimestamp(0)).toBe("-");
    });
});

describe("formatSize()", () => {
    it("shows a dash when there is no file", () => {
        expect(formatSize(null)).toBe("-");
    });

    it("shows a zero-byte file as zero rather than as absent", () => {
        // Unlike the timestamp, the guard here is `=== null` - an empty
        // download is a real and reportable state, and "-" would hide it.
        expect(formatSize(0)).toBe("0 B");
    });

    it("uses plain bytes below a kilobyte", () => {
        expect(formatSize(512)).toBe("512 B");
        expect(formatSize(1023)).toBe("1023 B");
    });

    it("switches to kilobytes at exactly 1024", () => {
        expect(formatSize(1024)).toBe("1.0 KB");
        expect(formatSize(1536)).toBe("1.5 KB");
    });

    it("switches to megabytes at exactly a mebibyte", () => {
        expect(formatSize(1024 * 1024 - 1)).toBe("1024.0 KB");
        expect(formatSize(1024 * 1024)).toBe("1.0 MB");
    });

    it("keeps one decimal at the top tier, which is where the catalogue lives", () => {
        // The Litres YML is tens of megabytes; "did it get bigger" is the
        // question this label answers.
        expect(formatSize(94_371_840)).toBe("90.0 MB");
        expect(formatSize(98_566_144)).toBe("94.0 MB");
    });
});

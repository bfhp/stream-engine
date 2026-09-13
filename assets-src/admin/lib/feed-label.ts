/* ==========================================================================
   Feed labels for the admin list

   A feed row has to show *something* clickable, and most of them have a
   title. Forum replies and comments don't - they are a body and nothing
   else - so the label falls back to a trimmed preview of the content, and
   finally to the id.

   Lives outside Feeds.tsx because none of it is React: extracting it means a
   test can import three pure functions instead of Mantine's whole barrel,
   which is the difference between a millisecond and a timeout.
   ========================================================================== */

const PREVIEW_LENGTH = 140;

/**
 * Only this much of a body is parsed. A feed's content can be a whole
 * article; the label shows 140 characters of it, so handing megabytes to
 * innerHTML once per row would be work thrown away.
 */
const CONTENT_PARSE_LIMIT = 5000;

export type FeedLabelSource = {
    id: number;
    title?: string | null;
    content?: string | null;
};

/** Collapses every run of whitespace - including newlines - to one space. */
export function normalizeText(value: string): string {
    return value.replace(/\s+/g, " ").trim();
}

/**
 * Stored feed bodies are trusted HTML (sanitized on write), and this puts
 * them through innerHTML to get at the text. That is safe *here* only
 * because the result is used as text: `textContent` is read back, so nothing
 * from the parse is ever inserted into the page.
 */
export function stripHtml(value: string): string {
    const element = document.createElement("div");
    element.innerHTML = value.slice(0, CONTENT_PARSE_LIMIT);

    return normalizeText(element.textContent || "");
}

export function makeFeedLabel(feed: FeedLabelSource): string {
    const title = normalizeText(feed.title || "");

    if (title !== "") {
        return title;
    }

    const content = stripHtml(feed.content || "");

    if (content === "") {
        return `Feed #${feed.id}`;
    }

    return content.length > PREVIEW_LENGTH
        ? `${content.slice(0, PREVIEW_LENGTH).trimEnd()}...`
        : content;
}

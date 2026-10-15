import Trix from "trix";
import { trans } from "./i18n";

/**
 * Images in Trix content are described by their caption: Trix writes no alt,
 * the server gives such images alt="", and the <figcaption> of the <figure>
 * they sit in carries the description (WCAG 1.1.1). Replace Trix's generic
 * "Add a caption…" with a hint that says what the caption is for.
 *
 * Trix reads the placeholder when it renders an attachment, so this works for
 * editors that are already on the page.
 */
export function describeImagesWithCaptions(): void {
    Trix.config.lang.captionPlaceholder = trans("js.editor.image_caption_placeholder");
}

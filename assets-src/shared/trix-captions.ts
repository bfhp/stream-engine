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

/**
 * <label for> does not label a custom element, so a <trix-editor> (role
 * textbox) next to its label still had no accessible name (WCAG 4.1.2).
 * Link each editor to the label that points at it or at its hidden input.
 */
export function labelTrixEditors(root: ParentNode = document): void {
    root.querySelectorAll<HTMLElement>("trix-editor").forEach((editor, index) => {
        if (editor.hasAttribute("aria-label") || editor.hasAttribute("aria-labelledby")) return;
        const ids = [editor.id, editor.getAttribute("input")].filter((id): id is string => !!id);
        const label = ids.map(id => document.querySelector<HTMLLabelElement>(`label[for="${CSS.escape(id)}"]`))
            .find((node): node is HTMLLabelElement => node !== null);
        if (!label) return;
        label.id ||= `${editor.id || "trix-editor"}-label-${index}`;
        editor.setAttribute("aria-labelledby", label.id);
    });
}


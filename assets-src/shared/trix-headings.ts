import Trix from "trix";

/**
 * Trix has one heading button and stores it as <h1>, the same level as the
 * page title, so a post could not have a section under its own title (WCAG
 * 1.3.1, 2.4.6). The button now writes <h2>.
 *
 * Content saved before this keeps its <h1>. When it is opened in an editor
 * the <h1> becomes an <h2> before Trix reads it, so the heading is kept and
 * is saved as <h2> from then on.
 */
export const TRIX_HEADING_TAG = "h2";

/** `<h1 …>` and `</h1>` become h2; nothing else changes. */
export function demoteTopHeadings(html: string): string {
    return html.replace(/<(\/?)h1(?=[\s>/])/gi, `<$1${TRIX_HEADING_TAG}`);
}

type TrixEditorElement = HTMLElement & { inputElement?: HTMLInputElement | null };

let installed = false;

export function useSectionHeadings(): void {
    Trix.config.blockAttributes.heading1.tagName = TRIX_HEADING_TAG;

    if (installed) return;
    installed = true;
    // Fired by every <trix-editor> just before it reads its input. Editors
    // that started before this listener existed read the old <h1> with the
    // old setting, which still parses it as the heading; they save it as
    // <h2> all the same.
    document.addEventListener("trix-before-initialize", (event) => {
        const editor = event.target as TrixEditorElement;
        const id = editor.getAttribute("input");
        const input = editor.inputElement ?? (id ? document.getElementById(id) as HTMLInputElement | null : null);
        if (input) input.value = demoteTopHeadings(input.value);
        else editor.innerHTML = demoteTopHeadings(editor.innerHTML);
    });
}

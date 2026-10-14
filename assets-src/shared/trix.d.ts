/**
 * Trix ships no type declarations. Only what this codebase actually calls is
 * declared; `import "trix"` for its side effect (registering the custom
 * elements) needs nothing.
 */
declare module "trix" {
    interface TrixHTMLSanitizer {
        getHTML(): string;
    }

    const Trix: {
        HTMLSanitizer: {
            /** DOMPurify plus Trix's own element/attribute/protocol rules. */
            sanitize(html: string): TrixHTMLSanitizer;
        };
    };

    export default Trix;
}

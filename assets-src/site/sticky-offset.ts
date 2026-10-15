/*
 * Both bundled themes keep the site header stuck to the top of the window.
 * When keyboard focus moves to an element further down (or Shift+Tab back
 * up), the browser scrolls it into view - by default right under that header,
 * which then hides it (WCAG 2.4.11 Focus Not Obscured). The CSS sets
 * `scroll-padding-block-start` from `--ui-sticky-offset`; this keeps the
 * variable equal to the header's real height, which changes when the
 * navigation wraps or the mobile menu opens.
 */
const HEADER_SELECTOR = "body > header.site-header, body > header.sticky-top, body header.site-header";

export function initStickyOffset(root: Document = document): void {
    const header = root.querySelector<HTMLElement>(HEADER_SELECTOR);
    if (!header) return;

    const style = root.documentElement.style;
    const update = (): void => {
        const position = getComputedStyle(header).position;
        const height = position === "sticky" || position === "fixed" ? header.getBoundingClientRect().height : 0;
        style.setProperty("--ui-sticky-offset", `${Math.ceil(height)}px`);
    };

    update();
    if (typeof ResizeObserver !== "undefined") {
        new ResizeObserver(update).observe(header);
    }
}

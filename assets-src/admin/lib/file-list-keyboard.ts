/*
 * Arrow keys for the file browser's file list.
 *
 * Chonky gives every file one Tab stop (its selection checkbox, or the row
 * itself), so a large folder takes as many Tab presses as it has files.
 * This adds the arrow keys on top, in the list and in the grid view:
 *
 * - Up and Down go to the file above or below (in a grid, the nearest one
 *   in the next row);
 * - Left and Right go to the previous or next file in reading order
 *   (mirrored when the page is right to left);
 * - Home and End go to the first and last file.
 *
 * Tab keeps working as before. The list is virtualized, so Home and End
 * scroll to the top or bottom first and focus the file that is rendered
 * there. Movement is by layout rather than by Chonky's state, which keeps
 * this independent of the library's internals.
 */

const ITEM = "[role=\"listitem\"][data-chonky-context-file-id]";

const KEYS = new Set(["ArrowUp", "ArrowDown", "ArrowLeft", "ArrowRight", "Home", "End"]);

/** The element that takes focus for a file: its checkbox, or the row. */
export function focusTargetOf(item: HTMLElement): HTMLElement {
    return item.querySelector<HTMLElement>("input[type=\"checkbox\"]") ?? item;
}

function renderedItems(root: HTMLElement): HTMLElement[] {
    return [...root.querySelectorAll<HTMLElement>(ITEM)].filter(item => item.getClientRects().length > 0);
}

function scrollerOf(item: HTMLElement, root: HTMLElement): HTMLElement | null {
    for (let el = item.parentElement; el && el !== root.parentElement; el = el.parentElement) {
        const overflow = getComputedStyle(el).overflowY;
        if ((overflow === "auto" || overflow === "scroll") && el.scrollHeight > el.clientHeight) return el;
    }
    return null;
}

/** The nearest item in the next row up or down, by horizontal distance. */
function verticalNeighbour(items: HTMLElement[], current: HTMLElement, down: boolean): HTMLElement | undefined {
    const from = current.getBoundingClientRect();
    const centre = from.left + from.width / 2;
    const candidates = items
        .filter(item => item !== current)
        .map(item => ({ item, rect: item.getBoundingClientRect() }))
        .filter(({ rect }) => (down ? rect.top >= from.bottom - 1 : rect.bottom <= from.top + 1));
    if (!candidates.length) return undefined;

    const rowTop = down
        ? Math.min(...candidates.map(({ rect }) => rect.top))
        : Math.max(...candidates.map(({ rect }) => rect.top));

    return candidates
        .filter(({ rect }) => Math.abs(rect.top - rowTop) < 1)
        .sort((a, b) => Math.abs(a.rect.left + a.rect.width / 2 - centre) - Math.abs(b.rect.left + b.rect.width / 2 - centre))[0]
        ?.item;
}

function focusItem(item: HTMLElement): void {
    focusTargetOf(item).focus();
}

/**
 * Handles one keydown inside the file browser. Returns true when it moved
 * focus, so the caller prevents the default (page scrolling).
 */
export function moveInFileList(event: KeyboardEvent, root: HTMLElement): boolean {
    if (!KEYS.has(event.key) || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return false;

    const target = event.target as HTMLElement | null;
    const current = target?.closest<HTMLElement>(ITEM);
    // Only from a file's own focus target: a text field, a menu or a row
    // action keeps its arrow keys.
    if (!current || !root.contains(current) || focusTargetOf(current) !== target) return false;

    const items = renderedItems(root);
    const index = items.indexOf(current);
    if (index < 0) return false;

    const rtl = current.closest("[dir]")?.getAttribute("dir") === "rtl";
    const forward = rtl ? "ArrowLeft" : "ArrowRight";
    let next: HTMLElement | undefined;

    switch (event.key) {
        case "ArrowDown":
        case "ArrowUp":
            next = verticalNeighbour(items, current, event.key === "ArrowDown");
            break;
        case "ArrowLeft":
        case "ArrowRight":
            next = items[event.key === forward ? index + 1 : index - 1];
            break;
        case "Home":
        case "End": {
            const scroller = scrollerOf(current, root);
            const toEnd = event.key === "End";
            if (scroller) {
                scroller.scrollTop = toEnd ? scroller.scrollHeight : 0;
                // Let the virtualized list render what is there now.
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    const now = renderedItems(root);
                    const edge = toEnd ? now[now.length - 1] : now[0];
                    if (edge) focusItem(edge);
                }));
                return true;
            }
            next = toEnd ? items[items.length - 1] : items[0];
            break;
        }
    }

    if (!next) return true; // at the edge: stay, but do not scroll the page
    focusItem(next);
    return true;
}

/**
 * Chonky puts role="list" on its outer wrapper, but between that wrapper and
 * the files sit the virtualized list's scroller (focusable, tabindex="0"),
 * the drop zone and the loading status, which a list may not contain
 * (axe `aria-required-children`, WCAG 4.1.2). The role moves to the element
 * that holds the rows, so the scroller sits outside the list. Idempotent:
 * run it again whenever Chonky re-renders.
 */
export function fixFileListRoles(root: HTMLElement, label: string): void {
    root.querySelectorAll<HTMLElement>(".chonky-fileListWrapper[role=\"list\"]").forEach((wrapper) => {
        wrapper.removeAttribute("role");
    });
    root.querySelectorAll<HTMLElement>("[data-testid=\"virtuoso-item-list\"], .virtuoso-grid-list").forEach((list) => {
        if (!list.querySelector(ITEM)) return;
        if (list.getAttribute("role") !== "list") list.setAttribute("role", "list");
        if (list.getAttribute("aria-label") !== label) list.setAttribute("aria-label", label);
    });
}

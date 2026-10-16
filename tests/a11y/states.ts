import { expect, type Locator, type Page } from "@playwright/test";

/*
 * Helpers for scanning UI that only exists after an interaction: dialogs,
 * menus, dropdowns, tabs, toasts. They never submit anything that changes
 * data; every state is opened, scanned and closed again.
 */

/** Mantine renders modals and drawers with role="dialog". */
export function adminDialog(page: Page): Locator {
    return page.locator('[role="dialog"]').last();
}

/** Opens a state, waits for `visible`, and returns once it is on screen. */
export async function open(trigger: Locator, visible: Locator): Promise<void> {
    await expect(trigger).toBeVisible();
    await trigger.click();
    await expect(visible).toBeVisible();
}

/**
 * Closes the topmost overlay with Escape, as a keyboard user would, and
 * checks that focus went back to `returnTo`, the control that opened it
 * (WCAG 2.4.3).
 */
export async function closeWithEscape(page: Page, overlay: Locator, returnTo?: Locator): Promise<void> {
    await page.keyboard.press("Escape");
    await expect(overlay).toBeHidden();
    if (returnTo) {
        await expect(returnTo, "focus returns to the control that opened the overlay").toBeFocused();
    }
}

/**
 * WCAG 1.4.10 (Reflow): at 320 CSS px content must not need horizontal
 * scrolling. axe has no rule for this, so measure it directly.
 */
export async function expectNoHorizontalScroll(page: Page): Promise<void> {
    const overflow = await page.evaluate(() => {
        const root = document.documentElement;
        const offenders = [...document.querySelectorAll<HTMLElement>("body *")]
            .filter(element => element.getBoundingClientRect().right > root.clientWidth + 1)
            .filter(element => !element.closest("[hidden], dialog:not([open]), .ui-skip-link"))
            .slice(0, 5)
            .map(element => element.outerHTML.slice(0, 120));

        return { scrollWidth: root.scrollWidth, clientWidth: root.clientWidth, offenders };
    });

    expect(overflow.scrollWidth, `Content wider than the viewport:\n${overflow.offenders.join("\n")}`)
        .toBeLessThanOrEqual(overflow.clientWidth + 1);
}

/*
 * WCAG 1.4.12 (Text Spacing): with the spacing below applied by the reader,
 * no text may be cut off. axe has no rule for this either. The check looks
 * for elements that clip their own content (overflow hidden or clip) and
 * whose text no longer fits; scrolling containers, visually hidden text and
 * hidden elements are skipped.
 */
const TEXT_SPACING_CSS = `
*, *::before, *::after {
    line-height: 1.5 !important;
    letter-spacing: 0.12em !important;
    word-spacing: 0.16em !important;
}
p { margin-block-end: 2em !important; }
`;

export async function expectTextSpacingFits(page: Page): Promise<void> {
    await page.addStyleTag({ content: TEXT_SPACING_CSS });
    const clipped = await page.evaluate(() => {
        const clips = (value: string) => value === "hidden" || value === "clip";
        return [...document.querySelectorAll<HTMLElement>("body *")]
            .filter(element => {
                if (!element.textContent?.trim()) return false;
                if (element.closest('[hidden], [aria-hidden="true"], dialog:not([open]), .ui-visually-hidden, .visually-hidden, .ui-skip-link, .admin-skip-link')) return false;
                const rect = element.getBoundingClientRect();
                // Visually hidden text is a 1px box by design.
                if (rect.width <= 2 || rect.height <= 2) return false;
                const style = getComputedStyle(element);
                if (style.visibility === "hidden" || style.display === "none") return false;
                // Clamped excerpts are cut on purpose; the full text is one link away.
                if (style.getPropertyValue("-webkit-line-clamp") !== "none" && style.getPropertyValue("-webkit-line-clamp") !== "") return false;
                const cutX = clips(style.overflowX) && element.scrollWidth > element.clientWidth + 1;
                const cutY = clips(style.overflowY) && element.scrollHeight > element.clientHeight + 1;
                return cutX || cutY;
            })
            .slice(0, 5)
            .map(element => `${element.outerHTML.slice(0, 160)} (scroll ${element.scrollWidth}x${element.scrollHeight}, box ${element.clientWidth}x${element.clientHeight})`);
    });

    expect(clipped, `Text cut off with WCAG 1.4.12 spacing:\n${clipped.join("\n")}`).toEqual([]);
}

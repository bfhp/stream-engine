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

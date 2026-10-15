import { expect, test } from "@playwright/test";
import { SIGN_IN_BUTTON } from "./paths";
import { closeWithEscape } from "./states";
import { expectFocusContained, expectFocusWithin, expectKeyboardWalk } from "./keyboard";

/* Keyboard operation of public pages for a guest (WCAG 2.1.1, 2.1.2, 2.4.3, 2.4.7, 1.4.11). */

test("home page can be walked with Tab", async ({ page }) => {
    await page.goto("/");
    await expectKeyboardWalk(page);
});

test("not found page can be walked with Tab", async ({ page }) => {
    await page.goto("/a11y-scan-missing-page");
    await expectKeyboardWalk(page);
});

test("sign-in dialog keeps and returns focus", async ({ page }) => {
    await page.goto("/");
    const trigger = page.locator(SIGN_IN_BUTTON).first();
    const dialog = page.locator("#authFormModal");

    // Open it from the keyboard, as a keyboard user would.
    await trigger.focus();
    await page.keyboard.press("Enter");
    await expect(dialog).toBeVisible();
    await expectFocusWithin(dialog);

    await expectFocusContained(page, dialog);
    await closeWithEscape(page, dialog, trigger);
});

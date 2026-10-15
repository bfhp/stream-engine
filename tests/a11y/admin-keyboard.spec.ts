import { test } from "@playwright/test";
import { waitForAdminScreen } from "./scan";
import { expectKeyboardWalk } from "./keyboard";

/*
 * Keyboard operation of the administration panel and the signed-in public
 * site (WCAG 2.1.1, 2.1.2, 2.4.7, 1.4.11). Monaco keeps Tab for indentation
 * by design; the walk leaves it with Monaco's own toggle (Ctrl+M, or
 * Ctrl+Shift+M on macOS), as the hint next to each editor explains. This is
 * listed as a known limitation in ACCESSIBILITY.md.
 */
const ADMIN_SCREENS: Array<[id: string, route: string]> = [
    ["dashboard", "/"],
    ["feeds", "/feeds"],
    ["pages", "/pages"],
    ["menus", "/menus"],
    ["settings", "/settings"],
    ["registration", "/registration"],
    ["themes", "/themes"],
    ["widgets", "/widgets"],
    ["cron", "/cron"],
    ["users", "/users"],
];

for (const [id, route] of ADMIN_SCREENS) {
    test(`admin ${id} can be walked with Tab`, async ({ page }) => {
        await page.goto(`/admin/#${route}`);
        await waitForAdminScreen(page);
        await expectKeyboardWalk(page);
    });
}

test("own profile can be walked with Tab", async ({ page }) => {
    await page.goto("/profile");
    await expectKeyboardWalk(page);
});

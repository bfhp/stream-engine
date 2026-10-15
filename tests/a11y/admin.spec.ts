import { expect, test } from "@playwright/test";
import { expectNoNewViolations, waitForAdminScreen } from "./scan";

/** Built-in administration screens, as routed by the admin HashRouter. */
const ADMIN_SCREENS: Array<[id: string, route: string]> = [
    ["dashboard", "/"],
    ["feeds", "/feeds"],
    ["pages", "/pages"],
    ["menus", "/menus"],
    ["settings", "/settings"],
    ["registration", "/registration"],
    ["themes", "/themes"],
    ["widgets", "/widgets"],
    ["file-browser", "/file-browser"],
    ["cron", "/cron"],
    ["users", "/users"],
];

for (const [id, route] of ADMIN_SCREENS) {
    test(`admin ${id}`, async ({ page }, testInfo) => {
        await page.goto(`/admin/#${route}`);
        await waitForAdminScreen(page);
        await expectNoNewViolations(page, `admin/${id}`, testInfo);
    });
}

test("own profile", async ({ page }, testInfo) => {
    await page.goto("/profile");
    await expectNoNewViolations(page, "member/profile", testInfo);
});

test("admin skip link moves keyboard focus to the main region", async ({ page }) => {
    await page.goto("/admin/#/");
    await waitForAdminScreen(page);
    await page.keyboard.press("Tab");

    const skipLink = page.locator(".admin-skip-link");
    await expect(skipLink).toBeFocused();
    await expect(skipLink).toBeInViewport();

    await page.keyboard.press("Enter");
    await expect(page.locator("#admin-main")).toBeFocused();
    // The link must not have been followed as a HashRouter route.
    expect(new URL(page.url()).hash).toBe("#/");
});

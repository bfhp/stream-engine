import { test } from "@playwright/test";
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

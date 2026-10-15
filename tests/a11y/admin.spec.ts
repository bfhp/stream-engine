import { expect, test, type Page } from "@playwright/test";
import { expectNoNewViolations, waitForAdminScreen } from "./scan";
import { adminDialog, closeWithEscape, expectNoHorizontalScroll, open } from "./states";

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
    // Editors, empty: nothing is saved.
    ["page-new", "/pages/new"],
    ["feed-new", "/feeds/new"],
    ["menu-new", "/menus/new"],
    // The root page always exists.
    ["page-edit", "/pages/1"],
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

/* Open states of the administration panel. Nothing here saves. */
test.describe("admin open states", () => {
    async function screen(page: Page, route: string): Promise<void> {
        await page.goto(`/admin/#${route}`);
        await waitForAdminScreen(page);
    }

    test("dashboard customization dialog", async ({ page }, testInfo) => {
        await screen(page, "/");
        const dialog = adminDialog(page);
        await open(page.locator("main button:has(.tabler-icon-settings)").first(), dialog);
        await expectNoNewViolations(page, "admin/dashboard-customize", testInfo);
        await closeWithEscape(page, dialog);
    });

    test("user editor dialog", async ({ page }, testInfo) => {
        await screen(page, "/users");
        const dialog = adminDialog(page);
        await open(page.locator("main table tbody button:has(.tabler-icon-edit):not([disabled])").first(), dialog);
        await expectNoNewViolations(page, "admin/users-edit", testInfo);
        await closeWithEscape(page, dialog);
    });

    test("feed list menus and popovers", async ({ page }, testInfo) => {
        await screen(page, "/feeds");
        const triggers = page.locator('main button[aria-haspopup]');
        const count = await triggers.count();
        expect(count, "popup triggers on the feed list").toBeGreaterThan(0);
        for (let index = 0; index < count; index++) {
            const trigger = triggers.nth(index);
            await trigger.click();
            await expect(trigger).toHaveAttribute("aria-expanded", "true");
            const popup = page.locator(`#${await trigger.getAttribute("aria-controls")}`);
            await expect(popup).toBeVisible();
            await expectNoNewViolations(page, `admin/feeds-popup-${index + 1}`, testInfo);
            await closeWithEscape(page, popup);
        }
    });

    test("settings select dropdown", async ({ page }, testInfo) => {
        await screen(page, "/settings");
        // Mantine keeps every Select's listbox in the DOM; scan the open one.
        const listbox = page.locator('[role="listbox"]:visible');
        await open(page.locator("main .mantine-Select-input").first(), listbox);
        await expectNoNewViolations(page, "admin/settings-select", testInfo);
        await closeWithEscape(page, listbox);
    });

    test("cron history drawer and run confirmation", async ({ page }, testInfo) => {
        await screen(page, "/cron");
        const dialog = adminDialog(page);

        await open(page.locator("main table tbody tr td:first-child button").first(), dialog);
        await expectNoNewViolations(page, "admin/cron-history", testInfo);
        await closeWithEscape(page, dialog);

        const run = page.locator("main table tbody button:has(.tabler-icon-player-play):not([disabled])").first();
        test.skip(await run.count() === 0, "No cron task can be run on this site.");
        await open(run, dialog);
        await expectNoNewViolations(page, "admin/cron-run-confirm", testInfo);
        // Escape, not the confirm button: the task must not actually run.
        await closeWithEscape(page, dialog);
    });
});

/* Open states of the signed-in public site. */
test.describe("member open states", () => {
    test("profile tabs", async ({ page }, testInfo) => {
        await page.goto("/profile");
        const tabs = page.locator('[role="tab"][aria-controls]');
        const count = await tabs.count();
        expect(count, "profile tabs").toBeGreaterThan(1);

        // The first tab is covered by the "own profile" scan.
        for (let index = 1; index < count; index++) {
            const tab = tabs.nth(index);
            const pane = page.locator(`#${await tab.getAttribute("aria-controls")}`);
            await tab.click();
            await expect(tab).toHaveAttribute("aria-selected", "true");
            await expect(pane).toBeVisible();
            await expectNoNewViolations(page, `member/profile-${await tab.getAttribute("aria-controls")}`, testInfo);
        }
    });

    test("profile at 320 CSS px", async ({ page }, testInfo) => {
        await page.setViewportSize({ width: 320, height: 640 });
        await page.goto("/profile");
        await expectNoHorizontalScroll(page);
        await expectNoNewViolations(page, "member/profile-narrow", testInfo);
    });

    // Last in the file: if the dialog ever confirmed, the session would end.
    test("user menu and sign-out confirmation", async ({ page }, testInfo) => {
        await page.goto("/");
        const toggle = page.locator("#menu-user-toggle");
        const menu = page.locator('[aria-labelledby="menu-user-toggle"]');
        await open(toggle, menu);
        await expectNoNewViolations(page, "member/user-menu", testInfo);

        const confirm = page.locator("#globalConfirmModal");
        await open(menu.locator("[data-logout]").first(), confirm);
        await expectNoNewViolations(page, "member/sign-out-confirm", testInfo,
            builder => builder.include("#globalConfirmModal"));
        await page.keyboard.press("Escape");
        await expect(confirm).toBeHidden();

        await page.reload();
        await expect(page.locator("body")).toHaveAttribute("data-auth", "1");
    });
});

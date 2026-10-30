import { expect, test } from "@playwright/test";
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

/* The file browser's arrow keys (assets-src/admin/lib/file-list-keyboard.ts).
   Uses whatever the uploads folder holds (in CI, bin/seed-demo.php adds a
   folder and two images); nothing is created here. "Next" is ArrowRight,
   or ArrowLeft in a right-to-left locale. */
test("admin file browser moves between files with the arrow keys", async ({ page }) => {
    await page.goto("/admin/#/file-browser");
    await waitForAdminScreen(page);
    const files = page.locator('[role="listitem"][data-chonky-context-file-id]');
    await files.first().waitFor({ timeout: 10_000 }).catch(() => undefined);
    test.skip(await files.count() < 2, "The uploads folder has fewer than two entries.");

    const focusedFileId = () => page.evaluate(() =>
        document.activeElement?.closest("[data-chonky-context-file-id]")?.getAttribute("data-chonky-context-file-id") ?? null);
    const first = files.first();
    const firstTarget = first.locator('input[type="checkbox"]');
    await ((await firstTarget.count()) ? firstTarget : first).focus();
    const start = await focusedFileId();
    expect(start).not.toBeNull();

    const rtl = await page.evaluate(() => document.documentElement.dir === "rtl");
    await page.keyboard.press(rtl ? "ArrowLeft" : "ArrowRight");
    const next = await focusedFileId();
    expect(next).not.toBeNull();
    expect(next).not.toBe(start);

    await page.keyboard.press("Home");
    await expect.poll(focusedFileId).toBe(start);
});

import { expect, test } from "@playwright/test";
import { SIGN_IN_BUTTON } from "./paths";
import { expectNoNewViolations } from "./scan";

test("home page", async ({ page }, testInfo) => {
    await page.goto("/");
    await expectNoNewViolations(page, "guest/home", testInfo);
});

test("not found page", async ({ page }, testInfo) => {
    const response = await page.goto("/a11y-scan-missing-page");
    expect(response?.status()).toBe(404);
    await expectNoNewViolations(page, "guest/not-found", testInfo);
});

test("sign-in dialog", async ({ page }, testInfo) => {
    await page.goto("/");
    await page.locator(SIGN_IN_BUTTON).first().click();
    await expect(page.locator("#authFormModal")).toBeVisible();
    await expectNoNewViolations(page, "guest/sign-in-dialog", testInfo, builder => builder.include("#authFormModal"));
});

/*
 * Registration and password recovery exist only when the site has pages for
 * them, so follow whatever the sign-in dialog links to instead of assuming
 * fixed URLs.
 */
test("pages linked from the sign-in dialog", async ({ page, baseURL }, testInfo) => {
    await page.goto("/");
    const hrefs = await page.locator("#authFormModal a[href]").evaluateAll(links =>
        links.map(link => (link as HTMLAnchorElement).href));
    const paths = [...new Set(hrefs
        .map(href => new URL(href))
        .filter(url => url.origin === new URL(baseURL ?? "http://localhost").origin)
        .map(url => url.pathname))];

    test.skip(paths.length === 0, "The sign-in dialog links to no account pages on this site.");

    for (const path of paths) {
        await test.step(path, async () => {
            await page.goto(path);
            await expectNoNewViolations(page, `guest${path.replace(/\/$/, "")}`, testInfo);
        });
    }
});

test("skip link moves keyboard focus to the main content", async ({ page }) => {
    await page.goto("/");
    await page.keyboard.press("Tab");

    const skipLink = page.locator(".ui-skip-link");
    await expect(skipLink).toBeFocused();
    await expect(skipLink).toBeInViewport();

    await page.keyboard.press("Enter");
    await expect(page.locator("#main-content")).toBeFocused();
});

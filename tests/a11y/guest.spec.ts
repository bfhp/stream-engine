import { expect, test } from "@playwright/test";
import { SIGN_IN_BUTTON } from "./paths";
import { expectNoNewViolations } from "./scan";
import { expectNoHorizontalScroll, expectTextSpacingFits } from "./states";

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

test("failed sign-in feedback", async ({ page }, testInfo) => {
    await page.goto("/");
    await page.locator(SIGN_IN_BUTTON).first().click();
    // An address that cannot exist, so no real account is ever touched.
    await page.locator("#authEmail").fill("a11y-scan@example.invalid");
    await page.locator("#authPassword").fill("not-a-password");

    const response = page.waitForResponse(candidate =>
        new URL(candidate.url()).pathname === "/api/v1/auth" && candidate.request().method() === "POST");
    await page.locator('#authForm button[type="submit"]').click();
    expect((await response).ok()).toBe(false);

    await expect(page.locator("#globalToast")).toBeVisible();
    await expectNoNewViolations(page, "guest/sign-in-failed", testInfo);
});

/* WCAG 1.4.10: the same pages at 320 CSS px, scanned and checked for reflow. */
test.describe("narrow viewport", () => {
    test.use({ viewport: { width: 320, height: 640 } });

    test("home page", async ({ page }, testInfo) => {
        await page.goto("/");
        await expectNoHorizontalScroll(page);
        await expectNoNewViolations(page, "guest/home-narrow", testInfo);
    });

    test("sign-in dialog", async ({ page }, testInfo) => {
        await page.goto("/");
        await page.locator(SIGN_IN_BUTTON).first().click();
        await expect(page.locator("#authFormModal")).toBeVisible();
        await expectNoHorizontalScroll(page);
        await expectNoNewViolations(page, "guest/sign-in-dialog-narrow", testInfo,
            builder => builder.include("#authFormModal"));
    });
});

/* WCAG 1.4.12: no text is cut off with the reader's own text spacing. */
test.describe("text spacing", () => {
    test("home page", async ({ page }) => {
        await page.goto("/");
        await expectTextSpacingFits(page);
    });

    test("sign-in dialog", async ({ page }) => {
        await page.goto("/");
        await page.locator(SIGN_IN_BUTTON).first().click();
        await expect(page.locator("#authFormModal")).toBeVisible();
        await expectTextSpacingFits(page);
    });
});

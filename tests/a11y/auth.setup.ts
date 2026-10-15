import { expect, test as setup } from "@playwright/test";
import { ADMIN_STATE, SIGN_IN_BUTTON } from "./paths";

setup("sign in as the administrator", async ({ page }) => {
    const email = process.env.A11Y_ADMIN_EMAIL;
    const password = process.env.A11Y_ADMIN_PASSWORD;
    if (!email || !password) {
        throw new Error("Set A11Y_ADMIN_EMAIL and A11Y_ADMIN_PASSWORD to scan administration screens.");
    }

    await page.goto("/");
    await page.locator(SIGN_IN_BUTTON).first().click();
    await page.locator("#authEmail").fill(email);
    await page.locator("#authPassword").fill(password);

    const response = page.waitForResponse(candidate =>
        new URL(candidate.url()).pathname === "/api/v1/auth" && candidate.request().method() === "POST");
    await page.locator('#authForm button[type="submit"]').click();
    expect((await response).ok(), "administrator sign-in").toBe(true);

    await page.context().storageState({ path: ADMIN_STATE });
});

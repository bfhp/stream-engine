import { expect, test as setup } from "@playwright/test";
import { writeFileSync } from "node:fs";
import { ADMIN_STATE, SIGN_IN_BUTTON, SITE_MAP } from "./paths";

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

/*
 * Where the site's sections live: the content scans look pages up by their
 * action (forums.list, users.list, ...) instead of assuming URLs, so they run
 * on any site. Dynamic segments such as {username} end the path.
 */
setup("map the site's section pages", async ({ browser }) => {
    const context = await browser.newContext({ storageState: ADMIN_STATE });
    const page = await context.newPage();
    await page.goto("/admin/");

    const paths = await page.evaluate(async () => {
        type Row = { id: number; parentId: number | null; pattern: string; action: string };
        const response = await fetch("/api/v1/admin/pages");
        if (!response.ok) throw new Error(`GET /api/v1/admin/pages: ${response.status}`);
        const rows = ((await response.json()) as { data: Row[] }).data;
        const byId = new Map(rows.map(row => [row.id, row]));

        const pathOf = (row: Row): string | null => {
            const segments: string[] = [];
            for (let node: Row | undefined = row; node; node = node.parentId === null ? undefined : byId.get(node.parentId)) {
                if (node.pattern.includes("{")) return null;
                if (node.pattern !== "") segments.unshift(node.pattern);
            }
            return segments.length === 0 ? "/" : `/${segments.join("/")}/`;
        };

        const map: Record<string, string> = {};
        for (const row of rows) {
            const path = pathOf(row);
            if (path !== null && !(row.action in map)) map[row.action] = path;
        }
        return map;
    });

    writeFileSync(SITE_MAP, JSON.stringify(paths, null, 2));
    await context.close();
});


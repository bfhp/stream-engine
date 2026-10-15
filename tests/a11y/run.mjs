#!/usr/bin/env node
/*
 * Runs the accessibility scan once per theme/locale combination.
 *
 *   A11Y_THEMES=default,bootstrap A11Y_LOCALES=en,ar npm run test:a11y
 *
 * Without A11Y_THEMES and A11Y_LOCALES the site is scanned as it is
 * configured. With either, this script signs in as the administrator,
 * records the current theme, theme settings and site locale, then for each
 * combination activates the theme (with its colour mode set to "system", so
 * the light and dark projects each get their own scheme), sets the locale,
 * and runs Playwright. The recorded settings are restored at the end, also
 * after a failure or Ctrl+C.
 *
 * Settings are changed through the admin API from a real browser page with
 * the administrator's session, the same requests the admin panel makes, so
 * cookies and CSRF behave exactly as they do for a person.
 *
 * Extra arguments are passed to every `playwright test` run.
 */
import { spawnSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { chromium } from "@playwright/test";

const here = path.dirname(fileURLToPath(import.meta.url));
const config = path.join(here, "playwright.config.ts");
// Kept in sync with ADMIN_STATE in paths.ts.
const adminState = path.resolve(here, "../../storage/a11y/admin-state.json");
const baseURL = process.env.A11Y_BASE_URL ?? "http://127.0.0.1:8080";
const extraArgs = process.argv.slice(2);

const list = value => (value ?? "").split(",").map(item => item.trim()).filter(Boolean);
const themes = list(process.env.A11Y_THEMES);
const locales = list(process.env.A11Y_LOCALES);

function playwright(args, env = {}) {
    const result = spawnSync("npx", ["playwright", "test", "-c", config, ...args, ...extraArgs], {
        stdio: "inherit",
        env: { ...process.env, ...env },
    });

    return result.status ?? 1;
}

if (themes.length === 0 && locales.length === 0) {
    process.exit(playwright([]));
}

async function adminApi() {
    const browser = await chromium.launch();
    const context = await browser.newContext({ baseURL, storageState: adminState });
    const page = await context.newPage();
    await page.goto("/admin/");

    const call = (method, url, body) => page.evaluate(async ({ method, url, body }) => {
        const token = document.cookie.split("; ").find(row => row.startsWith("csrfToken="))?.slice(10) ?? "";
        const response = await fetch(url, {
            method,
            headers: { "Content-Type": "application/json", "X-CSRF-Token": token },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        if (!response.ok) {
            throw new Error(`${method} ${url}: ${response.status} ${await response.text()}`);
        }

        return response.json();
    }, { method, url, body });

    return { call, close: () => browser.close() };
}

// 1. Sign in once; the scan runs reuse the stored session through their
//    own setup project.
if (playwright(["--project=setup"]) !== 0) {
    process.exit(1);
}

const api = await adminApi();
const themePayload = await api.call("GET", "/api/v1/admin/themes");
const settingsPayload = await api.call("GET", "/api/v1/admin/settings");
const original = {
    // A configured theme that is no longer installed falls back to another;
    // restore the one that was actually in use.
    themeId: themePayload.fallback ? themePayload.activeId : themePayload.configuredId,
    values: Object.fromEntries(themePayload.themes.map(theme => [theme.id, theme.values ?? {}])),
    locale: (settingsPayload.data ?? []).find(setting => setting.key === "locale")?.value,
};

const known = new Set(themePayload.themes.map(theme => theme.id));
for (const theme of themes) {
    if (!known.has(theme)) {
        console.error(`[a11y] Unknown theme "${theme}". Installed: ${[...known].join(", ")}.`);
        await api.close();
        process.exit(1);
    }
}

async function restore() {
    if (themes.length > 0) {
        // Saving a theme also activates it, so the original theme goes last.
        const order = [...themes.filter(theme => theme !== original.themeId), original.themeId];
        for (const themeId of order) {
            await api.call("PATCH", "/api/v1/admin/themes", { themeId, settings: original.values[themeId] ?? {} });
        }
    }
    if (locales.length > 0 && original.locale !== undefined) {
        await api.call("PATCH", "/api/v1/admin/settings/locale", { value: original.locale });
    }
    console.log(`[a11y] Restored theme "${original.themeId}"${original.locale ? ` and locale "${original.locale}"` : ""}.`);
}

// Ctrl+C reaches the Playwright child too; stop after it and restore.
let interrupted = false;
process.on("SIGINT", () => {
    interrupted = true;
});

let failed = 0;
try {
    combinations:
    for (const theme of themes.length > 0 ? themes : [original.themeId]) {
        for (const locale of locales.length > 0 ? locales : [original.locale ?? "current"]) {
            await new Promise(resolve => setImmediate(resolve));
            if (interrupted) break combinations;

            const label = `${theme}-${locale}`;
            if (themes.length > 0) {
                const settings = { ...(original.values[theme] ?? {}) };
                if ("color_mode" in settings) settings.color_mode = "system";
                await api.call("PATCH", "/api/v1/admin/themes", { themeId: theme, settings });
            }
            if (locales.length > 0) {
                await api.call("PATCH", "/api/v1/admin/settings/locale", { value: locale });
            }

            console.log(`\n[a11y] ===== ${label} =====`);
            if (playwright([], { A11Y_RUN_LABEL: label }) !== 0) {
                failed++;
                console.log(`[a11y] ${label}: failed`);
            }
        }
    }
} finally {
    await restore();
    await api.close();
}

process.exit(interrupted ? 130 : failed > 0 ? 1 : 0);

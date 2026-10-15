import { defineConfig, devices } from "@playwright/test";
import path from "node:path";
import { ADMIN_STATE, OUTPUT_DIR } from "./paths";

/*
 * Page-level WCAG 2.2 A/AA scan with axe-core in Chromium. It needs a running
 * Stream Engine site; locally, point A11Y_BASE_URL at the Docker development
 * site. Run it through `npm run test:a11y` (tests/a11y/run.mjs), which can
 * also switch theme and locale between runs:
 *
 *   A11Y_BASE_URL=http://localhost:5000 \
 *   A11Y_ADMIN_EMAIL=admin@example.com A11Y_ADMIN_PASSWORD=... \
 *   A11Y_THEMES=default,bootstrap A11Y_LOCALES=en,ar \
 *   npm run test:a11y
 *
 * Every page runs in the light and dark colour schemes.
 */
const schemes = ["light", "dark"] as const;

export default defineConfig({
    testDir: ".",
    outputDir: path.join(OUTPUT_DIR, "test-results"),
    // One worker: the pages share one site and one admin session, and
    // baseline updates write a single file.
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    reporter: process.env.CI
        ? [["list"], ["html", { open: "never", outputFolder: path.join(OUTPUT_DIR, "report") }]]
        : [["list"]],
    use: {
        ...devices["Desktop Chrome"],
        baseURL: process.env.A11Y_BASE_URL ?? "http://127.0.0.1:8080",
        // Without this, axe can measure a modal mid fade-in and report the
        // blended, semi-transparent colours. Both themes drop transitions
        // under reduced motion, so the scan sees the settled state.
        reducedMotion: "reduce",
        trace: "retain-on-failure",
    },
    projects: [
        { name: "setup", testMatch: /auth\.setup\.ts$/ },
        ...schemes.flatMap(colorScheme => [
            {
                name: `guest-${colorScheme}`,
                testMatch: /guest(-keyboard)?\.spec\.ts$/,
                use: { colorScheme },
            },
            {
                name: `admin-${colorScheme}`,
                testMatch: /admin(-keyboard)?\.spec\.ts$/,
                dependencies: ["setup"],
                use: { colorScheme, storageState: ADMIN_STATE },
            },
        ]),
    ],
});

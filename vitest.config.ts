import { defineConfig } from "vitest/config";
import { existsSync } from "node:fs";
import path from "node:path";
import { feedTypesPlugin, translationsPlugin } from "./vite-build-data.ts";

const projectRoot = path.resolve(".");
const installedEngineRoot = path.join(projectRoot, "vendor/bfhp/stream-engine");
const engineRoot = existsSync(path.join(installedEngineRoot, "assets-src"))
    ? installedEngineRoot
    : projectRoot;

/**
 * Deliberately separate from vite.config.ts: that file is the *build*
 * description (entry points, output layout, asset naming) and none of it
 * applies to a test run. Vitest picks this file up in preference to
 * vite.config.ts, so the two stay independent.
 */
export default defineConfig({
    plugins: [
        translationsPlugin(path.join(engineRoot, "src/Lang")),
        feedTypesPlugin(path.join(projectRoot, "vendor/autoload.php")),
    ],
    resolve: {
        alias: {
            "@stream-engine": path.join(engineRoot, "assets-src"),
        },
    },
    test: {
        // app.ts is DOM code end to end - document.cookie, document.body
        // dataset, createElement-based escaping. jsdom, not node.
        environment: "jsdom",
        include: ["tests/**/*.test.ts"],
        // Each worker loads jsdom and the build-data plugins. Capping the pool
        // keeps CI stable on runners that report more CPUs than they can
        // sustain concurrently.
        maxWorkers: 2,
        // No globals: tests import describe/it/expect explicitly, so the
        // bare tsconfig.json needs no "types" entry to typecheck them.
        globals: false,
        restoreMocks: true,
    },
});

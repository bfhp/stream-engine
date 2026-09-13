import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { existsSync, readdirSync } from "node:fs";
import path from "node:path";
import { feedTypesPlugin, translationsPlugin } from "./vite-build-data.ts";

const projectRoot = path.resolve(".");
const installedEngineRoot = path.join(projectRoot, "vendor/bfhp/stream-engine");
const engineRoot = existsSync(path.join(installedEngineRoot, "assets-src"))
    ? installedEngineRoot
    : projectRoot;
const engineAssets = path.join(engineRoot, "assets-src");

// Module entry points are optional: removing a module must still allow a build.
function moduleAssetEntries(): Record<string, string> {
    const entries: Record<string, string> = {};
    if (!existsSync("modules")) return entries;
    for (const module of readdirSync("modules").sort()) {
        const assets = `modules/${module}/assets`;
        if (!existsSync(assets)) continue;
        for (const file of readdirSync(assets).sort()) {
            if (!/\.(ts|tsx)$/.test(file) || file.endsWith(".d.ts")) continue;
            const name = file.replace(/\.(ts|tsx)$/, "");
            if (entries[name]) throw new Error(`Duplicate module asset entry: ${name}`);
            entries[name] = `${assets}/${file}`;
        }
    }
    return entries;
}

const moduleEntries = moduleAssetEntries();
const builtInEntryNames = ["admin", "site", "register", "search", "feedback", "messages", "retrieve", "profile", "users", "forums", "blog-post-form"];
for (const name of Object.keys(moduleEntries)) {
    if (builtInEntryNames.includes(name)) throw new Error(`Reserved module asset entry: ${name}`);
}

export default defineConfig({
    plugins: [
        translationsPlugin(path.join(engineRoot, "src/Lang")),
        feedTypesPlugin(path.join(projectRoot, "vendor/autoload.php")),
        react(),
    ],
    resolve: {
        alias: {
            "@stream-engine": engineAssets,
        },
    },
    base: "/assets/",
    publicDir: false,
    build: {
        outDir: "public/assets",
        emptyOutDir: true,
        chunkSizeWarningLimit: 1000,
        minify: true,
        license: {
            fileName: "THIRD_PARTY_LICENSES.md",
        },
        rollupOptions: {
            input: {
                ...moduleEntries,
                admin: "assets-src/admin/main.tsx",
                site: "assets-src/site/main.ts",
                register: path.join(engineAssets, "pages/register.ts"),
                search: path.join(engineAssets, "pages/search.ts"),
                feedback: path.join(engineAssets, "pages/feedback.ts"),
                messages: path.join(engineAssets, "pages/messages.ts"),
                retrieve: path.join(engineAssets, "pages/retrieve.ts"),
                profile: path.join(engineAssets, "pages/profile.ts"),
                users: path.join(engineAssets, "pages/users.ts"),
                forums: path.join(engineAssets, "pages/forums.ts"),
                "blog-post-form": path.join(engineAssets, "pages/blog-post-form.ts"),
            },
            output: {
                postBanner: "/*! Third-party licenses: /assets/THIRD_PARTY_LICENSES.md */",
                entryFileNames: "js/[name].js",
                chunkFileNames: "js/chunks/[name].js",
                assetFileNames: (assetInfo) => {
                    const name = assetInfo.names?.[0] ?? "";
                    const originalName = (assetInfo.originalFileNames?.[0] ?? "").replaceAll("\\", "/");
                    // @ts-ignore
                    if (name.endsWith(".css")) {
                        return "css/[name][extname]";
                    }
                    if (/\.(png|jpg|jpeg|gif|svg|webp)$/.test(name)) {
                        return "img/[name][extname]";
                    }
                    if (/\.(woff2?|woff|ttf|eot)$/.test(name)) {
                        return "fonts/[name][extname]";
                    }
                    return "assets/[name][extname]";
                }
            }
        }
    }
});

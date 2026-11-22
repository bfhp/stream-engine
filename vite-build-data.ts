import { execFileSync } from "node:child_process";
import { readdirSync } from "node:fs";
import path from "node:path";
import type { Plugin } from "vite";

const publicId = "virtual:translations";
const resolvedId = `\0${publicId}`;
// One module per locale and scope: `virtual:translations/i18n-<locale>-<scope>`.
// The last path segment becomes the chunk name (js/chunks/i18n-ru-site.js).
const catalogPrefix = `${publicId}/i18n-`;
const resolvedCatalogPrefix = `\0${catalogPrefix}`;
const feedTypesPublicId = "virtual:feed-types";
const feedTypesResolvedId = `\0${feedTypesPublicId}`;

/**
 * `admin` is every `js.admin.*` key (only the admin panel uses them), `site`
 * is every other `js.*` key (site pages and the shared code the panel uses
 * too). Server-only keys (anything not under `js.`) never reach the browser.
 */
const translationScopes = ["site", "admin"] as const;
type TranslationScope = typeof translationScopes[number];
type Catalogs = Record<string, Record<string, string | string[] | Record<string, string>>>;

function inScope(key: string, scope: TranslationScope): boolean {
    return key.startsWith("js.admin.") === (scope === "admin");
}

/**
 * Browser translations, split so a page downloads only what it uses.
 *
 * Previously `virtual:translations` was every `js.*` key of every locale in
 * one module (729 KB, ~60% of it admin strings) that every site page loaded.
 * Now it is a small index: the locale list and a loader per locale and scope,
 * each a dynamic import of its own chunk. shared/i18n.ts awaits English plus
 * the document locale, so a site page fetches two small chunks.
 *
 * Several language directories may be given (engine first, then the site's
 * own src/Lang); later ones override keys of earlier ones.
 */
export function translationsPlugin(languagePaths: string | string[] = "src/Lang"): Plugin {
    const languageDirectories = (Array.isArray(languagePaths) ? languagePaths : [languagePaths])
        .map(languagePath => path.resolve(languagePath));
    let cached: { files: string[]; catalogs: Catalogs } | null = null;

    function read(): { files: string[]; catalogs: Catalogs } {
        if (cached) return cached;

        const files = languageDirectories.flatMap(languageDirectory =>
            readdirSync(languageDirectory)
                .filter(file => /^[a-z][a-z0-9_-]*\.php$/i.test(file))
                .sort()
                .map(file => path.join(languageDirectory, file))
        );
        const catalogs = JSON.parse(execFileSync(
            "php",
            [
                "-r",
                "$out = []; foreach (array_slice($argv, 1) as $file) { $locale = pathinfo($file, PATHINFO_FILENAME); $messages = array_filter(require $file, static fn ($key): bool => str_starts_with($key, 'js.'), ARRAY_FILTER_USE_KEY); $out[$locale] = array_replace($out[$locale] ?? [], $messages); } echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);",
                ...files,
            ],
            { encoding: "utf8", maxBuffer: 10 * 1024 * 1024 },
        )) as Catalogs;

        cached = { files, catalogs };
        return cached;
    }

    return {
        name: "stream-engine-translations",
        buildStart() {
            cached = null;
        },
        watchChange(id) {
            if (cached?.files.includes(path.resolve(id))) cached = null;
        },
        resolveId(id) {
            return id === publicId || id.startsWith(catalogPrefix) ? `\0${id}` : null;
        },
        load(id) {
            if (id !== resolvedId && !id.startsWith(resolvedCatalogPrefix)) return null;

            const { files, catalogs } = read();
            files.forEach(file => this.addWatchFile(file));

            if (id === resolvedId) {
                const locales = Object.keys(catalogs).sort();
                const loaders = locales.flatMap(locale => translationScopes.map(scope =>
                    `    ${JSON.stringify(`${locale}/${scope}`)}: () => import(${JSON.stringify(`${catalogPrefix}${locale}-${scope}`)}),`
                ));

                return [
                    `export const locales = ${JSON.stringify(locales)};`,
                    "const loaders = {",
                    ...loaders,
                    "};",
                    "export function loadCatalog(locale, scope) {",
                    "    const load = loaders[`${locale}/${scope}`];",
                    "    return load ? load().then(module => module.default) : Promise.resolve({});",
                    "}",
                ].join("\n");
            }

            const match = /^(.+)-(site|admin)$/.exec(id.slice(resolvedCatalogPrefix.length));
            if (!match) return null;
            const [, locale, scope] = match as unknown as [string, string, TranslationScope];
            const messages = Object.fromEntries(
                Object.entries(catalogs[locale] ?? {}).filter(([key]) => inScope(key, scope)),
            );

            return `export default ${JSON.stringify(messages)};`;
        },
    };
}

export function feedTypesPlugin(autoloadPath = "vendor/autoload.php"): Plugin {
    const autoloader = path.resolve(autoloadPath);

    return {
        name: "stream-engine-feed-types",
        resolveId(id) {
            return id === feedTypesPublicId ? feedTypesResolvedId : null;
        },
        load(id) {
            if (id !== feedTypesResolvedId) return null;

            this.addWatchFile(autoloader);

            const result = JSON.parse(execFileSync(
                "php",
                [
                    "-r",
                    "require $argv[1]; $registry = new StreamEngine\\Core\\ModuleRegistry(); $files = array_map(static fn (array $entry): string => (new ReflectionClass($entry['controllerClass']))->getFileName(), $registry->entries()); echo json_encode(['feedTypes' => $registry->feedTypes(), 'files' => $files], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);",
                    autoloader,
                ],
                { encoding: "utf8" },
            )) as { feedTypes: Record<string, string>; files: string[] };

            result.files.forEach(file => this.addWatchFile(file));

            return `export default ${JSON.stringify(result.feedTypes)};`;
        },
    };
}

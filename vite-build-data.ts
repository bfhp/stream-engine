import { execFileSync } from "node:child_process";
import { readdirSync } from "node:fs";
import path from "node:path";
import type { Plugin } from "vite";

const publicId = "virtual:translations";
const resolvedId = `\0${publicId}`;
const feedTypesPublicId = "virtual:feed-types";
const feedTypesResolvedId = `\0${feedTypesPublicId}`;

type Messages = Record<string, string | string[] | Record<string, string>>;
type Catalogs = Record<string, Messages>;

/** What a translation file registers - see shared/i18n.ts (TranslationEntry). */
interface TranslationEntry {
    locale: string;
    locales: string[];
    messages: Messages;
}

/**
 * Browser translations, one file per locale and scope, that the server links
 * for the page's locale (TranslationManager::browserScriptUrl(), the
 * `translationScripts` Twig global in the layouts):
 *
 * - `js/i18n/<locale>.js`: every `js.*` key except `js.admin.*`;
 * - `js/i18n/<locale>-admin.js`: the `js.admin.*` keys, for the admin panel.
 *
 * English is merged into every other locale at build time, so a page needs
 * exactly one file per scope. Each file is a classic script that pushes one
 * TranslationEntry onto `globalThis.__streamEngineTranslations`; linked with
 * `defer` before the page's modules, it has run before any of them.
 *
 * Previously every page bundled every locale's strings, admin ones included
 * (a 729 KB i18n.js). Server-only keys (anything not under `js.`) never reach
 * the browser.
 *
 * `virtual:translations` exports the same entries for every locale, unmerged,
 * for the test suite to register; application code does not import it.
 *
 * Several language directories may be given (engine first, then the site's
 * own src/Lang); later ones override keys of earlier ones.
 */
export function translationsPlugin(languagePaths: string | string[] = "src/Lang"): Plugin {
    const languageDirectories = (Array.isArray(languagePaths) ? languagePaths : [languagePaths])
        .map(languagePath => path.resolve(languagePath));

    function languageFiles(): string[] {
        return languageDirectories.flatMap(languageDirectory =>
            readdirSync(languageDirectory)
                .filter(file => /^[a-z][a-z0-9_-]*\.php$/i.test(file))
                .sort()
                .map(file => path.join(languageDirectory, file))
        );
    }

    function readCatalogs(files: string[]): Catalogs {
        const catalogs = JSON.parse(execFileSync(
            "php",
            [
                "-r",
                "$out = []; foreach (array_slice($argv, 1) as $file) { $locale = strtolower(pathinfo($file, PATHINFO_FILENAME)); $messages = array_filter(require $file, static fn ($key): bool => str_starts_with($key, 'js.'), ARRAY_FILTER_USE_KEY); $out[$locale] = array_replace($out[$locale] ?? [], $messages); } echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);",
                ...files,
            ],
            { encoding: "utf8", maxBuffer: 10 * 1024 * 1024 },
        )) as Catalogs;

        return catalogs;
    }

    function scoped(messages: Messages, admin: boolean): Messages {
        return Object.fromEntries(Object.entries(messages).filter(([key]) => key.startsWith("js.admin.") === admin));
    }

    return {
        name: "stream-engine-translations",
        resolveId(id) {
            return id === publicId ? resolvedId : null;
        },
        load(id) {
            if (id !== resolvedId) return null;

            const files = languageFiles();
            files.forEach(file => this.addWatchFile(file));
            const catalogs = readCatalogs(files);
            const locales = Object.keys(catalogs).sort();
            const entries: TranslationEntry[] = locales.flatMap(locale => [false, true].map(admin => ({
                locale,
                locales,
                messages: scoped(catalogs[locale], admin),
            })));

            return `export default ${JSON.stringify(entries)};`;
        },
        generateBundle() {
            const catalogs = readCatalogs(languageFiles());
            const locales = Object.keys(catalogs).sort();
            const english = catalogs.en ?? {};

            for (const locale of locales) {
                const merged = { ...english, ...catalogs[locale] };
                for (const admin of [false, true]) {
                    const entry: TranslationEntry = { locale, locales, messages: scoped(merged, admin) };
                    this.emitFile({
                        type: "asset",
                        fileName: `js/i18n/${locale}${admin ? "-admin" : ""}.js`,
                        source: `(globalThis.__streamEngineTranslations ||= []).push(${JSON.stringify(entry)});\n`,
                    });
                }
            }
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

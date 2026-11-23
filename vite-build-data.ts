import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { mkdirSync, readdirSync, writeFileSync } from "node:fs";
import path from "node:path";
import type { Plugin, Rollup } from "vite";

type OutputChunk = Rollup.OutputChunk;

const feedTypesPublicId = "virtual:feed-types";
const feedTypesResolvedId = `\0${feedTypesPublicId}`;

/**
 * Import specifiers of the browser translation catalogs. Code imports them
 * like any module (`import catalog, { locale } from "stream-engine-translations"`);
 * in a build they stay external and the page's import map points them at the
 * locale file the server chose (Core\Assets::importMap()), in dev and tests a
 * virtual module stands in. Bare names outside the `@stream-engine` alias on
 * purpose, so the alias never rewrites them.
 */
export const translationSpecifiers = {
    site: "stream-engine-translations",
    admin: "stream-engine-translations/admin",
} as const;

type TranslationScope = keyof typeof translationSpecifiers;
type Messages = Record<string, string | string[] | Record<string, string>>;
type Catalogs = Record<string, Messages>;

/** Built translation files by scope and locale, paths relative to the build's base. */
export type TranslationFiles = Record<TranslationScope, Record<string, string>>;

const scopes = Object.keys(translationSpecifiers) as TranslationScope[];

function inScope(key: string, scope: TranslationScope): boolean {
    return key.startsWith("js.admin.") === (scope === "admin");
}

function scoped(messages: Messages, scope: TranslationScope): Messages {
    return Object.fromEntries(Object.entries(messages).filter(([key]) => inScope(key, scope)));
}

/**
 * Browser translations from the PHP language files: every `js.*` key, split
 * into the site scope and the admin scope (`js.admin.*`), which only the admin
 * panel imports (admin/i18n.ts).
 *
 * In a build each locale and scope becomes its own content-hashed module,
 * `js/i18n/<locale>[-admin]-<hash>.js`, with the English fallback merged in
 * and `locale`/`locales` exports. The files are recorded on `api.files` for
 * assetManifestPlugin, so the server can map the specifiers to the page
 * locale's files in an import map: a page downloads one locale, and the
 * import stays an ordinary static one.
 *
 * Several language directories may be given (engine first, then the site's
 * own src/Lang); later ones override keys of earlier ones.
 */
export function translationsPlugin(languagePaths: string | string[] = "src/Lang"): Plugin<{ files: TranslationFiles }> {
    const languageDirectories = (Array.isArray(languagePaths) ? languagePaths : [languagePaths])
        .map(languagePath => path.resolve(languagePath));
    const files: TranslationFiles = { site: {}, admin: {} };
    let isBuild = false;

    function languageFiles(): string[] {
        return languageDirectories.flatMap(languageDirectory =>
            readdirSync(languageDirectory)
                .filter(file => /^[a-z][a-z0-9_-]*\.php$/i.test(file))
                .sort()
                .map(file => path.join(languageDirectory, file))
        );
    }

    function readCatalogs(paths: string[]): Catalogs {
        return JSON.parse(execFileSync(
            "php",
            [
                "-r",
                "$out = []; foreach (array_slice($argv, 1) as $file) { $locale = strtolower(pathinfo($file, PATHINFO_FILENAME)); $messages = array_filter(require $file, static fn ($key): bool => str_starts_with($key, 'js.'), ARRAY_FILTER_USE_KEY); $out[$locale] = array_replace($out[$locale] ?? [], $messages); } echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);",
                ...paths,
            ],
            { encoding: "utf8", maxBuffer: 10 * 1024 * 1024 },
        )) as Catalogs;
    }

    const specifierScope = new Map<string, TranslationScope>(scopes.map(scope => [translationSpecifiers[scope], scope]));

    return {
        name: "stream-engine-translations",
        // Before Vite's own resolver, which would look for a package by that name.
        enforce: "pre",
        api: { files },
        configResolved(config) {
            isBuild = config.command === "build";
        },
        resolveId(id) {
            if (!specifierScope.has(id)) return null;

            return isBuild ? { id, external: true } : `\0${id}`;
        },
        /**
         * Dev server and tests: every locale is in the module, and the
         * document's `<html lang>` picks one when it is first imported, with the
         * same exact / base language / English order the server uses.
         */
        load(id) {
            const scope = id.startsWith("\0") ? specifierScope.get(id.slice(1)) : undefined;
            if (scope === undefined) return null;

            const paths = languageFiles();
            paths.forEach(file => this.addWatchFile(file));
            const catalogs = readCatalogs(paths);
            const byLocale = Object.fromEntries(Object.entries(catalogs).map(([locale, messages]) => [locale, scoped(messages, scope)]));

            return [
                `const catalogs = ${JSON.stringify(byLocale)};`,
                `export const locales = ${JSON.stringify(Object.keys(catalogs).sort())};`,
                "const requested = String(globalThis.document?.documentElement?.lang || \"en\").trim().toLowerCase().replace(/_/g, \"-\");",
                "const base = requested.split(\"-\")[0];",
                "export const locale = catalogs[requested] ? requested : catalogs[base] ? base : \"en\";",
                "export default { ...(catalogs.en ?? {}), ...(catalogs[locale] ?? {}) };",
            ].join("\n");
        },
        generateBundle() {
            const catalogs = readCatalogs(languageFiles());
            const locales = Object.keys(catalogs).sort();
            const english = catalogs.en ?? {};

            for (const scope of scopes) {
                files[scope] = {};
                for (const locale of locales) {
                    const source = [
                        `export const locale = ${JSON.stringify(locale)};`,
                        `export const locales = ${JSON.stringify(locales)};`,
                        `export default ${JSON.stringify(scoped({ ...english, ...catalogs[locale] }, scope))};`,
                        "",
                    ].join("\n");
                    const hash = createHash("sha256").update(source).digest("base64url").slice(0, 8);
                    const fileName = `js/i18n/${locale}${scope === "admin" ? "-admin" : ""}-${hash}.js`;

                    this.emitFile({ type: "asset", fileName, source });
                    files[scope][locale] = fileName;
                }
            }
        },
    };
}

/** What Core\Assets reads: per entry name, its script, stylesheets and static imports to preload. */
interface ManifestEntry {
    js: string | null;
    css: string[];
    preload: string[];
}

/** A PHP literal for a JSON-like value - the manifest is read with `include`, which opcache keeps in memory. */
function phpLiteral(value: unknown): string {
    if (value === null || value === undefined) return "null";
    if (typeof value === "string") return `'${value.replace(/\\/g, "\\\\").replace(/'/g, "\\'")}'`;
    if (typeof value === "number" || typeof value === "boolean") return JSON.stringify(value);
    if (Array.isArray(value)) return `[${value.map(phpLiteral).join(", ")}]`;

    return `[${Object.entries(value as Record<string, unknown>).map(([key, item]) => `${phpLiteral(key)} => ${phpLiteral(item)}`).join(", ")}]`;
}

/** A chunk with nothing but comments: a CSS-only entry's leftover script. */
function isEmptyChunk(chunk: OutputChunk): boolean {
    return chunk.code.replace(/\/\*[\s\S]*?\*\//g, "").trim() === "";
}

/**
 * Writes `.vite/manifest.php` into the build directory: for every entry its
 * hashed script (null for a CSS-only entry), every stylesheet it and its
 * static imports pull in, and those imports for `<link rel="modulepreload">`;
 * plus the translation files from translationsPlugin. Core\Assets turns an
 * entry name into tags with it, so templates and controllers never spell a
 * built file name. Paths are relative to the build's base (`/assets/`).
 */
export function assetManifestPlugin(translations: { api?: { files: TranslationFiles } }): Plugin {
    return {
        name: "stream-engine-asset-manifest",
        apply: "build",
        writeBundle(options, bundle) {
            const chunks = new Map<string, OutputChunk>();
            for (const output of Object.values(bundle)) {
                if (output.type === "chunk") chunks.set(output.fileName, output);
            }

            const entries: Record<string, ManifestEntry> = {};
            for (const chunk of chunks.values()) {
                if (!chunk.isEntry) continue;

                const css = new Set<string>();
                const preload = new Set<string>();
                const visit = (current: OutputChunk): void => {
                    current.viteMetadata?.importedCss.forEach((file: string) => css.add(file));
                    for (const imported of current.imports) {
                        const importedChunk = chunks.get(imported);
                        if (preload.has(imported) || importedChunk === undefined) continue;
                        preload.add(imported);
                        visit(importedChunk);
                    }
                };
                visit(chunk);

                const cssOnly = isEmptyChunk(chunk);
                entries[chunk.name] = {
                    js: cssOnly ? null : chunk.fileName,
                    css: [...css],
                    preload: cssOnly ? [] : [...preload].filter(file => !isEmptyChunk(chunks.get(file)!)),
                };
            }

            const directory = path.join(options.dir ?? "", ".vite");
            mkdirSync(directory, { recursive: true });
            writeFileSync(
                path.join(directory, "manifest.php"),
                "<?php\n\n// Generated by assetManifestPlugin (vite-build-data.ts). Do not edit.\n\nreturn "
                    + phpLiteral({ entries, translations: translations.api?.files ?? { site: {}, admin: {} } })
                    + ";\n",
            );
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

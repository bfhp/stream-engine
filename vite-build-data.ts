import { execFileSync } from "node:child_process";
import { readdirSync } from "node:fs";
import path from "node:path";
import type { Plugin } from "vite";

const publicId = "virtual:translations";
const resolvedId = `\0${publicId}`;
const feedTypesPublicId = "virtual:feed-types";
const feedTypesResolvedId = `\0${feedTypesPublicId}`;

export function translationsPlugin(languagePath = "src/Lang"): Plugin {
    const languageDirectory = path.resolve(languagePath);

    return {
        name: "stream-engine-translations",
        resolveId(id) {
            return id === publicId ? resolvedId : null;
        },
        load(id) {
            if (id !== resolvedId) return null;

            const languageFiles = readdirSync(languageDirectory)
                .filter(file => /^[a-z][a-z0-9_-]*\.php$/i.test(file))
                .sort()
                .map(file => path.join(languageDirectory, file));
            languageFiles.forEach(file => this.addWatchFile(file));

            const catalogs = JSON.parse(execFileSync(
                "php",
                [
                    "-r",
                    "$out = []; foreach (array_slice($argv, 1) as $file) { $out[pathinfo($file, PATHINFO_FILENAME)] = require $file; } echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);",
                    ...languageFiles,
                ],
                { encoding: "utf8" },
            )) as Record<string, Record<string, string | string[]>>;
            const browserCatalogs = Object.fromEntries(Object.entries(catalogs).map(([locale, messages]) => [
                locale,
                Object.fromEntries(Object.entries(messages).filter(([key]) => key.startsWith("js."))),
            ]));

            return `export default ${JSON.stringify(browserCatalogs)};`;
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

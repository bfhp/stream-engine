/*
 * The browser translation catalogs (translationsPlugin in vite-build-data.ts).
 * In a build they are external and resolved by the page's import map; in dev
 * and tests the plugin serves them. Either way: the catalog of one locale,
 * English fallback merged in, plus that locale's code and the full locale list.
 */
declare module "stream-engine-translations" {
    const catalog: Record<string, string | string[] | Record<string, string>>;
    export default catalog;
    export const locale: string;
    export const locales: string[];
}

declare module "stream-engine-translations/admin" {
    const catalog: Record<string, string | string[] | Record<string, string>>;
    export default catalog;
    export const locale: string;
    export const locales: string[];
}

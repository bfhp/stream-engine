/**
 * Every locale's translation entries (site and admin scopes, without the
 * English merge), for the test suite to register on
 * `globalThis.__streamEngineTranslations`. Pages get theirs from the
 * per-locale files the server links instead - see translationsPlugin.
 */
declare module "virtual:translations" {
    const entries: {
        locale: string;
        locales: string[];
        messages: Record<string, string | string[] | Record<string, string>>;
    }[];
    export default entries;
}

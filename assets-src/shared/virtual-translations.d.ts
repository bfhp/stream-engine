declare module "virtual:translations" {
    /** `admin`: the `js.admin.*` keys; `site`: every other `js.*` key. */
    export type TranslationScope = "site" | "admin";
    export type BuildCatalog = Record<string, string | string[] | Record<string, string>>;

    /** Locale codes that have a language file, sorted. */
    export const locales: string[];
    /** One locale's keys of one scope, from its own chunk; `{}` for an unknown locale. */
    export function loadCatalog(locale: string, scope: TranslationScope): Promise<BuildCatalog>;
}

import { selectPluralForm, type PluralForms, type PluralNumberOptions } from "./plural";
import siteCatalog, { locale, locales } from "stream-engine-translations";

/** A message, or the plural forms of a noun (keyed by CLDR category, or a legacy list). */
export type TranslationValue = string | PluralForms;
export type TranslationParams = Record<string, string | number>;

export type TranslationCatalog = Record<string, TranslationValue>;

/*
 * The catalog is an ordinary static import. In a build
 * `stream-engine-translations` is external, and the page's import map
 * (Core\Assets::importMap()) points it at the file for the locale the server
 * picked, English fallback already merged in; in dev and tests the
 * translations plugin serves every locale and picks by `<html lang>`. So the
 * strings are there the moment this module runs. The admin panel adds its own
 * `js.admin.*` keys through admin/i18n.ts.
 */

/** Replaces every translator's catalog in a unit test - see setTranslationsForTests(). */
let testCatalog: TranslationCatalog | null = null;

function interpolate(text: string, params: TranslationParams): string {
    return text.replace(/\{([^{}]+)}/g, (placeholder, name: string) =>
        Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : placeholder
    );
}

export interface Translator {
    trans(key: string, params?: TranslationParams): string;
    /**
     * The form of a noun for `count` in the document locale, without the count.
     * A plain string value is a unit that does not inflect ("КБ") and is used as
     * is; a missing key comes back as the key.
     *
     * When the count is shown with fixed digits (`toFixed(1)`), pass the same
     * digits as `numberOptions`: the form depends on the digits shown, so 1 shown
     * as "1.0" takes "megabytes", not "megabyte".
     */
    transChoice(key: string, count: number, params?: TranslationParams, numberOptions?: PluralNumberOptions): string;
    /**
     * `transChoice()` with the count in front, formatted in the document locale
     * with `numberOptions`. The form is chosen for the number as formatted.
     */
    transChoiceWithCount(key: string, count: number, params?: TranslationParams, numberOptions?: PluralNumberOptions): string;
}

/** Translation functions over one catalog, in the document locale. */
export function createTranslator(catalog: TranslationCatalog): Translator {
    const lookup = (key: string): TranslationValue | undefined => (testCatalog ?? catalog)[key];

    const transChoice: Translator["transChoice"] = (key, count, params = {}, numberOptions = {}) => {
        const value = lookup(key);
        const text = typeof value === "string"
            ? value
            : value !== undefined
                ? selectPluralForm(value, count, locale, numberOptions) ?? key
                : key;

        return interpolate(text, { count, ...params });
    };

    return {
        trans(key, params = {}) {
            const value = lookup(key);

            return interpolate(typeof value === "string" ? value : key, params);
        },
        transChoice,
        transChoiceWithCount(key, count, params = {}, numberOptions = {}) {
            const formatted = new Intl.NumberFormat(locale, numberOptions).format(count);

            return `${formatted} ${transChoice(key, count, params, numberOptions)}`;
        },
    };
}

/** The site strings: every `js.*` key except the admin panel's. */
export const { trans, transChoice, transChoiceWithCount } = createTranslator(siteCatalog);

/** The CMS locale of the loaded catalog - the one the server picked for this document. */
export function getLocale(): string {
    return locale;
}

/** Locale codes that have a language file, sorted. */
export function getAvailableLocales(): string[] {
    return [...locales];
}

/** Replaces the catalog of every translator for an isolated unit test. */
export function setTranslationsForTests(messages: TranslationCatalog): void {
    testCatalog = messages;
}

export function resetTranslations(): void {
    testCatalog = null;
}

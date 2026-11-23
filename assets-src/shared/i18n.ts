import { selectPluralForm, type PluralForms, type PluralNumberOptions } from "./plural";
import catalogs from "virtual:translations";

/** A message, or the plural forms of a noun (keyed by CLDR category, or a legacy list). */
export type TranslationValue = string | PluralForms;
export type TranslationParams = Record<string, string | number>;

export type TranslationCatalog = Record<string, TranslationValue>;

function resolveLocale(available: Record<string, TranslationCatalog>, requestedLocale: string): string {
    const locale = requestedLocale.trim().toLowerCase().replace(/_/g, "-");
    const baseLocale = locale.split("-")[0];

    if (available[locale]) return locale;
    if (available[baseLocale]) return baseLocale;
    if (available.en) return "en";
    if (available.ru) return "ru";

    return Object.keys(available)[0] ?? locale;
}

/** Locale codes discovered from the build-time language catalogs. */
export function getAvailableLocales(): string[] {
    return Object.keys(catalogs).sort();
}

export function selectCatalog(
    available: Record<string, TranslationCatalog>,
    requestedLocale: string,
): TranslationCatalog {
    const fallback = available.en ?? {};
    const selected = available[resolveLocale(available, requestedLocale)] ?? {};

    return selected === fallback ? fallback : { ...fallback, ...selected };
}

const activeLocale = document.documentElement.lang || "en";
const resolvedLocale = resolveLocale(catalogs, activeLocale);
const builtInCatalog = selectCatalog(catalogs, activeLocale);
let catalog = builtInCatalog;

function interpolate(text: string, params: TranslationParams): string {
    return text.replace(/\{([^{}]+)}/g, (placeholder, name: string) =>
        Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : placeholder
    );
}

export function trans(key: string, params: TranslationParams = {}): string {
    const value = catalog[key];
    const text = typeof value === "string" ? value : key;

    return interpolate(text, params);
}

/**
 * The form of a noun for `count` in the document locale, without the count.
 * A plain string value is a unit that does not inflect ("КБ") and is used as
 * is; a missing key comes back as the key.
 *
 * When the count is shown with fixed digits (`toFixed(1)`), pass the same
 * digits as `numberOptions`: the form depends on the digits shown, so 1 shown
 * as "1.0" takes "megabytes", not "megabyte".
 */
export function transChoice(
    key: string,
    count: number,
    params: TranslationParams = {},
    numberOptions: PluralNumberOptions = {},
): string {
    const value = catalog[key];
    const text = typeof value === "string"
        ? value
        : value !== undefined
            ? selectPluralForm(value, count, resolvedLocale, numberOptions) ?? key
            : key;

    return interpolate(text, { count, ...params });
}

/**
 * `transChoice()` with the count in front, formatted in the document locale
 * with `numberOptions`. The form is chosen for the number as formatted.
 */
export function transChoiceWithCount(
    key: string,
    count: number,
    params: TranslationParams = {},
    numberOptions: PluralNumberOptions = {},
): string {
    const formatted = new Intl.NumberFormat(resolvedLocale, numberOptions).format(count);

    return `${formatted} ${transChoice(key, count, params, numberOptions)}`;
}

/** The normalized CMS locale selected by the server for this document. */
export function getLocale(): string {
    return resolvedLocale;
}

/** Replaces the build-time catalog for an isolated unit test. */
export function setTranslationsForTests(messages: TranslationCatalog): void {
    catalog = messages;
}

export function resetTranslations(): void {
    catalog = builtInCatalog;
}

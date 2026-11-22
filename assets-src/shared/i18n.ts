import { selectPluralForm, type PluralForms, type PluralNumberOptions } from "./plural";

/** A message, or the plural forms of a noun (keyed by CLDR category, or a legacy list). */
export type TranslationValue = string | PluralForms;
export type TranslationParams = Record<string, string | number>;

export type TranslationCatalog = Record<string, TranslationValue>;

/**
 * What a translation file registers. The server links `js/i18n/<locale>.js`
 * (and `<locale>-admin.js` in the admin panel) before the page's modules;
 * each pushes one entry here. `messages` already includes the English
 * fallback; `locales` lists every locale the build has.
 */
export interface TranslationEntry {
    locale: string;
    locales: string[];
    messages: TranslationCatalog;
}

declare global {
    var __streamEngineTranslations: TranslationEntry[] | undefined;
}

function registeredEntries(): TranslationEntry[] {
    return globalThis.__streamEngineTranslations ?? [];
}

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
    return [...new Set(registeredEntries().flatMap(entry => entry.locales))].sort();
}

export function selectCatalog(
    available: Record<string, TranslationCatalog>,
    requestedLocale: string,
): TranslationCatalog {
    const fallback = available.en ?? {};
    const selected = available[resolveLocale(available, requestedLocale)] ?? {};

    return selected === fallback ? fallback : { ...fallback, ...selected };
}

/**
 * The document locale and its catalog, worked out on first use rather than
 * when this module loads: by then every translation file linked before the
 * page's modules has registered, and a test may set `<html lang>` after
 * something has already imported this module.
 */
let loaded: { locale: string; catalog: TranslationCatalog } | null = null;
let testCatalog: TranslationCatalog | null = null;

function load(): { locale: string; catalog: TranslationCatalog } {
    if (loaded === null) {
        const available: Record<string, TranslationCatalog> = {};
        for (const entry of registeredEntries()) {
            available[entry.locale] = { ...available[entry.locale], ...entry.messages };
        }

        const requested = document.documentElement.lang || "en";
        loaded = {
            locale: resolveLocale(available, requested),
            catalog: selectCatalog(available, requested),
        };
    }

    return loaded;
}

function activeCatalog(): TranslationCatalog {
    return testCatalog ?? load().catalog;
}

function interpolate(text: string, params: TranslationParams): string {
    return text.replace(/\{([^{}]+)}/g, (placeholder, name: string) =>
        Object.prototype.hasOwnProperty.call(params, name) ? String(params[name]) : placeholder
    );
}

export function trans(key: string, params: TranslationParams = {}): string {
    const value = activeCatalog()[key];
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
    const value = activeCatalog()[key];
    const text = typeof value === "string"
        ? value
        : value !== undefined
            ? selectPluralForm(value, count, load().locale, numberOptions) ?? key
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
    const formatted = new Intl.NumberFormat(load().locale, numberOptions).format(count);

    return `${formatted} ${transChoice(key, count, params, numberOptions)}`;
}

/** The normalized CMS locale selected by the server for this document. */
export function getLocale(): string {
    return load().locale;
}

/** Replaces the build-time catalog for an isolated unit test. */
export function setTranslationsForTests(messages: TranslationCatalog): void {
    testCatalog = messages;
}

export function resetTranslations(): void {
    testCatalog = null;
}

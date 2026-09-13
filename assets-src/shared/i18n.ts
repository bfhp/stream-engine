import { plural } from "./plural";
import catalogs from "virtual:translations";

export type TranslationValue = string | string[];
export type TranslationParams = Record<string, string | number>;

export type TranslationCatalog = Record<string, TranslationValue>;

function resolveLocale(available: Record<string, TranslationCatalog>, requestedLocale: string): string {
    const locale = requestedLocale.toLowerCase();
    const baseLocale = locale.split("-")[0];

    if (available[locale]) return locale;
    if (available[baseLocale]) return baseLocale;
    if (available.en) return "en";
    if (available.ru) return "ru";

    return Object.keys(available)[0] ?? locale;
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

function selectForm(forms: string[], count: number): string {
    if (resolvedLocale.startsWith("ru")) {
        return plural(count, forms[0], forms[1], forms[2]);
    }

    const category = new Intl.PluralRules(resolvedLocale).select(count);
    if (forms.length === 2) return category === "one" ? forms[0] : forms[1];
    if (category === "one") return forms[0];
    if (category === "few") return forms[1];

    return forms[forms.length - 1];
}

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

export function transChoice(key: string, count: number, params: TranslationParams = {}): string {
    const value = catalog[key];
    const forms = Array.isArray(value) ? value : [];
    const text = forms.length >= 2
        ? selectForm(forms, count)
        : key;

    return interpolate(text, { count, ...params });
}

export function transChoiceWithCount(key: string, count: number, params: TranslationParams = {}): string {
    return `${count} ${transChoice(key, count, params)}`;
}

/** Replaces the build-time catalog for an isolated unit test. */
export function setTranslationsForTests(messages: TranslationCatalog): void {
    catalog = messages;
}

export function resetTranslations(): void {
    catalog = builtInCatalog;
}

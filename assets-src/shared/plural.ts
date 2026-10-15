/* ==========================================================================
   CLDR plural selection

   The browser half of `Core\Formatter::pluralCategory()` /
   `selectPluralForm()`. Both read CLDR through ICU - `Intl.PluralRules`
   here, intl's MessageFormatter on the server - so a count rendered on the
   first page load and re-rendered here after it gets the same form.
   `tests/Support/plural-rules.json` pins the two together: the PHP and the
   Vitest suites both assert against it.

   A catalog value is keyed by category (`{ one: "post", other: "posts" }`)
   or, for catalogs written before that, a positional list
   (`[one, few, many]`), mapped by `legacyPluralIndex()`.
   ========================================================================== */

export type PluralCategory = Intl.LDMLPluralRule;
export type PluralForms = string[] | Partial<Record<PluralCategory, string>>;

/** Languages whose `other` (fractions) takes the `few` noun. Legacy lists only. */
const LEGACY_OTHER_AS_FEW = ["be", "pl", "ru", "uk"];

const rulesCache = new Map<string, Intl.PluralRules>();

function rulesFor(locale: string): Intl.PluralRules {
    let rules = rulesCache.get(locale);
    if (!rules) {
        rules = new Intl.PluralRules(locale);
        rulesCache.set(locale, rules);
    }

    return rules;
}

/** The CLDR plural category of `n` in `locale`. */
export function pluralCategory(n: number, locale: string): PluralCategory {
    return rulesFor(locale).select(n);
}

/**
 * Where a category lives in a legacy positional list: `[one, other]` or
 * `[one, few, many]`, Arabic's being `[singular, plural (3-10), accusative
 * singular (11-99)]`. Mirrors `Core\Formatter::legacyPluralIndex()`.
 */
export function legacyPluralIndex(locale: string, category: PluralCategory, length: number): number {
    if (length <= 1 || category === "one") return 0;

    const language = locale.toLowerCase().split(/[-_]/)[0];
    let index: number;

    if (language === "ar") {
        // 100-102 and so on take the singular.
        index = category === "other" ? 0 : category === "many" ? 2 : 1;
    } else if (category === "two" || category === "few") {
        index = 1;
    } else if (category === "other" && LEGACY_OTHER_AS_FEW.includes(language)) {
        index = 1;
    } else {
        index = 2;
    }

    return Math.min(index, length - 1);
}

/**
 * Picks the form for `n`, or `undefined` when there is none. A keyed set
 * missing the category falls back to `other`.
 */
export function selectPluralForm(forms: PluralForms, n: number, locale: string): string | undefined {
    const category = pluralCategory(n, locale);

    if (Array.isArray(forms)) {
        return forms.length > 0 ? forms[legacyPluralIndex(locale, category, forms.length)] : undefined;
    }

    return forms[category] ?? forms.other;
}

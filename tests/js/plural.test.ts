import { describe, expect, it } from "vitest";

import { legacyPluralIndex, pluralCategory, selectPluralForm, type PluralCategory } from "../../assets-src/shared/plural";
import rulesFixture from "../Support/plural-rules.json";

/**
 * `tests/Support/plural-rules.json` is the contract between the two runtimes:
 * `tests/Core/PluralRulesTest.php` asserts PHP intl against it, this file
 * asserts the browser's `Intl.PluralRules`. A count is rendered server-side
 * on the first page load and here on every update after it, so if an ICU
 * upgrade on either side moves a rule, one of the suites goes red instead of
 * a number changing its grammar on refresh.
 */
type LocaleRules = { categories: PluralCategory[]; samples: Record<string, PluralCategory> };

const rules = Object.fromEntries(
    Object.entries(rulesFixture).filter(([key]) => !key.startsWith("_")),
) as unknown as Record<string, LocaleRules>;

describe("pluralCategory()", () => {
    for (const [locale, { categories, samples }] of Object.entries(rules)) {
        it(`matches the shared fixture for ${locale}`, () => {
            for (const [n, expected] of Object.entries(samples)) {
                expect(pluralCategory(Number(n), locale), `${locale}, n = ${n}`).toBe(expected);
            }
        });

        it(`uses the same categories as the server for ${locale}`, () => {
            const order: PluralCategory[] = ["zero", "one", "two", "few", "many", "other"];
            const actual = new Intl.PluralRules(locale).resolvedOptions().pluralCategories;

            expect(order.filter(category => actual.includes(category))).toEqual(categories);
        });
    }

    it("follows the digits shown, not the value", () => {
        const oneDecimal = { minimumFractionDigits: 1, maximumFractionDigits: 1 };

        expect(pluralCategory(1, "en", oneDecimal)).toBe("other");
        expect(pluralCategory(1, "ru", oneDecimal)).toBe("other");
        expect(pluralCategory(2, "ru", oneDecimal)).toBe("other");
        // French keeps 1,0 singular: its rule looks at the integer part only.
        expect(pluralCategory(1, "fr", oneDecimal)).toBe("one");
        // 1.04 rounds to "1.0", and the rule sees the rounded number.
        expect(pluralCategory(1.04, "en", oneDecimal)).toBe("other");
        expect(pluralCategory(1, "en")).toBe("one");
    });

    it("chooses the plural for English 21, which the old server rule got wrong", () => {
        expect(pluralCategory(21, "en")).toBe("other");
        expect(pluralCategory(1, "en")).toBe("one");
    });
});

describe("selectPluralForm()", () => {
    const russian = { one: "книга", few: "книги", many: "книг", other: "книги" };

    it("picks the keyed form for the category", () => {
        expect(selectPluralForm(russian, 1, "ru")).toBe("книга");
        expect(selectPluralForm(russian, 3, "ru")).toBe("книги");
        expect(selectPluralForm(russian, 11, "ru")).toBe("книг");
        expect(selectPluralForm(russian, 21, "ru")).toBe("книга");
        expect(selectPluralForm(russian, 1.5, "ru")).toBe("книги");
        expect(selectPluralForm(russian, -21, "ru")).toBe("книга");
    });

    it("uses all six Arabic categories", () => {
        const forms = { zero: "z", one: "o", two: "t", few: "f", many: "m", other: "x" };

        expect([0, 1, 2, 3, 11, 100, 103].map(n => selectPluralForm(forms, n, "ar")))
            .toEqual(["z", "o", "t", "f", "m", "x", "f"]);
    });

    it("falls back to other when the category is missing", () => {
        expect(selectPluralForm({ one: "o", other: "x" }, 5, "ru")).toBe("x");
        expect(selectPluralForm({ one: "o" }, 5, "ru")).toBeUndefined();
        expect(selectPluralForm([], 5, "ru")).toBeUndefined();
    });

    it("still reads legacy positional lists", () => {
        expect(selectPluralForm(["a", "b"], 21, "en")).toBe("b");
        expect(selectPluralForm(["a", "b", "c"], 21, "en")).toBe("c");
        expect(selectPluralForm(["a", "b", "c"], 21, "ru")).toBe("a");
        expect(selectPluralForm(["a", "b", "c"], 1.5, "ru")).toBe("b");
        expect(selectPluralForm(["a", "b", "c"], 100, "ar")).toBe("a");
        expect(selectPluralForm(["a"], 7, "ja")).toBe("a");
    });
});

/** The table `testLegacyIndexTable()` in PluralRulesTest.php asserts too. */
describe("legacyPluralIndex()", () => {
    const cases: [string, PluralCategory, number, number][] = [
        ["en", "one", 3, 0], ["en", "other", 3, 2], ["en", "other", 2, 1],
        ["ru", "few", 3, 1], ["ru", "many", 3, 2], ["ru", "other", 3, 1],
        ["pl", "other", 3, 1], ["fr", "many", 3, 2], ["fr", "many", 2, 1],
        ["ar", "zero", 3, 1], ["ar", "two", 3, 1], ["ar", "few", 3, 1],
        ["ar", "many", 3, 2], ["ar", "other", 3, 0], ["ar", "many", 2, 1],
        ["pt-br", "other", 3, 2], ["ja", "other", 1, 0],
    ];

    it("maps each category to the same position as the server", () => {
        for (const [locale, category, length, index] of cases) {
            expect(legacyPluralIndex(locale, category, length), `${locale} ${category} of ${length}`).toBe(index);
        }
    });
});

import { describe, expect, it } from "vitest";

import { plural, pluralWithCount } from "../../assets-src/shared/plural";

/**
 * The rule the front end had four implementations of, written three different
 * ways: `profile.ts`'s `plural()`, `blog-post-form.ts`'s and `forum-poll.ts`'s
 * `pluralize()`, and `messages.ts`'s `pluralRu()`.
 *
 * They agreed on every non-negative number and disagreed on negatives - the
 * three that skipped `Math.abs` fell through to the many-form for -1, because
 * `-1 % 10` is `-1` in JavaScript, not `9`. No live bug, since every counter
 * on the site counts things, but it is the divergence that would surface the
 * first time somebody rendered a delta.
 *
 * The other reason this is one function now: the same counts are rendered
 * server-side by `Core\Formatter::plural()` on the first page load and
 * client-side on every update after it, so the two have to agree. The table
 * below is the one the PHP formatting tests assert on the
 * PHP side.
 */
const FORMS: [string, string, string] = ["книга", "книги", "книг"];

const form = (n: number) => plural(n, ...FORMS);

describe("plural()", () => {
    it("uses the singular for one", () => {
        expect(form(1)).toBe("книга");
    });

    it("uses the few-form for two to four", () => {
        expect(form(2)).toBe("книги");
        expect(form(3)).toBe("книги");
        expect(form(4)).toBe("книги");
    });

    it("uses the many-form for five upwards and for zero", () => {
        expect(form(0)).toBe("книг");
        expect(form(5)).toBe("книг");
        expect(form(9)).toBe("книг");
        expect(form(10)).toBe("книг");
    });

    /**
     * The teens are the whole reason the function exists: 11-14 take the
     * many-form despite ending in 1-4, and they do so at every hundred.
     */
    it("treats the teens as an exception", () => {
        expect(form(11)).toBe("книг");
        expect(form(12)).toBe("книг");
        expect(form(13)).toBe("книг");
        expect(form(14)).toBe("книг");
        expect(form(15)).toBe("книг");
        expect(form(19)).toBe("книг");
    });

    it("applies the exception at every hundred, and only there", () => {
        expect(form(111)).toBe("книг");
        expect(form(114)).toBe("книг");
        expect(form(1011)).toBe("книг");

        // 101 and 102 are past the teens again.
        expect(form(101)).toBe("книга");
        expect(form(102)).toBe("книги");
        expect(form(121)).toBe("книга");
    });

    it("looks only at the last two digits", () => {
        expect(form(21)).toBe("книга");
        expect(form(1_000_001)).toBe("книга");
        expect(form(22)).toBe("книги");
        expect(form(25)).toBe("книг");
    });

    /**
     * The divergence that motivated the consolidation. `Math.abs` first, so a
     * negative reads like its magnitude - matching `Core\Formatter::plural()`,
     * which has always had it.
     */
    it("handles negatives like their magnitude", () => {
        expect(form(-1)).toBe("книга");
        expect(form(-2)).toBe("книги");
        expect(form(-5)).toBe("книг");
        expect(form(-11)).toBe("книг");
        expect(form(-21)).toBe("книга");
    });

    /**
     * The table asserted on the PHP side too. If either implementation moves,
     * one of the two test suites goes red rather than a count quietly
     * disagreeing with itself between the server render and the first update.
     */
    it("agrees with Core\\Formatter::plural() across the interesting range", () => {
        const expected: Record<number, string> = {
            0: "книг", 1: "книга", 2: "книги", 4: "книги", 5: "книг",
            10: "книг", 11: "книг", 12: "книг", 14: "книг", 15: "книг",
            20: "книг", 21: "книга", 22: "книги", 25: "книг",
            100: "книг", 101: "книга", 102: "книги", 111: "книг", 114: "книг",
            1000: "книг", 1001: "книга", 1002: "книги",
        };

        for (const [n, want] of Object.entries(expected)) {
            expect(form(Number(n)), `n = ${n}`).toBe(want);
        }
    });
});

describe("pluralWithCount()", () => {
    it("puts the number in front", () => {
        expect(pluralWithCount(1, ...FORMS)).toBe("1 книга");
        expect(pluralWithCount(5, ...FORMS)).toBe("5 книг");
        expect(pluralWithCount(0, ...FORMS)).toBe("0 книг");
    });

    it("renders the number as given, without grouping", () => {
        // The places that want thousands separated do their own
        // number_format-style work first; this one must not surprise them.
        expect(pluralWithCount(1234, ...FORMS)).toBe("1234 книги");
    });

    it("keeps the sign", () => {
        expect(pluralWithCount(-1, ...FORMS)).toBe("-1 книга");
    });
});

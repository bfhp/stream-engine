import { afterEach, beforeEach, describe, expect, it } from "vitest";
import {
    createTranslator,
    getAvailableLocales,
    getLocale,
    resetTranslations,
    setTranslationsForTests,
    trans,
    transChoice,
    transChoiceWithCount,
} from "../../assets-src/shared/i18n";

beforeEach(() => {
    setTranslationsForTests({
        "js.greeting": "Hello, {name}!",
        "js.book": ["book", "books", "books"],
        "js.post": { one: "post", other: "posts" },
        "js.kb": "KB",
    });
});

afterEach(resetTranslations);

describe("browser translations", () => {
    it("reads the catalog the translations module served for the document locale", () => {
        resetTranslations();

        // jsdom's <html> has no lang: English, from the real catalogs.
        expect(getLocale()).toBe("en");
        expect(getAvailableLocales()).toContain("ru");
        expect(trans("js.common.load_more")).not.toBe("js.common.load_more");
    });

    it("builds translators over other catalogs, which a test catalog replaces too", () => {
        const admin = createTranslator({ "js.admin.title": "Admin" });

        // beforeEach's test catalog stands in for every translator's own.
        expect(admin.trans("js.greeting", { name: "Ada" })).toBe("Hello, Ada!");
        expect(admin.trans("js.admin.title")).toBe("js.admin.title");

        resetTranslations();
        expect(admin.trans("js.admin.title")).toBe("Admin");
    });

    it("reads and interpolates the build-time catalog", () => {
        expect(trans("js.greeting", { name: "Ada" })).toBe("Hello, Ada!");
    });

    it("keeps unknown keys and placeholders visible", () => {
        expect(trans("js.missing", { value: 1 })).toBe("js.missing");
        expect(trans("js.greeting")).toBe("Hello, {name}!");
    });

    it("selects numeric forms and can include the count", () => {
        expect(transChoice("js.book", 1)).toBe("book");
        expect(transChoice("js.book", 2)).toBe("books");
        expect(transChoiceWithCount("js.book", 5)).toBe("5 books");
    });

    it("selects CLDR-keyed forms", () => {
        expect(transChoice("js.post", 1)).toBe("post");
        expect(transChoice("js.post", 21)).toBe("posts");
        expect(transChoiceWithCount("js.post", 0)).toBe("0 posts");
    });

    it("chooses the form for the number as displayed", () => {
        const oneDecimal = { minimumFractionDigits: 1, maximumFractionDigits: 1 };

        expect(transChoice("js.post", 1, {}, oneDecimal)).toBe("posts");
        expect(transChoiceWithCount("js.post", 1, {}, oneDecimal)).toBe("1.0 posts");
        expect(transChoiceWithCount("js.post", 1)).toBe("1 post");
    });

    it("returns a non-inflecting unit as is and a missing key as the key", () => {
        expect(transChoice("js.kb", 5)).toBe("KB");
        expect(transChoice("js.missing", 5)).toBe("js.missing");
    });
});

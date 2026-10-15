import { afterEach, beforeEach, describe, expect, it } from "vitest";
import {
    resetTranslations,
    selectCatalog,
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
    it("selects exact and base locales before falling back to English", () => {
        const catalogs = {
            en: { "js.label": "English", "js.fallback": "Fallback" },
            es: { "js.label": "Spanish" },
        };

        expect(selectCatalog(catalogs, "es-ES")["js.label"]).toBe("Spanish");
        expect(selectCatalog(catalogs, "ES_mx")["js.label"]).toBe("Spanish");
        expect(selectCatalog(catalogs, "es-ES")["js.fallback"]).toBe("Fallback");
        expect(selectCatalog(catalogs, "de")["js.label"]).toBe("English");
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

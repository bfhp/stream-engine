import { describe, expect, it } from "vitest";

import { getApiErrorMessage } from "../../assets-src/shared/api-errors";

const FALLBACK = "Что-то пошло не так";

/**
 * The precedence chain every catch block in the front end depends on. Getting
 * the order or the depth wrong doesn't break anything visibly - it just replaces
 * the server's message with a generic one, which is how auth.ts's logout path
 * went on showing "Ошибка выхода" for every failure while login, reading the
 * same payload one level shallower, showed the real reason.
 */
describe("getApiErrorMessage()", () => {
    it("takes a bare string", () => {
        expect(getApiErrorMessage("Слишком длинное имя", FALLBACK)).toBe("Слишком длинное имя");
    });

    it("takes error.error, the shape the API actually sends", () => {
        // StreamEngine::handleRequest()'s API branch answers a
        // ValidationException with exactly this.
        expect(getApiErrorMessage({ error: "Ник занят" }, FALLBACK)).toBe("Ник занят");
    });

    it("looks one level deeper when error.error is an object", () => {
        expect(getApiErrorMessage({ error: { message: "Вложенное" } }, FALLBACK)).toBe("Вложенное");
    });

    it("falls back to error.message", () => {
        expect(getApiErrorMessage({ message: "На верхнем уровне" }, FALLBACK)).toBe("На верхнем уровне");
    });

    it("reads a real Error's message", () => {
        expect(getApiErrorMessage(new Error("Invalid JSON response"), FALLBACK))
            .toBe("Invalid JSON response");
    });

    it("prefers the shallower level when both are present", () => {
        expect(getApiErrorMessage(
            { error: "Ближе", message: "Дальше" },
            FALLBACK
        )).toBe("Ближе");
    });

    describe("falls back rather than showing something useless", () => {
        // api() throws the parsed payload, which is null when a failing response
        // carried no JSON body at all - the case that made login's unguarded
        // `error.error` a TypeError waiting to happen.
        it.each([
            ["null", null],
            ["undefined", undefined],
            ["an empty object", {}],
            ["a number", 0],
            ["an array", []],
            ["a blank string", "   "],
            ["a blank error", { error: "  " }],
            ["a blank nested message", { error: { message: "\t" } }],
            ["an error object with no message", { error: {} }],
        ])("%s", (_label, value) => {
            expect(getApiErrorMessage(value, FALLBACK)).toBe(FALLBACK);
        });
    });

    it("skips a blank level instead of stopping at it", () => {
        // A whitespace-only error.error must not shadow a usable error.message.
        expect(getApiErrorMessage({ error: "  ", message: "Годное" }, FALLBACK)).toBe("Годное");
    });
});

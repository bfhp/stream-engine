import { describe, expect, it, vi } from "vitest";
import { collectModulePages, type AdminPage } from "../../assets-src/admin/module-pages";

const page = (path: string): AdminPage => ({ path, label: "Example", load: vi.fn() });

describe("admin module pages", () => {
    it("has no external routes when no modules are present", () => {
        expect(collectModulePages({})).toEqual([]);
    });
    it("collects pages in deterministic module order without loading their components", () => {
        const first = page("/first");
        const second = page("/second");
        expect(collectModulePages({ z: { default: [second] }, a: { default: [first] } })).toEqual([first, second]);
        expect(first.load).not.toHaveBeenCalled();
    });
    it("rejects duplicate and built-in section routes", () => {
        expect(() => collectModulePages({ a: { default: [page("/sync"), page("/sync")] } })).toThrow(/Duplicate/);
        for (const path of ["/feeds", "/feeds/new", "/pages", "/settings", "/widgets", "/users"]) {
            expect(() => collectModulePages({ a: { default: [page(path)] } })).toThrow(/reserved/);
        }
    });
    it("rejects invalid paths and malformed exports", () => {
        for (const path of ["/", "relative", "/sync/", "/:id", "/SYNC", "/*"]) {
            expect(() => collectModulePages({ a: { default: [page(path)] } })).toThrow(/Invalid/);
        }
        expect(() => collectModulePages({ a: { default: null } })).toThrow(/page list/);
        expect(() => collectModulePages({ a: { default: [{ ...page("/sync"), label: "" }] } })).toThrow(/Invalid/);
    });
});

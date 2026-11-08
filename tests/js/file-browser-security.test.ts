import { act, createElement } from "react";
import { createRoot } from "react-dom/client";
import { MantineProvider } from "@mantine/core";
import deepmerge from "deepmerge";
import { afterEach, describe, expect, it, vi } from "vitest";
import FileBrowser from "../../assets-src/admin/pages/FileBrowser";
import { toBrowserFile } from "../../assets-src/admin/lib/uploads-browser";

const { mergeInputs } = vi.hoisted(() => ({ mergeInputs: [] as unknown[][] }));

// Execute the installed implementation: observing the boundary must not replace
// either Chonky's theme merging or the vulnerable deepmerge behavior.
vi.mock("deepmerge", async importOriginal => {
    const original = await importOriginal<{ default: typeof import("deepmerge") }>();
    const merge = original.default;
    return { default: Object.assign((...args: Parameters<typeof merge>) => {
        mergeInputs.push(args);
        return merge(...args);
    }, { all: merge.all }) };
});

afterEach(() => {
    vi.unstubAllGlobals();
    mergeInputs.length = 0;
});

describe("Chonky advisory trust boundary", () => {
    it("reproduces poisoning of the returned object with the installed deepmerge", () => {
        const target = JSON.parse('{"__proto__":{"advisoryMarker":"poisoned"}}');
        const result = deepmerge<Record<string, unknown>>(target, {});
        expect(result.advisoryMarker).toBe("poisoned");
        expect(Object.prototype.hasOwnProperty.call(result, "advisoryMarker")).toBe(false);
        expect((Object.prototype as Record<string, unknown>).advisoryMarker).toBeUndefined();
        expect(mergeInputs).toHaveLength(1);
    });

    it("drops prototype and theme properties when mapping API entries", () => {
        const entry = JSON.parse('{"id":"__proto__","path":"__proto__","name":"__proto__","isDir":true,"__proto__":{"advisoryMarker":"poisoned"},"constructor":{"prototype":{"advisoryMarker":"poisoned"}},"theme":{"advisoryMarker":"poisoned"},"muiThemeOptions":{"advisoryMarker":"poisoned"}}');
        const file = toBrowserFile(entry);
        expect(Object.getPrototypeOf(file)).toBe(Object.prototype);
        for (const key of ["__proto__", "constructor", "theme", "muiThemeOptions", "advisoryMarker"]) {
            expect(Object.prototype.hasOwnProperty.call(file, key)).toBe(false);
        }
        expect(file.name).toBe("__proto__");
    });

    it.each(["light", "dark"] as const)("keeps malicious API properties out of theme merges (%s)", async scheme => {
        vi.stubGlobal("IS_REACT_ACT_ENVIRONMENT", true);
        vi.stubGlobal("ResizeObserver", class {
            observe() {}
            unobserve() {}
            disconnect() {}
        });
        vi.stubGlobal("matchMedia", (query: string) => ({
            matches: false, media: query, addListener() {}, removeListener() {},
            addEventListener() {}, removeEventListener() {},
        }));
        const poison = JSON.parse('{"__proto__":{"advisoryMarker":"untrusted-theme-marker"}}');
        const entry = {
            id: "sentinel.pdf", path: "sentinel.pdf", name: "sentinel.pdf",
            isDir: false, ...poison, theme: poison, muiThemeOptions: poison,
        };
        const payload = {
            path: "", files: [entry], folderChain: [{
                ...entry, id: "__uploads_root__", path: "", name: "uploads", isDir: true,
            }], ...poison, theme: poison, muiThemeOptions: poison,
        };
        const fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => payload });
        vi.stubGlobal("fetch", fetchMock);
        const container = document.createElement("div");
        document.body.append(container);
        const root = createRoot(container);
        try {
            await act(async () => {
                root.render(createElement(MantineProvider, { forceColorScheme: scheme }, createElement(FileBrowser)));
            });
            expect(fetchMock).toHaveBeenCalledWith("/api/v1/admin/file-browser", expect.anything());
            // Chonky's real toolbar/breadcrumbs mounted and the API completed.
            expect(container.textContent).toContain("uploads");
            expect(mergeInputs.length).toBeGreaterThan(0);
            for (const inputs of mergeInputs) {
                const serialized = JSON.stringify(inputs);
                expect(serialized).not.toContain("untrusted-theme-marker");
                expect(serialized).not.toContain("sentinel.pdf");
            }
            expect(container.textContent).not.toContain("untrusted-theme-marker");
            expect((Object.prototype as Record<string, unknown>).advisoryMarker).toBeUndefined();
        } finally {
            await act(async () => root.unmount());
            container.remove();
        }
    });
});

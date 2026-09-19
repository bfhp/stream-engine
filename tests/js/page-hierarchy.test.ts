import { describe, expect, it } from "vitest";
import { forbiddenPageParentIds } from "../../assets-src/admin/lib/page-hierarchy";

describe("page hierarchy", () => {
    it("forbids the current page and all of its descendants as parents", () => {
        const pages = [
            { id: 1, parentId: null },
            { id: 2, parentId: 1 },
            { id: 3, parentId: 2 },
            { id: 4, parentId: 1 },
            { id: 5, parentId: null }
        ];

        expect([...forbiddenPageParentIds(pages, 1)].sort()).toEqual([1, 2, 3, 4]);
        expect([...forbiddenPageParentIds(pages, 2)].sort()).toEqual([2, 3]);
        expect([...forbiddenPageParentIds(pages, undefined)]).toEqual([]);
    });

    it("terminates even when the stored hierarchy is already cyclic", () => {
        const forbidden = forbiddenPageParentIds([
            { id: 1, parentId: 2 },
            { id: 2, parentId: 1 }
        ], 1);

        expect([...forbidden].sort()).toEqual([1, 2]);
    });
});

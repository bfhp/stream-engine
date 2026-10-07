import { describe, expect, it } from "vitest";
import {
    parentUploadPath,
    publicUploadUrl,
    toBrowserFile,
} from "../../assets-src/admin/lib/uploads-browser";

describe("admin uploads browser helpers", () => {
    it("encodes each public URL path segment without losing directories", () => {
        expect(publicUploadUrl("2/photo one.webp")).toBe("/uploads/2/photo%20one.webp");
    });

    it("moves to the parent without traversing above the root", () => {
        expect(parentUploadPath("2/nested")).toBe("2");
        expect(parentUploadPath("2")).toBe("");
        expect(parentUploadPath("")).toBe("");
    });

    it("maps server entries into read-only Chonky files with image previews", () => {
        const file = toBrowserFile({
            id: "2/photo.webp",
            path: "2/photo.webp",
            name: "photo.webp",
            isDir: false,
            size: 42,
            modifiedAt: 1_700_000_000,
            extension: "webp",
        });

        expect(file.publicUrl).toBe("/uploads/2/photo.webp");
        expect(file.thumbnailUrl).toBe(file.publicUrl);
        expect(file.modDate).toEqual(new Date(1_700_000_000_000));
        expect(file.draggable).toBe(false);
        expect(file.droppable).toBe(false);
    });
});

import { describe, expect, it } from "vitest";
import {
    hasMetadataErrors,
    metadataToRows,
    newMetadataRow,
    rowsToMetadata,
    validateMetadataRows
} from "../../assets-src/admin/lib/feed-metadata";

describe("feed metadata editor helpers", () => {
    it("treats the empty PHP array and non-objects as no metadata", () => {
        expect(metadataToRows([])).toEqual([]);
        expect(metadataToRows(null)).toEqual([]);
        expect(metadataToRows("x")).toEqual([]);
    });

    it("turns the API map into sorted rows with unique keys", () => {
        const rows = metadataToRows({ zeta: "1", alpha: "two" });
        expect(rows.map(r => [r.name, r.content])).toEqual([["alpha", "two"], ["zeta", "1"]]);
        expect(rows[0].key).not.toBe(rows[1].key);
    });

    it("builds the payload, normalizing names and dropping blank ones", () => {
        const rows = [
            newMetadataRow(" Membership_Type ", "open"),
            newMetadataRow("", "orphan"),
            newMetadataRow("empty", "")
        ];
        expect(rowsToMetadata(rows)).toEqual({ membership_type: "open", empty: "" });
    });

    it("flags invalid and duplicate names", () => {
        const ok = newMetadataRow("a.b-c_1", "x");
        const bad = newMetadataRow("_bad", "x");
        const dup = newMetadataRow("A.B-C_1", "y");
        const long = newMetadataRow("a".repeat(65), "x");
        const blank = newMetadataRow("", "x");

        const errors = validateMetadataRows([ok, bad, dup, long, blank]);
        expect(errors[ok.key]).toBeNull();
        expect(errors[bad.key]).toBe("invalid_name");
        expect(errors[dup.key]).toBe("duplicate_name");
        expect(errors[long.key]).toBe("invalid_name");
        expect(errors[blank.key]).toBeNull();
        expect(hasMetadataErrors(errors)).toBe(true);
        expect(hasMetadataErrors(validateMetadataRows([ok]))).toBe(false);
    });
});

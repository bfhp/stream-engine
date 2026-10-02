import { describe, expect, it } from "vitest";
import { dashboardGridSpan, moveLayoutItem, orderedLayout } from "../../assets-src/admin/lib/dashboard";

describe("admin dashboard layout helpers", () => {
    it("orders cards deterministically and normalizes positions", () => {
        expect(orderedLayout([
            { id: "b.card", size: "small", position: 30 },
            { id: "a.card", size: "wide", position: 10 }
        ])).toEqual([
            { id: "a.card", size: "wide", position: 0 },
            { id: "b.card", size: "small", position: 10 }
        ]);
    });

    it("moves a card without losing its size", () => {
        expect(moveLayoutItem([
            { id: "a.card", size: "small", position: 0 },
            { id: "b.card", size: "wide", position: 10 }
        ], "b.card", -1)).toEqual([
            { id: "b.card", size: "wide", position: 0 },
            { id: "a.card", size: "small", position: 10 }
        ]);
    });

    it("maps supported sizes onto the twelve-column grid", () => {
        expect(dashboardGridSpan("small")).toBe(4);
        expect(dashboardGridSpan("medium")).toBe(6);
        expect(dashboardGridSpan("wide")).toBe(12);
    });
});

import { createElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { MantineProvider } from "@mantine/core";
import { describe, expect, it } from "vitest";
import DashboardCardContent, {
    type CardDefinition, type CardResult
} from "../../assets-src/admin/components/DashboardCardContent";

function definition(kind: CardDefinition["kind"]): CardDefinition {
    return {
        id: `test.${kind}`,
        label: "Test card",
        kind,
        sizes: ["small"],
        defaultSize: "small",
        defaultPosition: 10,
        module: "Test"
    };
}

function render(kind: CardDefinition["kind"], result?: CardResult, loading = false): string {
    return renderToStaticMarkup(createElement(
        MantineProvider,
        null,
        createElement(DashboardCardContent, { definition: definition(kind), result, loading })
    ));
}

describe("DashboardCardContent", () => {
    it("renders an explicit loading state", () => {
        expect(render("metrics", undefined, true)).toContain('data-card-state="loading"');
    });

    it.each([
        ["empty", "No data yet."],
        ["unavailable", "This card is currently unavailable."],
        ["error", "This card could not be loaded."]
    ] as const)("renders the %s state", (status, message) => {
        const html = render("metrics", { id: "test.metrics", status, data: null });

        expect(html).toContain(`data-card-state="${status}"`);
        expect(html).toContain(message);
    });

    it("renders ready metrics including navigation", () => {
        const html = render("metrics", {
            id: "test.metrics",
            status: "ready",
            data: [{ label: "Active", value: 12, href: "#/users" }]
        });

        expect(html).toContain('data-card-kind="metrics"');
        expect(html).toContain('href="#/users"');
        expect(html).toContain("Active");
        expect(html).toContain("12");
    });

    it("renders ready shortcut links", () => {
        const html = render("links", {
            id: "test.links",
            status: "ready",
            data: [{ label: "Settings", href: "#/settings" }]
        });

        expect(html).toContain('data-card-kind="links"');
        expect(html).toContain('href="#/settings"');
        expect(html).toContain("Settings");
    });

    it("renders a ready activity list", () => {
        const html = render("list", {
            id: "test.list",
            status: "ready",
            data: [{ id: "item-1", label: "New post", description: "Article", href: "#/feeds/1" }]
        });

        expect(html).toContain('data-card-kind="list"');
        expect(html).toContain("New post");
        expect(html).toContain("Article");
        expect(html).toContain('href="#/feeds/1"');
    });
});

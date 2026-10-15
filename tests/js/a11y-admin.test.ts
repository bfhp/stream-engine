import { createElement, type ReactElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { MantineProvider } from "@mantine/core";
import { MemoryRouter } from "react-router-dom";
import { afterEach, describe, expect, it } from "vitest";
import DashboardCardContent, {
    type CardDefinition, type CardResult
} from "../../assets-src/admin/components/DashboardCardContent";
import AdminLayout from "../../assets-src/admin/layout/AdminLayout";
import { axeReport, mount } from "./support/axe";

/*
 * Component-level WCAG A/AA checks with axe-core. These run on server-rendered
 * markup in jsdom: they catch structural problems (missing names, labels,
 * invalid ARIA, duplicate ids) quickly, but not contrast, focus behaviour or
 * anything that needs layout. The Playwright scan in tests/a11y covers whole
 * pages in a real browser.
 */

function renderInProvider(element: ReactElement): string {
    return renderToStaticMarkup(createElement(MantineProvider, null, element));
}

function card(kind: CardDefinition["kind"]): CardDefinition {
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

afterEach(() => {
    document.body.innerHTML = "";
});

describe("accessibility: DashboardCardContent", () => {
    it.each([
        ["loading", undefined, true],
        ["empty", { id: "test.metrics", status: "empty", data: null }, false],
        ["unavailable", { id: "test.metrics", status: "unavailable", data: null }, false],
        ["error", { id: "test.metrics", status: "error", data: null }, false],
    ] as const)("has no violations in the %s state", async (_state, result, loading) => {
        const root = mount(renderInProvider(createElement(DashboardCardContent, {
            definition: card("metrics"),
            result: result as CardResult | undefined,
            loading,
        })));

        expect(await axeReport(root)).toBe("");
    });

    it.each([
        ["metrics", [
            { label: "Active", value: 12, href: "#/users" },
            { label: "Pending", value: 3 },
        ]],
        ["links", [{ label: "Settings", href: "#/settings" }]],
        ["list", [
            { id: "item-1", label: "New post", description: "Article", href: "#/feeds/1" },
            { id: "item-2", label: "Draft", timestamp: 1_700_000_000 },
        ]],
    ] as const)("has no violations for ready %s cards", async (kind, data) => {
        const root = mount(renderInProvider(createElement(DashboardCardContent, {
            definition: card(kind),
            result: { id: `test.${kind}`, status: "ready", data },
        })));

        expect(await axeReport(root)).toBe("");
    });
});

describe("accessibility: AdminLayout", () => {
    function renderLayout(): HTMLElement {
        return mount(renderInProvider(createElement(
            MemoryRouter,
            null,
            createElement(AdminLayout, { moduleAdminPages: [] })
        )));
    }

    it("has no violations in the application shell", async () => {
        expect(await axeReport(renderLayout())).toBe("");
    });

    it("starts with a skip link to a focusable main region", () => {
        const root = renderLayout();
        const first = root.querySelector("a[href], button");
        const main = root.querySelector("main");

        expect(first?.textContent?.trim()).toBe("Skip to main content");
        expect(first?.getAttribute("href")).toBe("#admin-main");
        expect(main?.id).toBe("admin-main");
        expect(main?.getAttribute("tabindex")).toBe("-1");
    });

    it("names the navigation toggle and exposes its state", () => {
        const toggle = renderLayout().querySelector("header button");

        expect(toggle?.getAttribute("aria-label")).toBe("Toggle navigation");
        expect(toggle?.getAttribute("aria-expanded")).toBe("true");
    });
});

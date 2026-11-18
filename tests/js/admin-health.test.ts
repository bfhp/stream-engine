import { act, createElement } from "react";
import { createRoot } from "react-dom/client";
import { MantineProvider } from "@mantine/core";
import { MemoryRouter } from "react-router-dom";
import { afterEach, expect, it, vi } from "vitest";
import Health, { type HealthResult } from "../../assets-src/admin/pages/Health";
import { resetTranslations, setTranslationsForTests } from "../../assets-src/shared/i18n";

afterEach(() => {
    vi.unstubAllGlobals();
    resetTranslations();
    document.cookie = "csrfToken=; Max-Age=0; path=/";
});

it("shows one result column with the newest scheduled or manual result", async () => {
    vi.stubGlobal("IS_REACT_ACT_ENVIRONMENT", true);
    vi.stubGlobal("ResizeObserver", class { observe() {} unobserve() {} disconnect() {} });
    vi.stubGlobal("matchMedia", (query: string) => ({
        matches: false, media: query, addListener() {}, removeListener() {},
        addEventListener() {}, removeEventListener() {},
    }));
    setTranslationsForTests({
        "js.admin.health.run_check": "Run {check}",
        "js.admin.health.stale": "Outdated",
        "js.admin.health.outdated": "Check the scheduler",
        "js.admin.refresh": "Refresh",
    });
    const cron: HealthResult = {
        status: "error", message: "Scheduler cannot connect", stale: true,
        checkedAt: 1_700_000_000, durationMs: 5000, lastSucceededAt: null, consecutiveFailures: 2,
    };
    const web: HealthResult = { ...cron, checkedAt: cron.checkedAt + 1, status: "ok", message: "Web connection works", stale: false };
    const newerCron: HealthResult = { ...web, checkedAt: web.checkedAt + 1, message: "Scheduler connection works" };
    const payload = (manual: HealthResult | null, scheduled = cron) => ({ interval: 300, data: [
        { id: "smtp", label: "SMTP", cron: scheduled, web: manual },
        { id: "cache", label: "Memcached", cron: null, web: null },
    ], extensions: [{ class: "MySite\\Extensions\\LegacyAuth", points: ["verifyPasswordFallback"] }] });
    const fetchMock = vi.fn()
        .mockResolvedValueOnce({ ok: true, json: async () => payload(null) })
        .mockResolvedValueOnce({ ok: true, json: async () => payload(web) })
        .mockResolvedValueOnce({ ok: true, json: async () => payload(web, newerCron) });
    vi.stubGlobal("fetch", fetchMock);
    document.cookie = "csrfToken=health-token; path=/";
    const container = document.createElement("div");
    document.body.append(container);
    const root = createRoot(container);
    try {
        await act(async () => root.render(createElement(MantineProvider, {},
            createElement(MemoryRouter, {}, createElement(Health)))));
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe("/api/v1/admin/health");
        expect(container.textContent).toContain("Check the scheduler");
        expect(container.querySelector("table thead tr")?.children).toHaveLength(3);
        expect(container.textContent).toContain("Scheduler cannot connect");
        expect(container.textContent).toContain("MySite\\Extensions\\LegacyAuth");
        expect(container.textContent).toContain("verifyPasswordFallback");
        const button = container.querySelector<HTMLButtonElement>('button[aria-label="Run SMTP"]');
        expect(button).not.toBeNull();
        await act(async () => button!.click());
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(fetchMock.mock.calls[1]).toEqual([
            "/api/v1/admin/health/smtp/run",
            { method: "POST", headers: { "X-CSRF-Token": "health-token" } },
        ]);
        expect(container.textContent).not.toContain("Scheduler cannot connect");
        expect(container.textContent).toContain("Web connection works");
        const refresh = Array.from(container.querySelectorAll("button")).find(button => button.textContent === "Refresh");
        await act(async () => refresh!.click());
        expect(container.textContent).toContain("Scheduler connection works");
        expect(container.textContent).not.toContain("Web connection works");
    } finally {
        await act(async () => root.unmount());
        container.remove();
    }
});

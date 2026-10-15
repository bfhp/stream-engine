import AxeBuilder from "@axe-core/playwright";
import { expect, type Page, type TestInfo } from "@playwright/test";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { BASELINE_FILE } from "./paths";
import { WCAG_TAGS, formatViolations } from "./format";

type Baseline = {
    "//"?: string[];
    pages: Record<string, string[]>;
};

/**
 * With A11Y_UPDATE_BASELINE=1 the scan records what it finds instead of
 * failing. Recording only ever adds rules (it is run once per locale and
 * colour scheme, and each run sees a different subset), so removing a fixed
 * rule from the baseline is a deliberate edit.
 */
const updating = process.env.A11Y_UPDATE_BASELINE === "1";

function readBaseline(): Baseline {
    if (!existsSync(BASELINE_FILE)) return { pages: {} };

    const parsed = JSON.parse(readFileSync(BASELINE_FILE, "utf8")) as Partial<Baseline>;

    return { ...parsed, pages: parsed.pages ?? {} };
}

function recordBaseline(pageId: string, ruleIds: string[]): void {
    const baseline = readBaseline();
    const merged = new Set([...(baseline.pages[pageId] ?? []), ...ruleIds]);
    if (merged.size === 0) return;

    baseline.pages[pageId] = [...merged].sort();
    baseline.pages = Object.fromEntries(Object.entries(baseline.pages).sort(([a], [b]) => a.localeCompare(b)));
    writeFileSync(BASELINE_FILE, JSON.stringify(baseline, null, 4) + "\n");
}

/**
 * Scans the current page for WCAG 2.2 A/AA violations and fails on any rule
 * that is not listed for `pageId` in baseline.json.
 *
 * The baseline is keyed by page and axe rule id, not by element, so a known
 * problem does not hide a second instance of the same rule on that page;
 * that is the trade-off for a baseline that survives markup changes.
 */
export async function expectNoNewViolations(
    page: Page,
    pageId: string,
    testInfo: TestInfo,
    configure: (builder: AxeBuilder) => AxeBuilder = builder => builder,
): Promise<void> {
    const results = await configure(new AxeBuilder({ page }).withTags(WCAG_TAGS)).analyze();

    await testInfo.attach(`axe-${pageId.replace(/\//g, "-")}.json`, {
        body: JSON.stringify(results.violations, null, 2),
        contentType: "application/json",
    });

    const observed = results.violations.map(violation => violation.id);
    if (updating) {
        recordBaseline(pageId, observed);
        return;
    }

    const known = new Set(readBaseline().pages[pageId] ?? []);
    for (const ruleId of known) {
        if (!observed.includes(ruleId)) {
            testInfo.annotations.push({
                type: "baseline",
                description: `${pageId}: "${ruleId}" was not found in this run; remove it from baseline.json if it is fixed in every locale and colour scheme.`,
            });
        }
    }

    const fresh = results.violations.filter(violation => !known.has(violation.id));
    expect(formatViolations(fresh), `New accessibility violations on ${pageId}`).toBe("");
}

/**
 * Waits until the admin SPA has replaced its loaders with content, so the
 * scan sees the screen a user would, not a skeleton.
 */
export async function waitForAdminScreen(page: Page): Promise<void> {
    await expect(page.locator("main")).not.toBeEmpty();
    await expect(page.locator('.mantine-Loader-root, [data-card-state="loading"]')).toHaveCount(0, {
        timeout: 15_000,
    });
}

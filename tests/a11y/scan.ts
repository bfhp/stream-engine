import AxeBuilder from "@axe-core/playwright";
import { expect, type Page, type TestInfo } from "@playwright/test";
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import path from "node:path";
import { BASELINE_FILE, OUTPUT_DIR } from "./paths";
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
    await expectProjectColorScheme(page, testInfo);
    const results = await configure(new AxeBuilder({ page }).withTags(WCAG_TAGS)).analyze();

    const report = JSON.stringify(results.violations, null, 2);
    await testInfo.attach(`axe-${pageId.replace(/\//g, "-")}.json`, {
        body: report,
        contentType: "application/json",
    });
    // Also on disk, one file per page and project, so baseline entries can be
    // investigated without a failing run (and are part of the CI artifact).
    const reportDir = path.join(OUTPUT_DIR, "violations", testInfo.project.name);
    mkdirSync(reportDir, { recursive: true });
    writeFileSync(path.join(reportDir, `${pageId.replace(/\//g, "-")}.json`), report + "\n");

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
    await expectNamedIconsOnly(page, pageId);
    expect(formatViolations(fresh), `New accessibility violations on ${pageId}`).toBe("");
}

/**
 * Two things axe accepts but VoiceOver reads badly (WCAG 1.1.1, 4.1.2):
 * an icon-font glyph (`.bi`) that is neither hidden nor an image with a name
 * is read as an empty "group", and a control named only by `title` loses its
 * name in "read all" (VO+A). Checks the whole document, templates included.
 */
async function expectNamedIconsOnly(page: Page, pageId: string): Promise<void> {
    const problems = await page.evaluate(() => {
        const describe = (el: Element) => el.outerHTML.slice(0, 160);
        const roots: ParentNode[] = [document, ...[...document.querySelectorAll("template")].map(t => t.content)];
        const found: string[] = [];
        for (const root of roots) {
            root.querySelectorAll("i.bi, i[class^='bi-'], i[class*=' bi-']").forEach((icon) => {
                const hidden = icon.closest("[aria-hidden='true']") !== null;
                const named = icon.getAttribute("role") === "img" && (icon.getAttribute("aria-label") ?? "").trim() !== "";
                if (!hidden && !named) found.push(`icon: ${describe(icon)}`);
            });
            root.querySelectorAll("button, a[href], [role='button']").forEach((control) => {
                if (!control.hasAttribute("title")) return;
                if (control.hasAttribute("aria-label") || control.hasAttribute("aria-labelledby")) return;
                if ((control.textContent ?? "").trim() !== "") return;
                if ([...control.querySelectorAll("img[alt]")].some(img => img.getAttribute("alt")!.trim() !== "")) return;
                found.push(`named only by title: ${describe(control)}`);
            });
        }
        return found;
    });
    expect(problems.join("\n"), `Unnamed icons or title-only controls on ${pageId}`).toBe("");
}

/**
 * Waits until the admin SPA has replaced its loaders with content, so the
 * scan sees the screen a user would, not a skeleton.
 */
export async function waitForAdminScreen(page: Page): Promise<void> {
    await expect(page.locator("main")).not.toBeEmpty();
    await expect(page.locator('.mantine-Loader-root, .mantine-Skeleton-root, [data-card-state="loading"]')).toHaveCount(0, {
        timeout: 15_000,
    });
}

/**
 * The light and dark projects only mean something if the page follows the
 * emulated system scheme. The admin always does; a public theme does only
 * when its "Color mode" setting is "System", and it defaults to "Dark".
 * In CI that is a broken setup and fails; locally it is reported, because a
 * developer may be scanning a site configured on purpose.
 */
async function expectProjectColorScheme(page: Page, testInfo: TestInfo): Promise<void> {
    const expected = testInfo.project.use.colorScheme;
    if (expected !== "light" && expected !== "dark") return;

    const actual = await page.evaluate(() => {
        const root = document.documentElement;
        return root.dataset.colorMode ?? root.dataset.mantineColorScheme ?? null;
    });
    if (actual === null || actual === expected) return;

    const message = `The page renders "${actual}" in the ${expected} project. `
        + 'Set the active theme\'s "Color mode" to "System" to scan both schemes.';
    if (process.env.CI) {
        throw new Error(message);
    }
    if (!testInfo.annotations.some(annotation => annotation.type === "color-scheme")) {
        testInfo.annotations.push({ type: "color-scheme", description: message });
        console.warn(`[a11y] ${message}`);
    }
}

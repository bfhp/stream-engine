import axe from "axe-core";
import type { RunOptions } from "axe-core";
import { WCAG_TAGS, formatViolations } from "../../a11y/format";

/**
 * Rules that cannot give a meaningful answer on a jsdom fragment:
 *
 * - jsdom has no layout and does not cascade stylesheet colours, so contrast
 *   is unknown. The Playwright page scan checks contrast in a real browser.
 * - `region` asks for all content to sit in a landmark, which only makes
 *   sense for a whole page.
 */
const JSDOM_DISABLED_RULES: NonNullable<RunOptions["rules"]> = {
    "color-contrast": { enabled: false },
    region: { enabled: false },
};

/**
 * Inserts server-rendered markup into the jsdom document so axe can inspect
 * it. Callers should clear `document.body` after each test.
 */
export function mount(html: string): HTMLElement {
    const root = document.createElement("div");
    root.innerHTML = html;
    document.body.append(root);

    return root;
}

/**
 * Runs the WCAG A/AA rules against `root` and returns the violations as
 * text, so `expect(await axeReport(root)).toBe("")` fails with a readable
 * list instead of a large object diff.
 */
export async function axeReport(root: Element, options: RunOptions = {}): Promise<string> {
    const results = await axe.run(root, {
        runOnly: { type: "tag", values: WCAG_TAGS },
        resultTypes: ["violations"],
        ...options,
        rules: { ...JSDOM_DISABLED_RULES, ...options.rules },
    });

    return formatViolations(results.violations);
}

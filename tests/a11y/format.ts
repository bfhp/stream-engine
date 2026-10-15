import type { Result } from "axe-core";

/**
 * The WCAG 2.2 level A and AA rule sets, the target named in
 * ACCESSIBILITY.md. axe tags every rule with the WCAG version that
 * introduced its success criterion, so all five tags are needed.
 * Best-practice rules are left out: they are useful advice but not part of
 * the conformance target, and turning them on would make the gate noisy.
 */
export const WCAG_TAGS = ["wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22aa"];

/**
 * Renders axe violations as plain text for an assertion message. Shared by
 * the Vitest component checks and the Playwright page scan so a failure
 * reads the same way in both.
 */
export function formatViolations(violations: Result[]): string {
    return violations.map(violation => {
        const nodes = violation.nodes.map(node => {
            const summary = (node.failureSummary ?? "").split("\n").join("\n      ");
            return `    ${node.target.join(" ")}\n      ${summary}`;
        }).join("\n");

        return `${violation.id} (${violation.impact ?? "unknown impact"}): ${violation.help}\n`
            + `  ${violation.helpUrl}\n${nodes}`;
    }).join("\n\n");
}

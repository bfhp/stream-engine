import { existsSync, readFileSync } from "node:fs";
import { test, type Page } from "@playwright/test";
import { SITE_MAP } from "./paths";

/*
 * Finding content without assuming URLs: section pages come from the site
 * map auth.setup.ts writes, individual forums, topics, posts and communities
 * from the first link of the right depth on their section's page.
 */

/** Static route segments that are actions, not content (new topic, edit, ...). */
const ACTION_SEGMENTS = new Set(["new", "post", "edit", "create", "manage", "read-all"]);

let siteMap: Record<string, string> | null = null;

/** Path of the page with this action, or null when the site has none. */
export function sectionPath(action: string): string | null {
    if (siteMap === null) {
        siteMap = existsSync(SITE_MAP) ? JSON.parse(readFileSync(SITE_MAP, "utf8")) as Record<string, string> : {};
    }

    return siteMap[action] ?? null;
}

/** Skips the current test when the site has no page for `action`. */
export function requireSection(action: string): string {
    const path = sectionPath(action);
    test.skip(path === null, `This site has no ${action} page.`);

    return path as string;
}

/**
 * Same-site links on the current page whose path is `base` plus exactly
 * `depth` more segments, skipping action segments, in document order.
 */
export async function deeperLinks(page: Page, base: string, depth = 1): Promise<string[]> {
    return page.evaluate(({ base, depth, actions }) => {
        const baseSegments = base.split("/").filter(Boolean);
        const found: string[] = [];
        for (const anchor of document.querySelectorAll<HTMLAnchorElement>("main a[href]")) {
            const url = new URL(anchor.href, location.href);
            if (url.origin !== location.origin || !url.pathname.startsWith(base)) continue;
            const segments = url.pathname.split("/").filter(Boolean);
            if (segments.length !== baseSegments.length + depth) continue;
            if (segments.slice(baseSegments.length).some(segment => actions.includes(segment))) continue;
            if (!found.includes(url.pathname)) found.push(url.pathname);
        }
        return found;
    }, { base, depth, actions: [...ACTION_SEGMENTS] });
}

/**
 * Opens the links one level below `base` until one of them links one level
 * further (a forum with a topic, a profile with a post). Returns both paths,
 * or skips the test when the site has no such content.
 */
export async function requireParentWithChild(page: Page, base: string, what: string): Promise<{ parent: string; child: string }> {
    await page.goto(base);
    for (const parent of (await deeperLinks(page, base)).slice(0, 10)) {
        await page.goto(parent);
        const [child] = await deeperLinks(page, parent);
        if (child) return { parent, child };
    }
    test.skip(true, `No ${what} found below ${base}.`);
    throw new Error("unreachable");
}

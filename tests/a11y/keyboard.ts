import { expect, type Locator, type Page } from "@playwright/test";

/*
 * Keyboard checks that axe cannot make: visible focus, focus indicator
 * contrast, keyboard traps, focus containment in dialogs and focus return.
 */

/** One stop of a Tab walk, measured in the page. */
type FocusStop = {
    marker: string;
    description: string;
    /** Computed properties that differ between focused and unfocused. */
    changed: string[];
    /** Colour of an outline or box-shadow indicator, if there is one. */
    indicatorColor: string | null;
    /** Effective background next to the element, behind the indicator. */
    adjacentColor: string;
};

type Rgba = [number, number, number, number];

function parseColor(value: string): Rgba | null {
    const match = value.match(/rgba?\(([^)]+)\)/);
    if (!match) return null;
    const parts = match[1].split(/[\s,/]+/).filter(Boolean).map(Number);
    return [parts[0], parts[1], parts[2], parts.length > 3 ? parts[3] : 1];
}

function luminance([r, g, b]: Rgba): number {
    const channel = (value: number) => {
        const c = value / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

function blend(top: Rgba, bottom: Rgba): Rgba {
    const alpha = top[3];
    return [0, 1, 2].map(i => top[i] * alpha + bottom[i] * (1 - alpha)).concat(1) as Rgba;
}

export function contrast(foreground: string, background: string): number | null {
    const fg = parseColor(foreground);
    const bg = parseColor(background);
    if (!fg || !bg) return null;
    const [high, low] = [luminance(blend(fg, bg)), luminance(bg)].sort((a, b) => b - a);

    return (high + 0.05) / (low + 0.05);
}

/** Marker of document.activeElement, assigning one on first visit; no blur. */
async function focusMarker(page: Page, index: number): Promise<string | null> {
    return page.evaluate((index) => {
        const element = document.activeElement as HTMLElement | null;
        if (!element || element === document.body || element === document.documentElement) return null;
        element.dataset.a11yTabStop ??= String(index);
        return element.dataset.a11yTabStop;
    }, index);
}

/**
 * Measures document.activeElement focused and, briefly, unfocused. Only done
 * once per element: blur() and focus() reset where focus is inside it (the
 * segment of a date input), so repeating it would walk in place.
 */
async function measureFocus(page: Page, index: number): Promise<FocusStop | null> {
    return page.evaluate((index) => {
        const element = document.activeElement as HTMLElement | null;
        if (!element || element === document.body || element === document.documentElement) return null;

        element.dataset.a11yTabStop ??= String(index);
        const properties = [
            "outlineStyle", "outlineWidth", "outlineColor", "boxShadow",
            "borderTopColor", "borderBottomColor", "backgroundColor", "color", "textDecorationLine",
        ] as const;
        type Snapshot = Record<(typeof properties)[number], string>;

        // Visually hidden inputs (checkboxes, switches, toggle buttons) draw
        // their focus ring on a sibling, the parent or their <label>.
        const labels = (element as Partial<HTMLInputElement>).labels;
        const labelNodes: HTMLElement[] = labels ? [...labels] : [];
        const targets = [element, element.nextElementSibling, element.parentElement, ...labelNodes]
            .filter((node): node is HTMLElement => node instanceof HTMLElement);
        const snapshot = () => targets.map(node => {
            const style = getComputedStyle(node);
            return Object.fromEntries(properties.map(name => [name, style[name]])) as Snapshot;
        });

        const focused = snapshot();
        element.blur();
        const unfocused = snapshot();
        element.focus({ preventScroll: true });

        const changed = targets.flatMap((_, i) =>
            properties.filter(name => focused[i][name] !== unfocused[i][name]).map(name => `${i}:${name}`));

        let indicatorColor: string | null = null;
        for (let i = 0; i < targets.length && indicatorColor === null; i++) {
            const now = focused[i];
            const before = unfocused[i];
            // "auto" is the browser's own two-tone ring; its colour cannot be read.
            const outlineShown = now.outlineStyle !== "none" && now.outlineStyle !== "auto"
                && parseFloat(now.outlineWidth) > 0;
            if (outlineShown && (i === 0 || now.outlineStyle !== before.outlineStyle || now.outlineColor !== before.outlineColor)) {
                indicatorColor = now.outlineColor;
            } else if (now.boxShadow !== "none" && now.boxShadow !== before.boxShadow) {
                indicatorColor = now.boxShadow.match(/rgba?\([^)]+\)/)?.[0] ?? null;
            }
        }

        let adjacentColor = "rgb(255, 255, 255)";
        for (let node = element.parentElement; node; node = node.parentElement) {
            const background = getComputedStyle(node).backgroundColor;
            if (!/rgba\([^)]*,\s*0\)|transparent/.test(background)) {
                adjacentColor = background;
                break;
            }
        }

        const id = element.id ? `#${element.id}` : "";
        const classes = [...element.classList].slice(0, 2).map(name => `.${name}`).join("");
        const label = (element.getAttribute("aria-label") ?? element.textContent ?? "").trim().slice(0, 40);

        return {
            marker: element.dataset.a11yTabStop ?? String(index),
            description: `<${element.tagName.toLowerCase()}${id}${classes}> ${label}`,
            changed,
            indicatorColor,
            adjacentColor,
        };
    }, index);
}

/**
 * Walks the page with Tab until focus comes back to where it started.
 * Fails on a keyboard trap (WCAG 2.1.2), on a stop without a visible change
 * when focused (2.4.7), and on an outline or shadow indicator below 3:1
 * against its background (1.4.11).
 */
export async function expectKeyboardWalk(page: Page, maxSteps = 250): Promise<number> {
    // Computed styles are read right after focus() and blur(); a running
    // transition would still report the old value.
    await page.addStyleTag({ content: "*, *::before, *::after { transition: none !important; animation: none !important; }" });
    await page.evaluate(() => {
        document.querySelectorAll("[data-a11y-tab-stop]").forEach(node => node.removeAttribute("data-a11y-tab-stop"));
        (document.activeElement as HTMLElement | null)?.blur();
    });

    const problems: string[] = [];
    const seen = new Set<string>();
    let previous: string | null = null;
    let repeats = 0;
    let first: string | null = null;

    for (let step = 0; step < maxSteps; step++) {
        await page.keyboard.press("Tab");
        const marker = await focusMarker(page, step);
        if (marker === null) {
            // Focus left the document (browser UI) after the last stop.
            if (first !== null) break;
            continue;
        }
        if (marker === first) break;
        first ??= marker;

        // Date and time inputs take several Tab presses, one per segment.
        repeats = marker === previous ? repeats + 1 : 0;
        if (repeats >= 5) {
            problems.push(`keyboard trap: Tab stays on the element marked ${marker}`);
            break;
        }
        previous = marker;
        if (seen.has(marker)) continue;
        seen.add(marker);

        const stop = await measureFocus(page, step);
        if (!stop) continue;

        if (stop.changed.length === 0) {
            problems.push(`no visible focus indicator: ${stop.description}`);
        } else if (stop.indicatorColor) {
            const ratio = contrast(stop.indicatorColor, stop.adjacentColor);
            if (ratio !== null && ratio < 3) {
                problems.push(`focus indicator ${ratio.toFixed(2)}:1 (${stop.indicatorColor} on ${stop.adjacentColor}): ${stop.description}`);
            }
        }
    }

    expect(problems.join("\n"), "Keyboard problems").toBe("");
    expect(seen.size, "focusable elements reached with Tab").toBeGreaterThan(0);

    return seen.size;
}

/**
 * WCAG 2.4.3: while a modal is open, Tab and Shift+Tab stay inside it. Focus
 * may briefly leave the document (browser UI) but never reaches the page
 * behind the dialog.
 */
export async function expectFocusContained(page: Page, container: Locator, presses = 12): Promise<void> {
    for (const key of ["Tab", "Shift+Tab"]) {
        for (let press = 0; press < presses; press++) {
            await page.keyboard.press(key);
            const inside = await container.evaluate(node => {
                const active = document.activeElement;
                return !active || active === document.body || node.contains(active);
            });
            expect(inside, `${key} moved focus out of the open dialog`).toBe(true);
        }
    }
}

/** Whether focus is on, or inside, the container (a dialog may focus itself). */
export async function expectFocusWithin(container: Locator): Promise<void> {
    await expect.poll(() => container.evaluate(node => node.contains(document.activeElement)),
        { message: "focus moves into the opened dialog" }).toBe(true);
}

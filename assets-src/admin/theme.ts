import {
    DEFAULT_THEME,
    createTheme,
    type CSSVariablesResolver,
    type MantineColorsTuple,
} from "@mantine/core";

/*
 * Contrast adjustments to Mantine's defaults so the administration panel meets
 * WCAG 1.4.3 (4.5:1 for normal text) in both colour schemes. Each value was
 * checked against the backgrounds the page scan reported: white, gray.0 and
 * gray.1 in light mode; dark.7, dark.6 and dark.5 in dark mode.
 */

/*
 * Mantine's green.9 (#2b8a3e) reaches only 4.4:1 under white text and 3.9:1 as
 * light-variant text on its own tint. This darker shade passes both; shades
 * 0-8 are unchanged.
 */
const green = [...DEFAULT_THEME.colors.green.slice(0, 9), "#237032"] as unknown as MantineColorsTuple;

/* red.9 (#c92a2a) drops to 4.4:1 as light-variant text over a table row's gray. */
const red = [...DEFAULT_THEME.colors.red.slice(0, 9), "#b52424"] as unknown as MantineColorsTuple;

export const adminTheme = createTheme({
    // Shade 9 is used for filled controls (white text) and, in light mode, for
    // light-variant text on a 10% tint; shade 6 (the default) passes neither.
    primaryShade: { light: 9, dark: 9 },
    colors: { green, red },
    // Mantine's own transitions (modals, collapses, loaders) honour the
    // operating system's reduced-motion setting (WCAG 2.3.3).
    respectReducedMotion: true,
});

/*
 * `c="dimmed"` defaults to gray.6 (3.3:1 on white) and dark.2 (3.5:1 on dark.6).
 */
export const adminCssVariablesResolver: CSSVariablesResolver = () => ({
    variables: {},
    light: { "--mantine-color-dimmed": "#666d75" },
    dark: { "--mantine-color-dimmed": "#a6a6a6" },
});

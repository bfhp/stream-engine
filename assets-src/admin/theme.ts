import {
    DEFAULT_THEME,
    Drawer,
    Menu,
    Modal,
    Tooltip,
    createTheme,
    type CSSVariablesResolver,
    type MantineColorsTuple,
} from "@mantine/core";
import { trans } from "../shared/i18n";

/*
 * Accessibility adjustments to Mantine's defaults for the administration
 * panel. Contrast values target WCAG 1.4.3 (4.5:1 for normal text) and were
 * checked against the backgrounds the page scan reported: white, gray.0,
 * gray.1 and gray.2 in light mode; dark.7, dark.6 and dark.5 in dark mode.
 */

/*
 * Shade 9 of each palette is used for filled controls (white text) and, in
 * light mode, for light-variant text on a 10% tint of itself (badges, light
 * buttons). These replacements are the default shade 9 darkened just enough
 * to reach 4.5:1 in both roles; palettes not listed already do. Shades 0-8
 * are unchanged.
 */
const SHADE_9: Record<string, string> = {
    red: "#b52424",
    blue: "#1863a9",
    cyan: "#0a6b7d",
    teal: "#076e4f",
    green: "#237032",
    lime: "#436c09",
    yellow: "#954d00",
    orange: "#ab390c",
};

const colors = Object.fromEntries(Object.entries(SHADE_9).map(([name, shade9]) => [
    name,
    [...DEFAULT_THEME.colors[name].slice(0, 9), shade9] as unknown as MantineColorsTuple,
]));

/* The close button of modals and drawers is an icon; give it a name (4.1.2). */
const closeButtonProps = { "aria-label": trans("js.admin.close") };

export const adminTheme = createTheme({
    primaryShade: { light: 9, dark: 9 },
    colors,
    // Mantine's own transitions (modals, collapses, loaders) honour the
    // operating system's reduced-motion setting (WCAG 2.3.3).
    respectReducedMotion: true,
    components: {
        Modal: Modal.extend({ defaultProps: { closeButtonProps } }),
        Drawer: Drawer.extend({ defaultProps: { closeButtonProps } }),
        // The placeholder is a focusable <div> inside role="menu", which may
        // only contain menu items (1.3.1); focus goes to the first item instead.
        Menu: Menu.extend({ defaultProps: { withInitialFocusPlaceholder: false } }),
        // Tooltips (1.4.13): they close with Escape already; the close delay
        // lets the pointer move onto the tooltip, which then stays open
        // (pointer-events are restored in accessibility.css), and keyboard
        // focus shows the same text as hovering.
        Tooltip: Tooltip.extend({
            defaultProps: { closeDelay: 150, events: { hover: true, focus: true, touch: false } },
        }),
    },
});

/*
 * - `c="dimmed"` defaults to gray.6 (3.3:1 on white) and dark.2 (3.5:1 on
 *   dark.6).
 * - Validation errors default to red.6 (3.3:1 on white) and red.8 (3.0:1 on
 *   dark.6).
 */
export const adminCssVariablesResolver: CSSVariablesResolver = () => ({
    variables: {},
    light: {
        "--mantine-color-dimmed": "#666d75",
        "--mantine-color-error": "#b52424",
    },
    dark: {
        "--mantine-color-dimmed": "#a6a6a6",
        "--mantine-color-error": "#ff8787",
    },
});

import { Text } from "@mantine/core";
import { trans } from "../../shared/i18n";

/** Monaco's "Toggle Tab Key Moves Focus" shortcut on this platform. */
export function tabFocusShortcut(): string {
    const platform = navigator.userAgent;
    return /Mac|iPhone|iPad/.test(platform) ? "Ctrl+Shift+M" : "Ctrl+M";
}

/**
 * Monaco keeps Tab for indentation, so by default keyboard users cannot Tab
 * out of it. WCAG 2.1.2 allows that only when the way out is explained, so
 * say it next to the editor and in the editor's own accessible name.
 */
export function codeEditorAriaLabel(label: string): string {
    return `${label}. ${trans("js.admin.code_editor.tab_hint", { shortcut: tabFocusShortcut() })}`;
}

export default function CodeEditorHint() {
    return (
        <Text size="xs" c="dimmed" mt={4}>
            {trans("js.admin.code_editor.tab_hint", { shortcut: tabFocusShortcut() })}
        </Text>
    );
}

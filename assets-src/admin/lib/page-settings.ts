export type PageSettings = Record<string, unknown> & { shareButtons?: boolean; commentsEnabled?: boolean };

export function parsePageSettings(value: string | null | undefined): PageSettings {
    if (!value?.trim()) {
        return {};
    }

    const settings: unknown = JSON.parse(value);
    if (settings === null) {
        return {};
    }
    if (typeof settings !== "object" || Array.isArray(settings)) {
        throw new Error(trans("js.admin.page_settings_invalid"));
    }

    return settings as PageSettings;
}
import { trans } from "../../shared/i18n";

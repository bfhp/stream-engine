import { trans } from "../../shared/i18n";

export type FeedContentWidth = "contained" | "full";

export type PageSettings = Record<string, unknown> & {
    shareButtons?: boolean;
    commentsEnabled?: boolean;
    feedContentWidth?: FeedContentWidth;
};

const FEED_CONTENT_WIDTH_ACTIONS = new Set(["article.show-id", "article.show-slug"]);

export function supportsFeedContentWidth(action: string): boolean {
    return FEED_CONTENT_WIDTH_ACTIONS.has(action);
}

export function feedContentWidth(settings: PageSettings): FeedContentWidth {
    return settings.feedContentWidth === "full" ? "full" : "contained";
}

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

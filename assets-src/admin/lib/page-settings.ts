import { trans } from "../../shared/i18n";

export type FeedContentWidth = "contained" | "full";

/** Widget placements, in the order of WidgetService::PLACEMENTS. */
export const WIDGET_PLACEMENTS = [
    "head",
    "after_header",
    "before_content",
    "after_content",
    "sidebar_top",
    "sidebar_bottom",
    "footer_legal",
    "footer_contacts",
] as const;

export type WidgetPlacement = typeof WIDGET_PLACEMENTS[number];

export type PageSettings = Record<string, unknown> & {
    shareButtons?: boolean;
    commentsEnabled?: boolean;
    relatedArticlesEnabled?: boolean;
    feedContentWidth?: FeedContentWidth;
    hiddenWidgets?: WidgetPlacement[];
};

const ARTICLE_SHOW_ACTIONS = new Set(["article.show-id", "article.show-slug"]);

export function supportsFeedContentWidth(action: string): boolean {
    return ARTICLE_SHOW_ACTIONS.has(action);
}

export function supportsRelatedArticles(action: string): boolean {
    return ARTICLE_SHOW_ACTIONS.has(action);
}

export function feedContentWidth(settings: PageSettings): FeedContentWidth {
    return settings.feedContentWidth === "full" ? "full" : "contained";
}

/**
 * Placements shown on the page. Settings store the hidden ones, so a page
 * without `hiddenWidgets` shows every placement.
 */
export function visibleWidgets(settings: PageSettings): WidgetPlacement[] {
    const hidden = Array.isArray(settings.hiddenWidgets) ? settings.hiddenWidgets : [];

    return WIDGET_PLACEMENTS.filter(placement => !hidden.includes(placement));
}

/** Stores the inverse of `visible`; drops the key when nothing is hidden. */
export function withVisibleWidgets(settings: PageSettings, visible: readonly string[]): PageSettings {
    const hidden = WIDGET_PLACEMENTS.filter(placement => !visible.includes(placement));
    const { hiddenWidgets: _previous, ...rest } = settings;

    return hidden.length > 0 ? { ...rest, hiddenWidgets: hidden } : rest;
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

import { trans } from "../../shared/i18n";

export const PAGE_ACTION_FIELDS = [
    "feedId",
    "feedType",
    "listFeedType",
    "termVocabulary"
] as const;

export type PageActionFieldName = typeof PAGE_ACTION_FIELDS[number];
export type PageActionFieldStatus = "unsupported" | "optional" | "required";

export type PageActionFieldContract = {
    status: PageActionFieldStatus;
    values?: string[];
    feedTypes?: string[];
};

export type PageActionRequirement = {
    oneOf: PageActionFieldName[];
};

export type PageActionSettingOption = {
    value: string;
    label: string;
};

export type PageActionSetting = {
    control: "select";
    label: string;
    required: boolean;
    options: PageActionSettingOption[];
};

export type PageAction = {
    action: string;
    label: string;
    module: string;
    fields: Record<PageActionFieldName, PageActionFieldContract>;
    requirements: PageActionRequirement[];
    settings: Record<string, PageActionSetting>;
};

export type PageActionConfiguration = Record<PageActionFieldName, string | number | "">;

export const PAGE_ACTION_FIELD_LABELS: Record<PageActionFieldName, string> = {
    feedId: trans("js.admin.feed_id"),
    feedType: trans("js.admin.feed_type"),
    listFeedType: trans("js.admin.list_feed_type"),
    termVocabulary: trans("js.admin.term_vocabulary")
};

function hasValue(value: string | number | ""): boolean {
    return value !== "";
}

export function validatePageActionConfiguration(
    action: PageAction,
    configuration: PageActionConfiguration
): Partial<Record<PageActionFieldName, string>> {
    const errors: Partial<Record<PageActionFieldName, string>> = {};

    PAGE_ACTION_FIELDS.forEach(field => {
        const contract = action.fields[field];
        const value = configuration[field];
        const label = PAGE_ACTION_FIELD_LABELS[field];

        if (contract.status === "unsupported" && hasValue(value)) {
            errors[field] = trans("js.admin.page_action.not_used_error", { label, action: action.action });
        } else if (contract.status === "required" && !hasValue(value)) {
            errors[field] = trans("js.admin.page_action.required_error", { label, action: action.action });
        } else if (hasValue(value) && contract.values && !contract.values.includes(String(value))) {
            errors[field] = trans("js.admin.page_action.allowed_error", { label, values: contract.values.join(", ") });
        }
    });

    action.requirements.forEach(requirement => {
        if (requirement.oneOf.some(field => hasValue(configuration[field]))) {
            return;
        }

        const message = trans("js.admin.page_action.one_of_error", { fields: requirement.oneOf
            .map(field => PAGE_ACTION_FIELD_LABELS[field])
            .join(", ") });
        requirement.oneOf.forEach(field => {
            errors[field] ??= message;
        });
    });

    return errors;
}

export function validatePageActionSettings(
    action: PageAction,
    settings: Record<string, unknown>
): Record<string, string> {
    const errors: Record<string, string> = {};

    Object.entries(action.settings).forEach(([key, descriptor]) => {
        const exists = Object.prototype.hasOwnProperty.call(settings, key);
        const value = settings[key];
        const label = trans(descriptor.label);

        if (!exists) {
            if (descriptor.required) {
                errors[key] = trans("js.admin.page_action.required_error", { label, action: action.action });
            }
            return;
        }
        if (typeof value !== "string") {
            errors[key] = trans("js.admin.page_action.setting_type_error", { label });
        } else if (descriptor.required && value.trim() === "") {
            errors[key] = trans("js.admin.page_action.required_error", { label, action: action.action });
        } else if (!descriptor.options.some(option => option.value === value)) {
            errors[key] = trans("js.admin.page_action.allowed_error", {
                label,
                values: descriptor.options.map(option => option.value).join(", ")
            });
        }
    });

    return errors;
}

export function buildPageActionSettingOptions(
    descriptor: PageActionSetting,
    currentValue: unknown
): PageActionSettingOption[] {
    const options = descriptor.options.map(option => ({
        value: option.value,
        label: trans(option.label)
    }));

    if (typeof currentValue === "string"
        && currentValue !== ""
        && !descriptor.options.some(option => option.value === currentValue)) {
        options.unshift({
            value: currentValue,
            label: `${currentValue} (${trans("js.admin.not_allowed")})`
        });
    }

    return options;
}

export function updatePageActionSetting(
    settings: Record<string, unknown>,
    key: string,
    value: string
): Record<string, unknown> {
    return { ...settings, [key]: value };
}

export function fieldDescription(action: PageAction | undefined, field: PageActionFieldName): string {
    if (!action) {
        return trans("js.admin.page_action.contract_unavailable");
    }

    const contract = action.fields[field];
    const requirement = action.requirements.find(item => item.oneOf.includes(field));
    const fixedValue = fixedPageActionValue(action, field);
    const parts = [
        fixedValue
            ? trans("js.admin.page_action.automatic", { action: action.action, value: fixedValue })
            : contract.status === "unsupported"
            ? trans("js.admin.page_action.not_used", { action: action.action })
            : contract.status === "required"
                ? trans("js.admin.page_action.required", { action: action.action })
                : trans("js.admin.page_action.optional", { action: action.action })
    ];

    if (requirement) {
        parts.push(trans("js.admin.page_action.one_of", { fields: requirement.oneOf
            .map(item => PAGE_ACTION_FIELD_LABELS[item])
            .join(" / ") }));
    }
    if (contract.values && !fixedValue) {
        parts.push(trans("js.admin.page_action.allowed", { values: contract.values.join(", ") }));
    }
    if (contract.feedTypes) {
        parts.push(trans("js.admin.page_action.feed_types", { values: contract.feedTypes.join(", ") }));
    }

    return parts.join(" ");
}

export function fixedPageActionValue(
    action: PageAction | undefined,
    field: PageActionFieldName
): string | undefined {
    const contract = action?.fields[field];

    return contract?.status === "required" && contract.values?.length === 1
        ? contract.values[0]
        : undefined;
}

export function allowedValues(
    action: PageAction | undefined,
    field: "feedType" | "listFeedType"
): string[] | undefined {
    return action?.fields[field].values;
}

export function buildFeedTypeOptions(
    labels: Record<string, string>,
    action: PageAction | undefined,
    field: "feedType" | "listFeedType",
    currentValue: string
): Array<{ value: string; label: string }> {
    if (action?.fields[field].status === "unsupported") {
        return currentValue
            ? [{ value: currentValue, label: `${currentValue} (${trans("js.admin.not_supported")})` }]
            : [];
    }

    const allowed = allowedValues(action, field);
    const options = Object.entries(labels)
        .filter(([value]) => !allowed || allowed.includes(value))
        .map(([value, label]) => ({ value, label: `${label} (${value})` }));

    if (currentValue && !options.some(option => option.value === currentValue)) {
        options.unshift({
            value: currentValue,
            label: `${currentValue} (${trans(currentValue in labels ? "js.admin.not_allowed" : "js.admin.unknown_type")})`
        });
    }

    return options;
}

export function isPageActionFieldDisabled(
    action: PageAction | undefined,
    field: PageActionFieldName,
    value: string | number | ""
): boolean {
    if (!action) {
        return true;
    }

    return action.fields[field].status === "unsupported" && !hasValue(value);
}

export function routeConfigurationWarnings(configuration: {
    pattern: string;
    feedId: number | "";
    feedType: string;
}): string[] {
    const hasPlaceholder = /\{\w+(?::[^}]+)?}/.test(configuration.pattern);
    const hasFeedId = configuration.feedId !== "";
    const hasFeedType = configuration.feedType !== "";
    const warnings: string[] = [];

    if (hasFeedType && !hasPlaceholder && !hasFeedId) {
        warnings.push(trans("js.admin.page_action.warning_feed_type"));
    }
    if (hasPlaceholder && hasFeedId && !hasFeedType) {
        warnings.push(trans("js.admin.page_action.warning_feed_id"));
    }
    if (hasPlaceholder && hasFeedId && hasFeedType) {
        warnings.push(trans("js.admin.page_action.warning_precedence"));
    }

    return warnings;
}

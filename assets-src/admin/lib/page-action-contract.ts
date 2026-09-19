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

export type PageAction = {
    action: string;
    label: string;
    module: string;
    fields: Record<PageActionFieldName, PageActionFieldContract>;
    requirements: PageActionRequirement[];
};

export type PageActionConfiguration = Record<PageActionFieldName, string | number | "">;

export const PAGE_ACTION_FIELD_LABELS: Record<PageActionFieldName, string> = {
    feedId: "Feed ID",
    feedType: "Feed type",
    listFeedType: "List feed type",
    termVocabulary: "Term vocabulary"
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
            errors[field] = `${label} is not used by ${action.action}. Clear it before saving.`;
        } else if (contract.status === "required" && !hasValue(value)) {
            errors[field] = `${label} is required by ${action.action}.`;
        } else if (hasValue(value) && contract.values && !contract.values.includes(String(value))) {
            errors[field] = `${label} must be one of: ${contract.values.join(", ")}.`;
        }
    });

    action.requirements.forEach(requirement => {
        if (requirement.oneOf.some(field => hasValue(configuration[field]))) {
            return;
        }

        const message = `Set at least one of: ${requirement.oneOf
            .map(field => PAGE_ACTION_FIELD_LABELS[field])
            .join(", ")}.`;
        requirement.oneOf.forEach(field => {
            errors[field] ??= message;
        });
    });

    return errors;
}

export function fieldDescription(action: PageAction | undefined, field: PageActionFieldName): string {
    if (!action) {
        return "The action contract is unavailable; existing legacy values will be preserved.";
    }

    const contract = action.fields[field];
    const requirement = action.requirements.find(item => item.oneOf.includes(field));
    const parts = [
        contract.status === "unsupported"
            ? `Not used by ${action.action}.`
            : contract.status === "required"
                ? `Required by ${action.action}.`
                : `Optional for ${action.action}.`
    ];

    if (requirement) {
        parts.push(`At least one of ${requirement.oneOf
            .map(item => PAGE_ACTION_FIELD_LABELS[item])
            .join(" / ")} is required.`);
    }
    if (contract.values) {
        parts.push(`Allowed: ${contract.values.join(", ")}.`);
    }
    if (contract.feedTypes) {
        parts.push(`The referenced feed must have type: ${contract.feedTypes.join(", ")}.`);
    }

    return parts.join(" ");
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
            ? [{ value: currentValue, label: `${currentValue} (not supported)` }]
            : [];
    }

    const allowed = allowedValues(action, field);
    const options = Object.entries(labels)
        .filter(([value]) => !allowed || allowed.includes(value))
        .map(([value, label]) => ({ value, label: `${label} (${value})` }));

    if (currentValue && !options.some(option => option.value === currentValue)) {
        options.unshift({
            value: currentValue,
            label: `${currentValue} (${currentValue in labels ? "not allowed" : "unknown type"})`
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
        warnings.push("Feed type usually needs a route placeholder such as {slug} (or {username}).");
    }
    if (hasPlaceholder && hasFeedId && !hasFeedType) {
        warnings.push("Feed ID pins one feed, so the route placeholder does not select a feed dynamically.");
    }
    if (hasPlaceholder && hasFeedId && hasFeedType) {
        warnings.push("Feed ID takes precedence when loading content; verify that this route is intentionally pinned.");
    }

    return warnings;
}

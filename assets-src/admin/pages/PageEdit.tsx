import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import {
    Button,
    Group,
    NumberInput,
    Select,
    SimpleGrid,
    Stack,
    Switch,
    Text,
    TextInput
} from "@mantine/core";
import { notifications } from "@mantine/notifications";
import {
    feedContentWidth,
    parsePageSettings,
    supportsFeedContentWidth,
    type FeedContentWidth,
    type PageSettings
} from "../lib/page-settings";
import { forbiddenPageParentIds } from "../lib/page-hierarchy";
import {
    buildFeedTypeOptions,
    fieldDescription,
    fixedPageActionValue,
    isPageActionFieldDisabled,
    type PageAction,
    type PageActionFieldName,
    routeConfigurationWarnings,
    validatePageActionConfiguration
} from "../lib/page-action-contract";
import { csrfHeaders } from "../../shared/csrf";
import { getApiResponseError } from "../../shared/api-errors";
import { FEED_TYPE_LABELS } from "../../shared/feed-types";
import { trans } from "../../shared/i18n";

type EditablePage = {
    id?: number;
    parentId: number | "";
    pattern: string;
    action: string;
    pageName: string;
    settings: PageSettings;
    feedType: string;
    listFeedType: string;
    termVocabulary: string;
    feedId: number | "";
    changefreq: string;
    accessRule: string;
};

type ParentPage = {
    id: number;
    parentId?: number | null;
    pageName?: string | null;
    action?: string | null;
    pattern?: string | null;
};

const CHANGEFREQ_OPTIONS = [
    { value: "", label: trans("js.admin.default") },
    { value: "always", label: trans("js.admin.changefreq.always") },
    { value: "hourly", label: trans("js.admin.changefreq.hourly") },
    { value: "daily", label: trans("js.admin.changefreq.daily") },
    { value: "weekly", label: trans("js.admin.changefreq.weekly") },
    { value: "monthly", label: trans("js.admin.changefreq.monthly") },
    { value: "yearly", label: trans("js.admin.changefreq.yearly") },
    { value: "never", label: trans("js.admin.changefreq.never") },
    { value: "noindex", label: trans("js.admin.changefreq.noindex") }
];

function blankPage(): EditablePage {
    return {
        parentId: "",
        pattern: "",
        action: "",
        pageName: "",
        settings: { shareButtons: false, commentsEnabled: false },
        feedType: "",
        listFeedType: "",
        termVocabulary: "",
        feedId: "",
        changefreq: "",
        accessRule: "public"
    };
}

function normalizePage(data: Partial<Omit<EditablePage, "settings">> & { settings?: string | null }): EditablePage {
    const isRootPage = data.id === 1;

    return {
        id: data.id,
        parentId: isRootPage ? "" : data.parentId ?? "",
        pattern: isRootPage ? "" : data.pattern ?? "",
        action: data.action ?? "",
        pageName: data.pageName ?? "",
        settings: parsePageSettings(data.settings),
        feedType: data.feedType ?? "",
        listFeedType: data.listFeedType ?? "",
        termVocabulary: data.termVocabulary ?? "",
        feedId: data.feedId ?? "",
        changefreq: data.changefreq ?? "",
        accessRule: data.accessRule ?? "public",
    };
}

export default function PageEdit() {
    const { id } = useParams();
    const navigate = useNavigate();
    const isNew = id === "new";
    const [page, setPage] = useState<EditablePage | null>(null);
    const [actions, setActions] = useState<PageAction[]>([]);
    const [actionsLoading, setActionsLoading] = useState(false);
    const [parentPages, setParentPages] = useState<ParentPage[]>([]);
    const [parentPagesLoading, setParentPagesLoading] = useState(false);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!id) {
            return;
        }

        if (isNew) {
            setPage(blankPage());
            return;
        }

        setLoading(true);
        fetch(`/api/v1/admin/pages/${id}`)
            .then(async response => {
                if (!response.ok) {
                    throw await getApiResponseError(response, trans("js.admin.pages.load_one_failed"));
                }

                return response.json();
            })
            .then(data => setPage(normalizePage(data)))
            .catch(err => {
                notifications.show({
                    color: "red",
                    title: trans("js.admin.error"),
                    message: err.message || trans("js.admin.pages.load_one_failed")
                });
            })
            .finally(() => setLoading(false));
    }, [id, isNew]);

    useEffect(() => {
        const controller = new AbortController();

        setActionsLoading(true);
        fetch("/api/v1/admin/page-actions", { signal: controller.signal })
            .then(async response => {
                if (!response.ok) {
                    throw await getApiResponseError(response, trans("js.admin.pages.actions_load_failed"));
                }

                return response.json();
            })
            .then(data => setActions(Array.isArray(data.data) ? data.data : []))
            .catch(err => {
                if (err.name !== "AbortError") {
                    notifications.show({
                        color: "red",
                        title: trans("js.admin.error"),
                        message: err.message || trans("js.admin.pages.actions_load_failed")
                    });
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setActionsLoading(false);
                }
            });

        return () => controller.abort();
    }, []);

    useEffect(() => {
        const controller = new AbortController();

        setParentPagesLoading(true);
        fetch("/api/v1/admin/pages", { signal: controller.signal })
            .then(async response => {
                if (!response.ok) {
                    throw await getApiResponseError(response, trans("js.admin.pages.parents_load_failed"));
                }

                return response.json();
            })
            .then(data => setParentPages(Array.isArray(data.data) ? data.data : []))
            .catch(err => {
                if (err.name !== "AbortError") {
                    notifications.show({
                        color: "red",
                        title: trans("js.admin.error"),
                        message: err.message || trans("js.admin.pages.parents_load_failed")
                    });
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setParentPagesLoading(false);
                }
            });

        return () => controller.abort();
    }, []);

    const actionOptions = useMemo(() => {
        const grouped = new Map<string, Array<{ value: string; label: string }>>();

        actions.forEach(item => {
            const options = grouped.get(item.module) || [];
            options.push({ value: item.action, label: `${trans(item.label)} (${item.action})` });
            grouped.set(item.module, options);
        });

        const data = Array.from(grouped, ([group, items]) => ({ group, items }));
        const currentAction = page?.action;

        if (currentAction && !actions.some(item => item.action === currentAction)) {
            data.unshift({
                group: trans("js.admin.unavailable"),
                items: [{ value: currentAction, label: `${currentAction} (${trans("js.admin.unknown_action")})` }]
            });
        }

        return data;
    }, [actions, page?.action]);

    const selectedAction = useMemo(
        () => actions.find(item => item.action === page?.action),
        [actions, page?.action]
    );

    const fixedFeedType = fixedPageActionValue(selectedAction, "feedType");
    const fixedListFeedType = fixedPageActionValue(selectedAction, "listFeedType");

    useEffect(() => {
        if (!selectedAction) {
            return;
        }

        setPage(current => {
            if (!current || current.action !== selectedAction.action) {
                return current;
            }

            const feedType = fixedFeedType ?? current.feedType;
            const listFeedType = fixedListFeedType ?? current.listFeedType;

            return feedType === current.feedType && listFeedType === current.listFeedType
                ? current
                : { ...current, feedType, listFeedType };
        });
    }, [fixedFeedType, fixedListFeedType, selectedAction]);

    const feedTypeOptions = useMemo(
        () => buildFeedTypeOptions(FEED_TYPE_LABELS, selectedAction, "feedType", page?.feedType || ""),
        [page?.feedType, selectedAction]
    );
    const listFeedTypeOptions = useMemo(
        () => buildFeedTypeOptions(FEED_TYPE_LABELS, selectedAction, "listFeedType", page?.listFeedType || ""),
        [page?.listFeedType, selectedAction]
    );

    const actionErrors = useMemo(() => {
        if (!page || !selectedAction) {
            return {};
        }

        return validatePageActionConfiguration(selectedAction, {
            feedId: page.feedId,
            feedType: page.feedType,
            listFeedType: page.listFeedType,
            termVocabulary: page.termVocabulary
        });
    }, [page, selectedAction]);

    const routeWarnings = useMemo(
        () => page ? routeConfigurationWarnings(page) : [],
        [page]
    );

    function isRequired(field: PageActionFieldName): boolean {
        return selectedAction?.fields[field].status === "required";
    }

    function actionFieldDescription(field: PageActionFieldName): string {
        return page?.action
            ? fieldDescription(selectedAction, field)
            : trans("js.admin.pages.select_action_help");
    }

    const forbiddenParentIds = useMemo(
        () => forbiddenPageParentIds(parentPages, page?.id),
        [page?.id, parentPages]
    );
    const parentError = page?.id !== 1 && page?.parentId === ""
        ? trans("js.admin.pages.parent_required")
        : page?.parentId !== "" && forbiddenParentIds.has(Number(page?.parentId))
            ? trans("js.admin.pages.parent_error")
            : undefined;

    const parentOptions = useMemo(() => {
        const options = parentPages
            .filter(item => !forbiddenParentIds.has(item.id))
            .map(item => {
                const details = [item.pageName, item.action, item.pattern]
                    .filter((value, index, values): value is string => Boolean(value) && values.indexOf(value) === index)
                    .join(" · ");

                return { value: String(item.id), label: `#${item.id} — ${details || trans("js.admin.root")}` };
            });
        const currentParent = page?.parentId;

        if (currentParent !== ""
            && !forbiddenParentIds.has(Number(currentParent))
            && !options.some(item => item.value === String(currentParent))) {
            options.unshift({
                value: String(currentParent),
                label: `#${currentParent} — ${trans("js.admin.unknown_page")}`
            });
        }

        return options;
    }, [forbiddenParentIds, page?.parentId, parentPages]);

    function update<K extends keyof EditablePage>(key: K, value: EditablePage[K]) {
        setPage(current => current ? { ...current, [key]: value } : current);
    }

    function save() {
        if (!page) {
            return;
        }

        if (!page.action) {
            notifications.show({ color: "red", title: trans("js.admin.pages.invalid"), message: trans("js.admin.pages.action_required") });
            return;
        }

        if (parentError) {
            notifications.show({ color: "red", title: trans("js.admin.pages.invalid_parent"), message: parentError });
            return;
        }

        if (Object.keys(actionErrors).length > 0) {
            notifications.show({
                color: "red",
                title: trans("js.admin.pages.invalid_action"),
                message: trans("js.admin.pages.review_action_fields")
            });
            return;
        }

        setSaving(true);

        fetch(isNew ? "/api/v1/admin/pages" : `/api/v1/admin/pages/${id}`, {
            method: isNew ? "POST" : "PATCH",
            headers: {
                "Content-Type": "application/json",
                ...csrfHeaders()
            },
            body: JSON.stringify({ ...page, settings: JSON.stringify(page.settings) })
        })
            .then(async response => {
                if (!response.ok) {
                    throw await getApiResponseError(response, trans("js.admin.pages.save_failed"));
                }

                return response.json();
            })
            .then(data => {
                const saved = normalizePage(data);
                setPage(saved);

                notifications.show({
                    color: "green",
                    message: trans("js.admin.pages.saved"),
                    autoClose: 2000
                });

                if (isNew && saved.id) {
                    navigate(`/pages/${saved.id}`, { replace: true });
                }
            })
            .catch(err => {
                notifications.show({
                    color: "red",
                    title: trans("js.admin.error"),
                    message: err.message || trans("js.admin.pages.save_failed")
                });
            })
            .finally(() => setSaving(false));
    }

    if (!page || loading) {
        return <Text>{trans("js.admin.loading")}</Text>;
    }

    return (
        <Stack>
            <Group justify="space-between">
                <Button variant="light" onClick={() => navigate("/pages")}>
                    {trans("js.admin.back")}
                </Button>

                <Button loading={saving} onClick={save}>
                    {trans("js.admin.save")}
                </Button>
            </Group>

            <SimpleGrid cols={{ base: 1, md: 2 }}>
                <TextInput
                    label={trans("js.admin.pattern")}
                    value={page.pattern}
                    onChange={event => update("pattern", event.currentTarget.value)}
                    disabled={page.id === 1}
                />
                <Select
                    label={trans("js.admin.action")}
                    required
                    searchable
                    data={actionOptions}
                    value={page.action}
                    onChange={value => update("action", value || "")}
                    disabled={actionsLoading}
                    nothingFoundMessage={trans("js.admin.pages.no_actions")}
                    allowDeselect={false}
                />
                <TextInput
                    label={trans("js.admin.page_name")}
                    value={page.pageName}
                    onChange={event => update("pageName", event.currentTarget.value)}
                />
                <Select
                    label={trans("js.admin.parent_id")}
                    error={parentError}
                    required={page.id !== 1}
                    searchable
                    data={parentOptions}
                    value={page.parentId === "" ? null : String(page.parentId)}
                    onChange={value => update("parentId", value === null ? "" : Number(value))}
                    disabled={parentPagesLoading || page.id === 1}
                    nothingFoundMessage={trans("js.admin.pages.no_parents")}
                    allowDeselect={false}
                />
                <Select
                    label={trans("js.admin.feed_type")}
                    description={actionFieldDescription("feedType")}
                    error={actionErrors.feedType}
                    required={isRequired("feedType")}
                    searchable
                    data={feedTypeOptions}
                    value={page.feedType || null}
                    onChange={value => update("feedType", value || "")}
                    disabled={isPageActionFieldDisabled(selectedAction, "feedType", page.feedType)
                        || page.feedType === fixedFeedType}
                    clearable={!fixedFeedType}
                    nothingFoundMessage={trans("js.admin.pages.no_feed_types")}
                />
                <Select
                    label={trans("js.admin.list_feed_type")}
                    description={actionFieldDescription("listFeedType")}
                    error={actionErrors.listFeedType}
                    required={isRequired("listFeedType")}
                    searchable
                    data={listFeedTypeOptions}
                    value={page.listFeedType || null}
                    onChange={value => update("listFeedType", value || "")}
                    disabled={isPageActionFieldDisabled(selectedAction, "listFeedType", page.listFeedType)
                        || page.listFeedType === fixedListFeedType}
                    clearable={!fixedListFeedType}
                    nothingFoundMessage={trans("js.admin.pages.no_feed_types")}
                />
                <TextInput
                    label={trans("js.admin.term_vocabulary")}
                    description={actionFieldDescription("termVocabulary")}
                    error={actionErrors.termVocabulary}
                    required={isRequired("termVocabulary")}
                    value={page.termVocabulary}
                    onChange={event => update("termVocabulary", event.currentTarget.value)}
                    disabled={isPageActionFieldDisabled(selectedAction, "termVocabulary", page.termVocabulary)}
                />
                <NumberInput
                    label={trans("js.admin.feed_id")}
                    description={actionFieldDescription("feedId")}
                    error={actionErrors.feedId}
                    required={isRequired("feedId")}
                    min={1}
                    value={page.feedId}
                    onChange={value => update("feedId", value === "" ? "" : Number(value))}
                    disabled={isPageActionFieldDisabled(selectedAction, "feedId", page.feedId)}
                />
                <Select
                    label={trans("js.admin.changefreq")}
                    data={CHANGEFREQ_OPTIONS}
                    value={page.changefreq}
                    onChange={value => update("changefreq", value || "")}
                    allowDeselect={false}
                />
                <Select
                    label={trans("js.admin.access")}
                    data={[
                        {value: "public", label: trans("js.admin.access_public")},
                        {value: "authenticated", label: trans("js.admin.access_authenticated")},
                        {value: "moderator", label: trans("js.admin.access_moderator")},
                        {value: "admin", label: trans("js.admin.access_admin")},
                    ]}
                    value={page.accessRule}
                    onChange={value => update("accessRule", value || "public")}
                    allowDeselect={false}
                />
            </SimpleGrid>

            {page.action && (
                <Text size="sm" c="dimmed">
                    {selectedAction
                        ? `${trans(selectedAction.label)} · ${selectedAction.module} · ${selectedAction.action}`
                        : `${page.action} · ${trans("js.admin.pages.unavailable_legacy_action")}`}
                </Text>
            )}

            {routeWarnings.map(warning => (
                <Text key={warning} size="sm" c="orange">
                    {warning}
                </Text>
            ))}

            <Stack gap="sm">
                <Text fw={500}>{trans("js.admin.page_settings")}</Text>
                {supportsFeedContentWidth(page.action) && (
                    <Select
                        label={trans("js.admin.feed_content_width_label")}
                        description={trans("js.admin.feed_content_width_description")}
                        data={[
                            { value: "contained", label: trans("js.admin.feed_content_width_contained") },
                            { value: "full", label: trans("js.admin.feed_content_width_full") },
                        ]}
                        value={feedContentWidth(page.settings)}
                        onChange={value => update("settings", {
                            ...page.settings,
                            feedContentWidth: (value || "contained") as FeedContentWidth
                        })}
                        allowDeselect={false}
                    />
                )}
                <Switch
                    label={trans("js.admin.comments_label")}
                    description={trans("js.admin.comments_description")}
                    onLabel={trans("js.admin.on")}
                    offLabel={trans("js.admin.off")}
                    checked={Boolean(page.settings.commentsEnabled)}
                    onChange={event => update("settings", {
                        ...page.settings,
                        commentsEnabled: event.currentTarget.checked
                    })}
                />
                <Switch
                    label={trans("js.admin.share_label")}
                    description={trans("js.admin.share_description")}
                    onLabel={trans("js.admin.on")}
                    offLabel={trans("js.admin.off")}
                    checked={Boolean(page.settings.shareButtons)}
                    onChange={event => update("settings", {
                        ...page.settings,
                        shareButtons: event.currentTarget.checked
                    })}
                />
            </Stack>
        </Stack>
    );
}

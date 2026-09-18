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
import { parsePageSettings, type PageSettings } from "../lib/page-settings";
import { csrfHeaders } from "../../shared/csrf";
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
    updated: number | "";
    accessRule: string;
};

type PageAction = {
    action: string;
    label: string;
    module: string;
};

type ParentPage = {
    id: number;
    pageName?: string | null;
    action?: string | null;
    pattern?: string | null;
};

const CHANGEFREQ_OPTIONS = [
    { value: "", label: "Default" },
    { value: "always", label: "always" },
    { value: "hourly", label: "hourly" },
    { value: "daily", label: "daily" },
    { value: "weekly", label: "weekly" },
    { value: "monthly", label: "monthly" },
    { value: "yearly", label: "yearly" },
    { value: "never", label: "never" },
    { value: "noindex", label: "noindex" }
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
        updated: Math.floor(Date.now() / 1000),
        accessRule: "public"
    };
}

function normalizePage(data: Partial<Omit<EditablePage, "settings">> & { settings?: string | null }): EditablePage {
    return {
        ...blankPage(),
        ...data,
        settings: parsePageSettings(data.settings),
        parentId: data.parentId ?? "",
        feedId: data.feedId ?? "",
        updated: data.updated ?? "",
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
                    throw new Error(await response.text() || "Failed to load page");
                }

                return response.json();
            })
            .then(data => setPage(normalizePage(data)))
            .catch(err => {
                notifications.show({
                    color: "red",
                    title: "Error",
                    message: err.message || "Failed to load page"
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
                    throw new Error(await response.text() || "Failed to load page actions");
                }

                return response.json();
            })
            .then(data => setActions(Array.isArray(data.data) ? data.data : []))
            .catch(err => {
                if (err.name !== "AbortError") {
                    notifications.show({
                        color: "red",
                        title: "Error",
                        message: err.message || "Failed to load page actions"
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
                    throw new Error(await response.text() || "Failed to load parent pages");
                }

                return response.json();
            })
            .then(data => setParentPages(Array.isArray(data.data) ? data.data : []))
            .catch(err => {
                if (err.name !== "AbortError") {
                    notifications.show({
                        color: "red",
                        title: "Error",
                        message: err.message || "Failed to load parent pages"
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
            options.push({ value: item.action, label: `${item.label} (${item.action})` });
            grouped.set(item.module, options);
        });

        const data = Array.from(grouped, ([group, items]) => ({ group, items }));
        const currentAction = page?.action;

        if (currentAction && !actions.some(item => item.action === currentAction)) {
            data.unshift({
                group: "Unavailable",
                items: [{ value: currentAction, label: `${currentAction} (unknown action)` }]
            });
        }

        return data;
    }, [actions, page?.action]);

    const feedTypeOptions = useMemo(() => {
        const options = Object.entries(FEED_TYPE_LABELS).map(([value, label]) => ({
            value,
            label: `${label} (${value})`
        }));
        const unknownTypes = [page?.feedType, page?.listFeedType]
            .filter((value): value is string => Boolean(value) && !(value in FEED_TYPE_LABELS))
            .filter((value, index, values) => values.indexOf(value) === index)
            .map(value => ({ value, label: `${value} (unknown type)` }));

        return [...unknownTypes, ...options];
    }, [page?.feedType, page?.listFeedType]);

    const parentOptions = useMemo(() => {
        const options = parentPages
            .filter(item => item.id !== page?.id)
            .map(item => {
                const details = [item.pageName, item.action, item.pattern]
                    .filter((value, index, values): value is string => Boolean(value) && values.indexOf(value) === index)
                    .join(" · ");

                return { value: String(item.id), label: `#${item.id} — ${details || "Root"}` };
            });
        const currentParent = page?.parentId;

        if (currentParent !== "" && !options.some(item => item.value === String(currentParent))) {
            options.unshift({
                value: String(currentParent),
                label: `#${currentParent} — unknown page`
            });
        }

        return options;
    }, [page?.id, page?.parentId, parentPages]);

    function update<K extends keyof EditablePage>(key: K, value: EditablePage[K]) {
        setPage(current => current ? { ...current, [key]: value } : current);
    }

    function save() {
        if (!page) {
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
                    throw new Error(await response.text() || "Save failed");
                }

                return response.json();
            })
            .then(data => {
                const saved = normalizePage(data);
                setPage(saved);

                notifications.show({
                    color: "green",
                    message: "Page saved",
                    autoClose: 2000
                });

                if (isNew && saved.id) {
                    navigate(`/pages/${saved.id}`, { replace: true });
                }
            })
            .catch(err => {
                notifications.show({
                    color: "red",
                    title: "Error",
                    message: err.message || "Failed to save page"
                });
            })
            .finally(() => setSaving(false));
    }

    if (!page || loading) {
        return <Text>Loading...</Text>;
    }

    return (
        <Stack>
            <Group justify="space-between">
                <Button variant="light" onClick={() => navigate("/pages")}>
                    Back
                </Button>

                <Button loading={saving} onClick={save}>
                    Save
                </Button>
            </Group>

            <SimpleGrid cols={{ base: 1, md: 2 }}>
                <TextInput
                    label="Pattern"
                    value={page.pattern}
                    onChange={event => update("pattern", event.currentTarget.value)}
                />
                <Select
                    label="Action"
                    required
                    searchable
                    data={actionOptions}
                    value={page.action}
                    onChange={value => update("action", value || "")}
                    disabled={actionsLoading}
                    nothingFoundMessage="No actions found"
                    allowDeselect={false}
                />
                <TextInput
                    label="Page name"
                    value={page.pageName}
                    onChange={event => update("pageName", event.currentTarget.value)}
                />
                <Select
                    label="Parent ID"
                    searchable
                    clearable
                    data={parentOptions}
                    value={page.parentId === "" ? null : String(page.parentId)}
                    onChange={value => update("parentId", value === null ? "" : Number(value))}
                    disabled={parentPagesLoading}
                    nothingFoundMessage="No parent pages found"
                />
                <Select
                    label="Feed type"
                    searchable
                    clearable
                    data={feedTypeOptions}
                    value={page.feedType || null}
                    onChange={value => update("feedType", value || "")}
                    nothingFoundMessage="No feed types found"
                />
                <Select
                    label="List feed type"
                    searchable
                    clearable
                    data={feedTypeOptions}
                    value={page.listFeedType || null}
                    onChange={value => update("listFeedType", value || "")}
                    nothingFoundMessage="No feed types found"
                />
                <TextInput
                    label="Term vocabulary"
                    value={page.termVocabulary}
                    onChange={event => update("termVocabulary", event.currentTarget.value)}
                />
                <NumberInput
                    label="Feed ID"
                    min={1}
                    value={page.feedId}
                    onChange={value => update("feedId", value === "" ? "" : Number(value))}
                />
                <Select
                    label="Changefreq"
                    data={CHANGEFREQ_OPTIONS}
                    value={page.changefreq}
                    onChange={value => update("changefreq", value || "")}
                    allowDeselect={false}
                />
                <NumberInput
                    label="Updated timestamp"
                    min={0}
                    value={page.updated}
                    onChange={value => update("updated", value === "" ? "" : Number(value))}
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

            <Stack gap="sm">
                <Text fw={500}>{trans("js.admin.page_settings")}</Text>
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

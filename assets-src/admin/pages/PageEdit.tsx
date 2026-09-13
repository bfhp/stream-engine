import { useEffect, useState } from "react";
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
                <TextInput
                    label="Action"
                    required
                    value={page.action}
                    onChange={event => update("action", event.currentTarget.value)}
                />
                <TextInput
                    label="Page name"
                    value={page.pageName}
                    onChange={event => update("pageName", event.currentTarget.value)}
                />
                <NumberInput
                    label="Parent ID"
                    min={1}
                    value={page.parentId}
                    onChange={value => update("parentId", value === "" ? "" : Number(value))}
                />
                <TextInput
                    label="Feed type"
                    value={page.feedType}
                    onChange={event => update("feedType", event.currentTarget.value)}
                />
                <TextInput
                    label="List feed type"
                    value={page.listFeedType}
                    onChange={event => update("listFeedType", event.currentTarget.value)}
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

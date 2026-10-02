import { useEffect, useMemo, useState } from "react";
import {
    ActionIcon,
    Alert,
    Button,
    ColorInput,
    Group,
    Select,
    SimpleGrid,
    Stack,
    Switch,
    Text,
    TextInput,
    Tooltip
} from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { IconDeviceFloppy, IconExternalLink, IconRefresh } from "@tabler/icons-react";
import { csrfHeaders } from "../../shared/csrf";
import { trans } from "../../shared/i18n";

type ThemeSetting = {
    type: "select" | "color" | "string" | "boolean";
    label: string;
    default: string;
    options: Record<string, string>;
};

type Theme = {
    id: string;
    name: string;
    settings: Record<string, ThemeSetting>;
    values: Record<string, string>;
};

type ThemePayload = {
    activeId: string;
    configuredId: string;
    fallback: boolean;
    themes: Theme[];
};

export default function Themes() {
    const [payload, setPayload] = useState<ThemePayload | null>(null);
    const [themeId, setThemeId] = useState("default");
    const [drafts, setDrafts] = useState<Record<string, string>>({});
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [refreshKey, setRefreshKey] = useState(0);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError(null);

        fetch("/api/v1/admin/themes", { signal: controller.signal })
            .then(async response => {
                if (!response.ok) {
                    throw new Error(await response.text() || trans("js.admin.themes.load_failed"));
                }
                return response.json();
            })
            .then((data: ThemePayload) => {
                setPayload(data);
                const selected = data.themes.find(theme => theme.id === data.activeId) ?? data.themes[0];
                if (selected) {
                    setThemeId(selected.id);
                    setDrafts(selected.values);
                }
            })
            .catch(err => {
                if (err.name !== "AbortError") {
                    setError(err.message || trans("js.admin.themes.load_failed"));
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [refreshKey]);

    const selectedTheme = payload?.themes.find(theme => theme.id === themeId) ?? null;
    const dirty = useMemo(() => selectedTheme !== null && (
        themeId !== payload?.activeId
        || Object.keys(selectedTheme.settings).some(key => drafts[key] !== selectedTheme.values[key])
    ), [drafts, payload, selectedTheme, themeId]);

    function selectTheme(id: string) {
        const theme = payload?.themes.find(item => item.id === id);
        if (theme) {
            setThemeId(id);
            setDrafts(theme.values);
        }
    }

    function saveTheme() {
        if (!dirty || !selectedTheme) {
            return;
        }

        setSaving(true);
        fetch("/api/v1/admin/themes", {
            method: "PATCH",
            headers: { "Content-Type": "application/json", ...csrfHeaders() },
            body: JSON.stringify({ themeId, settings: drafts })
        })
            .then(async response => {
                if (!response.ok) {
                    throw new Error(await response.text() || trans("js.admin.themes.save_failed"));
                }
                return response.json();
            })
            .then(() => {
                const savedTheme = { ...selectedTheme, values: { ...drafts } };
                setPayload(current => current ? {
                    ...current,
                    activeId: themeId,
                    configuredId: themeId,
                    fallback: false,
                    themes: current.themes.map(theme => theme.id === themeId ? savedTheme : theme)
                } : current);
                notifications.show({
                    color: "green",
                    message: trans("js.admin.themes.saved"),
                    autoClose: 2000
                });
            })
            .catch(err => notifications.show({
                color: "red",
                title: trans("js.admin.error"),
                message: err.message || trans("js.admin.themes.save_failed")
            }))
            .finally(() => setSaving(false));
    }

    return (
        <Stack>
            <Group justify="space-between" align="end">
                <div>
                    <Text fw={700} size="xl">{trans("js.admin.themes.title")}</Text>
                    <Text c="dimmed" size="sm">{trans("js.admin.themes.help")}</Text>
                </div>
                <Group>
                    <Tooltip label={trans("js.admin.refresh")}>
                        <ActionIcon variant="default" loading={loading} onClick={() => setRefreshKey(key => key + 1)}>
                            <IconRefresh size={18} />
                        </ActionIcon>
                    </Tooltip>
                    <Button
                        loading={saving}
                        disabled={!dirty}
                        leftSection={<IconDeviceFloppy size={16} />}
                        onClick={saveTheme}
                    >
                        {trans("js.admin.save")}
                    </Button>
                </Group>
            </Group>

            {error && <Text c="red">{error}</Text>}
            {payload?.fallback && <Alert color="yellow">{trans("js.admin.themes.fallback")}</Alert>}

            <Group align="end">
                <Select
                    label={trans("js.admin.themes.theme")}
                    data={(payload?.themes ?? []).map(theme => ({ value: theme.id, label: theme.name }))}
                    value={themeId}
                    onChange={value => value && selectTheme(value)}
                    allowDeselect={false}
                    flex={1}
                />
                <Button
                    component="a"
                    href={`/?theme_preview=${encodeURIComponent(themeId)}`}
                    target="_blank"
                    rel="noopener noreferrer"
                    variant="default"
                    leftSection={<IconExternalLink size={16} />}
                >
                    {trans("js.admin.themes.preview")}
                </Button>
            </Group>

            {selectedTheme && (
                <SimpleGrid cols={{ base: 1, md: 2 }}>
                    {Object.entries(selectedTheme.settings).map(([key, definition]) => {
                        if (definition.type === "select") {
                            return <Select
                                key={key}
                                label={definition.label}
                                data={Object.entries(definition.options).map(([value, label]) => ({ value, label }))}
                                value={drafts[key] ?? definition.default}
                                onChange={value => setDrafts(current => ({
                                    ...current,
                                    [key]: value ?? definition.default
                                }))}
                                allowDeselect={false}
                            />;
                        }
                        if (definition.type === "color") {
                            return <ColorInput
                                key={key}
                                label={definition.label}
                                value={drafts[key] ?? definition.default}
                                onChange={value => setDrafts(current => ({ ...current, [key]: value }))}
                            />;
                        }
                        if (definition.type === "boolean") {
                            return <Switch
                                key={key}
                                label={definition.label}
                                checked={(drafts[key] ?? definition.default) === "1"}
                                onChange={event => setDrafts(current => ({
                                    ...current,
                                    [key]: event.currentTarget.checked ? "1" : "0"
                                }))}
                            />;
                        }
                        return <TextInput
                            key={key}
                            label={definition.label}
                            value={drafts[key] ?? definition.default}
                            onChange={event => setDrafts(current => ({
                                ...current,
                                [key]: event.currentTarget.value
                            }))}
                        />;
                    })}
                </SimpleGrid>
            )}
        </Stack>
    );
}

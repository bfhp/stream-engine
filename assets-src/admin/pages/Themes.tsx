import { useEffect, useMemo, useState } from "react";
import {
    ActionIcon,
    Alert,
    Badge,
    Box,
    Button,
    Card,
    ColorInput,
    Group,
    Select,
    SimpleGrid,
    Stack,
    Switch,
    Text,
    TextInput,
    Tooltip,
    UnstyledButton
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
    version: string;
    themeColor: string;
    settings: Record<string, ThemeSetting>;
    values: Record<string, string>;
};

function ThemePreview({ theme, colorMode }: { theme: Theme; colorMode: string }) {
    const dark = colorMode !== "light";
    const background = dark ? "#0b1220" : "#eef2f7";
    const surface = dark ? "#172033" : "#ffffff";
    const muted = dark ? "#526079" : "#c8d0dc";
    const text = dark ? "#e8edf5" : "#263246";

    return (
        <Box h={178} bg={background} p="sm" style={{ overflow: "hidden" }}>
            <Group gap={5} mb="sm">
                {["#ff6b6b", "#ffd43b", "#51cf66"].map(color => (
                    <Box key={color} w={7} h={7} bg={color} style={{ borderRadius: "50%" }} />
                ))}
                <Box h={7} ms={5} flex={1} bg={muted} opacity={0.45} style={{ borderRadius: 4 }} />
            </Group>
            <Box bg={surface} p="xs" mb="xs" style={{ borderRadius: 6 }}>
                <Group justify="space-between">
                    <Box w="28%" h={7} bg={theme.themeColor} style={{ borderRadius: 4 }} />
                    <Group gap={6}>
                        <Box w={24} h={5} bg={muted} style={{ borderRadius: 4 }} />
                        <Box w={24} h={5} bg={muted} style={{ borderRadius: 4 }} />
                        <Box w={16} h={16} bg={muted} style={{ borderRadius: "50%" }} />
                    </Group>
                </Group>
            </Box>
            <Box bg={surface} p="sm" mb="xs" style={{ borderRadius: 6 }}>
                <Box w="45%" h={9} bg={text} opacity={0.9} mb={7} style={{ borderRadius: 4 }} />
                <Box w="82%" h={5} bg={muted} mb={5} style={{ borderRadius: 4 }} />
                <Box w="68%" h={5} bg={muted} style={{ borderRadius: 4 }} />
            </Box>
            <Group gap="xs" align="stretch">
                {[0, 1, 2].map(item => (
                    <Box key={item} bg={surface} p="xs" flex={1} style={{ borderRadius: 6 }}>
                        <Box h={24} bg={muted} opacity={0.45} mb={6} style={{ borderRadius: 4 }} />
                        <Box w="75%" h={5} bg={text} opacity={0.7} style={{ borderRadius: 4 }} />
                    </Box>
                ))}
            </Group>
        </Box>
    );
}

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

            <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }}>
                {(payload?.themes ?? []).map(theme => {
                    const selected = theme.id === themeId;
                    const colorMode = selected
                        ? (drafts.color_mode ?? theme.values.color_mode ?? "dark")
                        : (theme.values.color_mode ?? "dark");

                    return (
                        <Card
                            key={theme.id}
                            withBorder
                            padding={0}
                            shadow={selected ? "md" : "xs"}
                            style={{
                                borderColor: selected ? "var(--mantine-color-blue-6)" : undefined,
                                borderWidth: selected ? 2 : 1,
                                overflow: "hidden"
                            }}
                        >
                            <UnstyledButton
                                aria-pressed={selected}
                                aria-label={trans("js.admin.themes.select", { name: theme.name })}
                                onClick={() => selectTheme(theme.id)}
                                style={{ display: "block", width: "100%", textAlign: "start" }}
                            >
                                <ThemePreview theme={theme} colorMode={colorMode} />
                                <Box p="md">
                                    <Group justify="space-between" wrap="nowrap">
                                        <Text fw={600} truncate>{theme.name}</Text>
                                        {theme.id === payload?.activeId && (
                                            <Badge color="green" variant="light">
                                                {trans("js.admin.themes.active")}
                                            </Badge>
                                        )}
                                    </Group>
                                    <Text c="dimmed" size="xs" mt={3}>
                                        {theme.id} · v{theme.version}
                                    </Text>
                                </Box>
                            </UnstyledButton>
                            <Group justify="flex-end" px="md" pb="md">
                                <Button
                                    component="a"
                                    href={`/?theme_preview=${encodeURIComponent(theme.id)}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    variant="subtle"
                                    size="compact-sm"
                                    leftSection={<IconExternalLink size={14} />}
                                >
                                    {trans("js.admin.themes.preview")}
                                </Button>
                            </Group>
                        </Card>
                    );
                })}
            </SimpleGrid>

            {selectedTheme && (
                <Card withBorder>
                    <Text fw={600} mb="md">
                        {trans("js.admin.themes.configure", { name: selectedTheme.name })}
                    </Text>
                    {Object.keys(selectedTheme.settings).length === 0 ? (
                        <Text c="dimmed" size="sm">{trans("js.admin.themes.no_settings")}</Text>
                    ) : <SimpleGrid cols={{ base: 1, md: 2 }}>
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
                    </SimpleGrid>}
                </Card>
            )}
        </Stack>
    );
}

import { useEffect, useMemo, useState } from "react";
import {
    ActionIcon,
    Button,
    Group,
    NumberInput,
    Select,
    SimpleGrid,
    Stack,
    Text,
    TextInput,
    Tooltip
} from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { IconDeviceFloppy, IconRefresh } from "@tabler/icons-react";
import { csrfHeaders } from "../../shared/csrf";
import { formatTimestamp } from "../lib/format";
import { trans } from "../../shared/i18n";

type Setting = {
    key: string;
    value: string;
    updatedAt: number;
};

type EditableKey = "site_name" | "locale" | "uploads.user_limit_mb";
type SettingsMap = Partial<Record<EditableKey, Setting>>;
type Drafts = Record<EditableKey, string>;

const EDITABLE_KEYS: EditableKey[] = ["site_name", "locale", "uploads.user_limit_mb"];
const LOCALE_OPTIONS = [
    { value: "en", label: trans("js.admin.locale_en") },
    { value: "de", label: trans("js.admin.locale_de") },
    { value: "fr", label: trans("js.admin.locale_fr") },
    { value: "ru", label: trans("js.admin.locale_ru") },
    { value: "es", label: trans("js.admin.locale_es") },
    { value: "pt-br", label: trans("js.admin.locale_pt_br") },
    { value: "ar", label: trans("js.admin.locale_ar") },
    { value: "it", label: trans("js.admin.locale_it") },
    { value: "ja", label: trans("js.admin.locale_ja") }
];

function defaultValue(key: EditableKey): string {
    if (key === "locale") {
        return "ru";
    }

    if (key === "uploads.user_limit_mb") {
        return "500";
    }

    return "";
}

function indexedSettings(items: Setting[]): SettingsMap {
    const allowed = new Set<string>(EDITABLE_KEYS);

    return Object.fromEntries(
        items
            .filter(setting => allowed.has(setting.key))
            .map(setting => [setting.key, setting])
    ) as SettingsMap;
}

export default function Settings() {
    const [settings, setSettings] = useState<SettingsMap>({});
    const [drafts, setDrafts] = useState<Drafts>({
        site_name: "",
        locale: "ru",
        "uploads.user_limit_mb": "500"
    });
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [refreshKey, setRefreshKey] = useState(0);

    useEffect(() => {
        const controller = new AbortController();

        setLoading(true);
        setError(null);

        fetch("/api/v1/admin/settings", { signal: controller.signal })
            .then(async response => {
                if (!response.ok) {
                    throw new Error(await response.text() || trans("js.admin.settings.load_failed"));
                }

                return response.json();
            })
            .then(data => {
                const items = Array.isArray(data.data) ? data.data : [];
                const nextSettings = indexedSettings(items);

                setSettings(nextSettings);
                setDrafts({
                    site_name: nextSettings.site_name?.value ?? defaultValue("site_name"),
                    locale: nextSettings.locale?.value ?? defaultValue("locale"),
                    "uploads.user_limit_mb": nextSettings["uploads.user_limit_mb"]?.value
                        ?? defaultValue("uploads.user_limit_mb")
                });
            })
            .catch(err => {
                if (err.name !== "AbortError") {
                    setError(err.message || trans("js.admin.settings.load_failed"));
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [refreshKey]);

    const dirtyKeys = useMemo(
        () => EDITABLE_KEYS.filter(key => drafts[key] !== (settings[key]?.value ?? defaultValue(key))),
        [drafts, settings]
    );

    const lastUpdated = useMemo(() => {
        const timestamps = EDITABLE_KEYS
            .map(key => settings[key]?.updatedAt ?? null)
            .filter((timestamp): timestamp is number => timestamp !== null);

        return timestamps.length > 0 ? Math.max(...timestamps) : null;
    }, [settings]);

    function updateDraft(key: EditableKey, value: string) {
        setDrafts(current => ({ ...current, [key]: value }));
    }

    function saveSettings() {
        if (dirtyKeys.length === 0) {
            return;
        }

        setSaving(true);
        const localeChanged = dirtyKeys.includes("locale");

        Promise.all(dirtyKeys.map(key =>
            fetch(`/api/v1/admin/settings/${encodeURIComponent(key)}`, {
                method: "PATCH",
                headers: {
                    "Content-Type": "application/json",
                    ...csrfHeaders()
                },
                body: JSON.stringify({ value: drafts[key] })
            }).then(async response => {
                if (!response.ok) {
                    throw new Error(await response.text() || trans("js.admin.save_failed"));
                }

                return response.json();
            })
        ))
            .then(savedItems => {
                setSettings(current => ({
                    ...current,
                    ...Object.fromEntries(savedItems.map((setting: Setting) => [setting.key, setting]))
                }));

                notifications.show({
                    color: "green",
                    message: trans("js.admin.settings.saved"),
                    autoClose: 2000
                });
                if (localeChanged) {
                    window.location.reload();
                }
            })
            .catch(err => {
                notifications.show({
                    color: "red",
                    title: trans("js.admin.error"),
                    message: err.message || trans("js.admin.settings.save_failed")
                });
            })
            .finally(() => setSaving(false));

    }

    return (
        <Stack>
            <Group justify="space-between" align="end">
                <div>
                    <Text fw={700} size="xl">{trans("js.admin.settings.title")}</Text>
                    <Text c="dimmed" size="sm">
                        {trans("js.admin.updated")}: {formatTimestamp(lastUpdated)}
                    </Text>
                </div>

                <Group>
                    <Tooltip label={trans("js.admin.refresh")}>
                        <ActionIcon
                            variant="default"
                            loading={loading}
                            onClick={() => setRefreshKey(key => key + 1)}
                        >
                            <IconRefresh size={18} />
                        </ActionIcon>
                    </Tooltip>

                    <Button
                        loading={saving}
                        disabled={dirtyKeys.length === 0}
                        leftSection={<IconDeviceFloppy size={16} />}
                        onClick={saveSettings}
                    >
                        {trans("js.admin.save")}
                    </Button>
                </Group>
            </Group>

            {error && <Text c="red">{error}</Text>}

            <SimpleGrid cols={{ base: 1, md: 2 }}>
                <TextInput
                    label={trans("js.admin.settings.site_name")}
                    description={trans("js.admin.settings.key", { key: "site_name" })}
                    placeholder="Stream Engine"
                    value={drafts.site_name}
                    onChange={event => updateDraft("site_name", event.currentTarget.value)}
                />

                <Select
                    label={trans("js.admin.settings.locale")}
                    description={trans("js.admin.settings.key", { key: "locale" })}
                    data={LOCALE_OPTIONS}
                    value={drafts.locale}
                    onChange={value => updateDraft("locale", value || defaultValue("locale"))}
                    allowDeselect={false}
                />

                <NumberInput
                    label={trans("js.admin.settings.upload_limit")}
                    description={trans("js.admin.settings.key", { key: "uploads.user_limit_mb" })}
                    suffix=" MB"
                    min={1}
                    step={50}
                    allowDecimal={false}
                    value={drafts["uploads.user_limit_mb"]}
                    onChange={value => updateDraft(
                        "uploads.user_limit_mb",
                        String(value || defaultValue("uploads.user_limit_mb"))
                    )}
                />
            </SimpleGrid>

        </Stack>
    );
}

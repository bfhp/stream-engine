import { useEffect, useMemo, useState } from "react";
import {
    ActionIcon,
    Alert,
    Button,
    FileInput,
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
import { IconDeviceFloppy, IconPhoto, IconRefresh, IconRestore } from "@tabler/icons-react";
import { csrfHeaders } from "../../shared/csrf";
import { formatTimestamp } from "../lib/format";
import { getAvailableLocales, getLocale, trans } from "../../shared/i18n";

type Setting = {
    key: string;
    value: string;
    updatedAt: number;
};

type EditableKey = "site_name" | "locale" | "date_format" | "time_format" | "uploads.user_limit_mb";
type SettingsMap = Partial<Record<EditableKey, Setting>>;
type Drafts = Record<EditableKey, string>;
type SiteIcon = {
    customized: boolean;
    converterAvailable: boolean;
    svg: string | null;
    ico: string;
    apple: string;
};

const EDITABLE_KEYS: EditableKey[] = ["site_name", "locale", "date_format", "time_format", "uploads.user_limit_mb"];
const displayNames = new Intl.DisplayNames([getLocale()], { type: "language" });
const localeCollator = new Intl.Collator(getLocale());
const LOCALE_OPTIONS = getAvailableLocales()
    .map(locale => ({
        value: locale,
        label: `${displayNames.of(locale) || locale} (${locale})`
    }))
    .sort((left, right) => localeCollator.compare(left.label, right.label));
const DATE_FORMAT_OPTIONS = [
    { value: "auto", label: trans("js.admin.settings.format_auto") },
    { value: "dmy", label: trans("js.admin.settings.date_dmy") },
    { value: "mdy", label: trans("js.admin.settings.date_mdy") },
    { value: "iso", label: trans("js.admin.settings.date_iso") },
];
const TIME_FORMAT_OPTIONS = [
    { value: "auto", label: trans("js.admin.settings.format_auto") },
    { value: "24h", label: trans("js.admin.settings.time_24h") },
    { value: "12h", label: trans("js.admin.settings.time_12h") },
];

function defaultValue(key: EditableKey): string {
    if (key === "locale") {
        return "ru";
    }

    if (key === "date_format" || key === "time_format") {
        return "auto";
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
        date_format: "auto",
        time_format: "auto",
        "uploads.user_limit_mb": "500"
    });
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [refreshKey, setRefreshKey] = useState(0);
    const [siteIcon, setSiteIcon] = useState<SiteIcon>({
        customized: false,
        converterAvailable: false,
        svg: "/favicon.svg",
        ico: "/favicon.ico",
        apple: "/apple-touch-icon.png"
    });
    const [siteIconFile, setSiteIconFile] = useState<File | null>(null);
    const [resettingSiteIcon, setResettingSiteIcon] = useState(false);

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
                if (data.siteIcon) {
                    setSiteIcon(data.siteIcon);
                }

                setSettings(nextSettings);
                setDrafts({
                    site_name: nextSettings.site_name?.value ?? defaultValue("site_name"),
                    locale: nextSettings.locale?.value ?? defaultValue("locale"),
                    date_format: nextSettings.date_format?.value ?? defaultValue("date_format"),
                    time_format: nextSettings.time_format?.value ?? defaultValue("time_format"),
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
        if (dirtyKeys.length === 0 && siteIconFile === null) {
            return;
        }

        setSaving(true);
        const displayFormatChanged = dirtyKeys.some(key =>
            key === "locale" || key === "date_format" || key === "time_format"
        );

        const settingRequests = dirtyKeys.map(key =>
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
        );
        const iconRequest = siteIconFile === null
            ? Promise.resolve<SiteIcon | null>(null)
            : (() => {
                const body = new FormData();
                body.append("file", siteIconFile);

                return fetch("/api/v1/admin/settings/site-icon", {
                    method: "POST",
                    headers: csrfHeaders(),
                    body
                }).then(async response => {
                    if (!response.ok) {
                        throw new Error(await response.text() || trans("js.admin.settings.site_icon_save_failed"));
                    }

                    return response.json() as Promise<SiteIcon>;
                });
            })();

        Promise.all([Promise.all(settingRequests), iconRequest])
            .then(([savedItems, savedIcon]) => {
                setSettings(current => ({
                    ...current,
                    ...Object.fromEntries(savedItems.map((setting: Setting) => [setting.key, setting]))
                }));
                if (savedIcon) {
                    setSiteIcon(savedIcon);
                    setSiteIconFile(null);
                }

                notifications.show({
                    color: "green",
                    message: trans("js.admin.settings.saved"),
                    autoClose: 2000
                });
                if (displayFormatChanged || savedIcon) {
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

    function resetSiteIcon() {
        setResettingSiteIcon(true);
        fetch("/api/v1/admin/settings/site-icon", {
            method: "DELETE",
            headers: csrfHeaders()
        })
            .then(async response => {
                if (!response.ok) {
                    throw new Error(await response.text() || trans("js.admin.settings.site_icon_reset_failed"));
                }

                return response.json();
            })
            .then(icon => {
                setSiteIcon(icon);
                setSiteIconFile(null);
                notifications.show({
                    color: "green",
                    message: trans("js.admin.settings.site_icon_reset"),
                    autoClose: 2000
                });
                window.location.reload();
            })
            .catch(err => notifications.show({
                color: "red",
                title: trans("js.admin.error"),
                message: err.message || trans("js.admin.settings.site_icon_reset_failed")
            }))
            .finally(() => setResettingSiteIcon(false));
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
                        disabled={dirtyKeys.length === 0 && siteIconFile === null}
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

                <Select
                    label={trans("js.admin.settings.date_format")}
                    description={trans("js.admin.settings.key", { key: "date_format" })}
                    data={DATE_FORMAT_OPTIONS}
                    value={drafts.date_format}
                    onChange={value => updateDraft("date_format", value || defaultValue("date_format"))}
                    allowDeselect={false}
                />

                <Select
                    label={trans("js.admin.settings.time_format")}
                    description={trans("js.admin.settings.key", { key: "time_format" })}
                    data={TIME_FORMAT_OPTIONS}
                    value={drafts.time_format}
                    onChange={value => updateDraft("time_format", value || defaultValue("time_format"))}
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

            <Stack gap="xs">
                <Text fw={500}>{trans("js.admin.settings.site_icon")}</Text>
                {!siteIcon.converterAvailable && (
                    <Alert color="yellow" icon={<IconPhoto size={18} />}>
                        {trans("js.admin.settings.site_icon_no_converter")}
                    </Alert>
                )}
                <Group align="end" wrap="wrap">
                    <img
                        src={siteIcon.svg || siteIcon.apple}
                        alt=""
                        width={48}
                        height={48}
                        style={{ objectFit: "contain" }}
                    />
                    <FileInput
                        label={trans("js.admin.settings.site_icon_file")}
                        description={siteIcon.converterAvailable
                            ? trans("js.admin.settings.site_icon_formats_svg")
                            : trans("js.admin.settings.site_icon_formats_raster")}
                        accept={siteIcon.converterAvailable
                            ? "image/svg+xml,image/png,image/jpeg,image/webp,image/gif"
                            : "image/png,image/jpeg,image/webp,image/gif"}
                        value={siteIconFile}
                        onChange={setSiteIconFile}
                        clearable
                        w={360}
                    />
                    <Button
                        variant="default"
                        leftSection={<IconRestore size={16} />}
                        loading={resettingSiteIcon}
                        disabled={!siteIcon.customized && siteIconFile === null}
                        onClick={resetSiteIcon}
                    >
                        {trans("js.admin.settings.site_icon_default")}
                    </Button>
                </Group>
            </Stack>

        </Stack>
    );
}

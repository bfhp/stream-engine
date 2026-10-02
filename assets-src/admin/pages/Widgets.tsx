import { useEffect, useMemo, useState } from "react";
import Editor from "@monaco-editor/react";
import {
    ActionIcon,
    Button,
    Group,
    Select,
    Stack,
    Text,
    Tooltip,
    useComputedColorScheme
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

type Placement = "after_header" | "before_content" | "after_content" | "sidebar_top" | "sidebar_bottom" | "footer_legal" | "footer_contacts";
type WidgetMap = Partial<Record<Placement, Setting>>;
type WidgetDrafts = Record<Placement, string>;

const WIDGETS: Array<{ placement: Placement; label: string; description: string }> = [
    {
        placement: "after_header",
        label: trans("js.admin.widgets.after_header"),
        description: trans("js.admin.widgets.after_header_help")
    },
    {
        placement: "before_content",
        label: trans("js.admin.widgets.before_content"),
        description: trans("js.admin.widgets.before_content_help")
    },
    {
        placement: "after_content",
        label: trans("js.admin.widgets.after_content"),
        description: trans("js.admin.widgets.after_content_help")
    },
    {
        placement: "sidebar_top",
        label: trans("js.admin.widgets.sidebar_top"),
        description: trans("js.admin.widgets.sidebar_top_help")
    },
    {
        placement: "sidebar_bottom",
        label: trans("js.admin.widgets.sidebar_bottom"),
        description: trans("js.admin.widgets.sidebar_bottom_help")
    },
    {
        placement: "footer_legal",
        label: trans("js.admin.widgets.footer_legal"),
        description: trans("js.admin.widgets.footer_legal_help")
    },
    {
        placement: "footer_contacts",
        label: trans("js.admin.widgets.footer_contacts"),
        description: trans("js.admin.widgets.footer_contacts_help")
    }
];

const DEFAULT_DRAFTS: WidgetDrafts = {
    after_header: "",
    before_content: "",
    after_content: "",
    sidebar_top: "",
    sidebar_bottom: "",
    footer_legal: "",
    footer_contacts: ""
};

function settingKey(placement: Placement): string {
    return `widgets.${placement}`;
}

function indexWidgets(items: Setting[]): WidgetMap {
    const placements = new Set(WIDGETS.map(widget => settingKey(widget.placement)));

    return Object.fromEntries(
        items
            .filter(setting => placements.has(setting.key))
            .map(setting => [setting.key.replace(/^widgets\./, ""), setting])
    ) as WidgetMap;
}

export default function Widgets() {
    const colorScheme = useComputedColorScheme("light");
    const [widgets, setWidgets] = useState<WidgetMap>({});
    const [drafts, setDrafts] = useState<WidgetDrafts>(DEFAULT_DRAFTS);
    const [placement, setPlacement] = useState<Placement>("after_header");
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
                    throw new Error(await response.text() || trans("js.admin.widgets.load_failed"));
                }

                return response.json();
            })
            .then(data => {
                const items = Array.isArray(data.data) ? data.data : [];
                const nextWidgets = indexWidgets(items);

                setWidgets(nextWidgets);
                setDrafts({
                    ...DEFAULT_DRAFTS,
                    ...Object.fromEntries(
                        WIDGETS.map(widget => [
                            widget.placement,
                            nextWidgets[widget.placement]?.value ?? ""
                        ])
                    )
                });
            })
            .catch(err => {
                if (err.name !== "AbortError") {
                    setError(err.message || trans("js.admin.widgets.load_failed"));
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [refreshKey]);

    const currentWidget = WIDGETS.find(widget => widget.placement === placement) ?? WIDGETS[0];
    const currentValue = drafts[placement];
    const savedValue = widgets[placement]?.value ?? "";
    const dirty = currentValue !== savedValue;
    const updatedAt = widgets[placement]?.updatedAt ?? null;

    const placementOptions = useMemo(
        () => WIDGETS.map(widget => ({
            value: widget.placement,
            label: widget.label
        })),
        []
    );

    function saveWidget() {
        setSaving(true);

        fetch(`/api/v1/admin/settings/${encodeURIComponent(settingKey(placement))}`, {
            method: "PATCH",
            headers: {
                "Content-Type": "application/json",
                ...csrfHeaders()
            },
            body: JSON.stringify({ value: currentValue })
        })
            .then(async response => {
                if (!response.ok) {
                    throw new Error(await response.text() || trans("js.admin.save_failed"));
                }

                return response.json();
            })
            .then((setting: Setting) => {
                setWidgets(current => ({
                    ...current,
                    [placement]: setting
                }));

                notifications.show({
                    color: "green",
                    message: trans("js.admin.widgets.saved"),
                    autoClose: 2000
                });
            })
            .catch(err => {
                notifications.show({
                    color: "red",
                    title: trans("js.admin.error"),
                    message: err.message || trans("js.admin.widgets.save_failed")
                });
            })
            .finally(() => setSaving(false));
    }

    return (
        <Stack>
            <Group justify="space-between" align="end">
                <div>
                    <Text fw={700} size="xl">{trans("js.admin.widgets.title")}</Text>
                    <Text c="dimmed" size="sm">
                        {settingKey(placement)} · {trans("js.admin.updated")}: {formatTimestamp(updatedAt)}
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
                        disabled={!dirty}
                        leftSection={<IconDeviceFloppy size={16} />}
                        onClick={saveWidget}
                    >
                        {trans("js.admin.save")}
                    </Button>
                </Group>
            </Group>

            {error && <Text c="red">{error}</Text>}

            <Select
                label={trans("js.admin.widgets.placement")}
                description={currentWidget.description}
                data={placementOptions}
                value={placement}
                onChange={value => setPlacement((value || "after_header") as Placement)}
                allowDeselect={false}
            />

            <div>
                <Text fw={500} mb={6}>HTML</Text>
                <Editor
                    height="520px"
                    theme={colorScheme === "dark" ? "vs-dark" : "light"}
                    defaultLanguage="html"
                    value={currentValue}
                    onChange={value => setDrafts(current => ({
                        ...current,
                        [placement]: value || ""
                    }))}
                    options={{
                        fontSize: 14,
                        minimap: { enabled: false },
                        wordWrap: "on",
                        automaticLayout: true
                    }}
                />
            </div>
        </Stack>
    );
}

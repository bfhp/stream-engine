import { useEffect, useMemo, useState } from "react";
import {
    ActionIcon, Alert, Button, Card, Checkbox, Grid, Group, Modal, Select, SimpleGrid,
    Skeleton, Stack, Text, Tooltip
} from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { IconArrowDown, IconArrowUp, IconRefresh, IconSettings } from "@tabler/icons-react";
import { csrfHeaders } from "../../shared/csrf";
import {
    dashboardGridSpan, moveLayoutItem, orderedLayout, type DashboardSize, type LayoutItem
} from "../lib/dashboard";

type CardDefinition = {
    id: string; label: string; kind: "metrics" | "links"; sizes: DashboardSize[];
    defaultSize: DashboardSize; defaultPosition: number; module: string;
};
type CardResult = { id: string; status: "ready" | "empty" | "error" | "unavailable"; data: unknown };
type DashboardPayload = {
    version: number; catalog: CardDefinition[];
    layout: { version: number; items: LayoutItem[] }; cards: CardResult[];
};
type Metric = { label: string; value: string | number };
type Shortcut = { label: string; href: string };

async function requestError(response: Response, fallback: string): Promise<Error> {
    const text = await response.text();
    try {
        return new Error((JSON.parse(text) as { error?: string }).error || fallback);
    } catch (_error) {
        return new Error(text || fallback);
    }
}

export default function Dashboard() {
    const [payload, setPayload] = useState<DashboardPayload | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [refreshKey, setRefreshKey] = useState(0);
    const [customizing, setCustomizing] = useState(false);
    const [draft, setDraft] = useState<LayoutItem[]>([]);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError(null);
        fetch("/api/v1/admin/dashboard", { signal: controller.signal })
            .then(async response => {
                if (!response.ok) throw await requestError(response, "Failed to load dashboard");
                return response.json() as Promise<DashboardPayload>;
            })
            .then(setPayload)
            .catch(fetchError => {
                if (fetchError.name !== "AbortError") setError(fetchError.message || "Failed to load dashboard");
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [refreshKey]);

    const definitions = useMemo(
        () => new Map((payload?.catalog || []).map(card => [card.id, card])),
        [payload]
    );
    const results = useMemo(
        () => new Map((payload?.cards || []).map(card => [card.id, card])),
        [payload]
    );
    const customizerCards = useMemo(() => {
        const catalog = payload?.catalog || [];
        const byId = new Map(catalog.map(card => [card.id, card]));
        const selected = orderedLayout(draft)
            .map(item => byId.get(item.id))
            .filter((card): card is CardDefinition => Boolean(card));
        const selectedIds = new Set(selected.map(card => card.id));

        return [...selected, ...catalog.filter(card => !selectedIds.has(card.id))];
    }, [draft, payload]);

    function openCustomizer() {
        setDraft(orderedLayout(payload?.layout.items || []));
        setCustomizing(true);
    }

    function toggleCard(card: CardDefinition, checked: boolean) {
        setDraft(current => checked
            ? orderedLayout([...current, { id: card.id, size: card.defaultSize, position: current.length * 10 }])
            : orderedLayout(current.filter(item => item.id !== card.id)));
    }

    function saveLayout() {
        if (!payload) return;
        setSaving(true);
        fetch("/api/v1/admin/dashboard", {
            method: "PATCH",
            headers: { "Content-Type": "application/json", ...csrfHeaders() },
            body: JSON.stringify({ version: payload.version, items: orderedLayout(draft) })
        })
            .then(async response => {
                if (!response.ok) throw await requestError(response, "Failed to save dashboard");
                return response.json() as Promise<DashboardPayload>;
            })
            .then(next => {
                setPayload(next);
                setCustomizing(false);
                notifications.show({ color: "green", message: "Dashboard layout saved", autoClose: 2000 });
            })
            .catch(saveError => notifications.show({ color: "red", title: "Layout was not saved", message: saveError.message }))
            .finally(() => setSaving(false));
    }

    function resetLayout() {
        setSaving(true);
        fetch("/api/v1/admin/dashboard", { method: "DELETE", headers: csrfHeaders() })
            .then(async response => {
                if (!response.ok) throw await requestError(response, "Failed to reset dashboard");
                return response.json() as Promise<DashboardPayload>;
            })
            .then(next => {
                setPayload(next);
                setDraft(next.layout.items);
                setCustomizing(false);
                notifications.show({ color: "green", message: "Default layout restored", autoClose: 2000 });
            })
            .catch(resetError => notifications.show({ color: "red", title: "Layout was not reset", message: resetError.message }))
            .finally(() => setSaving(false));
    }

    function renderContent(definition: CardDefinition, result: CardResult | undefined) {
        if (!result) return <Skeleton height={70} />;
        if (result.status === "error") return <Alert color="red">This card could not be loaded.</Alert>;
        if (result.status === "unavailable") return <Text c="dimmed">This card is currently unavailable.</Text>;
        if (result.status === "empty") return <Text c="dimmed">No data yet.</Text>;
        if (definition.kind === "metrics") {
            const metrics = Array.isArray(result.data) ? result.data as Metric[] : [];
            return <SimpleGrid cols={{ base: 1, sm: Math.min(metrics.length, 3) }}>
                {metrics.map(metric => <div key={metric.label}>
                    <Text size="xl" fw={700}>{metric.value}</Text><Text size="sm" c="dimmed">{metric.label}</Text>
                </div>)}
            </SimpleGrid>;
        }
        const links = Array.isArray(result.data) ? result.data as Shortcut[] : [];
        return <Group>{links.map(link => <Button key={link.href} component="a" href={link.href} variant="light">{link.label}</Button>)}</Group>;
    }

    return <Stack>
        <Group justify="space-between">
            <div><Text fw={700} size="xl">Dashboard</Text><Text c="dimmed" size="sm">Your administration overview</Text></div>
            <Group>
                <Tooltip label="Refresh"><ActionIcon variant="default" loading={loading} onClick={() => setRefreshKey(key => key + 1)}><IconRefresh size={18} /></ActionIcon></Tooltip>
                <Button variant="default" leftSection={<IconSettings size={16} />} disabled={!payload} onClick={openCustomizer}>Customize</Button>
            </Group>
        </Group>

        {error && <Alert color="red" title="Dashboard unavailable">{error}<Button ml="md" size="compact-sm" variant="light" onClick={() => setRefreshKey(key => key + 1)}>Try again</Button></Alert>}
        {loading && !payload && <SimpleGrid cols={{ base: 1, md: 3 }}>{[1, 2, 3].map(id => <Skeleton key={id} height={160} />)}</SimpleGrid>}
        {!error && payload && payload.layout.items.length === 0 && <Alert>No cards are visible. Use Customize to add cards.</Alert>}
        {!error && payload && <Grid>
            {payload.layout.items.map(item => {
                const definition = definitions.get(item.id);
                if (!definition) return null;
                return <Grid.Col key={item.id} span={{ base: 12, sm: dashboardGridSpan(item.size) }}>
                    <Card withBorder h="100%">
                        <Text fw={600} mb="md">{definition.label}</Text>
                        {renderContent(definition, results.get(item.id))}
                    </Card>
                </Grid.Col>;
            })}
        </Grid>}

        <Modal opened={customizing} onClose={() => setCustomizing(false)} title="Customize dashboard" size="lg">
            <Stack>
                {customizerCards.map(card => {
                    const item = draft.find(candidate => candidate.id === card.id);
                    return <Card key={card.id} withBorder padding="sm">
                        <Group justify="space-between" wrap="nowrap">
                            <Checkbox checked={Boolean(item)} label={card.label} onChange={event => toggleCard(card, event.currentTarget.checked)} />
                            {item && <Group wrap="nowrap">
                                <Select size="xs" w={120} data={card.sizes} value={item.size} allowDeselect={false}
                                    onChange={value => value && setDraft(current => current.map(candidate => candidate.id === card.id ? { ...candidate, size: value as DashboardSize } : candidate))} />
                                <ActionIcon variant="subtle" aria-label="Move up" onClick={() => setDraft(current => moveLayoutItem(current, card.id, -1))}><IconArrowUp size={16} /></ActionIcon>
                                <ActionIcon variant="subtle" aria-label="Move down" onClick={() => setDraft(current => moveLayoutItem(current, card.id, 1))}><IconArrowDown size={16} /></ActionIcon>
                            </Group>}
                        </Group>
                    </Card>;
                })}
                <Group justify="space-between"><Button color="red" variant="subtle" loading={saving} onClick={resetLayout}>Restore defaults</Button>
                    <Group><Button variant="default" onClick={() => setCustomizing(false)}>Cancel</Button><Button loading={saving} onClick={saveLayout}>Save</Button></Group>
                </Group>
            </Stack>
        </Modal>
    </Stack>;
}

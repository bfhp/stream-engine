import { useEffect, useMemo, useState } from "react";
import {
    ActionIcon, Alert, Box, Button, Card, Checkbox, Grid, Group, Modal, Select, SimpleGrid,
    Skeleton, Stack, Text, Tooltip
} from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { IconArrowDown, IconArrowUp, IconGripVertical, IconRefresh, IconSettings } from "@tabler/icons-react";
import { csrfHeaders } from "../../shared/csrf";
import {
    dashboardGridSpan, moveLayoutItem, moveLayoutItemTo, orderedLayout,
    type DashboardSize, type LayoutItem
} from "../lib/dashboard";
import DashboardCardContent, {
    type CardDefinition, type CardResult
} from "../components/DashboardCardContent";
import { trans } from "../../shared/i18n";

type DashboardPayload = {
    version: number; catalog: CardDefinition[];
    layout: { version: number; items: LayoutItem[] }; cards: CardResult[];
};

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
    const [draggingId, setDraggingId] = useState<string | null>(null);
    const [orderAnnouncement, setOrderAnnouncement] = useState("");
    const [cardOverrides, setCardOverrides] = useState<Record<string, CardResult>>({});
    const [cardLoading, setCardLoading] = useState<Set<string>>(() => new Set());

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError(null);
        fetch("/api/v1/admin/dashboard", { signal: controller.signal })
            .then(async response => {
                if (!response.ok) throw await requestError(response, trans("js.admin.dashboard.load_failed"));
                return response.json() as Promise<DashboardPayload>;
            })
            .then(next => {
                setPayload(next);
                setCardOverrides({});
            })
            .catch(fetchError => {
                if (fetchError.name !== "AbortError") setError(fetchError.message || trans("js.admin.dashboard.load_failed"));
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
    const results = useMemo(() => new Map([
        ...(payload?.cards || []).map(card => [card.id, card] as const),
        ...Object.values(cardOverrides).map(card => [card.id, card] as const)
    ]), [cardOverrides, payload]);
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
                if (!response.ok) throw await requestError(response, trans("js.admin.dashboard.save_failed"));
                return response.json() as Promise<DashboardPayload>;
            })
            .then(next => {
                setPayload(next);
                setCustomizing(false);
                notifications.show({ color: "green", message: trans("js.admin.dashboard.saved"), autoClose: 2000 });
            })
            .catch(saveError => notifications.show({ color: "red", title: trans("js.admin.dashboard.not_saved"), message: saveError.message }))
            .finally(() => setSaving(false));
    }

    function moveCard(id: string, direction: -1 | 1) {
        setDraft(current => moveLayoutItem(current, id, direction));
        const card = definitions.get(id);
        if (card) setOrderAnnouncement(trans(direction < 0 ? "js.admin.dashboard.moved_up" : "js.admin.dashboard.moved_down", { label: trans(card.label) }));
    }

    function dropCard(targetId: string) {
        if (!draggingId) return;
        setDraft(current => moveLayoutItemTo(current, draggingId, targetId));
        const card = definitions.get(draggingId);
        if (card) setOrderAnnouncement(trans("js.admin.dashboard.reordered", { label: trans(card.label) }));
        setDraggingId(null);
    }

    function resetLayout() {
        setSaving(true);
        fetch("/api/v1/admin/dashboard", { method: "DELETE", headers: csrfHeaders() })
            .then(async response => {
                if (!response.ok) throw await requestError(response, trans("js.admin.dashboard.reset_failed"));
                return response.json() as Promise<DashboardPayload>;
            })
            .then(next => {
                setPayload(next);
                setDraft(next.layout.items);
                setCustomizing(false);
                notifications.show({ color: "green", message: trans("js.admin.dashboard.restored"), autoClose: 2000 });
            })
            .catch(resetError => notifications.show({ color: "red", title: trans("js.admin.dashboard.not_reset"), message: resetError.message }))
            .finally(() => setSaving(false));
    }

    function refreshCard(id: string) {
        setCardLoading(current => new Set(current).add(id));
        fetch(`/api/v1/admin/dashboard/cards/${encodeURIComponent(id)}`)
            .then(async response => {
                if (!response.ok) throw await requestError(response, trans("js.admin.dashboard.card_load_failed"));
                return response.json() as Promise<CardResult>;
            })
            .then(result => setCardOverrides(current => ({ ...current, [id]: result })))
            .catch(refreshError => {
                setCardOverrides(current => ({ ...current, [id]: { id, status: "error", data: null } }));
                notifications.show({ color: "red", title: trans("js.admin.dashboard.card_not_refreshed"), message: refreshError.message });
            })
            .finally(() => setCardLoading(current => {
                const next = new Set(current);
                next.delete(id);
                return next;
            }));
    }

    return <Stack>
        <Group justify="space-between">
            <div><Text fw={700} size="xl">{trans("js.admin.dashboard.title")}</Text><Text c="dimmed" size="sm">{trans("js.admin.dashboard.subtitle")}</Text></div>
            <Group>
                <Tooltip label={trans("js.admin.refresh")}><ActionIcon variant="default" loading={loading} onClick={() => setRefreshKey(key => key + 1)}><IconRefresh size={18} /></ActionIcon></Tooltip>
                <Button variant="default" leftSection={<IconSettings size={16} />} disabled={!payload} onClick={openCustomizer}>{trans("js.admin.dashboard.customize")}</Button>
            </Group>
        </Group>

        {error && <Alert color="red" title={trans("js.admin.dashboard.unavailable")}>{error}<Button ms="md" size="compact-sm" variant="light" onClick={() => setRefreshKey(key => key + 1)}>{trans("js.admin.try_again")}</Button></Alert>}
        {loading && !payload && <SimpleGrid cols={{ base: 1, md: 3 }}>{[1, 2, 3].map(id => <Skeleton key={id} height={160} />)}</SimpleGrid>}
        {!error && payload && payload.layout.items.length === 0 && <Alert>{trans("js.admin.dashboard.no_cards")}</Alert>}
        {!error && payload && <Grid>
            {payload.layout.items.map(item => {
                const definition = definitions.get(item.id);
                if (!definition) return null;
                return <Grid.Col key={item.id} span={{ base: 12, sm: dashboardGridSpan(item.size) }}>
                    <Card withBorder h="100%">
                        <Group justify="space-between" mb="md">
                            <Text fw={600}>{trans(definition.label)}</Text>
                            <Tooltip label={trans("js.admin.dashboard.refresh_card")}><ActionIcon variant="subtle" size="sm"
                                loading={cardLoading.has(item.id)} aria-label={trans("js.admin.dashboard.refresh_named", { label: trans(definition.label) })}
                                onClick={() => refreshCard(item.id)}><IconRefresh size={15} /></ActionIcon></Tooltip>
                        </Group>
                        <DashboardCardContent definition={definition} result={results.get(item.id)}
                            loading={cardLoading.has(item.id)} />
                    </Card>
                </Grid.Col>;
            })}
        </Grid>}

        <Modal opened={customizing} onClose={() => setCustomizing(false)} title={trans("js.admin.dashboard.customize_title")} size="lg">
            <Stack>
                {customizerCards.map(card => {
                    const item = draft.find(candidate => candidate.id === card.id);
                    return <Card key={card.id} withBorder padding="sm"
                        onDragOver={event => { if (item && draggingId) event.preventDefault(); }}
                        onDrop={() => item && dropCard(card.id)}
                        style={{ opacity: draggingId === card.id ? 0.55 : 1 }}>
                        <Group justify="space-between" wrap="nowrap">
                            <Group wrap="nowrap">
                                {item && <Box component="span" draggable role="button" tabIndex={0}
                                    aria-label={trans("js.admin.dashboard.drag_aria", { label: trans(card.label) })}
                                    aria-grabbed={draggingId === card.id}
                                    onDragStart={() => setDraggingId(card.id)} onDragEnd={() => setDraggingId(null)}
                                    onKeyDown={event => {
                                        if (event.key === "ArrowUp" || event.key === "ArrowDown") {
                                            event.preventDefault();
                                            moveCard(card.id, event.key === "ArrowUp" ? -1 : 1);
                                        }
                                    }}
                                    style={{ cursor: "grab", display: "inline-flex" }}><IconGripVertical size={18} /></Box>}
                                <Checkbox checked={Boolean(item)} label={trans(card.label)} onChange={event => toggleCard(card, event.currentTarget.checked)} />
                            </Group>
                            {item && <Group wrap="nowrap">
                                <Select size="xs" w={120} data={card.sizes} value={item.size} allowDeselect={false}
                                    onChange={value => value && setDraft(current => current.map(candidate => candidate.id === card.id ? { ...candidate, size: value as DashboardSize } : candidate))} />
                                <ActionIcon variant="subtle" aria-label={trans("js.admin.move_up", { label: trans(card.label) })} onClick={() => moveCard(card.id, -1)}><IconArrowUp size={16} /></ActionIcon>
                                <ActionIcon variant="subtle" aria-label={trans("js.admin.move_down", { label: trans(card.label) })} onClick={() => moveCard(card.id, 1)}><IconArrowDown size={16} /></ActionIcon>
                            </Group>}
                        </Group>
                    </Card>;
                })}
                <Text component="div" aria-live="polite" pos="absolute" style={{ width: 1, height: 1, overflow: "hidden", clip: "rect(0 0 0 0)" }}>
                    {orderAnnouncement}
                </Text>
                <Group justify="space-between"><Button color="red" variant="subtle" loading={saving} onClick={resetLayout}>{trans("js.admin.restore_defaults")}</Button>
                    <Group><Button variant="default" onClick={() => setCustomizing(false)}>{trans("js.admin.cancel")}</Button><Button loading={saving} onClick={saveLayout}>{trans("js.admin.save")}</Button></Group>
                </Group>
            </Stack>
        </Modal>
    </Stack>;
}

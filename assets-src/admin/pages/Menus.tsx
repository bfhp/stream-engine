import { useCallback, useEffect, useMemo, useState } from "react";
import { ActionIcon, Badge, Button, Group, Paper, Select, SimpleGrid, Stack, Table, Text, TextInput, Tooltip } from "@mantine/core";
import { IconArrowDown, IconArrowUp, IconCornerDownLeft, IconCornerDownRight, IconEdit, IconEye, IconPlus, IconRefresh } from "@tabler/icons-react";
import { Link, useNavigate } from "react-router-dom";
import { csrfHeaders } from "../../shared/csrf";
import { menuDepth, type MenuItemRecord, type MenuPreviewGroup, type MenuPreviewItem } from "../lib/menu";

async function responseJson(response: Response, fallback: string) {
    const body = await response.json().catch(() => null);
    if (!response.ok) throw new Error(typeof body?.error === "string" ? body.error : fallback);
    return body;
}

function PreviewItems({ items }: { items: MenuPreviewItem[] }) {
    return <Stack gap={4} ml="md">{items.map(item => <div key={item.id}>
        <Text size="sm">{item.label || "Divider"}</Text>
        {item.children.length > 0 && <PreviewItems items={item.children} />}
    </div>)}</Stack>;
}

export default function Menus() {
    const [items, setItems] = useState<MenuItemRecord[]>([]);
    const [preview, setPreview] = useState<MenuPreviewGroup[]>([]);
    const [query, setQuery] = useState("");
    const [group, setGroup] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [refreshKey, setRefreshKey] = useState(0);
    const navigate = useNavigate();

    const loadPreview = useCallback(() => {
        fetch("/api/v1/admin/menus/preview")
            .then(response => responseJson(response, "Failed to load menu preview"))
            .then(data => setPreview(Array.isArray(data.groups) ? data.groups : []))
            .catch(err => setError(err.message || "Failed to load menu preview"));
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError(null);
        fetch("/api/v1/admin/menus", { signal: controller.signal })
            .then(response => responseJson(response, "Failed to load menu items"))
            .then(data => {
                setItems(Array.isArray(data.data) ? data.data : []);
                loadPreview();
            })
            .catch(err => { if (err.name !== "AbortError") setError(err.message || "Failed to load menu items"); })
            .finally(() => { if (!controller.signal.aborted) setLoading(false); });
        return () => controller.abort();
    }, [loadPreview, refreshKey]);

    const groupNames = useMemo(() => Array.from(new Set(items.map(item => item.menuGroup))).sort((left, right) => {
        const leftOrder = items.find(item => item.menuGroup === left)?.groupOrder ?? 0;
        const rightOrder = items.find(item => item.menuGroup === right)?.groupOrder ?? 0;
        return leftOrder - rightOrder || left.localeCompare(right);
    }), [items]);
    const byId = useMemo(() => new Map(items.filter(item => item.id).map(item => [item.id!, item])), [items]);
    const orderedItems = useMemo(() => {
        const result: MenuItemRecord[] = [];
        const visited = new Set<number>();
        for (const groupName of groupNames) {
            const append = (parentId: number | null) => {
                items.filter(item => item.menuGroup === groupName && item.parentId === parentId)
                    .sort((left, right) => left.sortOrder - right.sortOrder || (left.id ?? 0) - (right.id ?? 0))
                    .forEach(item => {
                        if (!item.id || visited.has(item.id)) return;
                        visited.add(item.id);
                        result.push(item);
                        append(item.id);
                    });
            };
            append(null);
        }
        items.forEach(item => { if (!item.id || !visited.has(item.id)) result.push(item); });
        return result;
    }, [groupNames, items]);
    const filteredItems = useMemo(() => {
        const needle = query.trim().toLowerCase();
        return orderedItems.filter(item => (!group || item.menuGroup === group) && (!needle || [
            item.id, item.label, item.menuGroup, item.type, item.accessRule
        ].some(value => String(value ?? "").toLowerCase().includes(needle))));
    }, [group, orderedItems, query]);
    const reorderingEnabled = query.trim() === "" && group === null;

    function moveSibling(item: MenuItemRecord, direction: -1 | 1) {
        const siblings = orderedItems.filter(candidate => candidate.menuGroup === item.menuGroup && candidate.parentId === item.parentId);
        const other = siblings[siblings.findIndex(candidate => candidate.id === item.id) + direction];
        if (!other) return;
        setItems(current => current.map(candidate => candidate.id === item.id
            ? { ...candidate, sortOrder: other.sortOrder }
            : candidate.id === other.id ? { ...candidate, sortOrder: item.sortOrder } : candidate));
    }

    function indent(item: MenuItemRecord) {
        const previous = orderedItems[orderedItems.findIndex(candidate => candidate.id === item.id) - 1];
        if (!previous || previous.menuGroup !== item.menuGroup || previous.id === item.parentId) return;
        const nextOrder = Math.max(0, ...items.filter(candidate => candidate.parentId === previous.id).map(candidate => candidate.sortOrder)) + 10;
        setItems(current => current.map(candidate => candidate.id === item.id ? { ...candidate, parentId: previous.id!, sortOrder: nextOrder } : candidate));
    }

    function outdent(item: MenuItemRecord) {
        if (item.parentId === null) return;
        const parent = byId.get(item.parentId);
        if (!parent) return;
        setItems(current => current.map(candidate => candidate.id === item.id
            ? { ...candidate, parentId: parent.parentId, sortOrder: parent.sortOrder + 5 }
            : candidate));
    }

    function moveGroup(name: string, direction: -1 | 1) {
        const other = groupNames[groupNames.indexOf(name) + direction];
        if (!other) return;
        const order = items.find(item => item.menuGroup === name)?.groupOrder ?? 0;
        const otherOrder = items.find(item => item.menuGroup === other)?.groupOrder ?? 0;
        setItems(current => current.map(item => item.menuGroup === name
            ? { ...item, groupOrder: otherOrder }
            : item.menuGroup === other ? { ...item, groupOrder: order } : item));
    }

    function saveOrder() {
        setSaving(true);
        setError(null);
        fetch("/api/v1/admin/menus", {
            method: "PATCH",
            headers: { "Content-Type": "application/json", ...csrfHeaders() },
            body: JSON.stringify({
                groups: groupNames,
                items: orderedItems.map(item => ({ id: item.id, parentId: item.parentId, menuGroup: item.menuGroup }))
            })
        })
            .then(response => responseJson(response, "Failed to save menu order"))
            .then(data => { setItems(Array.isArray(data.data) ? data.data : []); loadPreview(); })
            .catch(err => setError(err.message || "Failed to save menu order"))
            .finally(() => setSaving(false));
    }

    return <Stack>
        <Group justify="space-between" align="end">
            <div><Text fw={700} size="xl">Menus</Text><Text c="dimmed" size="sm">Navigation groups and nested menu items</Text></div>
            <Group>
                <Tooltip label="Refresh"><ActionIcon variant="default" loading={loading} onClick={() => setRefreshKey(key => key + 1)}><IconRefresh size={18} /></ActionIcon></Tooltip>
                <Button variant="light" loading={saving} disabled={!reorderingEnabled} onClick={saveOrder}>Save order</Button>
                <Button component={Link} to="/menus/new" leftSection={<IconPlus size={16} />}>New menu item</Button>
            </Group>
        </Group>
        <Group align="end" grow>
            <TextInput label="Search" placeholder="Label, group, type or target" value={query} onChange={event => setQuery(event.currentTarget.value)} />
            <Select label="Menu group" placeholder="All groups" data={groupNames.map(value => ({ value, label: value }))} value={group} onChange={setGroup} clearable />
        </Group>
        {error && <Text c="red">{error}</Text>}
        <SimpleGrid cols={{ base: 1, xl: 3 }}>
            <Stack style={{ gridColumn: "span 2" }}>
                {groupNames.map((name, groupIndex) => <Paper key={name} withBorder>
                    <Group p="sm" justify="space-between">
                        <Text fw={600}>{name}</Text>
                        <Group gap={4}>
                            <ActionIcon variant="subtle" disabled={!reorderingEnabled || groupIndex === 0} onClick={() => moveGroup(name, -1)} aria-label="Move group up"><IconArrowUp size={16} /></ActionIcon>
                            <ActionIcon variant="subtle" disabled={!reorderingEnabled || groupIndex === groupNames.length - 1} onClick={() => moveGroup(name, 1)} aria-label="Move group down"><IconArrowDown size={16} /></ActionIcon>
                        </Group>
                    </Group>
                    <Table striped highlightOnHover><Table.Tbody>
                        {filteredItems.filter(item => item.menuGroup === name).map(item => {
                            const siblings = orderedItems.filter(candidate => candidate.menuGroup === item.menuGroup && candidate.parentId === item.parentId);
                            const siblingIndex = siblings.findIndex(candidate => candidate.id === item.id);
                            return <Table.Tr key={item.id} style={{ cursor: "pointer" }} onClick={() => navigate(`/menus/${item.id}`)}>
                                <Table.Td><Text pl={menuDepth(item, byId) * 20}>{menuDepth(item, byId) > 0 && "↳ "}{item.label || <Text span c="dimmed">Divider</Text>}</Text></Table.Td>
                                <Table.Td><Badge variant="light">{item.type}</Badge></Table.Td>
                                <Table.Td>{item.enabled ? <Badge color="green">Enabled</Badge> : <Badge color="gray">Disabled</Badge>}</Table.Td>
                                <Table.Td onClick={event => event.stopPropagation()}><Group gap={2} justify="flex-end" wrap="nowrap">
                                    <ActionIcon variant="subtle" disabled={!reorderingEnabled || siblingIndex === 0} onClick={() => moveSibling(item, -1)} aria-label="Move item up"><IconArrowUp size={16} /></ActionIcon>
                                    <ActionIcon variant="subtle" disabled={!reorderingEnabled || siblingIndex === siblings.length - 1} onClick={() => moveSibling(item, 1)} aria-label="Move item down"><IconArrowDown size={16} /></ActionIcon>
                                    <ActionIcon variant="subtle" disabled={!reorderingEnabled || orderedItems.findIndex(candidate => candidate.id === item.id) === 0} onClick={() => indent(item)} aria-label="Indent item"><IconCornerDownRight size={16} /></ActionIcon>
                                    <ActionIcon variant="subtle" disabled={!reorderingEnabled || item.parentId === null} onClick={() => outdent(item)} aria-label="Outdent item"><IconCornerDownLeft size={16} /></ActionIcon>
                                    <ActionIcon component={Link} to={`/menus/${item.id}`} variant="subtle" aria-label="Edit"><IconEdit size={18} /></ActionIcon>
                                </Group></Table.Td>
                            </Table.Tr>;
                        })}
                    </Table.Tbody></Table>
                </Paper>)}
                {!loading && filteredItems.length === 0 && <Text c="dimmed" ta="center">No menu items found</Text>}
            </Stack>
            <Paper withBorder p="md">
                <Group mb="md"><IconEye size={18} /><Text fw={600}>Preview for you</Text></Group>
                <Stack>{preview.map(previewGroup => <div key={previewGroup.name}>
                    <Text size="sm" fw={600}>{previewGroup.name}</Text><PreviewItems items={previewGroup.items} />
                </div>)}{preview.length === 0 && <Text c="dimmed" size="sm">No enabled items are visible.</Text>}</Stack>
            </Paper>
        </SimpleGrid>
    </Stack>;
}

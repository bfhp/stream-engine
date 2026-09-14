import { useEffect, useMemo, useState } from "react";
import {
    ActionIcon,
    Badge,
    Button,
    Group,
    Select,
    Stack,
    Table,
    Text,
    TextInput,
    Tooltip
} from "@mantine/core";
import { IconEdit, IconPlus, IconRefresh } from "@tabler/icons-react";
import { Link, useNavigate } from "react-router-dom";
import { menuDepth, menuTarget, type MenuItemRecord } from "../lib/menu";

export default function Menus() {
    const [items, setItems] = useState<MenuItemRecord[]>([]);
    const [query, setQuery] = useState("");
    const [group, setGroup] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [refreshKey, setRefreshKey] = useState(0);
    const navigate = useNavigate();

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError(null);

        fetch("/api/v1/admin/menus", { signal: controller.signal })
            .then(async response => {
                if (!response.ok) {
                    throw new Error(await response.text() || "Failed to load menu items");
                }
                return response.json();
            })
            .then(data => setItems(Array.isArray(data.data) ? data.data : []))
            .catch(err => {
                if (err.name !== "AbortError") {
                    setError(err.message || "Failed to load menu items");
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [refreshKey]);

    const groups = useMemo(
        () => Array.from(new Set(items.map(item => item.menuGroup)))
            .sort()
            .map(value => ({ value, label: value })),
        [items]
    );
    const byId = useMemo(
        () => new Map(items.filter(item => item.id).map(item => [item.id!, item])),
        [items]
    );
    const orderedItems = useMemo(() => {
        const children = new Map<number | null, MenuItemRecord[]>();
        const result: MenuItemRecord[] = [];
        const visited = new Set<number>();

        for (const item of items) {
            const parentId = item.parentId !== null && byId.has(item.parentId) ? item.parentId : null;
            children.set(parentId, [...(children.get(parentId) || []), item]);
        }

        const append = (parentId: number | null) => {
            for (const item of children.get(parentId) || []) {
                if (!item.id || visited.has(item.id)) {
                    continue;
                }
                visited.add(item.id);
                result.push(item);
                append(item.id);
            }
        };

        append(null);
        for (const item of items) {
            if (!item.id || !visited.has(item.id)) {
                result.push(item);
            }
        }

        return result;
    }, [byId, items]);
    const filteredItems = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return orderedItems.filter(item => {
            if (group && item.menuGroup !== group) {
                return false;
            }

            return !needle || [
                item.id,
                item.label,
                item.menuGroup,
                item.type,
                menuTarget(item),
                item.accessRule
            ].some(value => String(value ?? "").toLowerCase().includes(needle));
        });
    }, [group, orderedItems, query]);

    return (
        <Stack>
            <Group justify="space-between" align="end">
                <div>
                    <Text fw={700} size="xl">Menus</Text>
                    <Text c="dimmed" size="sm">Navigation groups and nested menu items</Text>
                </div>

                <Group>
                    <Tooltip label="Refresh">
                        <ActionIcon variant="default" loading={loading} onClick={() => setRefreshKey(key => key + 1)}>
                            <IconRefresh size={18} />
                        </ActionIcon>
                    </Tooltip>
                    <Button component={Link} to="/menus/new" leftSection={<IconPlus size={16} />}>
                        New menu item
                    </Button>
                </Group>
            </Group>

            <Group align="end" grow>
                <TextInput
                    label="Search"
                    placeholder="Label, group, type or target"
                    value={query}
                    onChange={event => setQuery(event.currentTarget.value)}
                />
                <Select
                    label="Menu group"
                    placeholder="All groups"
                    data={groups}
                    value={group}
                    onChange={setGroup}
                    clearable
                />
            </Group>

            {error && <Text c="red">{error}</Text>}

            <Table striped highlightOnHover withTableBorder>
                <Table.Thead>
                    <Table.Tr>
                        <Table.Th>Label</Table.Th>
                        <Table.Th>Group</Table.Th>
                        <Table.Th>Type</Table.Th>
                        <Table.Th>Target</Table.Th>
                        <Table.Th>Access</Table.Th>
                        <Table.Th>Order</Table.Th>
                        <Table.Th />
                    </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                    {filteredItems.map(item => (
                        <Table.Tr
                            key={item.id}
                            style={{ cursor: "pointer" }}
                            onClick={() => navigate(`/menus/${item.id}`)}
                        >
                            <Table.Td>
                                <Text pl={menuDepth(item, byId) * 20}>
                                    {menuDepth(item, byId) > 0 && "↳ "}
                                    {item.label || <Text span c="dimmed">Divider</Text>}
                                </Text>
                            </Table.Td>
                            <Table.Td>{item.menuGroup}</Table.Td>
                            <Table.Td><Badge variant="light">{item.type}</Badge></Table.Td>
                            <Table.Td>{menuTarget(item)}</Table.Td>
                            <Table.Td>{item.accessRule}</Table.Td>
                            <Table.Td>{item.sortOrder}</Table.Td>
                            <Table.Td onClick={event => event.stopPropagation()}>
                                <Tooltip label="Edit">
                                    <ActionIcon component={Link} to={`/menus/${item.id}`} variant="subtle">
                                        <IconEdit size={18} />
                                    </ActionIcon>
                                </Tooltip>
                            </Table.Td>
                        </Table.Tr>
                    ))}
                    {!loading && filteredItems.length === 0 && (
                        <Table.Tr>
                            <Table.Td colSpan={7}>
                                <Text c="dimmed" ta="center">No menu items found</Text>
                            </Table.Td>
                        </Table.Tr>
                    )}
                </Table.Tbody>
            </Table>
        </Stack>
    );
}

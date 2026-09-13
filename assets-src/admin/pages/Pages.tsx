import { useEffect, useMemo, useState } from "react";
import {
    ActionIcon,
    Badge,
    Button,
    Group,
    Stack,
    Table,
    Text,
    TextInput,
    Tooltip
} from "@mantine/core";
import { Link, useNavigate } from "react-router-dom";
import { IconEdit, IconPlus, IconRefresh } from "@tabler/icons-react";
import { trans } from "../../shared/i18n";

type PageListItem = {
    id: number;
    parentId?: number | null;
    pattern?: string | null;
    action?: string | null;
    pageName?: string | null;
    feedType?: string | null;
    feedId?: number | null;
    accessRule?: string | null;
};

export default function Pages() {
    const [pages, setPages] = useState<PageListItem[]>([]);
    const [query, setQuery] = useState("");
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [refreshKey, setRefreshKey] = useState(0);
    const navigate = useNavigate();

    useEffect(() => {
        const controller = new AbortController();

        setLoading(true);
        setError(null);

        fetch("/api/v1/admin/pages", { signal: controller.signal })
            .then(async response => {
                if (!response.ok) {
                    throw new Error(await response.text() || "Failed to load pages");
                }

                return response.json();
            })
            .then(data => setPages(Array.isArray(data.data) ? data.data : []))
            .catch(err => {
                if (err.name !== "AbortError") {
                    setError(err.message || "Failed to load pages");
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [refreshKey]);

    const filteredPages = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (!needle) {
            return pages;
        }

        return pages.filter(page => [
            page.id,
            page.parentId,
            page.pattern,
            page.action,
            page.pageName,
            page.feedType,
            page.feedId
        ].some(value => String(value ?? "").toLowerCase().includes(needle)));
    }, [pages, query]);

    return (
        <Stack>
            <Group justify="space-between" align="end">
                <div>
                    <Text fw={700} size="xl">Pages</Text>
                    <Text c="dimmed" size="sm">Routing table records from pages</Text>
                </div>

                <Group>
                    <Tooltip label="Refresh">
                        <ActionIcon
                            variant="default"
                            loading={loading}
                            onClick={() => setRefreshKey(key => key + 1)}
                        >
                            <IconRefresh size={18} />
                        </ActionIcon>
                    </Tooltip>

                    <Button
                        component={Link}
                        to="/pages/new"
                        leftSection={<IconPlus size={16} />}
                    >
                        New page
                    </Button>
                </Group>
            </Group>

            <TextInput
                label="Search"
                placeholder="ID, action, pattern, title, feed"
                value={query}
                onChange={event => setQuery(event.currentTarget.value)}
            />

            {error && <Text c="red">{error}</Text>}

            <Table striped highlightOnHover withTableBorder>
                <Table.Thead>
                    <Table.Tr>
                        <Table.Th>ID</Table.Th>
                        <Table.Th>Parent</Table.Th>
                        <Table.Th>Pattern</Table.Th>
                        <Table.Th>Action</Table.Th>
                        <Table.Th>Name</Table.Th>
                        <Table.Th>Feed</Table.Th>
                        <Table.Th>{trans("js.admin.access")}</Table.Th>
                        <Table.Th />
                    </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                    {filteredPages.map(page => (
                        <Table.Tr
                            key={page.id}
                            style={{ cursor: "pointer" }}
                            onClick={() => navigate(`/pages/${page.id}`)}
                        >
                            <Table.Td>{page.id}</Table.Td>
                            <Table.Td>{page.parentId ?? "-"}</Table.Td>
                            <Table.Td>{page.pattern || <Text c="dimmed">root</Text>}</Table.Td>
                            <Table.Td><Badge variant="light">{page.action || "-"}</Badge></Table.Td>
                            <Table.Td>{page.pageName || "-"}</Table.Td>
                            <Table.Td>
                                {page.feedType || page.feedId
                                    ? `${page.feedType || "-"} ${page.feedId ? `#${page.feedId}` : ""}`
                                    : "-"}
                            </Table.Td>
                            <Table.Td>{page.accessRule ?? "public"}</Table.Td>
                            <Table.Td onClick={event => event.stopPropagation()}>
                                <Tooltip label="Edit">
                                    <ActionIcon
                                        component={Link}
                                        to={`/pages/${page.id}`}
                                        variant="subtle"
                                    >
                                        <IconEdit size={18} />
                                    </ActionIcon>
                                </Tooltip>
                            </Table.Td>
                        </Table.Tr>
                    ))}

                    {!loading && filteredPages.length === 0 && (
                        <Table.Tr>
                            <Table.Td colSpan={8}>
                                <Text c="dimmed" ta="center">No pages found</Text>
                            </Table.Td>
                        </Table.Tr>
                    )}
                </Table.Tbody>
            </Table>
        </Stack>
    );
}

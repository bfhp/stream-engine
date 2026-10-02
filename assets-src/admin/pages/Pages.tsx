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
                    throw new Error(await response.text() || trans("js.admin.pages.load_failed"));
                }

                return response.json();
            })
            .then(data => setPages(Array.isArray(data.data) ? data.data : []))
            .catch(err => {
                if (err.name !== "AbortError") {
                    setError(err.message || trans("js.admin.pages.load_failed"));
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
                    <Text fw={700} size="xl">{trans("js.admin.pages.title")}</Text>
                    <Text c="dimmed" size="sm">{trans("js.admin.pages.subtitle")}</Text>
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
                        component={Link}
                        to="/pages/new"
                        leftSection={<IconPlus size={16} />}
                    >
                        {trans("js.admin.pages.new")}
                    </Button>
                </Group>
            </Group>

            <TextInput
                label={trans("js.admin.search")}
                placeholder={trans("js.admin.pages.search_placeholder")}
                value={query}
                onChange={event => setQuery(event.currentTarget.value)}
            />

            {error && <Text c="red">{error}</Text>}

            <Table striped highlightOnHover withTableBorder>
                <Table.Thead>
                    <Table.Tr>
                        <Table.Th>ID</Table.Th>
                        <Table.Th>{trans("js.admin.parent")}</Table.Th>
                        <Table.Th>{trans("js.admin.pattern")}</Table.Th>
                        <Table.Th>{trans("js.admin.action")}</Table.Th>
                        <Table.Th>{trans("js.admin.name")}</Table.Th>
                        <Table.Th>{trans("js.admin.feed")}</Table.Th>
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
                            <Table.Td>{page.pattern || <Text c="dimmed">{trans("js.admin.root")}</Text>}</Table.Td>
                            <Table.Td><Badge variant="light">{page.action || "-"}</Badge></Table.Td>
                            <Table.Td>{page.pageName || "-"}</Table.Td>
                            <Table.Td>
                                {page.feedType || page.feedId
                                    ? `${page.feedType || "-"} ${page.feedId ? `#${page.feedId}` : ""}`
                                    : "-"}
                            </Table.Td>
                            <Table.Td>{page.accessRule ?? "public"}</Table.Td>
                            <Table.Td onClick={event => event.stopPropagation()}>
                                <Tooltip label={trans("js.admin.edit")}>
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
                                <Text c="dimmed" ta="center">{trans("js.admin.pages.empty")}</Text>
                            </Table.Td>
                        </Table.Tr>
                    )}
                </Table.Tbody>
            </Table>
        </Stack>
    );
}

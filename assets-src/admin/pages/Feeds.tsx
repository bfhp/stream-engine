import { useEffect, useMemo, useState } from "react";
import {
    ActionIcon,
    Badge,
    Button,
    Checkbox,
    Group,
    Menu,
    NumberInput,
    Select,
    Stack,
    Table,
    Text,
    TextInput,
    Tooltip
} from "@mantine/core";
import { Link, useNavigate } from "react-router-dom";
import {
    IconAdjustmentsHorizontal,
    IconArrowsSort,
    IconColumns3,
    IconFilterOff,
    IconPlus,
    IconRefresh
} from "@tabler/icons-react";
import { FEED_TYPES } from "../../shared/feed-types";
import { makeFeedLabel } from "../lib/feed-label";
import { formatTimestamp } from "../lib/format";

type FeedListItem = {
    id: number;
    parentId?: number | null;
    ownerId?: number | null;
    title?: string | null;
    content?: string | null;
    type?: string | null;
    slug?: string | null;
    visibility?: string | null;
    position?: number | null;
    views?: number | null;
    ratingSum?: number | null;
    ratingCount?: number | null;
    createdAt?: number | null;
    createdAtLabel?: string | null;
    createdAtTitle?: string | null;
    updatedAt?: number | null;
    authorDisplayName?: string | null;
};

type ColumnKey =
    | "id"
    | "title"
    | "type"
    | "slug"
    | "owner"
    | "parent"
    | "visibility"
    | "position"
    | "rating"
    | "views"
    | "created"
    | "updated";

type SortKey =
    | "id_desc"
    | "created_desc"
    | "position_asc"
    | "rating_desc";

const COLUMN_STORAGE_KEY = "stream-engine.admin.feeds.columns";

const COLUMNS: Array<{ key: ColumnKey; label: string }> = [
    { key: "id", label: "ID" },
    { key: "title", label: "Title" },
    { key: "type", label: "Type" },
    { key: "slug", label: "Slug" },
    { key: "owner", label: "Owner" },
    { key: "parent", label: "Parent" },
    { key: "visibility", label: "Visibility" },
    { key: "position", label: "Position" },
    { key: "rating", label: "Rating" },
    { key: "views", label: "Views" },
    { key: "created", label: "Created" },
    { key: "updated", label: "Updated" }
];

const DEFAULT_COLUMNS: ColumnKey[] = [
    "id",
    "title",
    "type",
    "owner",
    "parent",
    "visibility",
    "created"
];

function initialColumns(): ColumnKey[] {
    try {
        const stored = window.localStorage.getItem(COLUMN_STORAGE_KEY);
        const parsed = stored ? JSON.parse(stored) : null;

        if (!Array.isArray(parsed)) {
            return DEFAULT_COLUMNS;
        }

        const allowed = new Set(COLUMNS.map(column => column.key));
        const columns = parsed.filter((key): key is ColumnKey => allowed.has(key));

        return columns.length > 0 ? columns : DEFAULT_COLUMNS;
    } catch (_error) {
        return DEFAULT_COLUMNS;
    }
}

function ratingLabel(feed: FeedListItem): string {
    const count = feed.ratingCount || 0;

    if (count === 0) {
        return "-";
    }

    return ((feed.ratingSum || 0) / count).toFixed(2);
}

function numericValue(value: string): string {
    return value.replace(/[^\d]/g, "");
}

export default function Feeds() {
    const [feeds, setFeeds] = useState<FeedListItem[]>([]);
    const [type, setType] = useState<string | null>(null);
    const [id, setId] = useState("");
    const [slug, setSlug] = useState("");
    const [ownerId, setOwnerId] = useState("");
    const [parentId, setParentId] = useState("");
    const [sort, setSort] = useState<SortKey>("id_desc");
    const [limit, setLimit] = useState(100);
    const [visibleColumns, setVisibleColumns] = useState<ColumnKey[]>(initialColumns);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [refreshKey, setRefreshKey] = useState(0);
    const navigate = useNavigate();

    const hasType = Boolean(type);
    const hasParent = parentId !== "";

    const sortOptions = useMemo(() => [
        { value: "id_desc", label: "ID: newest first" },
        { value: "created_desc", label: "Created: newest first", disabled: !hasType },
        { value: "position_asc", label: "Position: low to high", disabled: !hasParent },
        { value: "rating_desc", label: "Rating: high to low", disabled: !hasType }
    ], [hasParent, hasType]);

    useEffect(() => {
        if ((sort === "created_desc" || sort === "rating_desc") && !hasType) {
            setSort("id_desc");
        }

        if (sort === "position_asc" && !hasParent) {
            setSort("id_desc");
        }
    }, [hasParent, hasType, sort]);

    useEffect(() => {
        window.localStorage.setItem(COLUMN_STORAGE_KEY, JSON.stringify(visibleColumns));
    }, [visibleColumns]);

    useEffect(() => {
        const controller = new AbortController();
        const params = new URLSearchParams({ sort, limit: String(limit) });

        if (type) {
            params.set("type", type);
        }

        if (id !== "") {
            params.set("id", id);
        }

        if (slug.trim() !== "") {
            params.set("slug", slug.trim());
        }

        if (ownerId !== "") {
            params.set("owner_id", ownerId);
        }

        if (parentId !== "") {
            params.set("parent_id", parentId);
        }

        setLoading(true);
        setError(null);

        fetch(`/api/v1/feeds?${params.toString()}`, { signal: controller.signal })
            .then(async response => {
                if (!response.ok) {
                    throw new Error(await response.text() || "Failed to load feeds");
                }

                return response.json();
            })
            .then(data => setFeeds(Array.isArray(data.data) ? data.data : []))
            .catch(err => {
                if (err.name !== "AbortError") {
                    setError(err.message || "Failed to load feeds");
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [id, limit, ownerId, parentId, refreshKey, slug, sort, type]);

    function resetFilters() {
        setType(null);
        setId("");
        setSlug("");
        setOwnerId("");
        setParentId("");
        setSort("id_desc");
    }

    function toggleColumn(key: ColumnKey) {
        setVisibleColumns(current => {
            if (current.includes(key)) {
                return current.length === 1 ? current : current.filter(column => column !== key);
            }

            return [...current, key];
        });
    }

    function isColumnVisible(key: ColumnKey): boolean {
        return visibleColumns.includes(key);
    }

    function renderHeader(key: ColumnKey, label: string) {
        const sortByColumn: Partial<Record<ColumnKey, SortKey>> = {
            created: "created_desc",
            position: "position_asc",
            rating: "rating_desc"
        };

        const nextSort = sortByColumn[key];

        if (!nextSort) {
            return <Table.Th key={key}>{label}</Table.Th>;
        }

        const disabled = (key === "created" || key === "rating") && !hasType || key === "position" && !hasParent;

        return (
            <Table.Th key={key}>
                <Tooltip label={disabled ? "Needs an indexed filter" : "Sort"}>
                    <Button
                        variant="subtle"
                        size="compact-sm"
                        px={4}
                        rightSection={<IconArrowsSort size={14} />}
                        disabled={disabled}
                        onClick={() => setSort(nextSort)}
                    >
                        {label}
                    </Button>
                </Tooltip>
            </Table.Th>
        );
    }

    function renderCell(feed: FeedListItem, key: ColumnKey) {
        const label = makeFeedLabel(feed);

        switch (key) {
            case "id":
                return feed.id;
            case "title":
                return (
                    <Text
                        component={Link}
                        to={`/feeds/${feed.id}`}
                        lineClamp={1}
                        maw={520}
                        title={label}
                    >
                        {label}
                    </Text>
                );
            case "type":
                return <Badge variant="light">{feed.type || "-"}</Badge>;
            case "slug":
                return <Text size="sm" lineClamp={1} maw={220}>{feed.slug || "-"}</Text>;
            case "owner":
                return (
                    <Stack gap={0}>
                        <Text size="sm">{feed.authorDisplayName || `#${feed.ownerId || "-"}`}</Text>
                        <Text size="xs" c="dimmed">#{feed.ownerId || "-"}</Text>
                    </Stack>
                );
            case "parent":
                return feed.parentId ? `#${feed.parentId}` : "-";
            case "visibility":
                return <Badge color={feed.visibility === "private" ? "red" : feed.visibility === "members" ? "yellow" : "green"} variant="light">{feed.visibility || "-"}</Badge>;
            case "position":
                return feed.position ?? "-";
            case "rating":
                return (
                    <Stack gap={0}>
                        <Text size="sm">{ratingLabel(feed)}</Text>
                        <Text size="xs" c="dimmed">{feed.ratingCount || 0} votes</Text>
                    </Stack>
                );
            case "views":
                return feed.views ?? 0;
            case "created":
                return (
                    <Text size="sm" c="dimmed" title={feed.createdAtTitle || undefined}>
                        {feed.createdAtLabel || formatTimestamp(feed.createdAt || null)}
                    </Text>
                );
            case "updated":
                return <Text size="sm" c="dimmed">{formatTimestamp(feed.updatedAt || null)}</Text>;
        }
    }

    return (
        <Stack gap="md">
            <Group justify="space-between" align="flex-end">
                <Group align="flex-end" gap="sm">
                    <TextInput
                        label="ID"
                        value={id}
                        onChange={(event) => setId(numericValue(event.currentTarget.value))}
                        w={110}
                    />
                    <Select
                        label="Type"
                        placeholder="All"
                        data={FEED_TYPES}
                        value={type}
                        onChange={setType}
                        clearable
                        searchable
                        w={210}
                    />
                    <TextInput
                        label="Slug"
                        value={slug}
                        onChange={(event) => setSlug(event.currentTarget.value)}
                        w={220}
                    />
                    <TextInput
                        label="Owner"
                        value={ownerId}
                        onChange={(event) => setOwnerId(numericValue(event.currentTarget.value))}
                        w={120}
                    />
                    <TextInput
                        label="Parent"
                        value={parentId}
                        onChange={(event) => setParentId(numericValue(event.currentTarget.value))}
                        w={120}
                    />
                    <Select
                        label="Sort"
                        data={sortOptions}
                        value={sort}
                        onChange={(value) => setSort((value || "id_desc") as SortKey)}
                        allowDeselect={false}
                        w={230}
                    />
                    <NumberInput
                        label="Limit"
                        min={1}
                        max={500}
                        value={limit}
                        onChange={(value) => setLimit(typeof value === "number" ? value : 100)}
                        w={110}
                    />
                </Group>

                <Group gap="xs">
                    <Menu shadow="md" width={220}>
                        <Menu.Target>
                            <Tooltip label="Columns">
                                <ActionIcon variant="default" size="lg">
                                    <IconColumns3 size={18} />
                                </ActionIcon>
                            </Tooltip>
                        </Menu.Target>
                        <Menu.Dropdown>
                            {COLUMNS.map(column => (
                                <Menu.Item key={column.key} closeMenuOnClick={false}>
                                    <Checkbox
                                        label={column.label}
                                        checked={isColumnVisible(column.key)}
                                        onChange={() => toggleColumn(column.key)}
                                    />
                                </Menu.Item>
                            ))}
                        </Menu.Dropdown>
                    </Menu>

                    <Menu shadow="md" width={240}>
                        <Menu.Target>
                            <Tooltip label="Placeholders">
                                <ActionIcon variant="default" size="lg">
                                    <IconAdjustmentsHorizontal size={18} />
                                </ActionIcon>
                            </Tooltip>
                        </Menu.Target>
                        <Menu.Dropdown>
                            <Menu.Label>Waiting for indexes</Menu.Label>
                            <Menu.Item disabled>Title contains</Menu.Item>
                            <Menu.Item disabled>Visibility</Menu.Item>
                            <Menu.Item disabled>Updated date range</Menu.Item>
                            <Menu.Item disabled>Views range</Menu.Item>
                        </Menu.Dropdown>
                    </Menu>

                    <Tooltip label="Reset filters">
                        <ActionIcon variant="default" size="lg" onClick={resetFilters}>
                            <IconFilterOff size={18} />
                        </ActionIcon>
                    </Tooltip>

                    <Tooltip label="Reload">
                        <ActionIcon variant="default" size="lg" onClick={() => setRefreshKey(value => value + 1)}>
                            <IconRefresh size={18} />
                        </ActionIcon>
                    </Tooltip>

                    <Button leftSection={<IconPlus size={16} />} onClick={() => navigate("/feeds/new")}>
                        New feed
                    </Button>
                </Group>
            </Group>

            {error ? <Text c="red" size="sm">{error}</Text> : null}

            <Table.ScrollContainer minWidth={980}>
                <Table striped highlightOnHover withTableBorder>
                    <Table.Thead>
                        <Table.Tr>
                            {COLUMNS
                                .filter(column => isColumnVisible(column.key))
                                .map(column => renderHeader(column.key, column.label))}
                        </Table.Tr>
                    </Table.Thead>

                    <Table.Tbody>
                        {feeds.map(feed => (
                            <Table.Tr key={feed.id}>
                                {COLUMNS
                                    .filter(column => isColumnVisible(column.key))
                                    .map(column => (
                                        <Table.Td key={column.key}>
                                            {renderCell(feed, column.key)}
                                        </Table.Td>
                                    ))}
                            </Table.Tr>
                        ))}
                        {feeds.length === 0 && !loading ? (
                            <Table.Tr>
                                <Table.Td colSpan={visibleColumns.length}>
                                    <Text c="dimmed" ta="center" py="xl">No feeds</Text>
                                </Table.Td>
                            </Table.Tr>
                        ) : null}
                    </Table.Tbody>
                </Table>
            </Table.ScrollContainer>

            {loading ? <Text c="dimmed" size="sm">Loading...</Text> : null}
        </Stack>
    );
}

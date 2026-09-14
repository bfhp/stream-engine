import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import {
    Button,
    Group,
    Modal,
    NumberInput,
    Select,
    SimpleGrid,
    Stack,
    Text,
    TextInput
} from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { IconTrash } from "@tabler/icons-react";
import { csrfHeaders } from "../../shared/csrf";
import { type MenuItemRecord } from "../lib/menu";

type PageOption = { id: number; pattern?: string; action?: string; pageName?: string };

function blankItem(items: MenuItemRecord[]): MenuItemRecord {
    const rootOrders = items
        .filter(item => item.menuGroup === "main" && item.parentId === null)
        .map(item => item.sortOrder);

    return {
        parentId: null,
        menuGroup: "main",
        type: "internal",
        pageId: null,
        url: null,
        action: null,
        label: "",
        accessRule: "public",
        sortOrder: (rootOrders.length > 0 ? Math.max(...rootOrders) : 0) + 10
    };
}

async function responseJson(response: Response, fallback: string) {
    const body = await response.json().catch(() => null);
    if (!response.ok) {
        throw new Error(typeof body?.error === "string" ? body.error : fallback);
    }
    return body;
}

export default function MenuEdit() {
    const { id } = useParams();
    const navigate = useNavigate();
    const isNew = id === "new";
    const [item, setItem] = useState<MenuItemRecord | null>(null);
    const [items, setItems] = useState<MenuItemRecord[]>([]);
    const [pages, setPages] = useState<PageOption[]>([]);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);

    useEffect(() => {
        if (!id) {
            return;
        }

        setLoading(true);
        Promise.all([
            fetch("/api/v1/admin/menus").then(response => responseJson(response, "Failed to load menu items")),
            fetch("/api/v1/admin/pages").then(response => responseJson(response, "Failed to load pages")),
            isNew
                ? Promise.resolve(null)
                : fetch(`/api/v1/admin/menus/${id}`).then(response => responseJson(response, "Failed to load menu item"))
        ])
            .then(([menuData, pageData, current]) => {
                const loadedItems = Array.isArray(menuData.data) ? menuData.data : [];
                setItems(loadedItems);
                setPages(Array.isArray(pageData.data) ? pageData.data : []);
                setItem(current ?? blankItem(loadedItems));
            })
            .catch(err => notifications.show({
                color: "red",
                title: "Error",
                message: err.message || "Failed to load menu item"
            }))
            .finally(() => setLoading(false));
    }, [id, isNew]);

    function update<K extends keyof MenuItemRecord>(key: K, value: MenuItemRecord[K]) {
        setItem(current => current ? { ...current, [key]: value } : current);
    }

    const parentOptions = useMemo(() => items
        .filter(candidate => {
            if (candidate.id === item?.id || candidate.menuGroup !== item?.menuGroup) {
                return false;
            }

            let parentId = candidate.parentId;
            const seen = new Set<number>();
            while (parentId !== null && !seen.has(parentId)) {
                if (parentId === item?.id) {
                    return false;
                }
                seen.add(parentId);
                parentId = items.find(entry => entry.id === parentId)?.parentId ?? null;
            }

            return true;
        })
        .map(candidate => ({
            value: String(candidate.id),
            label: `${candidate.label || "Divider"} (#${candidate.id})`
        })), [item, items]);

    const pageOptions = useMemo(() => pages.map(page => ({
        value: String(page.id),
        label: `${page.pageName || page.action || page.pattern || "Root"} (#${page.id})`
    })), [pages]);

    function save() {
        if (!item) {
            return;
        }

        setSaving(true);
        fetch(isNew ? "/api/v1/admin/menus" : `/api/v1/admin/menus/${id}`, {
            method: isNew ? "POST" : "PATCH",
            headers: { "Content-Type": "application/json", ...csrfHeaders() },
            body: JSON.stringify(item)
        })
            .then(response => responseJson(response, "Failed to save menu item"))
            .then(saved => {
                setItem(saved);
                notifications.show({ color: "green", message: "Menu item saved", autoClose: 2000 });
                if (isNew && saved.id) {
                    navigate(`/menus/${saved.id}`, { replace: true });
                }
            })
            .catch(err => notifications.show({ color: "red", title: "Error", message: err.message }))
            .finally(() => setSaving(false));
    }

    function remove() {
        setDeleting(true);
        fetch(`/api/v1/admin/menus/${id}`, { method: "DELETE", headers: csrfHeaders() })
            .then(response => responseJson(response, "Failed to delete menu item"))
            .then(() => {
                notifications.show({ color: "green", message: "Menu item deleted", autoClose: 2000 });
                navigate("/menus", { replace: true });
            })
            .catch(err => notifications.show({ color: "red", title: "Error", message: err.message }))
            .finally(() => {
                setDeleting(false);
                setConfirmDelete(false);
            });
    }

    if (!item || loading) {
        return <Text>Loading...</Text>;
    }

    const needsPage = item.type === "internal" || item.type === "dynamic";

    return (
        <Stack>
            <Group justify="space-between">
                <Group>
                    <Button variant="light" onClick={() => navigate("/menus")}>Back</Button>
                    {!isNew && (
                        <Button color="red" variant="light" leftSection={<IconTrash size={16} />} onClick={() => setConfirmDelete(true)}>
                            Delete
                        </Button>
                    )}
                </Group>
                <Button loading={saving} onClick={save}>Save</Button>
            </Group>

            <div>
                <Text fw={700} size="xl">{isNew ? "New menu item" : `Menu item #${item.id}`}</Text>
                <Text c="dimmed" size="sm">Choose where and how this item appears in site navigation</Text>
            </div>

            <SimpleGrid cols={{ base: 1, md: 2 }}>
                <TextInput
                    label="Menu group"
                    description="For example: main or bottom"
                    required
                    value={item.menuGroup}
                    onChange={event => setItem(current => current ? {
                        ...current,
                        menuGroup: event.currentTarget.value,
                        parentId: null
                    } : current)}
                />
                <Select
                    label="Type"
                    required
                    data={[
                        { value: "internal", label: "Internal page" },
                        { value: "dynamic", label: "Dynamic page" },
                        { value: "external", label: "External link" },
                        { value: "action", label: "Frontend action" },
                        { value: "divider", label: "Divider" }
                    ]}
                    value={item.type}
                    onChange={value => update("type", (value || "internal") as MenuItemRecord["type"])}
                    allowDeselect={false}
                />
                {item.type !== "divider" && (
                    <TextInput
                        label="Label"
                        required
                        value={item.label || ""}
                        onChange={event => update("label", event.currentTarget.value)}
                    />
                )}
                <Select
                    label="Parent item"
                    description="Only items in the same group can be parents"
                    placeholder="Top level"
                    data={parentOptions}
                    value={item.parentId ? String(item.parentId) : null}
                    onChange={value => update("parentId", value ? Number(value) : null)}
                    clearable
                    searchable
                />
                {needsPage && (
                    <Select
                        label="Page"
                        required
                        searchable
                        data={pageOptions}
                        value={item.pageId ? String(item.pageId) : null}
                        onChange={value => update("pageId", value ? Number(value) : null)}
                    />
                )}
                {item.type === "external" && (
                    <TextInput
                        label="URL"
                        required
                        placeholder="https://example.com"
                        value={item.url || ""}
                        onChange={event => update("url", event.currentTarget.value)}
                    />
                )}
                {item.type === "action" && (
                    <TextInput
                        label="Frontend action"
                        description="Rendered as a data-* attribute"
                        required
                        value={item.action || ""}
                        onChange={event => update("action", event.currentTarget.value)}
                    />
                )}
                <Select
                    label="Access"
                    data={[
                        { value: "public", label: "Public" },
                        { value: "authenticated", label: "Authenticated" },
                        { value: "moderator", label: "Moderator" },
                        { value: "admin", label: "Administrator" }
                    ]}
                    value={item.accessRule}
                    onChange={value => update("accessRule", value || "public")}
                    allowDeselect={false}
                />
                <NumberInput
                    label="Sort order"
                    description="Lower values are shown first"
                    min={0}
                    step={10}
                    allowDecimal={false}
                    value={item.sortOrder}
                    onChange={value => update("sortOrder", Number(value) || 0)}
                />
            </SimpleGrid>

            <Modal opened={confirmDelete} onClose={() => setConfirmDelete(false)} title="Delete menu item?" centered>
                <Stack>
                    <Text>This cannot be undone. Items with children cannot be deleted.</Text>
                    <Group justify="flex-end">
                        <Button variant="default" onClick={() => setConfirmDelete(false)}>Cancel</Button>
                        <Button color="red" loading={deleting} onClick={remove}>Delete</Button>
                    </Group>
                </Stack>
            </Modal>
        </Stack>
    );
}

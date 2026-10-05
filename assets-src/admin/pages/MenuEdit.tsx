import { useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import {
    Button,
    Group,
    Modal,
    Radio,
    Select,
    SimpleGrid,
    Stack,
    Text,
    TextInput,
    Switch
} from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { IconTrash } from "@tabler/icons-react";
import { csrfHeaders } from "../../shared/csrf";
import { type MenuItemRecord } from "../lib/menu";
import { trans } from "../../shared/i18n";

type PageOption = { id: number; pattern?: string; action?: string; pageName?: string };

function blankItem(items: MenuItemRecord[]): MenuItemRecord {
    return {
        parentId: null,
        menuGroup: "main",
        type: "internal",
        pageId: null,
        url: null,
        action: null,
        label: "",
        accessRule: "public",
        sortOrder: 0,
        groupOrder: items.find(item => item.menuGroup === "main")?.groupOrder ?? 10,
        enabled: true
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
    const [childStrategy, setChildStrategy] = useState<"promote" | "delete">("promote");

    useEffect(() => {
        if (!id) {
            return;
        }

        setLoading(true);
        Promise.all([
            fetch("/api/v1/admin/menus").then(response => responseJson(response, trans("js.admin.menu.load_failed"))),
            fetch("/api/v1/admin/pages").then(response => responseJson(response, trans("js.admin.pages.load_failed"))),
            isNew
                ? Promise.resolve(null)
                : fetch(`/api/v1/admin/menus/${id}`).then(response => responseJson(response, trans("js.admin.menu.item_load_failed")))
        ])
            .then(([menuData, pageData, current]) => {
                const loadedItems = Array.isArray(menuData.data) ? menuData.data : [];
                setItems(loadedItems);
                setPages(Array.isArray(pageData.data) ? pageData.data : []);
                setItem(current ?? blankItem(loadedItems));
            })
            .catch(err => notifications.show({
                color: "red",
                title: trans("js.admin.error"),
                message: err.message || trans("js.admin.menu.item_load_failed")
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
            label: `${candidate.label || trans("js.admin.menu.divider")} (#${candidate.id})`
        })), [item, items]);

    const pageOptions = useMemo(() => pages.map(page => ({
        value: String(page.id),
        label: `${page.pageName || page.action || page.pattern || trans("js.admin.root")} (#${page.id})`
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
            .then(response => responseJson(response, trans("js.admin.menu.item_save_failed")))
            .then(saved => {
                setItem(saved);
                notifications.show({ color: "green", message: trans("js.admin.menu.item_saved"), autoClose: 2000 });
                if (isNew && saved.id) {
                    navigate(`/menus/${saved.id}`, { replace: true });
                }
            })
            .catch(err => notifications.show({ color: "red", title: trans("js.admin.error"), message: err.message }))
            .finally(() => setSaving(false));
    }

    function remove() {
        setDeleting(true);
        fetch(`/api/v1/admin/menus/${id}`, {
            method: "DELETE",
            headers: { "Content-Type": "application/json", ...csrfHeaders() },
            body: JSON.stringify({ children: childStrategy })
        })
            .then(response => responseJson(response, trans("js.admin.menu.item_delete_failed")))
            .then(() => {
                notifications.show({ color: "green", message: trans("js.admin.menu.item_deleted"), autoClose: 2000 });
                navigate("/menus", { replace: true });
            })
            .catch(err => notifications.show({ color: "red", title: trans("js.admin.error"), message: err.message }))
            .finally(() => {
                setDeleting(false);
                setConfirmDelete(false);
            });
    }

    if (!item || loading) {
        return <Text>{trans("js.admin.loading")}</Text>;
    }

    const needsPage = item.type === "internal" || item.type === "dynamic";
    const hasChildren = items.some(candidate => candidate.parentId === item.id);

    return (
        <Stack>
            <Group justify="space-between">
                <Group>
                    <Button variant="light" onClick={() => navigate("/menus")}>{trans("js.admin.back")}</Button>
                    {!isNew && (
                        <Button color="red" variant="light" leftSection={<IconTrash size={16} />} onClick={() => setConfirmDelete(true)}>
                            {trans("js.admin.delete")}
                        </Button>
                    )}
                </Group>
                <Button loading={saving} onClick={save}>{trans("js.admin.save")}</Button>
            </Group>

            <div>
                <Text fw={700} size="xl">{isNew ? trans("js.admin.menu.new") : trans("js.admin.menu.item_number", { id: item.id ?? "" })}</Text>
                <Text c="dimmed" size="sm">{trans("js.admin.menu.edit_help")}</Text>
            </div>

            <SimpleGrid cols={{ base: 1, md: 2 }}>
                <TextInput
                    label={trans("js.admin.menu.group")}
                    description={trans("js.admin.menu.group_help")}
                    required
                    value={item.menuGroup}
                    onChange={event => {
                        const menuGroup = event.currentTarget.value;
                        setItem(current => current ? {
                            ...current,
                            menuGroup,
                            parentId: null
                        } : current);
                    }}
                />
                <Select
                    label={trans("js.admin.type")}
                    required
                    data={[
                        { value: "internal", label: trans("js.admin.menu.type_internal") },
                        { value: "dynamic", label: trans("js.admin.menu.type_dynamic") },
                        { value: "external", label: trans("js.admin.menu.type_external") },
                        { value: "action", label: trans("js.admin.menu.type_action") },
                        { value: "divider", label: trans("js.admin.menu.divider") }
                    ]}
                    value={item.type}
                    onChange={value => update("type", (value || "internal") as MenuItemRecord["type"])}
                    allowDeselect={false}
                />
                {item.type !== "divider" && (
                    <TextInput
                        label={trans("js.admin.label")}
                        required
                        value={item.label || ""}
                        onChange={event => update("label", event.currentTarget.value)}
                    />
                )}
                <Select
                    label={trans("js.admin.menu.parent_item")}
                    description={trans("js.admin.menu.parent_help")}
                    placeholder={trans("js.admin.menu.top_level")}
                    data={parentOptions}
                    value={item.parentId ? String(item.parentId) : null}
                    onChange={value => update("parentId", value ? Number(value) : null)}
                    clearable
                    searchable
                />
                {needsPage && (
                    <Select
                        label={trans("js.admin.page")}
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
                    <Select
                        label={trans("js.admin.menu.frontend_action")}
                        description={trans("js.admin.menu.frontend_action_help")}
                        required
                        data={[{ value: "logout", label: trans("js.admin.menu.logout") }]}
                        value={item.action}
                        onChange={value => update("action", value)}
                        allowDeselect={false}
                    />
                )}
                <Select
                    label={trans("js.admin.access")}
                    data={[
                        { value: "public", label: trans("js.admin.access_public") },
                        { value: "authenticated", label: trans("js.admin.access_authenticated") },
                        { value: "moderator", label: trans("js.admin.access_moderator") },
                        { value: "admin", label: trans("js.admin.access_admin") }
                    ]}
                    value={item.accessRule}
                    onChange={value => update("accessRule", value || "public")}
                    allowDeselect={false}
                />
                <Switch
                    label={trans("js.admin.enabled")}
                    description={trans("js.admin.menu.enabled_help")}
                    checked={item.enabled}
                    onChange={event => update("enabled", event.currentTarget.checked)}
                />
            </SimpleGrid>

            <Modal opened={confirmDelete} onClose={() => setConfirmDelete(false)} title={trans("js.admin.menu.delete_title")} centered>
                <Stack>
                    <Text>{trans("js.admin.cannot_undo")}</Text>
                    {hasChildren && (
                        <Radio.Group
                            label={trans("js.admin.menu.children_question")}
                            value={childStrategy}
                            onChange={value => setChildStrategy(value as "promote" | "delete")}
                        >
                            <Stack mt="xs" gap="xs">
                                <Radio value="promote" label={trans("js.admin.menu.children_promote")} />
                                <Radio value="delete" label={trans("js.admin.menu.children_delete")} />
                            </Stack>
                        </Radio.Group>
                    )}
                    <Group justify="flex-end">
                        <Button variant="default" onClick={() => setConfirmDelete(false)}>{trans("js.admin.cancel")}</Button>
                        <Button color="red" loading={deleting} onClick={remove}>{trans("js.admin.delete")}</Button>
                    </Group>
                </Stack>
            </Modal>
        </Stack>
    );
}

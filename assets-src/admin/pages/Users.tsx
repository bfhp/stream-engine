import { useEffect, useState } from "react";
import {
    ActionIcon, Badge, Button, Group, Modal, Pagination, Select, Stack, Switch,
    Table, Text, TextInput, Tooltip
} from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { IconEdit, IconRefresh } from "@tabler/icons-react";
import { csrfHeaders } from "../../shared/csrf";
import { formatTimestamp } from "../lib/format";
import { userListParams, userListState } from "../lib/users";

type UserRole = "user" | "moderator" | "admin";
type AdminUser = {
    id: number; email: string; nick: string; username: string; role: UserRole;
    isActive: boolean; createdAt: number;
};
type UserDraft = Pick<AdminUser, "email" | "nick" | "username" | "role" | "isActive">;
type ListResponse = {
    data: AdminUser[];
    pagination: { currentPage: number; totalPages: number; total: number; limit: number };
    meta: { currentUserId: number; systemUserId: number };
};

const ROLE_OPTIONS = [
    { value: "user", label: "User" },
    { value: "moderator", label: "Moderator" },
    { value: "admin", label: "Administrator" }
];
const STATUS_OPTIONS = [
    { value: "active", label: "Active" },
    { value: "inactive", label: "Inactive" }
];

async function responseError(response: Response, fallback: string): Promise<Error> {
    const text = await response.text();
    try {
        const body = JSON.parse(text) as { error?: string };
        return new Error(body.error || fallback);
    } catch (_error) {
        return new Error(text || fallback);
    }
}

function roleColor(role: UserRole): string {
    if (role === "admin") return "red";
    if (role === "moderator") return "orange";
    return "blue";
}

export default function Users() {
    const [users, setUsers] = useState<AdminUser[]>([]);
    const [query, setQuery] = useState("");
    const [role, setRole] = useState<string | null>(null);
    const [status, setStatus] = useState<string | null>(null);
    const [page, setPage] = useState(1);
    const [totalPages, setTotalPages] = useState(1);
    const [total, setTotal] = useState(0);
    const [currentUserId, setCurrentUserId] = useState(0);
    const [systemUserId, setSystemUserId] = useState(1);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [refreshKey, setRefreshKey] = useState(0);
    const [editing, setEditing] = useState<AdminUser | null>(null);
    const [draft, setDraft] = useState<UserDraft | null>(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const controller = new AbortController();
        const params = userListParams(page, query, role, status);
        setLoading(true);
        setError(null);

        fetch(`/api/v1/admin/users?${params.toString()}`, { signal: controller.signal })
            .then(async response => {
                if (!response.ok) throw await responseError(response, "Failed to load users");
                return response.json() as Promise<ListResponse>;
            })
            .then(data => {
                setUsers(Array.isArray(data.data) ? data.data : []);
                setPage(data.pagination.currentPage);
                setTotalPages(data.pagination.totalPages);
                setTotal(data.pagination.total);
                setCurrentUserId(data.meta.currentUserId);
                setSystemUserId(data.meta.systemUserId);
            })
            .catch(fetchError => {
                if (fetchError.name !== "AbortError") setError(fetchError.message || "Failed to load users");
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });

        return () => controller.abort();
    }, [page, query, refreshKey, role, status]);

    function resetAndSet(setter: (value: string | null) => void, value: string | null) {
        setPage(1);
        setter(value);
    }

    function openEditor(user: AdminUser) {
        setEditing(user);
        setDraft({ email: user.email, nick: user.nick, username: user.username, role: user.role, isActive: user.isActive });
    }

    function updateDraft<K extends keyof UserDraft>(key: K, value: UserDraft[K]) {
        setDraft(current => current ? { ...current, [key]: value } : current);
    }

    function saveUser() {
        if (!editing || !draft) return;
        const privilegeChange = draft.role !== editing.role || draft.isActive !== editing.isActive;
        if (privilegeChange && !window.confirm("Apply this role or account-status change?")) return;

        setSaving(true);
        fetch(`/api/v1/admin/users/${editing.id}`, {
            method: "PATCH",
            headers: { "Content-Type": "application/json", ...csrfHeaders() },
            body: JSON.stringify(draft)
        })
            .then(async response => {
                if (!response.ok) throw await responseError(response, "Failed to save user");
                return response.json() as Promise<AdminUser>;
            })
            .then(saved => {
                setUsers(current => current.map(user => user.id === saved.id ? saved : user));
                setEditing(null);
                setDraft(null);
                notifications.show({ color: "green", message: "User saved", autoClose: 2000 });
            })
            .catch(saveError => notifications.show({
                color: "red", title: "User was not saved", message: saveError.message || "Failed to save user"
            }))
            .finally(() => setSaving(false));
    }

    const selfTargeted = editing?.id === currentUserId;
    const systemTargeted = editing?.id === systemUserId;
    const listState = userListState(loading, error, users.length);

    return (
        <Stack>
            <Group justify="space-between" align="end">
                <div>
                    <Text fw={700} size="xl">Users</Text>
                    <Text c="dimmed" size="sm">{total} accounts</Text>
                </div>
                <Tooltip label="Refresh">
                    <ActionIcon variant="default" loading={loading} onClick={() => setRefreshKey(key => key + 1)}>
                        <IconRefresh size={18} />
                    </ActionIcon>
                </Tooltip>
            </Group>

            <Group align="end" grow>
                <TextInput label="Search" placeholder="Email, name, username or ID" value={query}
                    onChange={event => { setPage(1); setQuery(event.currentTarget.value); }} />
                <Select label="Role" placeholder="All roles" data={ROLE_OPTIONS} value={role}
                    onChange={value => resetAndSet(setRole, value)} clearable />
                <Select label="Status" placeholder="All statuses" data={STATUS_OPTIONS} value={status}
                    onChange={value => resetAndSet(setStatus, value)} clearable />
            </Group>

            {listState === "error" && (
                <Stack align="center" py="xl">
                    <Text c="red">{error}</Text>
                    <Button variant="light" onClick={() => setRefreshKey(key => key + 1)}>Try again</Button>
                </Stack>
            )}

            {listState !== "error" && (
                <Table.ScrollContainer minWidth={850}>
                    <Table striped highlightOnHover withTableBorder>
                        <Table.Thead><Table.Tr>
                            <Table.Th>User</Table.Th><Table.Th>Email</Table.Th><Table.Th>Role</Table.Th>
                            <Table.Th>Status</Table.Th><Table.Th>Registered</Table.Th><Table.Th />
                        </Table.Tr></Table.Thead>
                        <Table.Tbody>
                            {users.map(user => (
                                <Table.Tr key={user.id}>
                                    <Table.Td>
                                        <Text fw={500}>{user.nick || `#${user.id}`}</Text>
                                        <Text c="dimmed" size="xs">@{user.username} · #{user.id}</Text>
                                    </Table.Td>
                                    <Table.Td>{user.email}</Table.Td>
                                    <Table.Td><Badge color={roleColor(user.role)} variant="light">{user.role}</Badge></Table.Td>
                                    <Table.Td><Badge color={user.isActive ? "green" : "gray"} variant="light">
                                        {user.isActive ? "Active" : "Inactive"}
                                    </Badge></Table.Td>
                                    <Table.Td>{formatTimestamp(user.createdAt)}</Table.Td>
                                    <Table.Td><Tooltip label={user.id === systemUserId ? "The system account is protected" : "Edit"}>
                                        <ActionIcon variant="subtle" disabled={user.id === systemUserId}
                                            aria-label={`Edit user ${user.id}`} onClick={() => openEditor(user)}>
                                            <IconEdit size={18} />
                                        </ActionIcon>
                                    </Tooltip></Table.Td>
                                </Table.Tr>
                            ))}
                            {listState === "empty" && <Table.Tr><Table.Td colSpan={6}>
                                <Text c="dimmed" ta="center" py="xl">No users found</Text>
                            </Table.Td></Table.Tr>}
                            {listState === "loading" && <Table.Tr><Table.Td colSpan={6}>
                                <Text c="dimmed" ta="center" py="xl">Loading users…</Text>
                            </Table.Td></Table.Tr>}
                        </Table.Tbody>
                    </Table>
                </Table.ScrollContainer>
            )}

            {listState !== "error" && totalPages > 1 && <Pagination value={page} onChange={setPage} total={totalPages} withEdges mx="auto" />}

            <Modal opened={editing !== null} onClose={() => setEditing(null)} title={`Edit user #${editing?.id ?? ""}`}>
                {draft && <Stack>
                    <TextInput label="Email" value={draft.email} onChange={event => updateDraft("email", event.currentTarget.value)} />
                    <TextInput label="Display name" maxLength={50} value={draft.nick}
                        onChange={event => updateDraft("nick", event.currentTarget.value)} />
                    <TextInput label="Username" maxLength={30} value={draft.username}
                        onChange={event => updateDraft("username", event.currentTarget.value)} />
                    <Select label="Role" data={ROLE_OPTIONS} value={draft.role} disabled={selfTargeted || systemTargeted}
                        onChange={value => value && updateDraft("role", value as UserRole)} allowDeselect={false} />
                    <Switch label="Account active"
                        description={selfTargeted ? "You cannot deactivate your own account." : "Deactivation signs the user out of every session."}
                        checked={draft.isActive} disabled={selfTargeted || systemTargeted}
                        onChange={event => updateDraft("isActive", event.currentTarget.checked)} />
                    <Group justify="flex-end">
                        <Button variant="default" onClick={() => setEditing(null)}>Cancel</Button>
                        <Button loading={saving} onClick={saveUser}>Save</Button>
                    </Group>
                </Stack>}
            </Modal>
        </Stack>
    );
}

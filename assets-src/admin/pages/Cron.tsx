import { useEffect, useMemo, useState } from "react";
import {
    ActionIcon, Alert, Badge, Card, Group, Select, SimpleGrid, Skeleton, Stack, Table, Text, Tooltip
} from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { IconAlertTriangle, IconDeviceFloppy, IconRefresh } from "@tabler/icons-react";
import { csrfHeaders } from "../../shared/csrf";
import { formatTimestamp } from "../lib/format";
import {
    cronStatusColors, formatCronInterval, formatDuration, type CronStatus, type SchedulerStatus
} from "../lib/cron";
import { trans } from "../../shared/i18n";

type CronTask = {
    task: string;
    module: string;
    interval: number;
    status: CronStatus;
    lastStartedAt: number | null;
    lastFinishedAt: number | null;
    lastSucceededAt: number | null;
    nextRunAt: number | null;
    durationMs: number | null;
    lockedAt: number | null;
    consecutiveFailures: number;
    lastError: string | null;
};

type CronMode = "os" | "web" | "off";

type CronPayload = {
    mode: CronMode;
    scheduler: {
        status: SchedulerStatus;
        lastStartedAt: number | null;
        lastFinishedAt: number | null;
    };
    summary: { total: number; running: number; overdue: number; failed: number };
    data: CronTask[];
};

const statusOptions: CronStatus[] = ["scheduled", "running", "due", "overdue", "failed", "never", "stale", "disabled"];

function statusLabel(status: CronStatus | SchedulerStatus): string {
    return trans(`js.admin.cron.status.${status}`);
}

function StatusBadge({ status }: { status: CronStatus | SchedulerStatus }) {
    return <Badge color={cronStatusColors[status]} variant="light">{statusLabel(status)}</Badge>;
}

export default function Cron() {
    const [payload, setPayload] = useState<CronPayload | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [refreshKey, setRefreshKey] = useState(0);
    const [status, setStatus] = useState<string | null>(null);
    const [modeDraft, setModeDraft] = useState<CronMode>("os");
    const [savingMode, setSavingMode] = useState(false);

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError(null);

        fetch("/api/v1/admin/cron", { signal: controller.signal })
            .then(async response => {
                if (!response.ok) throw new Error(await response.text() || trans("js.admin.cron.load_failed"));
                return response.json() as Promise<CronPayload>;
            })
            .then(next => {
                setPayload(next);
                setModeDraft(next.mode);
            })
            .catch(fetchError => {
                if (fetchError.name !== "AbortError") setError(fetchError.message || trans("js.admin.cron.load_failed"));
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });

        return () => controller.abort();
    }, [refreshKey]);

    useEffect(() => {
        const timer = window.setInterval(() => setRefreshKey(key => key + 1), 30_000);
        return () => window.clearInterval(timer);
    }, []);

    const tasks = useMemo(
        () => (payload?.data || []).filter(task => !status || task.status === status),
        [payload, status]
    );

    function saveMode() {
        if (!payload || modeDraft === payload.mode) return;
        setSavingMode(true);

        fetch("/api/v1/admin/settings/cron.mode", {
            method: "PATCH",
            headers: { "Content-Type": "application/json", ...csrfHeaders() },
            body: JSON.stringify({ value: modeDraft })
        })
            .then(async response => {
                if (!response.ok) throw new Error(await response.text() || trans("js.admin.save_failed"));
                setRefreshKey(key => key + 1);
                notifications.show({ color: "green", message: trans("js.admin.settings.saved"), autoClose: 2000 });
            })
            .catch(saveError => notifications.show({ color: "red", title: trans("js.admin.error"), message: saveError.message }))
            .finally(() => setSavingMode(false));
    }

    return <Stack>
        <Group justify="space-between">
            <div>
                <Text fw={700} size="xl">{trans("js.admin.cron.title")}</Text>
                <Text c="dimmed" size="sm">{trans("js.admin.cron.subtitle")}</Text>
            </div>
            <Tooltip label={trans("js.admin.refresh")}>
                <ActionIcon variant="default" loading={loading} onClick={() => setRefreshKey(key => key + 1)}>
                    <IconRefresh size={18} />
                </ActionIcon>
            </Tooltip>
        </Group>

        {error && <Alert color="red" title={trans("js.admin.cron.unavailable")}>{error}</Alert>}
        {payload?.mode === "web" && <Alert color="yellow" icon={<IconAlertTriangle size={18} />} title={trans("js.admin.cron.web_mode_title")}>
            {trans("js.admin.cron.web_mode_description")}
        </Alert>}

        {loading && !payload && <SimpleGrid cols={{ base: 1, sm: 2, lg: 4 }}>{[1, 2, 3, 4].map(id => <Skeleton key={id} height={100} />)}</SimpleGrid>}
        {payload && <>
            <SimpleGrid cols={{ base: 1, sm: 2, lg: 4 }}>
                <Card withBorder>
                    <Text c="dimmed" size="sm">{trans("js.admin.cron.mode")}</Text>
                    <Group mt="xs" wrap="nowrap" align="end">
                        <Select
                            flex={1}
                            value={modeDraft}
                            allowDeselect={false}
                            onChange={value => setModeDraft((value || payload.mode) as CronMode)}
                            data={(["os", "web", "off"] as CronMode[]).map(value => ({
                                value,
                                label: trans(`js.admin.cron.mode.${value}`)
                            }))}
                        />
                        <Tooltip label={trans("js.admin.save")}>
                            <ActionIcon
                                variant="filled"
                                size="lg"
                                loading={savingMode}
                                disabled={modeDraft === payload.mode}
                                onClick={saveMode}
                            >
                                <IconDeviceFloppy size={18} />
                            </ActionIcon>
                        </Tooltip>
                    </Group>
                </Card>
                <Card withBorder>
                    <Group justify="space-between"><Text c="dimmed" size="sm">{trans("js.admin.cron.scheduler")}</Text><StatusBadge status={payload.scheduler.status} /></Group>
                    <Text fw={600} mt="xs">{formatTimestamp(payload.scheduler.lastFinishedAt || payload.scheduler.lastStartedAt)}</Text>
                </Card>
                <Card withBorder>
                    <Text c="dimmed" size="sm">{trans("js.admin.cron.tasks")}</Text>
                    <Text fw={700} size="lg">{payload.summary.total}</Text>
                </Card>
                <Card withBorder>
                    <Text c="dimmed" size="sm">{trans("js.admin.cron.problems")}</Text>
                    <Text fw={700} size="lg" c={payload.summary.overdue + payload.summary.failed > 0 ? "red" : undefined}>
                        {payload.summary.overdue + payload.summary.failed}
                    </Text>
                </Card>
            </SimpleGrid>

            <Group justify="flex-end">
                <Select
                    clearable
                    w={240}
                    placeholder={trans("js.admin.cron.all_statuses")}
                    value={status}
                    onChange={setStatus}
                    data={statusOptions.map(value => ({ value, label: statusLabel(value) }))}
                />
            </Group>

            <Table.ScrollContainer minWidth={1050}>
                <Table striped highlightOnHover withTableBorder>
                    <Table.Thead><Table.Tr>
                        <Table.Th>{trans("js.admin.cron.task")}</Table.Th>
                        <Table.Th>{trans("js.admin.cron.module")}</Table.Th>
                        <Table.Th>{trans("js.admin.cron.interval")}</Table.Th>
                        <Table.Th>{trans("js.admin.status")}</Table.Th>
                        <Table.Th>{trans("js.admin.cron.last_success")}</Table.Th>
                        <Table.Th>{trans("js.admin.cron.next_run")}</Table.Th>
                        <Table.Th>{trans("js.admin.cron.duration")}</Table.Th>
                    </Table.Tr></Table.Thead>
                    <Table.Tbody>
                        {tasks.map(task => <Table.Tr key={task.task}>
                            <Table.Td>
                                <Text ff="monospace" size="sm">{task.task}</Text>
                                {task.lastError && <Text c="red" size="xs" maw={360} lineClamp={2} title={task.lastError}>{task.lastError}</Text>}
                            </Table.Td>
                            <Table.Td>{task.module}</Table.Td>
                            <Table.Td>{formatCronInterval(task.interval)}</Table.Td>
                            <Table.Td><StatusBadge status={task.status} /></Table.Td>
                            <Table.Td>{formatTimestamp(task.lastSucceededAt)}</Table.Td>
                            <Table.Td>{task.status === "due" || task.status === "overdue" || task.status === "failed" || task.status === "never"
                                ? trans("js.admin.cron.now")
                                : formatTimestamp(task.nextRunAt)}</Table.Td>
                            <Table.Td>{formatDuration(task.durationMs)}</Table.Td>
                        </Table.Tr>)}
                        {tasks.length === 0 && <Table.Tr><Table.Td colSpan={7}><Text ta="center" c="dimmed">{trans("js.admin.cron.empty")}</Text></Table.Td></Table.Tr>}
                    </Table.Tbody>
                </Table>
            </Table.ScrollContainer>
        </>}
    </Stack>;
}

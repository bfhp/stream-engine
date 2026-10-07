import { useEffect, useMemo, useState } from "react";
import {
    ActionIcon, Alert, Badge, Button, Card, Group, Modal, Select, SimpleGrid, Skeleton, Stack, Switch,
    Table, Text, Tooltip
} from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { IconAlertTriangle, IconDeviceFloppy, IconPlayerPlay, IconRefresh } from "@tabler/icons-react";
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
    enabled: boolean;
    status: CronStatus;
    lastStartedAt: number | null;
    lastFinishedAt: number | null;
    lastSucceededAt: number | null;
    nextRunAt: number | null;
    durationMs: number | null;
    lockedAt: number | null;
    consecutiveFailures: number;
    lastTrigger: "scheduled" | "manual" | null;
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

async function errorMessage(response: Response, fallback: string): Promise<string> {
    const body = await response.json().catch(() => null) as { error?: string } | null;
    return body?.error || fallback;
}

export default function Cron() {
    const [payload, setPayload] = useState<CronPayload | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [refreshKey, setRefreshKey] = useState(0);
    const [status, setStatus] = useState<string | null>(null);
    const [modeDraft, setModeDraft] = useState<CronMode>("os");
    const [savingMode, setSavingMode] = useState(false);
    const [pendingTasks, setPendingTasks] = useState<Record<string, boolean>>({});
    const [confirmRun, setConfirmRun] = useState<CronTask | null>(null);

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
        const refreshEvery = payload?.data.some(task => task.status === "running") ? 2_000 : 30_000;
        const timer = window.setInterval(() => setRefreshKey(key => key + 1), refreshEvery);
        return () => window.clearInterval(timer);
    }, [payload]);

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

    function setTaskPending(task: string, pending: boolean) {
        setPendingTasks(current => {
            const next = { ...current };
            if (pending) next[task] = true;
            else delete next[task];
            return next;
        });
    }

    async function toggleTask(task: CronTask, enabled: boolean) {
        setTaskPending(task.task, true);
        try {
            const response = await fetch(`/api/v1/admin/cron/${task.task}`, {
                method: "PATCH",
                headers: { "Content-Type": "application/json", ...csrfHeaders() },
                body: JSON.stringify({ enabled })
            });
            if (!response.ok) throw new Error(await errorMessage(response, trans("js.admin.cron.update_failed")));
            setRefreshKey(key => key + 1);
            notifications.show({
                color: "green",
                message: trans(enabled ? "js.admin.cron.enabled" : "js.admin.cron.disabled", { task: task.task }),
                autoClose: 2000
            });
        } catch (updateError) {
            const message = updateError instanceof Error ? updateError.message : trans("js.admin.cron.update_failed");
            notifications.show({ color: "red", title: trans("js.admin.error"), message });
        } finally {
            setTaskPending(task.task, false);
        }
    }

    async function runTask() {
        if (!confirmRun) return;
        const task = confirmRun;
        let accepted = false;
        setConfirmRun(null);
        setTaskPending(task.task, true);
        try {
            const response = await fetch(`/api/v1/admin/cron/${task.task}/run`, {
                method: "POST",
                headers: csrfHeaders()
            });
            if (!response.ok) throw new Error(await errorMessage(response, trans("js.admin.cron.run_failed")));
            accepted = true;
            notifications.show({ color: "green", message: trans("js.admin.cron.run_accepted", { task: task.task }), autoClose: 2500 });
            window.setTimeout(() => {
                setTaskPending(task.task, false);
                setRefreshKey(key => key + 1);
            }, 750);
        } catch (runError) {
            const message = runError instanceof Error ? runError.message : trans("js.admin.cron.run_failed");
            notifications.show({ color: "red", title: trans("js.admin.error"), message });
        } finally {
            if (!accepted) setTaskPending(task.task, false);
        }
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
        {payload?.mode === "off" && <Alert color="gray" title={trans("js.admin.cron.off_mode_title")}>
            {trans("js.admin.cron.off_mode_description")}
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

            <Table.ScrollContainer minWidth={1250}>
                <Table striped highlightOnHover withTableBorder>
                    <Table.Thead><Table.Tr>
                        <Table.Th>{trans("js.admin.cron.task")}</Table.Th>
                        <Table.Th>{trans("js.admin.cron.module")}</Table.Th>
                        <Table.Th>{trans("js.admin.cron.interval")}</Table.Th>
                        <Table.Th>{trans("js.admin.status")}</Table.Th>
                        <Table.Th>{trans("js.admin.cron.last_success")}</Table.Th>
                        <Table.Th>{trans("js.admin.cron.next_run")}</Table.Th>
                        <Table.Th>{trans("js.admin.cron.duration")}</Table.Th>
                        <Table.Th>{trans("js.admin.cron.enabled_column")}</Table.Th>
                        <Table.Th>{trans("js.admin.cron.actions")}</Table.Th>
                    </Table.Tr></Table.Thead>
                    <Table.Tbody>
                        {tasks.map(task => <Table.Tr key={task.task}>
                            <Table.Td>
                                <Text ff="monospace" size="sm">{task.task}</Text>
                                {task.lastError && <Text c="red" size="xs" maw={360} lineClamp={2} title={task.lastError}>{task.lastError}</Text>}
                            </Table.Td>
                            <Table.Td>{task.module}</Table.Td>
                            <Table.Td>{formatCronInterval(task.interval)}</Table.Td>
                            <Table.Td>
                                <StatusBadge status={task.status} />
                                {task.lastTrigger && <Text c="dimmed" size="xs" mt={4}>{trans(`js.admin.cron.trigger.${task.lastTrigger}`)}</Text>}
                            </Table.Td>
                            <Table.Td>{formatTimestamp(task.lastSucceededAt)}</Table.Td>
                            <Table.Td>{task.status === "due" || task.status === "overdue" || task.status === "failed" || task.status === "never"
                                ? trans("js.admin.cron.now")
                                : formatTimestamp(task.nextRunAt)}</Table.Td>
                            <Table.Td>{formatDuration(task.durationMs)}</Table.Td>
                            <Table.Td>
                                <Switch
                                    aria-label={trans("js.admin.cron.toggle_aria", { task: task.task })}
                                    checked={task.enabled}
                                    disabled={Boolean(pendingTasks[task.task])}
                                    onChange={event => toggleTask(task, event.currentTarget.checked)}
                                />
                            </Table.Td>
                            <Table.Td>
                                <Button
                                    size="xs"
                                    variant="light"
                                    leftSection={<IconPlayerPlay size={15} />}
                                    loading={Boolean(pendingTasks[task.task])}
                                    disabled={!task.enabled || task.status === "running"}
                                    onClick={() => setConfirmRun(task)}
                                >
                                    {trans("js.admin.cron.run_now")}
                                </Button>
                            </Table.Td>
                        </Table.Tr>)}
                        {tasks.length === 0 && <Table.Tr><Table.Td colSpan={9}><Text ta="center" c="dimmed">{trans("js.admin.cron.empty")}</Text></Table.Td></Table.Tr>}
                    </Table.Tbody>
                </Table>
            </Table.ScrollContainer>
        </>}

        <Modal
            opened={confirmRun !== null}
            onClose={() => setConfirmRun(null)}
            title={trans("js.admin.cron.run_confirm_title")}
            centered
        >
            <Stack>
                <Text>{trans("js.admin.cron.run_confirm", { task: confirmRun?.task || "" })}</Text>
                <Group justify="flex-end">
                    <Button variant="default" onClick={() => setConfirmRun(null)}>{trans("js.admin.cancel")}</Button>
                    <Button leftSection={<IconPlayerPlay size={16} />} onClick={runTask}>{trans("js.admin.cron.run_now")}</Button>
                </Group>
            </Stack>
        </Modal>
    </Stack>;
}

import { useCallback, useEffect, useState } from "react";
import { Alert, Badge, Button, Group, Stack, Table, Text } from "@mantine/core";
import { Link } from "react-router-dom";
import { csrfHeaders } from "../../shared/csrf";
import { trans } from "../../shared/i18n";
import { formatNumber, formatTimestamp } from "../lib/format";

export type HealthResult = {
    status: "ok" | "warning" | "error" | "unconfigured";
    message: string;
    checkedAt: number;
    durationMs: number;
    lastSucceededAt: number | null;
    consecutiveFailures: number;
    stale: boolean;
};
export type HealthCheck = { id: string; label: string; cron: HealthResult | null; web: HealthResult | null };
type Payload = { interval: number; data: HealthCheck[] };

function Result({ result }: { result: HealthResult | null }) {
    if (!result) return <Text c="dimmed" size="sm">{trans("js.admin.health.never")}</Text>;
    const color = { ok: "green", warning: "yellow", error: "red", unconfigured: "gray" }[result.status];
    return <Stack gap={4}>
        <Group gap="xs">
            <Badge color={color}>{trans(`js.admin.health.status.${result.status}`)}</Badge>
            {result.stale && <Badge color="orange">{trans("js.admin.health.stale")}</Badge>}
        </Group>
        <Text size="sm">{result.message}</Text>
        <Text size="xs" c="dimmed">{formatTimestamp(result.checkedAt)} · {formatNumber(result.durationMs)} ms</Text>
        <Text size="xs" c="dimmed">{trans("js.admin.health.last_success")}: {formatTimestamp(result.lastSucceededAt)}</Text>
    </Stack>;
}

export default function Health() {
    const [payload, setPayload] = useState<Payload | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [pending, setPending] = useState<string | null>(null);

    const load = useCallback(async (signal?: AbortSignal) => {
        setLoading(true);
        setError(null);
        try {
            const response = await fetch("/api/v1/admin/health", { signal });
            if (!response.ok) throw new Error(trans("js.admin.health.load_failed"));
            const next = await response.json() as Payload;
            if (!signal?.aborted) setPayload(next);
        } catch (failure) {
            if (!signal?.aborted) setError(failure instanceof Error ? failure.message : trans("js.admin.health.load_failed"));
        } finally {
            if (!signal?.aborted) setLoading(false);
        }
    }, []);

    useEffect(() => {
        const controller = new AbortController();
        void load(controller.signal);
        return () => controller.abort();
    }, [load]);

    async function run(id: string) {
        setPending(id);
        setError(null);
        try {
            const response = await fetch(`/api/v1/admin/health/${encodeURIComponent(id)}/run`, {
                method: "POST", headers: csrfHeaders(),
            });
            if (!response.ok) {
                const body = await response.json().catch(() => null) as { error?: string } | null;
                throw new Error(body?.error || trans("js.admin.health.run_failed"));
            }
            setPayload(await response.json() as Payload);
        } catch (failure) {
            setError(failure instanceof Error ? failure.message : trans("js.admin.health.run_failed"));
        } finally {
            setPending(null);
        }
    }

    const outdated = payload?.data.some(check => !check.cron || check.cron.stale);
    return <Stack>
        <Group justify="space-between">
            <Text component="h1" fw={700} size="xl" m={0}>{trans("js.admin.health.title")}</Text>
            <Button variant="default" loading={loading} disabled={pending !== null} onClick={() => void load()}>
                {trans("js.admin.refresh")}
            </Button>
        </Group>
        <Text c="dimmed">{trans("js.admin.health.description")}</Text>
        <Text size="sm">{trans("js.admin.health.contexts")}</Text>
        <Text size="sm">{trans("js.admin.health.notifications")}</Text>
        <Button component={Link} to="/cron" variant="subtle" style={{ alignSelf: "start" }}>
            {trans("js.admin.health.scheduler")}
        </Button>
        {error && <Alert color="red" role="alert">{error}</Alert>}
        {outdated && <Alert color="orange">{trans("js.admin.health.outdated")}</Alert>}
        {loading && !payload && <Text role="status">{trans("js.admin.loading")}</Text>}
        {payload && <Table.ScrollContainer minWidth={850}>
            <Table striped withTableBorder verticalSpacing="md">
                <Table.Thead><Table.Tr>
                    <Table.Th>{trans("js.admin.health.check")}</Table.Th>
                    <Table.Th>{trans("js.admin.health.cron")}</Table.Th>
                    <Table.Th>{trans("js.admin.health.web")}</Table.Th>
                    <Table.Th>{trans("js.admin.cron.actions")}</Table.Th>
                </Table.Tr></Table.Thead>
                <Table.Tbody>{payload.data.map(check => <Table.Tr key={check.id}>
                    <Table.Th scope="row">{check.label}</Table.Th>
                    <Table.Td><Result result={check.cron} /></Table.Td>
                    <Table.Td><Result result={check.web} /></Table.Td>
                    <Table.Td><Button size="xs" variant="light"
                        loading={pending === check.id} disabled={pending !== null || loading}
                        aria-label={trans("js.admin.health.run_check", { check: check.label })}
                        onClick={() => void run(check.id)}>{trans("js.admin.health.run")}</Button></Table.Td>
                </Table.Tr>)}</Table.Tbody>
            </Table>
        </Table.ScrollContainer>}
    </Stack>;
}

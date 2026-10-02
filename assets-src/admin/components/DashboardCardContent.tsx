import { Alert, Box, Button, Group, SimpleGrid, Skeleton, Stack, Text } from "@mantine/core";
import { formatNumber, formatTimestamp } from "../lib/format";
import type { DashboardSize } from "../lib/dashboard";
import { trans } from "../../shared/i18n";

export type CardDefinition = {
    id: string;
    label: string;
    kind: "metrics" | "links" | "list";
    sizes: DashboardSize[];
    defaultSize: DashboardSize;
    defaultPosition: number;
    module: string;
};

export type CardResult = {
    id: string;
    status: "ready" | "empty" | "error" | "unavailable";
    data: unknown;
};

type Metric = { label: string; value: string | number; format?: "timestamp"; href?: string };
type Shortcut = { label: string; href: string };
type ListItem = { id: string; label: string; description?: string; timestamp?: number; href?: string };

type DashboardCardContentProps = {
    definition: CardDefinition;
    result?: CardResult;
    loading?: boolean;
};

export default function DashboardCardContent({
    definition,
    result,
    loading = false
}: DashboardCardContentProps) {
    if (loading || !result) return <Skeleton height={70} data-card-state="loading" />;
    if (result.status === "error") {
        return <Alert color="red" data-card-state="error">{trans("js.admin.dashboard.card_error")}</Alert>;
    }
    if (result.status === "unavailable") {
        return <Text c="dimmed" data-card-state="unavailable">{trans("js.admin.dashboard.card_unavailable")}</Text>;
    }
    if (result.status === "empty") {
        return <Text c="dimmed" data-card-state="empty">{trans("js.admin.dashboard.card_empty")}</Text>;
    }
    if (definition.kind === "metrics") {
        const metrics = Array.isArray(result.data) ? result.data as Metric[] : [];
        return <SimpleGrid data-card-state="ready" data-card-kind="metrics"
            cols={{ base: 1, sm: Math.min(metrics.length, 3) }}>
            {metrics.map(metric => {
                const content = <>
                    <Text size="xl" fw={700}>
                        {metric.format === "timestamp" ? formatTimestamp(Number(metric.value))
                            : typeof metric.value === "number" ? formatNumber(metric.value) : metric.value}
                    </Text>
                    <Text size="sm" c="dimmed">{trans(metric.label)}</Text>
                </>;
                return metric.href
                    ? <Box component="a" key={metric.label} href={metric.href}
                        style={{ color: "inherit", textDecoration: "none" }}>{content}</Box>
                    : <div key={metric.label}>{content}</div>;
            })}
        </SimpleGrid>;
    }
    if (definition.kind === "links") {
        const links = Array.isArray(result.data) ? result.data as Shortcut[] : [];
        return <Group data-card-state="ready" data-card-kind="links">
            {links.map(link => <Button key={link.href} component="a" href={link.href} variant="light">
                {trans(link.label)}
            </Button>)}
        </Group>;
    }

    const items = Array.isArray(result.data) ? result.data as ListItem[] : [];
    return <Stack gap="xs" data-card-state="ready" data-card-kind="list">
        {items.map(item => <Group key={item.id} justify="space-between" wrap="nowrap">
            <div>
                {item.href
                    ? <Text component="a" href={item.href} fw={500}>{trans(item.label)}</Text>
                    : <Text fw={500}>{trans(item.label)}</Text>}
                {item.description && <Text c="dimmed" size="xs">{trans(item.description)}</Text>}
            </div>
            {item.timestamp && <Text c="dimmed" size="xs" style={{ whiteSpace: "nowrap" }}>
                {formatTimestamp(item.timestamp)}
            </Text>}
        </Group>)}
    </Stack>;
}

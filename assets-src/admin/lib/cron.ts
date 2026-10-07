import { formatNumber } from "./format";
import { trans } from "../../shared/i18n";

export type CronStatus = "scheduled" | "queued" | "running" | "due" | "overdue" | "failed" | "start_failed" | "timed_out" | "never" | "stale" | "disabled";
export type SchedulerStatus = "healthy" | "running" | "stale" | "failed" | "never" | "disabled";

export const cronStatusColors: Record<CronStatus | SchedulerStatus, string> = {
    healthy: "green",
    scheduled: "green",
    queued: "cyan",
    running: "blue",
    due: "yellow",
    overdue: "orange",
    failed: "red",
    start_failed: "red",
    timed_out: "orange",
    never: "gray",
    stale: "red",
    disabled: "gray",
};

export function formatCronInterval(seconds: number): string {
    if (seconds === 86400) return trans("js.admin.cron.interval_day");
    if (seconds === 3600) return trans("js.admin.cron.interval_hour");
    if (seconds === 60) return trans("js.admin.cron.interval_minute");
    if (seconds % 86400 === 0) return trans("js.admin.cron.interval_days", { count: seconds / 86400 });
    if (seconds % 3600 === 0) return trans("js.admin.cron.interval_hours", { count: seconds / 3600 });
    if (seconds % 60 === 0) return trans("js.admin.cron.interval_minutes", { count: seconds / 60 });

    return trans("js.admin.cron.interval_seconds", { count: seconds });
}

export function formatDuration(milliseconds: number | null): string {
    if (milliseconds === null) return "-";
    if (milliseconds < 1000) return `${formatNumber(milliseconds)} ms`;

    return `${formatNumber(milliseconds / 1000, { maximumFractionDigits: 2 })} s`;
}

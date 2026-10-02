import { getLocale } from "./i18n";

export type DateFormat = "auto" | "dmy" | "mdy" | "iso";
export type TimeFormat = "auto" | "24h" | "12h";

function configuredDateFormat(): DateFormat {
    const value = document.documentElement.dataset.dateFormat;

    return value === "dmy" || value === "mdy" || value === "iso" ? value : "auto";
}

function configuredTimeFormat(): TimeFormat {
    const value = document.documentElement.dataset.timeFormat;

    return value === "24h" || value === "12h" ? value : "auto";
}

function pad(value: number): string {
    return String(value).padStart(2, "0");
}

export function formatDateValue(
    date: Date,
    style: "short" | "medium" | "long" = "medium",
    locale = getLocale(),
    format = configuredDateFormat(),
): string {
    if (format === "auto") {
        return new Intl.DateTimeFormat(locale, { dateStyle: style }).format(date);
    }

    const day = pad(date.getDate());
    const month = pad(date.getMonth() + 1);
    const year = date.getFullYear();

    if (format === "mdy") return `${month}/${day}/${year}`;
    if (format === "iso") return `${year}-${month}-${day}`;

    return `${day}.${month}.${year}`;
}

export function formatTimeValue(
    date: Date,
    locale = getLocale(),
    format = configuredTimeFormat(),
): string {
    if (format === "24h") {
        return `${pad(date.getHours())}:${pad(date.getMinutes())}`;
    }

    return new Intl.DateTimeFormat(locale, {
        hour: "numeric",
        minute: "2-digit",
        ...(format === "12h" ? { hour12: true } : {}),
    }).format(date);
}

export function formatDateTimeValue(
    date: Date,
    locale = getLocale(),
    dateFormat = configuredDateFormat(),
    timeFormat = configuredTimeFormat(),
): string {
    if (dateFormat === "auto" && timeFormat === "auto") {
        return new Intl.DateTimeFormat(locale, {
            dateStyle: "medium",
            timeStyle: "short",
        }).format(date);
    }

    return `${formatDateValue(date, "medium", locale, dateFormat)}, ${formatTimeValue(date, locale, timeFormat)}`;
}

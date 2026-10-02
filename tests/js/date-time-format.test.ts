import { describe, expect, it } from "vitest";

import {
    formatDateTimeValue,
    formatDateValue,
    formatTimeValue,
} from "../../assets-src/shared/date-time-format";

const DATE = new Date(2026, 3, 23, 14, 5);

describe("date and time display settings", () => {
    it("supports each explicit date format", () => {
        expect(formatDateValue(DATE, "medium", "en", "dmy")).toBe("23.04.2026");
        expect(formatDateValue(DATE, "medium", "en", "mdy")).toBe("04/23/2026");
        expect(formatDateValue(DATE, "medium", "en", "iso")).toBe("2026-04-23");
    });

    it("delegates automatic dates to the selected locale", () => {
        expect(formatDateValue(DATE, "medium", "en", "auto")).toBe(
            new Intl.DateTimeFormat("en", { dateStyle: "medium" }).format(DATE),
        );
    });

    it("supports 24-hour and 12-hour clocks", () => {
        expect(formatTimeValue(DATE, "en", "24h")).toBe("14:05");
        expect(formatTimeValue(DATE, "en", "12h")).toBe("2:05 PM");
    });

    it("combines explicit date and time settings", () => {
        expect(formatDateTimeValue(DATE, "en", "iso", "24h")).toBe("2026-04-23, 14:05");
    });
});

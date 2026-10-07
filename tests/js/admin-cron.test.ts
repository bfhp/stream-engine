import { afterEach, describe, expect, it } from "vitest";
import { cronStatusColors, formatCronInterval, formatDuration } from "../../assets-src/admin/lib/cron";
import { resetTranslations, setTranslationsForTests } from "../../assets-src/shared/i18n";

describe("admin cron formatting", () => {
    afterEach(() => resetTranslations());

    it("uses readable labels for the common one-minute and one-hour intervals", () => {
        setTranslationsForTests({
            "js.admin.cron.interval_minute": "Every minute",
            "js.admin.cron.interval_hour": "Every hour",
        });

        expect(formatCronInterval(60)).toBe("Every minute");
        expect(formatCronInterval(3600)).toBe("Every hour");
    });

    it("formats short and long durations without inventing a value", () => {
        expect(formatDuration(null)).toBe("-");
        expect(formatDuration(250)).toBe("250 ms");
        expect(formatDuration(1250)).toBe("1.25 s");
    });

    it("renders disabled scheduling as a neutral state", () => {
        expect(cronStatusColors.disabled).toBe("gray");
    });
});

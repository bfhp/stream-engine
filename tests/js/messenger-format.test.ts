import { describe, expect, it } from "vitest";

import {
    AVATAR_COLORS,
    avatarColor,
    computeReadStatus,
    daysBetween,
    formatConvTime,
    formatDayLabel,
    formatTime,
    hardenMessageLinks,
    initialsFromName,
    pad,
    startOfDay,
    stripTags,
} from "../../assets-src/site/messenger-format";
import { bytesToLabel } from "../../assets-src/shared/bytes";

/**
 * The messenger's pure half, now that it is out of `initMessages()`.
 *
 * Dates are built with the local-time `Date` constructor and `now` is passed
 * in explicitly, so nothing here depends on the wall clock or on the runner's
 * timezone - which matters because every one of these functions works in
 * *local* calendar days, not in UTC.
 */

/** Local-time seconds-since-epoch, the shape the API sends. */
function at(year: number, month: number, day: number, hour = 12, minute = 0): number {
    return new Date(year, month - 1, day, hour, minute, 0).getTime() / 1000;
}

const NOW = new Date(2026, 2, 15, 14, 30, 0); // Sunday 15 March 2026, 14:30 local

describe("pad()", () => {
    it("pads a single digit and leaves two alone", () => {
        expect(pad(0)).toBe("00");
        expect(pad(7)).toBe("07");
        expect(pad(15)).toBe("15");
        // Not truncated - a three-digit value is a bug upstream, not
        // something to silently shorten.
        expect(pad(123)).toBe("123");
    });
});

describe("startOfDay()", () => {
    it("keeps the calendar day and drops everything below it", () => {
        const midnight = startOfDay(new Date(2026, 2, 15, 23, 59, 59, 999));

        expect(midnight.getFullYear()).toBe(2026);
        expect(midnight.getMonth()).toBe(2);
        expect(midnight.getDate()).toBe(15);
        expect(midnight.getHours()).toBe(0);
        expect(midnight.getMinutes()).toBe(0);
        expect(midnight.getSeconds()).toBe(0);
        expect(midnight.getMilliseconds()).toBe(0);
    });
});

describe("daysBetween()", () => {
    it("counts calendar days, not elapsed hours", () => {
        // 40 minutes ago, but on the other side of midnight - "yesterday",
        // which is what the day separator has to say. An elapsed-hours
        // implementation would call this 0.
        const now = new Date(2026, 2, 15, 0, 20);

        expect(daysBetween(at(2026, 3, 14, 23, 40), now)).toBe(1);
    });

    it("is zero for any moment on the same day, however far apart", () => {
        expect(daysBetween(at(2026, 3, 15, 0, 0), NOW)).toBe(0);
        expect(daysBetween(at(2026, 3, 15, 23, 59), NOW)).toBe(0);
    });

    it("is negative for a timestamp in the future", () => {
        // Clock skew between the server and the visitor's machine. The
        // formatters below branch on `<= 0` because of this.
        expect(daysBetween(at(2026, 3, 16), NOW)).toBe(-1);
    });

    it("counts a whole week as seven", () => {
        expect(daysBetween(at(2026, 3, 8), NOW)).toBe(7);
    });

    /**
     * The reason both ends are collapsed to midnight before subtracting: on a
     * 23- or 25-hour day, dividing elapsed milliseconds by 86400000 gives
     * 0.958 or 1.04, and the surrounding code compares against exact integers.
     * The Math.round() would rescue those two, but not a run of them.
     */
    it("still counts one day across a short or long day", () => {
        const shortDay = daysBetween(at(2026, 3, 14, 12), new Date(2026, 2, 15, 12));
        expect(shortDay).toBe(1);

        // A full week either side of a DST change in most zones.
        expect(daysBetween(at(2026, 3, 22, 12), new Date(2026, 2, 29, 12))).toBe(7);
    });
});

describe("formatTime()", () => {
    it("is a zero-padded 24-hour clock", () => {
        expect(formatTime(at(2026, 3, 15, 9, 5))).toBe("09:05");
        expect(formatTime(at(2026, 3, 15, 23, 59))).toBe("23:59");
        expect(formatTime(at(2026, 3, 15, 0, 0))).toBe("00:00");
    });
});

describe("formatConvTime()", () => {
    it("shows a time for today", () => {
        expect(formatConvTime(at(2026, 3, 15, 9, 5), NOW)).toBe("09:05");
    });

    /**
     * The `<= 0` rather than `=== 0`. A message the server stamped slightly
     * ahead of the visitor's clock must not fall through to the weekday
     * branch, which would name a day that hasn't happened.
     */
    it("shows a time for a timestamp in the future", () => {
        expect(formatConvTime(at(2026, 3, 16, 9, 5), NOW)).toBe("09:05");
    });

    it("shows a short weekday for the last six days", () => {
        // Saturday 14 March 2026.
        expect(formatConvTime(at(2026, 3, 14), NOW)).toBe("Sat");
        // Monday 9 March, six days back - still inside the window.
        expect(formatConvTime(at(2026, 3, 9), NOW)).toBe("Mon");
    });

    it("switches to a date on the seventh day", () => {
        // Exactly a week back is where the weekday would start repeating and
        // stop meaning anything.
        expect(formatConvTime(at(2026, 3, 8), NOW)).toBe("08.03.26");
    });

    it("uses a two-digit year, and gets 2000-2009 right", () => {
        // `String(year).slice(2)` - the case that breaks a naive `% 100`
        // written as a number, which would render 2007 as "7".
        expect(formatConvTime(at(2007, 1, 5), NOW)).toBe("05.01.07");
        expect(formatConvTime(at(2000, 12, 31), NOW)).toBe("31.12.00");
    });
});

describe("formatDayLabel()", () => {
    it("names today and yesterday", () => {
        expect(formatDayLabel(at(2026, 3, 15, 9), NOW)).toBe("Today.");
        expect(formatDayLabel(at(2026, 3, 14, 9), NOW)).toBe("Yesterday.");
    });

    it("spells out the weekday for the rest of the week, capitalized", () => {
        // The constant holds them lower-cased for use mid-sentence; this is a
        // heading, so the first letter is raised here.
        expect(formatDayLabel(at(2026, 3, 13), NOW)).toBe("Friday");
        expect(formatDayLabel(at(2026, 3, 9), NOW)).toBe("Monday");
    });

    it("falls back to a full date from a week back", () => {
        // Four-digit year, unlike the conversation list - a day separator has
        // the room.
        expect(formatDayLabel(at(2026, 3, 8), NOW)).toBe("08.03.2026");
        expect(formatDayLabel(at(2007, 1, 5), NOW)).toBe("05.01.2007");
    });

    it("treats a future timestamp as today rather than as a weekday", () => {
        // `=== 0` here, unlike formatConvTime's `<= 0`, so a skewed timestamp
        // takes the date branch instead. Recorded as the divergence it is.
        expect(formatDayLabel(at(2026, 3, 16), NOW)).toBe("16.03.2026");
    });
});

describe("avatarColor()", () => {
    it("is stable for the same id", () => {
        // The same person is the same colour in every conversation, which is
        // the only reason to derive it from the id rather than pick one.
        expect(avatarColor(42)).toBe(avatarColor(42));
    });

    it("wraps around the palette", () => {
        expect(avatarColor(0)).toBe(AVATAR_COLORS[0]);
        expect(avatarColor(AVATAR_COLORS.length)).toBe(AVATAR_COLORS[0]);
        expect(avatarColor(AVATAR_COLORS.length + 3)).toBe(AVATAR_COLORS[3]);
    });

    it("never returns undefined for a negative id", () => {
        // `Math.abs` - without it a negative id indexes off the front of the
        // array and the avatar renders with `background:undefined`.
        expect(AVATAR_COLORS).toContain(avatarColor(-3));
    });
});

describe("initialsFromName()", () => {
    it("takes the first letter of the first two words", () => {
        expect(initialsFromName("Михаил Булгаков")).toBe("МБ");
        expect(initialsFromName("Кандыба Виктор Михайлович")).toBe("КВ");
    });

    it("takes one letter from a single word", () => {
        expect(initialsFromName("Гомер")).toBe("Г");
    });

    it("upper-cases whatever it finds", () => {
        expect(initialsFromName("михаил булгаков")).toBe("МБ");
    });

    it("ignores extra whitespace", () => {
        expect(initialsFromName("   Михаил    Булгаков  ")).toBe("МБ");
    });

    /**
     * `User::getDisplayName()` falls back to `#42` for a user who never set a
     * nick. Without stripping the `#` every such user would share one avatar
     * marked `#`; with it they get the first digit of their id.
     */
    it("strips the leading hash of a nickless display name", () => {
        expect(initialsFromName("#42")).toBe("4");
    });

    it("falls back to a question mark rather than an empty avatar", () => {
        expect(initialsFromName("")).toBe("?");
        expect(initialsFromName("   ")).toBe("?");
        expect(initialsFromName("#")).toBe("?");
        expect(initialsFromName(null as unknown as string)).toBe("?");
    });
});

describe("stripTags()", () => {
    it("returns the text of a message, not its markup", () => {
        expect(stripTags('Смотри <a href="https://example.com">тут</a>'))
            .toBe("Смотри тут");
    });

    it("decodes entities", () => {
        expect(stripTags("&quot;Зикр&quot; &amp; Co")).toBe('"Зикр" & Co');
    });

    it("does not let the parse reach the page", () => {
        // innerHTML is used to get at the text; textContent is read back, so
        // nothing that was parsed is ever inserted anywhere.
        expect(stripTags('<img src=x onerror="alert(1)">')).toBe("");
    });

    it("survives a null or undefined body", () => {
        // A conversation whose last message was deleted comes back with a
        // null preview.
        expect(stripTags(null as unknown as string)).toBe("");
        expect(stripTags(undefined as unknown as string)).toBe("");
    });
});

describe("hardenMessageLinks()", () => {
    function render(html: string): HTMLElement {
        const root = document.createElement("div");
        root.innerHTML = html;

        return root;
    }

    it("makes a message link open safely", () => {
        const root = render('<div class="msgr-bubble-text"><a href="https://example.com">тут</a></div>');

        hardenMessageLinks(root);

        const a = root.querySelector("a")!;
        expect(a.getAttribute("target")).toBe("_blank");
        expect(a.getAttribute("rel")).toBe("noopener noreferrer nofollow");
    });

    it("overwrites whatever the server sent", () => {
        // The point of doing this client-side after every render: safe click
        // behaviour must not depend on the backend getting it right.
        const root = render(
            '<div class="msgr-bubble-text"><a href="https://example.com" target="_self" rel="opener">тут</a></div>'
        );

        hardenMessageLinks(root);

        const a = root.querySelector("a")!;
        expect(a.getAttribute("target")).toBe("_blank");
        expect(a.getAttribute("rel")).toBe("noopener noreferrer nofollow");
    });

    it("leaves the messenger's own chrome alone", () => {
        // Scoped to the bubble text - the conversation rows, the header and
        // the action buttons are all links too, and they navigate in place.
        const root = render('<a class="msgr-conv-row" href="/profile/">Профиль</a>');

        hardenMessageLinks(root);

        expect(root.querySelector("a")!.getAttribute("target")).toBeNull();
    });

    it("hardens every link in a bubble, and every bubble", () => {
        const root = render(`
            <div class="msgr-bubble-text"><a href="https://a.example">a</a><a href="https://b.example">b</a></div>
            <div class="msgr-bubble-text"><a href="https://c.example">c</a></div>
        `);

        hardenMessageLinks(root);

        expect([...root.querySelectorAll("a")].every(a => a.getAttribute("target") === "_blank")).toBe(true);
    });

    it("ignores an anchor with no href", () => {
        // `a[href]` - a bare <a> is not a link and setting rel on it would be
        // meaningless.
        const root = render('<div class="msgr-bubble-text"><a>не ссылка</a></div>');

        hardenMessageLinks(root);

        expect(root.querySelector("a")!.getAttribute("rel")).toBeNull();
    });
});

/**
 * The double tick. Worth more care than its four lines suggest: it is the
 * only place the messenger claims something about another person's behaviour,
 * and both wrong answers are bad - a tick that never turns double reads as a
 * broken app, one that turns double early is a lie.
 */
describe("computeReadStatus()", () => {
    const ME = 7;
    const THEM = 9;
    const THIRD = 11;

    const mine = { id: 100, user_id: ME };
    const theirs = { id: 100, user_id: THEM };

    it("shows no tick at all on somebody else's message", () => {
        // null, not false: false would render a single tick under a message
        // the visitor did not send.
        expect(computeReadStatus(theirs, ME, [ME, THEM], { [ME]: 100 })).toBeNull();
    });

    it("is unread while the other side's watermark is behind", () => {
        expect(computeReadStatus(mine, ME, [ME, THEM], { [THEM]: 99 })).toBe(false);
    });

    it("is read once the watermark reaches the message", () => {
        expect(computeReadStatus(mine, ME, [ME, THEM], { [THEM]: 100 })).toBe(true);
    });

    it("is read when the watermark is past the message", () => {
        // `>=`, not `===` - someone who has read a later message has
        // certainly read this one, and in a busy chat the watermark is almost
        // never exactly this id.
        expect(computeReadStatus(mine, ME, [ME, THEM], { [THEM]: 5000 })).toBe(true);
    });

    it("treats a participant with no watermark as having read nothing", () => {
        expect(computeReadStatus(mine, ME, [ME, THEM], {})).toBe(false);
    });

    it("ignores your own watermark", () => {
        // Otherwise every message would be read the moment it was sent, since
        // sending advances your own read state.
        expect(computeReadStatus(mine, ME, [ME, THEM], { [ME]: 100, [THEM]: 99 })).toBe(false);
    });

    it("needs everyone in a group, not just the fastest reader", () => {
        // "Read" means read by all, the same rule for a group as for a direct
        // chat - one member catching up is not the whole room.
        expect(computeReadStatus(mine, ME, [ME, THEM, THIRD], { [THEM]: 100, [THIRD]: 99 })).toBe(false);
        expect(computeReadStatus(mine, ME, [ME, THEM, THIRD], { [THEM]: 100, [THIRD]: 100 })).toBe(true);
    });

    it("is unread in a conversation with nobody else left in it", () => {
        // `every()` over an empty list is true, which would double-tick a
        // message nobody can have read - hence the explicit guard.
        expect(computeReadStatus(mine, ME, [ME], { [ME]: 100 })).toBe(false);
    });
});

describe("bytesToLabel()", () => {
    it("uses bytes below a kilobyte", () => {
        expect(bytesToLabel(0)).toBe("0 bytes");
        expect(bytesToLabel(1023)).toBe("1023 bytes");
    });

    it("uses whole kilobytes up to a megabyte", () => {
        expect(bytesToLabel(1024)).toBe("1 kilobyte");
        expect(bytesToLabel(1536)).toBe("2 kilobytes");
        expect(bytesToLabel(1024 * 1024 - 1)).toBe("1024 kilobytes");
    });

    it("uses one decimal from a megabyte up", () => {
        expect(bytesToLabel(1024 * 1024)).toBe("1.0 megabyte");
        expect(bytesToLabel(1024 * 1024 * 3 + 512 * 1024)).toBe("3.5 megabytes");
    });

    /**
     * A forum attachment gets this label while it uploads and
     * `ForumsController::formatFileSize()`'s once the page reloads. The two
     * are separate implementations in separate languages, so the boundaries
     * above are duplicated in `ForumsControllerTest::fileSizeProvider()` -
     * if either drifts, a file appears to change size on refresh.
     */
    it("agrees with the PHP side at every tier boundary", () => {
        expect([0, 512, 1023, 1024, 1536, 1024 * 1024 - 1, 1024 * 1024].map(bytesToLabel))
            .toEqual([
                "0 bytes",
                "512 bytes",
                "1023 bytes",
                "1 kilobyte",
                "2 kilobytes",
                "1024 kilobytes",
                "1.0 megabyte",
            ]);
    });
});

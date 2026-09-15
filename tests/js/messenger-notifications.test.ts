import { beforeEach, describe, expect, it } from "vitest";

import {
    NotificationConversation,
    planToasts,
    previewText,
    totalUnread,
    updateUnreadBadge,
} from "../../assets-src/site/messenger-notifications";

/**
 * What the five-second background poll does with what it gets back.
 *
 * Both of these run on every page of the site for every logged-in visitor, and
 * both fail quietly: a badge that never clears looks like unread mail that
 * isn't there, and a toast rule that is one comparison off means the site
 * announces the visitor's own messages back to them, forever, on every poll.
 */

function conversation(
    id: number,
    unread: number,
    last?: { id: number; text?: string; own?: boolean },
): NotificationConversation {
    return {
        id,
        unread_count: unread,
        last_message: last
            ? { id: last.id, text: last.text ?? "привет", is_own: last.own ?? false }
            : undefined,
    };
}

describe("totalUnread()", () => {
    it("sums across conversations", () => {
        expect(totalUnread([conversation(1, 2), conversation(2, 3)])).toBe(5);
    });

    it("is zero for an empty list", () => {
        expect(totalUnread([])).toBe(0);
    });
});

describe("updateUnreadBadge()", () => {
    let link: HTMLAnchorElement;

    beforeEach(() => {
        document.body.innerHTML = '<a href="/custom-inbox/" data-messages-link class="nav-link position-relative">Сообщения</a>';
        link = document.querySelector('[data-messages-link]')!;
    });

    const badge = () => link.querySelector(".badge");

    it("adds a badge when there is unread mail", () => {
        updateUnreadBadge([conversation(1, 3)]);

        expect(badge()).not.toBeNull();
        expect(badge()!.textContent).toBe("3");
    });

    it("updates the existing badge rather than adding a second", () => {
        updateUnreadBadge([conversation(1, 3)]);
        updateUnreadBadge([conversation(1, 5)]);

        expect(link.querySelectorAll(".badge")).toHaveLength(1);
        expect(badge()!.textContent).toBe("5");
    });

    it("removes the badge when everything has been read", () => {
        // Not just blanked: an empty `.badge` still renders as a coloured dot,
        // which reads as "one unread" at a glance.
        updateUnreadBadge([conversation(1, 3)]);
        updateUnreadBadge([conversation(1, 0)]);

        expect(badge()).toBeNull();
    });

    it("adds nothing when there was never anything to show", () => {
        updateUnreadBadge([conversation(1, 0)]);

        expect(badge()).toBeNull();
    });

    it("caps the count so it keeps fitting", () => {
        updateUnreadBadge([conversation(1, 99)]);
        expect(badge()!.textContent).toBe("99");

        updateUnreadBadge([conversation(1, 100)]);
        expect(badge()!.textContent).toBe("99+");

        updateUnreadBadge([conversation(1, 4231)]);
        expect(badge()!.textContent).toBe("99+");
    });

    it("sums every conversation into one badge", () => {
        updateUnreadBadge([conversation(1, 2), conversation(2, 3), conversation(3, 0)]);

        expect(badge()!.textContent).toBe("5");
    });

    it("does nothing on a page with no messages link", () => {
        // Guests, and any layout that doesn't render the header. Must not
        // throw - this runs inside a poll whose failure is only logged.
        document.body.innerHTML = "";

        expect(() => updateUnreadBadge([conversation(1, 3)])).not.toThrow();
    });

    it("gives the badge the classes that position it", () => {
        updateUnreadBadge([conversation(1, 3)]);

        // Absolutely positioned against the link, which is why the link
        // carries `position-relative`.
        expect(badge()!.className).toContain("position-absolute");
        expect(badge()!.className).toContain("rounded-pill");
    });
});

describe("previewText()", () => {
    it("drops the markup a sanitized message can carry", () => {
        expect(previewText('смотри <a href="https://example.com">тут</a>')).toBe("смотри тут");
    });

    it("leaves a plain message alone", () => {
        expect(previewText("привет")).toBe("привет");
    });

    it("is not a sanitizer, and is not asked to be", () => {
        // It is a regex over tags, so it cannot be one - a `<` that is not a
        // tag survives. That is fine: the server's purifier already limited
        // the markup to anchors, and the toast writes textContent.
        expect(previewText("1 < 2")).toBe("1 < 2");
    });
});

/**
 * The rule for "is this news". All three of its conditions exist because of a
 * specific way the alternative is wrong.
 */
describe("planToasts()", () => {
    it("says nothing about a conversation it is seeing for the first time", () => {
        // Otherwise every conversation with any history would toast on the
        // first poll after a page load - which is every page load.
        const seen = new Map<number, number>();

        expect(planToasts([conversation(1, 1, { id: 100 })], seen)).toEqual([]);
    });

    it("remembers the first sighting so the next message counts", () => {
        const seen = new Map<number, number>();

        planToasts([conversation(1, 1, { id: 100 })], seen);
        const toasts = planToasts([conversation(1, 2, { id: 101, text: "ещё" })], seen);

        expect(toasts).toEqual(["Новое сообщение: ещё"]);
    });

    it("says nothing about your own message coming back around", () => {
        // You just sent it, and on the messenger page it is already on screen.
        const seen = new Map<number, number>([[1, 100]]);

        expect(planToasts([conversation(1, 0, { id: 101, own: true })], seen)).toEqual([]);
    });

    it("still advances the watermark past your own message", () => {
        // The subtle one. Without this the next real message from the other
        // side is measured against id 100 - and so is every poll after it,
        // toasting the same message every five seconds.
        const seen = new Map<number, number>([[1, 100]]);

        planToasts([conversation(1, 0, { id: 101, own: true })], seen);

        expect(seen.get(1)).toBe(101);
        expect(planToasts([conversation(1, 1, { id: 101 })], seen)).toEqual([]);
    });

    it("says nothing when nothing has moved", () => {
        const seen = new Map<number, number>([[1, 100]]);

        expect(planToasts([conversation(1, 1, { id: 100 })], seen)).toEqual([]);
        expect(planToasts([conversation(1, 1, { id: 100 })], seen)).toEqual([]);
    });

    it("says nothing about a message id that went backwards", () => {
        // A deleted last message leaves an earlier one in its place; that is
        // not an arrival.
        const seen = new Map<number, number>([[1, 100]]);

        expect(planToasts([conversation(1, 1, { id: 99 })], seen)).toEqual([]);
    });

    it("skips a conversation with no messages at all", () => {
        const seen = new Map<number, number>();

        expect(planToasts([conversation(1, 0)], seen)).toEqual([]);
        // And records nothing, so the first real message is still a first
        // sighting rather than an arrival.
        expect(seen.has(1)).toBe(false);
    });

    it("strips markup out of the preview", () => {
        const seen = new Map<number, number>([[1, 100]]);

        const toasts = planToasts(
            [conversation(1, 1, { id: 101, text: 'смотри <a href="https://example.com">тут</a>' })],
            seen,
        );

        expect(toasts).toEqual(["Новое сообщение: смотри тут"]);
    });

    it("reports one toast per conversation that moved", () => {
        const seen = new Map<number, number>([[1, 100], [2, 200], [3, 300]]);

        const toasts = planToasts(
            [
                conversation(1, 1, { id: 101, text: "первое" }),
                conversation(2, 0, { id: 200, text: "не двигалось" }),
                conversation(3, 1, { id: 301, text: "третье" }),
            ],
            seen,
        );

        expect(toasts).toEqual(["Новое сообщение: первое", "Новое сообщение: третье"]);
    });

    it("keeps a per-conversation watermark rather than one global id", () => {
        // Ids are global across conversations, so a single watermark would
        // let a busy chat suppress a quiet one's notifications.
        const seen = new Map<number, number>([[1, 500], [2, 100]]);

        const toasts = planToasts(
            [conversation(1, 0, { id: 500 }), conversation(2, 1, { id: 101, text: "тихий чат" })],
            seen,
        );

        expect(toasts).toEqual(["Новое сообщение: тихий чат"]);
    });
});

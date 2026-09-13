import { trans } from "../shared/i18n";

/* ==========================================================================
   Messenger formatting and derived state

   The pure half of `messages.ts`. All of this used to live inside
   `initMessages()` - a 1600-line closure - despite depending on nothing from
   it but module-level constants, which meant none of it could be tested
   without standing up the whole messenger.

   Two kinds of thing are here, and they are related by being *decisions
   about what to display* rather than DOM work:

   - the time and avatar formatters the conversation list and the message
     bubbles use;
   - `computeReadStatus()`, which decides which tick a message shows.

   Everything that touches the live DOM, the poll, or the panel state stayed
   in `messages.ts`.
   ========================================================================== */

export const AVATAR_COLORS = [
    '#3b82f6', '#a855f7', '#ec4899', '#f59e0b',
    '#8b5cf6', '#f43f5e', '#10b981', '#06b6d4',
];

export const WEEKDAYS_SHORT = Array.from({ length: 7 }, (_, day) => trans(`js.date.weekday_short.${day}`));

export const WEEKDAYS_FULL = Array.from({ length: 7 }, (_, day) => trans(`js.date.weekday.${day}`));

/* ===============================
   Text
=============================== */

/**
 * Plain text of an already-HTML-ish string (decodes entities, drops any
 * tags) - used for the compact spots (conversation-list preview, reply
 * quote) where a message's real `<a>` would otherwise show as raw markup if
 * escaped instead of stripped.
 *
 * Safe despite the innerHTML because `textContent` is read back: nothing
 * from the parse reaches the page.
 */
export function stripTags(html: string): string {
    const div = document.createElement('div');
    div.innerHTML = html ?? '';

    return div.textContent || '';
}

/**
 * Message text is sanitized server-side (`MessageService::send()`/`edit()`
 * run it through a restrictive HTMLPurifier allowing only `a[href]` over
 * http(s)) and rendered as trusted HTML rather than escaped, so a real link
 * - typed by a user, or emitted by a system notification - renders as an
 * actual, clickable `<a>`.
 *
 * `target`/`rel` are enforced here, after each render, rather than trusted
 * from whatever (if anything) the server sent: safe click behaviour should
 * not depend on the backend always getting it right.
 */
export function hardenMessageLinks(root: ParentNode): void {
    root.querySelectorAll<HTMLAnchorElement>('.msgr-bubble-text a[href]').forEach((a) => {
        a.setAttribute('target', '_blank');
        a.setAttribute('rel', 'noopener noreferrer nofollow');
    });
}

/* ===============================
   Time
=============================== */

export function pad(n: number): string {
    return String(n).padStart(2, '0');
}

export function startOfDay(d: Date): Date {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate());
}

export function formatTime(unixSeconds: number): string {
    const d = new Date(unixSeconds * 1000);

    return `${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/**
 * Whole calendar days between then and now - not elapsed hours divided by
 * 24. Both ends are collapsed to local midnight first, so "yesterday" means
 * yesterday even if it was 40 minutes ago, and a DST boundary (a 23- or
 * 25-hour day) still counts as one day.
 *
 * `now` is injectable so the tests are not written against the wall clock.
 */
export function daysBetween(unixSeconds: number, now: Date = new Date()): number {
    const d = new Date(unixSeconds * 1000);

    return Math.round((startOfDay(now).getTime() - startOfDay(d).getTime()) / 86400000);
}

/**
 * The timestamp beside a conversation in the list: today's messages show a
 * time, this week's a weekday, anything older a date.
 *
 * `diff <= 0` rather than `=== 0` on purpose - a message timestamped in the
 * future (clock skew between the server and the visitor's machine) shows a
 * time rather than falling through to a weekday for a day that hasn't
 * happened.
 */
export function formatConvTime(unixSeconds: number, now: Date = new Date()): string {
    const diff = daysBetween(unixSeconds, now);

    if (diff <= 0) return formatTime(unixSeconds);
    if (diff < 7) return WEEKDAYS_SHORT[new Date(unixSeconds * 1000).getDay()];

    const d = new Date(unixSeconds * 1000);

    return `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${String(d.getFullYear()).slice(2)}`;
}

/**
 * The separator between days inside a conversation. Same tiers as
 * formatConvTime(), but spelled out - and with a four-digit year, since this
 * one is a heading rather than a column that has to stay narrow.
 */
export function formatDayLabel(unixSeconds: number, now: Date = new Date()): string {
    const diff = daysBetween(unixSeconds, now);

    if (diff === 0) return trans('js.date.today');
    if (diff === 1) return trans('js.date.yesterday');

    if (diff > 1 && diff < 7) {
        const w = WEEKDAYS_FULL[new Date(unixSeconds * 1000).getDay()];

        return w.charAt(0).toUpperCase() + w.slice(1);
    }

    const d = new Date(unixSeconds * 1000);

    return `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()}`;
}

/* ===============================
   Words and avatars
=============================== */

/**
 * A stable colour per user, so the same person is the same colour in every
 * conversation. `Math.abs` because a negative id would otherwise index off
 * the front of the array and give `undefined`.
 */
export function avatarColor(id: number): string {
    return AVATAR_COLORS[Math.abs(id) % AVATAR_COLORS.length];
}

/**
 * Up to two initials, from the first two words.
 *
 * The leading `#` is stripped first because `User::getDisplayName()` falls
 * back to `#42` for someone who never set a nick - so without it every such
 * user would share a `#` avatar. With it they get `4`, which is at least
 * distinguishing.
 */
export function initialsFromName(name: string): string {
    const cleaned = (name ?? '').replace(/^#/, '');
    const parts = cleaned.trim().split(/\s+/).filter(Boolean);

    if (!parts.length) return '?';

    return parts.slice(0, 2).map(p => p[0]).join('').toUpperCase();
}

/* ===============================
   Read state
=============================== */

export type ReadStatusMessage = {
    id: number;
    user_id: number;
};

/**
 * Which tick a message shows: `null` for no tick at all, `false` for one
 * (sent), `true` for two (read).
 *
 * Only your own messages carry a tick - `null` is "not mine, no tick", not
 * "unknown". Read means read by **everyone else** in the conversation, the
 * same rule for a direct chat and a group: a group message is only
 * double-ticked once the last participant has caught up.
 *
 * The watermark is `last_read_message_id` per participant, so the comparison
 * is `>=` rather than equality - a participant who has read past this
 * message has certainly read it. A participant with no entry counts as 0,
 * i.e. has read nothing.
 *
 * A conversation with nobody else in it (everyone left) is `false` rather
 * than `true`: there is no one for it to have been read by.
 */
export function computeReadStatus(
    message: ReadStatusMessage,
    currentUserId: number,
    participantIds: number[],
    readStates: Record<number, number>,
): boolean | null {
    if (message.user_id !== currentUserId) return null;

    const otherIds = participantIds.filter(id => id !== currentUserId);

    if (otherIds.length === 0) return false;

    return otherIds.every(id => (readStates[id] ?? 0) >= message.id);
}

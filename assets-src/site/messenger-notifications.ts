import { trans } from "../shared/i18n";

/* ==========================================================================
   Messenger notifications

   What the five-second background poll does with the conversation list it
   gets back: the unread badge on the header's messages link, and the toast
   for a message that arrived while the visitor was somewhere else.

   Extracted from `messenger-global.ts` because both are rules rather than
   plumbing - "which messages are news" in particular is the kind of thing
   that is wrong in a way nobody notices until the site is toasting people
   about their own messages. What stayed behind is the poll, the interval and
   the visibilitychange wiring.
   ========================================================================== */

export type NotificationConversation = {
    id: number;
    unread_count: number;
    last_message?: {
        id: number;
        text: string;
        /**
         * Server-side answer to "did I write this?" - the session knows who
         * is asking, so the page doesn't have to carry the viewer's id
         * around to work it out.
         */
        is_own: boolean;
    };
};

/** Where the badge hangs. */
const MESSAGES_LINK_SELECTOR = 'a[href="/messages/"]';

/** Above this the badge would stop fitting, and the exact number stops mattering. */
const BADGE_CAP = 99;

export function totalUnread(conversations: NotificationConversation[]): number {
    return conversations.reduce((sum, c) => sum + c.unread_count, 0);
}

/**
 * Adds, updates or removes the unread badge on the header link.
 *
 * Creates the element on demand and removes it at zero rather than leaving an
 * empty one in place - an empty `.badge` still renders as a coloured dot.
 */
export function updateUnreadBadge(
    conversations: NotificationConversation[],
    root: ParentNode = document,
): void {
    const btn = root.querySelector(MESSAGES_LINK_SELECTOR);
    if (!btn) return;

    const unread = totalUnread(conversations);
    let badge = btn.querySelector('.badge');

    if (unread <= 0) {
        badge?.remove();

        return;
    }

    if (!badge) {
        badge = document.createElement('span');
        badge.className =
            'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
        btn.appendChild(badge);
    }

    badge.textContent = unread > BADGE_CAP ? `${BADGE_CAP}+` : String(unread);
}

/**
 * Message text arrives as server-sanitized HTML - MessageService's purifier
 * allows exactly `a[href]` - so a message containing a link carries real
 * markup. This drops tags so the preview remains readable instead of showing
 * raw anchors.
 *
 * Cosmetic only, and deliberately not a sanitizer: the whitelist upstream
 * means there is nothing here but anchors, and escaping is the toast's job
 * (`toastMessage()` writes `textContent` unless asked otherwise).
 */
export function previewText(html: string): string {
    return html.replace(/<[^>]*>/g, '');
}

/**
 * Decides which conversations are worth a toast, and advances the watermark.
 *
 * Three rules, all of them load-bearing:
 *
 * - A conversation seen for the first time never toasts. Otherwise every
 *   conversation with any history would fire one on the first poll after a
 *   page load, which is every page load.
 * - Your own message coming back around is not news - you just sent it, and
 *   on the messenger page it is already on screen.
 * - The watermark advances either way. Skipping it for a message that did
 *   not toast would mean the *next* real message is measured against a stale
 *   id and toasts again.
 *
 * `lastSeen` is mutated on purpose: it is the poll's memory across ticks, and
 * the advance has to happen even on the paths that return nothing.
 */
export function planToasts(
    conversations: NotificationConversation[],
    lastSeen: Map<number, number>,
): string[] {
    const toasts: string[] = [];

    conversations.forEach((c) => {
        const message = c.last_message;
        if (!message) return;

        const previous = lastSeen.get(c.id);

        if (previous !== undefined && message.id > previous && !message.is_own) {
            toasts.push(trans("js.notification.new_message", { message: previewText(message.text) }));
        }

        lastSeen.set(c.id, message.id);
    });

    return toasts;
}

import CMS from "./app";
import {
    NotificationConversation,
    planToasts,
    updateUnreadBadge,
} from "./messenger-notifications";

const API = '/api/v1/conversations';

const POLL_INTERVAL_MS = 5000;

export type MessengerGlobal = {
    /** Stops polling and unsubscribes, so a later init() starts clean. */
    stop(): void;
};

/**
 * The one running poller, if any. Module scope because the guard has to
 * survive `initMessengerGlobal()` being called twice - `main.ts` calls it on
 * DOMContentLoaded, and nothing stops a page bundle calling it again.
 * `stop()` clears it, which is what lets a test start over.
 */
let active: MessengerGlobal | null = null;

/**
 * Polls the conversation list every five seconds and turns it into the header
 * badge and the "new message" toasts. Both of those rules live in
 * `messenger-notifications.ts`; this is the shell around them - the interval,
 * and the visibilitychange wiring that stops it while the tab is hidden.
 *
 * Returns a handle, or null when there is nobody to poll for. Two reasons the
 * handle exists rather than the function simply running forever: a test can
 * tear it down between cases, and a caller that wants to stop polling has a
 * way to say so that isn't reloading the page.
 */
export function initMessengerGlobal(): MessengerGlobal | null {
    if (!CMS.isAuthenticated()) return null;

    // Idempotent: a second call hands back the poller already running rather
    // than starting a second one against the same endpoint.
    if (active) return active;

    /** Conversation id -> last message id seen, this poller's memory. */
    const lastSeenMessages = new Map<number, number>();

    let interval: number | null = null;

    async function poll(): Promise<void> {
        try {
            const data = await CMS.api<NotificationConversation[]>(API);

            updateUnreadBadge(data);

            planToasts(data, lastSeenMessages).forEach((message) => {
                CMS.toast({ message, type: 'info' });
            });

        } catch (e) {
            console.error('Messenger global polling error', e);
        }
    }

    function start(): void {
        if (interval !== null) return;

        void poll();
        interval = window.setInterval(poll, POLL_INTERVAL_MS);
    }

    function stopPolling(): void {
        if (interval === null) return;

        window.clearInterval(interval);
        interval = null;
    }

    // Nothing to show while the tab is hidden, and the tab may be hidden for
    // hours - so the interval is torn down rather than left ticking. Coming
    // back polls immediately, which is what makes the badge correct on the
    // first glance rather than up to five seconds later.
    function handleVisibility(): void {
        if (document.hidden) {
            stopPolling();
        } else {
            start();
        }
    }

    document.addEventListener('visibilitychange', handleVisibility);

    const handle: MessengerGlobal = {
        stop() {
            stopPolling();
            document.removeEventListener('visibilitychange', handleVisibility);
            active = null;
        },
    };

    active = handle;
    start();

    return handle;
}

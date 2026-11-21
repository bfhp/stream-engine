/*
 * The cookie notice (partials/cookie-notice.twig). The server renders it,
 * hidden, on every page while the `cookie_notice.enabled` setting is on; this
 * shows it to visitors who have not dismissed it yet and remembers the
 * dismissal in a cookie for a year. Keeping the decision in the browser means
 * the page HTML does not depend on the visitor and stays cacheable.
 *
 * The notice is fixed to the bottom of the window, so while it is shown its
 * height goes into `--ui-cookie-notice-offset`: base.css turns that into
 * `scroll-padding-block-end` (focused elements are not scrolled under it,
 * WCAG 2.4.11) and padding at the end of the page.
 */
export const COOKIE_NOTICE_COOKIE = "cookie_notice";
const MAX_AGE = 60 * 60 * 24 * 365;

function isDismissed(root: Document): boolean {
    return root.cookie.split(";").some(part => part.trim() === `${COOKIE_NOTICE_COOKIE}=1`);
}

export function initCookieNotice(root: Document = document): void {
    const notice = root.getElementById("cookieNotice");
    if (!notice) return;

    if (isDismissed(root)) {
        notice.remove();
        return;
    }

    const html = root.documentElement;
    const update = (): void => {
        html.style.setProperty("--ui-cookie-notice-offset", `${Math.ceil(notice.getBoundingClientRect().height)}px`);
    };

    notice.hidden = false;
    html.classList.add("has-cookie-notice");
    update();
    const observer = typeof ResizeObserver !== "undefined" ? new ResizeObserver(update) : null;
    observer?.observe(notice);

    notice.querySelector<HTMLButtonElement>("[data-cookie-notice-accept]")?.addEventListener("click", () => {
        const secure = root.location?.protocol === "https:" ? "; Secure" : "";
        root.cookie = `${COOKIE_NOTICE_COOKIE}=1; path=/; max-age=${MAX_AGE}; SameSite=Lax${secure}`;

        observer?.disconnect();
        const hadFocus = notice.contains(root.activeElement);
        notice.remove();
        html.classList.remove("has-cookie-notice");
        html.style.removeProperty("--ui-cookie-notice-offset");

        // The focused button is gone; keep keyboard focus in the page rather
        // than letting it fall back to <body>, without scrolling.
        if (hadFocus) {
            root.getElementById("main-content")?.focus({ preventScroll: true });
        }
    });
}

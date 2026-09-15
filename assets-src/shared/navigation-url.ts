/**
 * Resolves a server-supplied navigation target while keeping navigation on
 * this site. Data attributes are DOM input, so callers validate them before
 * performing an API action whose success path depends on the redirect.
 */
export function resolveSameOriginUrl(value: string | null | undefined): string {
    const candidate = value?.trim();

    if (!candidate || /^[\\/]{2}/.test(candidate)) {
        throw new Error("Navigation URL is not configured safely");
    }

    let url: URL;

    try {
        url = new URL(candidate, window.location.href);
    } catch {
        throw new Error("Navigation URL is not configured safely");
    }

    if ((url.protocol !== "http:" && url.protocol !== "https:")
        || url.origin !== window.location.origin
        || url.username !== ""
        || url.password !== "") {
        throw new Error("Navigation URL is not configured safely");
    }

    return url.href;
}

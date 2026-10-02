export type UserListState = "loading" | "error" | "empty" | "ready";

/** The mutually exclusive display state for the users table body. */
export function userListState(loading: boolean, error: string | null, count: number): UserListState {
    if (error) return "error";
    if (loading && count === 0) return "loading";
    if (count === 0) return "empty";
    return "ready";
}

/** Keeps query-string construction deterministic and omits empty filters. */
export function userListParams(
    page: number,
    query: string,
    role: string | null,
    status: string | null,
): URLSearchParams {
    const params = new URLSearchParams({ page: String(page) });
    if (query.trim()) params.set("q", query.trim());
    if (role) params.set("role", role);
    if (status) params.set("status", status);

    return params;
}

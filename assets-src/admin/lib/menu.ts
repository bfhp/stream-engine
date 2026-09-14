export type MenuItemRecord = {
    id?: number;
    parentId: number | null;
    menuGroup: string;
    type: "internal" | "external" | "action" | "divider" | "dynamic";
    pageId: number | null;
    url: string | null;
    action: string | null;
    label: string | null;
    accessRule: string;
    sortOrder: number;
};

export function menuTarget(item: MenuItemRecord): string {
    if (item.type === "internal" || item.type === "dynamic") {
        return item.pageId ? `Page #${item.pageId}` : "—";
    }

    if (item.type === "external") {
        return item.url || "—";
    }

    if (item.type === "action") {
        return item.action || "—";
    }

    return "—";
}

export function menuDepth(item: MenuItemRecord, byId: Map<number, MenuItemRecord>): number {
    let depth = 0;
    let parentId = item.parentId;
    const seen = new Set<number>();

    while (parentId !== null && !seen.has(parentId)) {
        seen.add(parentId);
        depth += 1;
        parentId = byId.get(parentId)?.parentId ?? null;
    }

    return depth;
}

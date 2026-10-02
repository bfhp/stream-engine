export type DashboardSize = "small" | "medium" | "wide";
export type LayoutItem = { id: string; size: DashboardSize; position: number };

export function orderedLayout(items: LayoutItem[]): LayoutItem[] {
    return [...items]
        .sort((a, b) => a.position - b.position || a.id.localeCompare(b.id))
        .map((item, index) => ({ ...item, position: index * 10 }));
}

export function moveLayoutItem(items: LayoutItem[], id: string, direction: -1 | 1): LayoutItem[] {
    const ordered = orderedLayout(items);
    const index = ordered.findIndex(item => item.id === id);
    const target = index + direction;
    if (index < 0 || target < 0 || target >= ordered.length) return ordered;
    [ordered[index], ordered[target]] = [ordered[target], ordered[index]];

    return ordered.map((item, nextIndex) => ({ ...item, position: nextIndex * 10 }));
}

export function dashboardGridSpan(size: DashboardSize): number {
    if (size === "wide") return 12;
    if (size === "medium") return 6;
    return 4;
}

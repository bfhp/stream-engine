export type PageHierarchyItem = {
    id: number;
    parentId?: number | null;
};

export type PageHierarchyEntry<T extends PageHierarchyItem> = {
    page: T;
    depth: number;
};

export function orderPagesByHierarchy<T extends PageHierarchyItem>(
    pages: T[]
): PageHierarchyEntry<T>[] {
    const pageIds = new Set(pages.map(page => page.id));
    const children = new Map<number, T[]>();
    const roots: T[] = [];

    pages.forEach(page => {
        if (page.parentId === undefined || page.parentId === null || !pageIds.has(page.parentId)) {
            roots.push(page);
            return;
        }

        children.set(page.parentId, [...(children.get(page.parentId) || []), page]);
    });

    const ordered: PageHierarchyEntry<T>[] = [];
    const visited = new Set<number>();
    const appendPage = (page: T, depth: number) => {
        if (visited.has(page.id)) {
            return;
        }

        visited.add(page.id);
        ordered.push({ page, depth });
        (children.get(page.id) || []).forEach(child => appendPage(child, depth + 1));
    };

    roots.forEach(page => appendPage(page, 0));

    // Keep malformed cyclic hierarchies visible and make sure they cannot
    // recurse forever. Orphans are already treated as roots above.
    pages.forEach(page => appendPage(page, 0));

    return ordered;
}

export function forbiddenPageParentIds(
    pages: PageHierarchyItem[],
    currentPageId: number | undefined
): Set<number> {
    if (currentPageId === undefined) {
        return new Set();
    }

    const children = new Map<number, number[]>();
    pages.forEach(page => {
        if (page.parentId === undefined || page.parentId === null) {
            return;
        }

        children.set(page.parentId, [...(children.get(page.parentId) || []), page.id]);
    });

    const forbidden = new Set<number>([currentPageId]);
    const pending = [currentPageId];
    while (pending.length > 0) {
        const parentId = pending.pop() as number;
        (children.get(parentId) || []).forEach(childId => {
            if (!forbidden.has(childId)) {
                forbidden.add(childId);
                pending.push(childId);
            }
        });
    }

    return forbidden;
}

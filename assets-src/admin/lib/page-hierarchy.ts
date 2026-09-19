export type PageHierarchyItem = {
    id: number;
    parentId?: number | null;
};

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

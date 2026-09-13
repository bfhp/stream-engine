import { lazy, type ComponentType, type LazyExoticComponent } from "react";

export type AdminPage = {
    path: string;
    label: string;
    load: () => Promise<{ default: ComponentType }>;
};

export type AdminPageModules = Record<string, { default: AdminPage[] }>;

export type AdminRoute = Pick<AdminPage, "path" | "label"> & {
    Component: LazyExoticComponent<ComponentType>;
};

// Reserve whole built-in sections, including their parameterized editor routes.
const builtInSections = new Set(["feeds", "pages", "settings", "widgets", "users"]);

export function collectModulePages(modules: AdminPageModules): AdminPage[] {
    const pages: AdminPage[] = [];
    const paths = new Set<string>();
    for (const source of Object.keys(modules).sort()) {
        const entries = modules[source].default;
        if (!Array.isArray(entries)) throw new Error(`Admin module ${source} must export a page list`);
        for (const page of entries) {
            if (!page || typeof page.path !== "string" || !/^\/[a-z0-9-]+(?:\/[a-z0-9-]+)*$/.test(page.path)
                || typeof page.label !== "string" || !page.label.trim() || typeof page.load !== "function") {
                throw new Error(`Invalid admin page in ${source}`);
            }
            if (builtInSections.has(page.path.split("/")[1]) || paths.has(page.path)) {
                throw new Error(`Duplicate or reserved admin path ${page.path} in ${source}`);
            }
            paths.add(page.path);
            pages.push(page);
        }
    }
    return pages;
}

export function createModuleRoutes(modules: AdminPageModules): AdminRoute[] {
    return collectModulePages(modules).map(page => ({
        path: page.path,
        label: page.label,
        Component: lazy(page.load),
    }));
}

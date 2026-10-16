import { AppShell, Burger, Group, NavLink, Text } from "@mantine/core";
import { useEffect, useState, type MouseEvent } from "react";
import { Outlet, Link, useLocation } from "react-router-dom";
import type { AdminRoute } from "../module-pages";
import { trans } from "../../shared/i18n";
import "./accessibility.css";

export const ADMIN_MAIN_ID = "admin-main";

/*
 * The admin uses a HashRouter, so a plain "#admin-main" link would be read as a
 * route. Move focus to the main region instead of following the href.
 */
function skipToMain(event: MouseEvent<HTMLAnchorElement>): void {
    event.preventDefault();
    document.getElementById(ADMIN_MAIN_ID)?.focus();
}

/*
 * Below Mantine's "sm" breakpoint (48em) the open navigation covers the whole
 * screen, so a screen chosen from it would open unseen underneath. Mantine
 * switches layouts at max-width: calc(48em - 0.1px).
 */
const NAV_OVERLAY_QUERY = "(max-width: 47.99375em)";

export function navCoversContent(): boolean {
    return typeof window !== "undefined" && typeof window.matchMedia === "function"
        && window.matchMedia(NAV_OVERLAY_QUERY).matches;
}

/** The title the server rendered (site and admin name); screen titles go before it. */
const BASE_TITLE = typeof document === "undefined" ? "" : document.title;

type NavItem = { path: string; labelKey: string };

const CORE_NAV: NavItem[] = [
    { path: "/", labelKey: "js.admin.nav.dashboard" },
    { path: "/feeds", labelKey: "js.admin.nav.feeds" },
    { path: "/pages", labelKey: "js.admin.nav.pages" },
    { path: "/menus", labelKey: "js.admin.nav.menus" },
    { path: "/settings", labelKey: "js.admin.nav.settings" },
    { path: "/registration", labelKey: "js.admin.registration.title" },
    { path: "/themes", labelKey: "js.admin.nav.themes" },
    { path: "/widgets", labelKey: "js.admin.nav.widgets" },
    { path: "/file-browser", labelKey: "js.admin.nav.file_browser" },
    { path: "/cron", labelKey: "js.admin.dashboard.cron_tasks" },
];

const USERS_NAV: NavItem = { path: "/users", labelKey: "js.admin.nav.users" };

/** The navigation item a route belongs to: an exact match, else the longest
 *  section prefix ("/feeds/12" is in "Feeds"). */
export function currentNavItem(items: NavItem[], pathname: string): NavItem | undefined {
    return items
        .filter(item => pathname === item.path || (item.path !== "/" && pathname.startsWith(item.path + "/")))
        .sort((a, b) => b.path.length - a.path.length)[0];
}

type AdminLayoutProps = {
    moduleAdminPages: AdminRoute[];
};

export default function AdminLayout({ moduleAdminPages }: AdminLayoutProps) {
    const [opened, setOpened] = useState(true);
    const { pathname } = useLocation();
    const items = [...CORE_NAV, ...moduleAdminPages, USERS_NAV];
    const current = currentNavItem(items, pathname);
    const screenLabel = current ? trans(current.labelKey) : "";

    // Each admin screen gets its own window title (WCAG 2.4.2); the HashRouter
    // would otherwise keep the server's title on every screen.
    useEffect(() => {
        document.title = screenLabel && BASE_TITLE ? `${screenLabel} · ${BASE_TITLE}` : (screenLabel || BASE_TITLE);
    }, [screenLabel]);

    // On a narrow screen, close the navigation once an item is chosen and put
    // focus on the screen that opened, so keyboard and screen reader users
    // land on it instead of on a link that is now hidden.
    const closeIfCovering = () => {
        if (!navCoversContent()) return;
        setOpened(false);
        requestAnimationFrame(() => document.getElementById(ADMIN_MAIN_ID)?.focus());
    };

    return (
        <>
            <a className="admin-skip-link" href={`#${ADMIN_MAIN_ID}`} onClick={skipToMain}>
                {trans("js.admin.nav.skip_to_content")}
            </a>
            <AppShell
                header={{ height: 60 }}
                navbar={{
                    width: 250,
                    breakpoint: "sm",
                    collapsed: { mobile: !opened, desktop: !opened }
                }}
                padding="md"
            >
                <AppShell.Header>
                    <Group h="100%" px="md">
                        <Burger
                            opened={opened}
                            onClick={() => setOpened(!opened)}
                            aria-label={trans("js.admin.nav.toggle")}
                            aria-expanded={opened}
                        />
                        <Text fw={600}>{trans("js.admin.brand")}</Text>
                    </Group>
                </AppShell.Header>

                <AppShell.Navbar p="md" className="admin-navbar">
                    {items.map(({ path, labelKey }) => {
                        const active = current?.path === path;
                        return (
                            <NavLink key={path} component={Link} to={path} label={trans(labelKey)}
                                active={active} aria-current={active ? "page" : undefined}
                                onClick={closeIfCovering} />
                        );
                    })}
                </AppShell.Navbar>

                <AppShell.Main id={ADMIN_MAIN_ID} tabIndex={-1}>
                    <Outlet />
                </AppShell.Main>
            </AppShell>
        </>
    );
}

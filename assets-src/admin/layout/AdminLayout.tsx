import { AppShell, Burger, Group, NavLink, Text } from "@mantine/core";
import { useState } from "react";
import { Outlet, Link } from "react-router-dom";
import type { AdminRoute } from "../module-pages";
import { trans } from "../../shared/i18n";

type AdminLayoutProps = {
    moduleAdminPages: AdminRoute[];
};

export default function AdminLayout({ moduleAdminPages }: AdminLayoutProps) {
    const [opened, setOpened] = useState(true);

    return (
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
                    <Burger opened={opened} onClick={() => setOpened(!opened)} />
                    <Text fw={600}>{trans("js.admin.brand")}</Text>
                </Group>
            </AppShell.Header>

            <AppShell.Navbar p="md">
                <NavLink component={Link} to="/" label={trans("js.admin.nav.dashboard")} />
                <NavLink component={Link} to="/feeds" label={trans("js.admin.nav.feeds")} />
                <NavLink component={Link} to="/pages" label={trans("js.admin.nav.pages")} />
                <NavLink component={Link} to="/menus" label={trans("js.admin.nav.menus")} />
                <NavLink component={Link} to="/settings" label={trans("js.admin.nav.settings")} />
                <NavLink component={Link} to="/registration" label={trans("js.admin.registration.title")} />
                <NavLink component={Link} to="/themes" label={trans("js.admin.nav.themes")} />
                <NavLink component={Link} to="/widgets" label={trans("js.admin.nav.widgets")} />
                <NavLink component={Link} to="/file-browser" label={trans("js.admin.nav.file_browser")} />
                <NavLink component={Link} to="/cron" label={trans("js.admin.dashboard.cron_tasks")} />
                {moduleAdminPages.map(({ path, labelKey }) => (
                    <NavLink key={path} component={Link} to={path} label={trans(labelKey)} />
                ))}
                <NavLink component={Link} to="/users" label={trans("js.admin.nav.users")} />
            </AppShell.Navbar>

            <AppShell.Main>
                <Outlet />
            </AppShell.Main>
        </AppShell>
    );
}

import { AppShell, Burger, Group, NavLink, Text } from "@mantine/core";
import { useState } from "react";
import { Outlet, Link } from "react-router-dom";
import type { AdminRoute } from "../module-pages";

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
                    <Text fw={600}>Stream-Engine Admin</Text>
                </Group>
            </AppShell.Header>

            <AppShell.Navbar p="md">
                <NavLink component={Link} to="/" label="Dashboard" />
                <NavLink component={Link} to="/feeds" label="Feeds" />
                <NavLink component={Link} to="/pages" label="Pages" />
                <NavLink component={Link} to="/settings" label="Settings" />
                <NavLink component={Link} to="/widgets" label="Widgets" />
                {moduleAdminPages.map(({ path, label }) => (
                    <NavLink key={path} component={Link} to={path} label={label} />
                ))}
                <NavLink component={Link} to="/users" label="Users" />
            </AppShell.Navbar>

            <AppShell.Main>
                <Outlet />
            </AppShell.Main>
        </AppShell>
    );
}

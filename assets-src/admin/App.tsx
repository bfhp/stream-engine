import { Routes, Route } from "react-router-dom";
import AdminLayout from "./layout/AdminLayout";

import Dashboard from "./pages/Dashboard";
import Users from "./pages/Users";

import Feeds from "./pages/Feeds";
import FeedEdit from "./pages/FeedEdit";
import { Suspense } from "react";
import Pages from "./pages/Pages";
import PageEdit from "./pages/PageEdit";
import Settings from "./pages/Settings";
import Widgets from "./pages/Widgets";
import type { AdminRoute } from "./module-pages";

type AppProps = {
    moduleAdminPages: AdminRoute[];
};

export default function App({ moduleAdminPages }: AppProps) {
    return (
        <Routes>
            <Route element={<AdminLayout moduleAdminPages={moduleAdminPages} />}>
                <Route path="/feeds/:id" element={<FeedEdit />} />
                <Route path="/pages/:id" element={<PageEdit />} />
                {moduleAdminPages.map(({ path, Component }) => (
                    <Route key={path} path={path} element={
                        <Suspense fallback={<p>Loading…</p>}><Component /></Suspense>
                    } />
                ))}
                <Route path="/feeds" element={<Feeds />} />
                <Route path="/pages" element={<Pages />} />
                <Route path="/settings" element={<Settings />} />
                <Route path="/widgets" element={<Widgets />} />
                <Route path="/users" element={<Users />} />
                <Route path="/" element={<Dashboard />} />
            </Route>
        </Routes>
    );
}

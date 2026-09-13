import "@mantine/core/styles.css";
import "@mantine/notifications/styles.css";

import ReactDOM from "react-dom/client";
import { MantineProvider } from "@mantine/core";
import { Notifications } from "@mantine/notifications";
import { HashRouter } from "react-router-dom";

import "../shared/custom-content.css";

import App from "./App";
import { createModuleRoutes, type AdminPageModules } from "./module-pages";

export function mountAdmin(modules: AdminPageModules = {}): void {
    ReactDOM.createRoot(
        document.getElementById("root")!
    ).render(
        <MantineProvider>
            <Notifications />
            <HashRouter>
                <App moduleAdminPages={createModuleRoutes(modules)} />
            </HashRouter>
        </MantineProvider>
    );
}

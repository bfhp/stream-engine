import "@mantine/core/styles.css";
import "@mantine/notifications/styles.css";

import ReactDOM from "react-dom/client";
import { DirectionProvider, MantineProvider, type MantineColorSchemeManager } from "@mantine/core";
import { Notifications } from "@mantine/notifications";
import { HashRouter } from "react-router-dom";

import "../shared/custom-content.css";

import App from "./App";
import { isRtl } from "./lib/direction";
import { createModuleRoutes, type AdminPageModules } from "./module-pages";

const systemColorSchemeManager: MantineColorSchemeManager = {
    get: () => "auto",
    set: () => undefined,
    subscribe: () => undefined,
    unsubscribe: () => undefined,
    clear: () => undefined
};

export function mountAdmin(modules: AdminPageModules = {}): void {
    ReactDOM.createRoot(
        document.getElementById("root")!
    ).render(
        <DirectionProvider initialDirection={isRtl() ? "rtl" : "ltr"} detectDirection={false}>
            <MantineProvider
                colorSchemeManager={systemColorSchemeManager}
                defaultColorScheme="auto"
            >
                <Notifications />
                <HashRouter>
                    <App moduleAdminPages={createModuleRoutes(modules)} />
                </HashRouter>
            </MantineProvider>
        </DirectionProvider>
    );
}

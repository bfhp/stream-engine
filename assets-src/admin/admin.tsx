import "@mantine/core/styles.css";
import "@mantine/notifications/styles.css";

import ReactDOM from "react-dom/client";
import { DirectionProvider, MantineProvider, type MantineColorSchemeManager } from "@mantine/core";
import { Notifications } from "@mantine/notifications";
import { HashRouter } from "react-router-dom";

import "../shared/custom-content.css";

import App from "./App";
import { isRtl } from "./lib/direction";
import { adminCssVariablesResolver, adminTheme } from "./theme";
import { createModuleRoutes, type AdminPageModules } from "./module-pages";
import { installFocusKeeper } from "../shared/keep-focus";

installFocusKeeper();

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
                theme={adminTheme}
                cssVariablesResolver={adminCssVariablesResolver}
                colorSchemeManager={systemColorSchemeManager}
                defaultColorScheme="auto"
            >
                {/* Long enough to read (WCAG 2.2.1); Mantine pauses the timer while
                    the pointer is over a notification. Errors stay until closed. */}
                <Notifications autoClose={8000} />
                <HashRouter>
                    <App moduleAdminPages={createModuleRoutes(modules)} />
                </HashRouter>
            </MantineProvider>
        </DirectionProvider>
    );
}

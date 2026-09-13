import { initMessages } from "../site/messages";

import "./messages.css";

/*
 * The messenger fills exactly one viewport (see .messenger-main in
 * messages.css), but the site header sits above it and isn't a fixed size
 * (Bootstrap navbar, collapses/wraps differently per breakpoint) - so we
 * measure it and expose it as a CSS variable that messages.css subtracts
 * from 100vh, keeping the composer on-screen without needing to scroll.
 */
function updateHeaderHeightVar() {
    const header = document.querySelector("header");
    const height = header ? header.getBoundingClientRect().height : 0;
    document.documentElement.style.setProperty("--site-header-height", `${height}px`);
}

updateHeaderHeightVar();
window.addEventListener("resize", updateHeaderHeightVar);

document.addEventListener("DOMContentLoaded", () => {
    initMessages();
    updateHeaderHeightVar();
});

import "./vendor";

import "./style.css";
import "../shared/custom-content.css";
import "./img/default-avatar.svg?no-inline";
import "./img/default-community.svg?no-inline";

import CMS from "./app";

// Registers the Bootstrap UI adapter and Bootstrap's data API. Lives here
// while `default` is the Bootstrap design; moves to the `bootstrap` theme's
// own entry per docs/ADR-002-BASE-AND-BOOTSTRAP-THEMES.md.
import "./ui-bootstrap";

import "./auth";

import { initMessengerGlobal } from "./messenger-global";

// Before DOMContentLoaded: the router has to be listening for popstate /
// hashchange from the moment the page starts running, not from whenever the
// rest of the site's widgets get initialized.
CMS.hashRoute.init();

document.addEventListener("DOMContentLoaded", () => {
    initMessengerGlobal(); // Always (nearly)
    CMS.checkAndSetTimezoneCookie();
    CMS.initShare();
    CMS.initTooltips();
    CMS.initComments();
    CMS.initRating();
    CMS.initFavorite();
    CMS.initContinueReading();
    CMS.initDirectMessage();
});

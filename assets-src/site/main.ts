import "./vendor";

import "./style.css";
import "../shared/custom-content.css";
import "./modules/feedback.css";
import "./modules/search.css";
import "./modules/article.css";
import "./modules/profile.css";
import "./modules/users.css";
import "./modules/forums.css";
import "./img/default-avatar.svg?no-inline";
import "./img/default-community.svg?no-inline";

import CMS from "./app";

import "./auth";

import { initMessengerGlobal } from "./messenger-global";
import { initMentionAutocomplete } from "../shared/mentions-autocomplete";
import { initStickyOffset } from "./sticky-offset";
import { initPasswordToggles } from "../shared/password-toggle";
import { installFocusKeeper } from "../shared/keep-focus";
import { ui } from "../shared/ui";

// Before DOMContentLoaded: the router has to be listening for popstate /
// hashchange from the moment the page starts running, not from whenever the
// rest of the site's widgets get initialized.
CMS.hashRoute.init();

document.addEventListener("DOMContentLoaded", () => {
    initStickyOffset();
    // Live regions for toasts must be in the page before the first message.
    ui.toast.prepare();
    // A toast cut short by a reload or a redirect is shown again here.
    CMS.showPendingToast();
    initPasswordToggles();
    installFocusKeeper();
    initMentionAutocomplete();
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

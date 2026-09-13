import CMS from "./app";
import { getApiErrorMessage } from "../shared/api-errors";
import { trans } from "../shared/i18n";

/* ===============================
   LOGIN
=============================== */

const authForm = document.getElementById('authForm') as HTMLFormElement | null;

if (authForm) {
    authForm.addEventListener('submit', async (e: SubmitEvent) => {
        e.preventDefault();

        const form = e.currentTarget as HTMLFormElement;

        const email = (form.elements.namedItem('email') as HTMLInputElement)?.value;
        const password = (form.elements.namedItem('password') as HTMLInputElement)?.value;

        const data = { email, password };

        try {
            await CMS.api('/api/v1/auth', {
                method: 'POST',
                data
            });
            CMS.toast({
                message: trans("js.auth.welcome"),
                type: "success"
            });
            setTimeout(() => location.reload(), 800);

        } catch (error) {
            CMS.toast({
                message: getApiErrorMessage(error, trans("js.auth.login_failed")),
                type: "danger"
            });
        }
    });
}

/* ===============================
   LOGOUT
=============================== */

const logoutButtons = document.querySelectorAll<HTMLElement>('[data-logout]');

logoutButtons.forEach(btn => {
    btn.addEventListener('click', (e: MouseEvent) => {
        e.preventDefault();

        CMS.confirm({
            title: trans("js.auth.logout_title"),
            message: trans("js.auth.logout_confirm"),
            onConfirm: async () => {
                try {
                    await CMS.api('/api/v1/auth', {
                        method: 'DELETE'
                    });

                    CMS.toast({
                        message: trans("js.auth.logged_out"),
                        type: "info"
                    });

                    setTimeout(() => location.reload(), 800);

                } catch (error) {
                    // Was `error?.error?.message`, one level too deep for what
                    // this endpoint actually returns (`{"error": "<message>"}`,
                    // built by StreamEngine::handleRequest()'s API branch), so
                    // logout could only ever show the fallback. Login read the
                    // same payload with a different rule - which is how the two
                    // disagreed for as long as they did.
                    CMS.toast({
                        message: getApiErrorMessage(error, trans("js.auth.logout_failed")),
                        type: "danger"
                    });
                }
            }
        });
    });
});

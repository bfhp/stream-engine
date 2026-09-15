import { getApiErrorMessage } from "../shared/api-errors";
import { trans } from "../shared/i18n";
import { resolveSameOriginUrl } from "../shared/navigation-url";

const cms = window.CMS;

(function () {

    const form = document.getElementById('registerForm') as HTMLFormElement | null;
    if (!form) return;

    const submitBtn = form.querySelector<HTMLButtonElement>('button[type="submit"]');
    if (!submitBtn) return;

    /**
     * Reads a field through form.elements instead of the form's own named
     * access (`form.login.value`). Two reasons, one real and one practical:
     * named access is shadowed by the form's own properties, so a field called
     * `action`, `method`, `id` or `submit` silently returns the wrong thing;
     * and jsdom - which the Vitest suite runs on - does not implement it at all
     * (checked against 27.4.0: every `form.<name>` is undefined), so a test
     * could not drive this handler. app.ts and auth.ts already read fields this
     * way.
     */
    const value = (name: string): string =>
        (form.elements.namedItem(name) as HTMLInputElement).value;

    form.addEventListener('submit', async function (e) {

        e.preventDefault();

        // Disabling the button is not by itself a re-entry guard: it only stops
        // clicks, while implicit submission - Enter in any of the fields - fires
        // this handler regardless. Without this, the 800ms wait before the
        // redirect below was long enough to register the account twice by
        // holding Enter.
        if (submitBtn.disabled) return;

        const payload = {
            login: value('login').trim(),
            email: value('email').trim(),
            password: value('password')
        };

        if (payload.password.length < 10) {
            cms.toast({
                message: trans("js.auth.password_min"),
                type: "danger"
            });
            return;
        }

        submitBtn.disabled = true;

        try {
            const successUrl = resolveSameOriginUrl(form.dataset.successUrl);

            await cms.api(form.action, {
                method: 'POST',
                data: payload
            });

            cms.toast({
                message: trans("js.auth.registration_success"),
                type: "success"
            });

            setTimeout(() => {
                window.location.href = successUrl;
            }, 800);

        } catch (error) {

            cms.toast({
                message: getApiErrorMessage(error, trans("js.auth.registration_failed")),
                type: "danger"
            });

            submitBtn.disabled = false;
        }

    });

})();

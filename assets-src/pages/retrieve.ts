import { getApiErrorMessage } from "../shared/api-errors";
import { trans } from "../shared/i18n";

const cms = window.CMS;

/**
 * Reads a field through `form.elements` rather than the form's own named
 * access - see register.ts's copy of this note for why (shadowing, plus jsdom
 * doesn't implement it, so a test could not drive these handlers).
 */
function fieldValue(form: HTMLFormElement, name: string): string {
    const field = form.elements.namedItem(name) as HTMLInputElement | null;

    return field ? field.value : '';
}

(function () {
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('resetPasswordForm') as HTMLFormElement | null;

        if (!form) return;

        const submitBtn = form.querySelector<HTMLButtonElement>('button[type="submit"]');
        if (!submitBtn) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (submitBtn.disabled) return;

            const password = fieldValue(form, 'password');
            const password2 = fieldValue(form, 'password2');
            const token = fieldValue(form, 'token');

            if (password !== password2) {
                cms.toast({
                    message: trans("js.auth.passwords_mismatch"),
                    type: "danger"
                });
                return;
            }

            submitBtn.disabled = true;

            try {
                await cms.api(form.action, {
                    method: 'PUT',
                    data: { token, password }
                });

                cms.toast({
                    message: trans("js.auth.password_changed"),
                    type: "success"
                });

                setTimeout(() => {
                    window.location.href = '/';
                }, 1000);

            } catch (error) {
                cms.toast({
                    message: getApiErrorMessage(error, trans("js.auth.password_recovery_failed")),
                    type: "danger"
                });

                submitBtn.disabled = false;
            }
        });
    });

    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('forgotPasswordForm') as HTMLFormElement | null;

        if (!form) return;

        const submitBtn = form.querySelector<HTMLButtonElement>('button[type="submit"]');
        if (!submitBtn) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (submitBtn.disabled) return;

            submitBtn.disabled = true;

            try {
                await cms.api(form.action, {
                    method: 'POST',
                    data: { email: fieldValue(form, 'email').trim() }
                });

                // IMPORTANT: the same message always - a different answer for a
                // known and an unknown address turns this form into a way to
                // ask whether someone has an account here.
                cms.toast({
                    message: trans("js.auth.password_reset_sent"),
                    type: "success"
                });

                form.reset();

            } catch (error) {
                cms.toast({
                    message: getApiErrorMessage(error, trans("js.auth.password_change_failed")),
                    type: "danger"
                });
            } finally {
                submitBtn.disabled = false;
            }
        });
    });
})();

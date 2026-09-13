import { getApiErrorMessage } from "../shared/api-errors";
import { trans } from "../shared/i18n";

const cms = window.CMS;

(function () {

    const form = document.getElementById('feedbackForm') as HTMLFormElement | null;
    if (!form) return;

    const submitBtn = form.querySelector<HTMLButtonElement>('button[type="submit"]');
    if (!submitBtn) return;

    // form.elements rather than the form's own named access - see register.ts's
    // copy of this note for why (shadowing, plus jsdom doesn't implement it).
    const value = (name: string): string =>
        (form.elements.namedItem(name) as HTMLInputElement).value;

    form.addEventListener('submit', async function (e) {

        e.preventDefault();

        // See register.js's copy of this: `disabled` blocks clicks but not
        // implicit submission via Enter, so the disabled button alone never
        // closed the 1500ms window before the redirect below.
        if (submitBtn.disabled) return;

        const payload = {
            form_time: value('form_time'),
            form_hash: value('form_hash'),
            full_name: value('full_name').trim(),
            email: value('email').trim(),
            website: value('website'),
            message: value('message').trim()
        };

        if (payload.full_name.length === 0) {
            cms.toast({
                message: trans("js.feedback.name_required"),
                type: "danger"
            });
            return;
        }

        if (payload.email.length === 0) {
            cms.toast({
                message: trans("js.feedback.email_required"),
                type: "danger"
            });
            return;
        }

        if (payload.message.length < 30) {
            cms.toast({
                message: trans("js.feedback.message_required"),
                type: "danger"
            });
            return;
        }

        submitBtn.disabled = true;

        try {

            await cms.api(form.action, {
                method: 'POST',
                data: payload
            });

            cms.toast({
                message: trans("js.feedback.sent"),
                type: "success"
            });
            // TODO dynamic link
            setTimeout(() => {
                window.location.href = "/contacts/?success=1#feedbackFormHeader";
            }, 1500);
        } catch (error) {

            cms.toast({
                message: getApiErrorMessage(error, trans("js.feedback.send_failed")),
                type: "danger"
            });

            // Re-enabled here rather than in a `finally`: on success the
            // redirect above is 1500ms away and the button was handed straight
            // back, so a second click sent the same feedback twice. The window
            // is wider here than register.ts's 800ms.
            submitBtn.disabled = false;
        }

    });

})();

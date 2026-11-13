import { trans } from '../shared/i18n';
import { getApiErrorMessage } from '../shared/api-errors';

/** Delegation also covers photo fragments loaded by the viewer. */
export function initPhotoRating(): void {
    document.addEventListener('click', event => {
        const button = (event.target as HTMLElement).closest<HTMLButtonElement>('[data-photo-rate]');
        const widget = button?.closest<HTMLElement>('[data-photo-rating]');
        if (!button || button.disabled || !widget || widget.dataset.saving === 'true') return;
        const value = Number(button.dataset.photoRate);
        const error = widget.querySelector<HTMLElement>('[data-photo-rating-error]')!;
        const buttons = widget.querySelectorAll<HTMLButtonElement>('[data-photo-rate]');
        widget.dataset.saving = 'true'; error.textContent = ''; buttons.forEach(item => item.disabled = true);
        void window.CMS.api<{ratingAverage:number;ratingCount:number;userRating:number}>(widget.dataset.apiUrl!, { method: 'POST', data: { value } }).then(result => {
            widget.dataset.value = String(result.userRating);
            buttons.forEach(item => {
                const n = Number(item.dataset.photoRate); item.textContent = n <= result.userRating ? '★' : '☆'; item.setAttribute('aria-pressed', String(n === result.userRating));
            });
            widget.querySelector('[data-photo-rating-summary]')!.textContent = trans('js.rating.summary', { average: result.ratingAverage.toFixed(1), count: result.ratingCount });
            const compact = widget.closest('[data-photo-viewer]')?.querySelector('[data-photo-rating-compact]');
            if (compact) compact.textContent = `${result.ratingAverage.toFixed(1)} · ${result.ratingCount}`;
        }).catch(reason => { error.textContent = getApiErrorMessage(reason, trans('js.rating.save_failed')); }).finally(() => {
            widget.dataset.saving = 'false'; buttons.forEach(item => item.disabled = false);
        });
    });
}

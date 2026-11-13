import { expect, it, vi } from 'vitest';
import { initPhotoRating } from '../../assets-src/pages/photo-rating';

it('rates dynamically loaded photos, prevents duplicate submissions and retains the prior vote on failure', async () => {
    const widget = (slug: string) => `<div data-photo-rating data-api-url="/api/album/photos/${slug}/rating" data-value="3">${[1,2,3,4,5].map(n=>`<button data-photo-rate="${n}" aria-pressed="${n===3}">${n<=3?'★':'☆'}</button>`).join('')}<span data-photo-rating-summary>3.0</span><span data-photo-rating-error></span></div>`;
    document.body.innerHTML = widget('1');
    let reject: (error: Error) => void = () => {};
    const api = vi.fn().mockImplementationOnce(()=>new Promise((_resolve, fail)=>{reject=fail;})).mockResolvedValue({ratingAverage:4.5,ratingCount:2,userRating:5});
    Object.assign(window,{CMS:{api}}); initPhotoRating();
    const five=document.querySelector<HTMLButtonElement>('[data-photo-rate="5"]')!;
    five.click();five.click();expect(api).toHaveBeenCalledTimes(1);expect(five.disabled).toBe(true);
    reject(new Error('Failed'));
    await vi.waitFor(()=>expect(five.disabled).toBe(false));
    expect(document.querySelector<HTMLElement>('[data-photo-rating]')!.dataset.value).toBe('3');
    expect(document.querySelector('[data-photo-rating-error]')!.textContent).not.toBe('');
    five.click(); await vi.waitFor(()=>expect(document.querySelector<HTMLElement>('[data-photo-rating]')!.dataset.value).toBe('5'));
    expect(five.getAttribute('aria-pressed')).toBe('true');
    expect(api).toHaveBeenLastCalledWith('/api/album/photos/1/rating',{method:'POST',data:{value:5}});
    document.body.innerHTML=widget('2');document.querySelector<HTMLButtonElement>('[data-photo-rate="5"]')!.click();
    await vi.waitFor(()=>expect(api).toHaveBeenCalledTimes(3));
    expect(api).toHaveBeenLastCalledWith('/api/album/photos/2/rating',{method:'POST',data:{value:5}});
});

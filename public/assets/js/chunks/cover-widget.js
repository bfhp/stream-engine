/*! Third-party licenses: /assets/THIRD_PARTY_LICENSES.md */
import{n as e}from"./i18n.js";import{t}from"./uploads.js";import{t as n}from"./api-errors.js";function r(r){let{slot:i,urlInput:a,uploadsApiUrl:o,prompt:s,onError:c}=r;if(!i||!a)return;function l(){i.innerHTML=`
            <div class="blog-post-cover-drop" data-cover-drop>
                <i class="bi bi-card-image fs-4"></i>
                <div>
                    <div>${s}</div>
                    <div class="small text-body-secondary">${e(`js.cover.formats`)}</div>
                </div>
            </div>
            <input type="file" class="d-none" accept="image/*" data-cover-file>
        `,a.value=``,d()}function u(t,n){let r=n.replace(/[<>&]/g,``);i.innerHTML=`
            <div class="blog-post-cover-file">
                <div class="blog-post-cover-thumb" style="background-image:url('${t}')"></div>
                <div class="blog-post-cover-fileinfo">
                    <div class="blog-post-cover-filename">${r}</div>
                </div>
                <button type="button" class="blog-post-cover-remove" aria-label="${e(`js.common.remove_aria`)}"><i class="bi bi-x-lg"></i></button>
            </div>
        `,a.value=t,d()}function d(){let r=i.querySelector(`[data-cover-drop]`),a=i.querySelector(`[data-cover-file]`),s=i.querySelector(`.blog-post-cover-remove`);r?.addEventListener(`click`,()=>a?.click()),a?.addEventListener(`change`,async()=>{let r=a.files?.[0];if(r)try{u((await t(o,r)).url,r.name)}catch(t){c(n(t,e(`js.cover.upload_failed`)))}}),s?.addEventListener(`click`,l)}d()}export{r as t};
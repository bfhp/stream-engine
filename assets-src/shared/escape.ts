/* ==========================================================================
   HTML escaping

   The DOM round-trip form: hand the value to `textContent`, read `innerHTML`
   back. The browser does the escaping, so there is no list of characters to
   get wrong - but it does not escape quotes, because inside a text node they
   need no escaping. These strings end up in attribute values too, so the two
   quote characters are replaced afterwards.

   `app.ts` exposes this on the `CMS` surface (`CMS.escapeHtml`) and it is
   what every template-string renderer in the front end uses. It lives here so
   the renderers that are *not* inside `app.ts`'s closure can use the same
   one rather than a near-copy.

   Not the same as `comment-quotes.ts`'s `escapeCommentText()`, and
   deliberately so: that one is a plain string transform matching PHP's
   `htmlspecialchars()` exactly - including `&#039;` where this emits
   `&#39;` - because it previews what the server will store.
   ========================================================================== */

export function escapeHtml(value: unknown): string {
    const div = document.createElement("div");
    div.textContent = String(value ?? "");

    return div.innerHTML.replace(/"/g, "&quot;").replace(/'/g, "&#39;");
}

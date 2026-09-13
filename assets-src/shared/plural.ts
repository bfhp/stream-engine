/* ==========================================================================
   Russian numeric agreement

   One implementation of the rule the front end had four of - `profile.ts`'s
   `plural()`, `blog-post-form.ts`'s and `forum-poll.ts`'s `pluralize()`, and
   `messages.ts`'s `pluralRu()` - written three different ways.

   They agreed on every non-negative number and disagreed on negatives: the
   three that skipped `Math.abs` fell through to the many-form for -1 and -2,
   because `-1 % 10` is `-1` in JavaScript. No live bug, since every counter
   on the site counts things, but it is the kind of divergence that only
   matters once somebody renders a delta.

   `abs` is kept here because `Core\Formatter::plural()` has it, and that one
   is the authority: the same counts are rendered server-side on the first
   page load and client-side after it, so the two have to agree.
   ========================================================================== */

/**
 * Picks the form. Returns the **word only** - the callers that want the number
 * in front interpolate it, which keeps this usable in the places that put the
 * count somewhere else in the sentence.
 *
 * The one/few/many forms, and the 11-14 exception that makes the rule
 * worth writing down at all: they take the many-form despite ending in 1-4.
 */
export function plural(n: number, one: string, few: string, many: string): string {
    const mod100 = Math.abs(n) % 100;
    const mod10 = mod100 % 10;

    if (mod100 >= 11 && mod100 <= 14) return many;
    if (mod10 === 1) return one;
    if (mod10 >= 2 && mod10 <= 4) return few;

    return many;
}

/** `plural()` with the count in front. */
export function pluralWithCount(n: number, one: string, few: string, many: string): string {
    return `${n} ${plural(n, one, few, many)}`;
}

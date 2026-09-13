/* ==========================================================================
   API error messages

   CMS.api() rejects with the *parsed server payload*, not an Error, so every
   catch block has to dig the message out itself - and the shape varies:
   StreamEngine::handleRequest() answers a ValidationException with
   `{"error": "<message>"}`, while a few handlers nest it one level deeper.

   That dug-it-out-by-hand pattern was copied into five places with three
   different rules, and at least one of them was simply wrong:
   `assets-src/site/auth.ts` read `error.error` on login (correct) and
   `error?.error?.message` on logout (always undefined, so logout could only
   ever show its fallback). This is the canonical chain, extracted so those
   copies have somewhere to converge.
   ========================================================================== */

/**
 * Pulls a displayable message out of whatever api() rejected with, in
 * precedence order, falling back when nothing usable is there.
 *
 * Every level is checked for being a non-blank *string* rather than merely
 * present: a whitespace-only message is worse than the fallback, and
 * `error.error` is sometimes an object, in which case the message is one level
 * further down.
 *
 * Takes `unknown` because callers hand it a rejection value they know nothing
 * about - including `null`, which is what api() throws when a failing response
 * had no JSON body at all. That case is the reason login's unguarded
 * `error.error` was a TypeError waiting to happen.
 */
export function getApiErrorMessage(error: unknown, fallback: string): string {
    if (isNonBlankString(error)) {
        return error;
    }

    if (!isRecord(error)) {
        return fallback;
    }

    if (isNonBlankString(error.error)) {
        return error.error;
    }

    if (isRecord(error.error) && isNonBlankString(error.error.message)) {
        return error.error.message;
    }

    if (isNonBlankString(error.message)) {
        return error.message;
    }

    return fallback;
}

function isNonBlankString(value: unknown): value is string {
    return typeof value === 'string' && value.trim() !== '';
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

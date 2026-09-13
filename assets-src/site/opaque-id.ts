/* ==========================================================================
   Opaque public ids

   Turns an internal auto-increment id into a short, shuffled-looking code
   (and back). Used for anything that ends up visible to the visitor - the
   conversation id in the address bar's hash, for instance - so the URL
   doesn't advertise "this is conversation #42, so #41 and #43 exist too".

   This is obfuscation, NOT security: the transform is reversible in the
   browser (it has to be - the code has to become an id again), and anyone
   who reads the bundle can invert it themselves. Access control stays where
   it belongs, server-side; every API call still checks the caller is a
   participant. The only thing this buys is that ids stop being guessable at
   a glance and stop leaking how many rows a table has.
   ========================================================================== */

// 4-round Feistel network over a 32-bit block (two 16-bit halves). A Feistel
// is a bijection by construction, whatever the round function does - so
// every id maps to exactly one code and every code back to exactly one id,
// with no lookup table and no chance of two ids colliding.
const ROUND_KEYS = [0x9e3779b1, 0x85ebca77, 0xc2b2ae3d, 0x27d4eb2f];

// Codes are always this wide so they look uniform (base36 of a 32-bit
// value never needs more than 7 chars: 0xffffffff -> "1z141z3").
const CODE_LENGTH = 7;

const MAX_ID = 0xffffffff;

/**
 * Round function - any deterministic 16-bit-out mixer works for
 * reversibility; this one is a cheap xorshift-multiply so neighbouring ids
 * land far apart in the output space.
 */
function roundFunction(half: number, key: number): number {
    let x = (half ^ key) >>> 0;
    x = Math.imul(x, 0x2545f491) >>> 0;
    x = (x ^ (x >>> 13)) >>> 0;
    x = Math.imul(x, 0x27220a95) >>> 0;
    x = (x ^ (x >>> 15)) >>> 0;

    return x & 0xffff;
}

function isEncodableId(id: unknown): id is number {
    return typeof id === 'number'
        && Number.isInteger(id)
        && id > 0
        && id <= MAX_ID;
}

/**
 * Internal id -> opaque code. Returns null for anything that isn't a
 * plausible id, so callers can treat "no id" and "bad id" the same way
 * instead of putting a garbage code in the URL.
 */
export function encodeId(id: number): string | null {
    if (!isEncodableId(id)) return null;

    let left = (id >>> 16) & 0xffff;
    let right = id & 0xffff;

    for (let i = 0; i < ROUND_KEYS.length; i++) {
        const next = (left ^ roundFunction(right, ROUND_KEYS[i])) & 0xffff;
        left = right;
        right = next;
    }

    const block = (((left << 16) >>> 0) | right) >>> 0;

    return block.toString(36).padStart(CODE_LENGTH, '0');
}

/**
 * Opaque code -> internal id. Returns null for a malformed code (wrong
 * alphabet, too long, decodes to 0).
 *
 * A well-formed but made-up code still decodes to *some* number - the
 * transform covers the whole 32-bit space, so there's nothing to check it
 * against here. Callers must treat the result as an id to be looked up, not
 * as proof the row exists or is theirs; the API answers that.
 */
export function decodeId(code: string | null | undefined): number | null {
    if (typeof code !== 'string') return null;

    const normalized = code.trim().toLowerCase();
    if (!/^[0-9a-z]{1,8}$/.test(normalized)) return null;

    const block = parseInt(normalized, 36);
    if (!Number.isInteger(block) || block < 0 || block > MAX_ID) return null;

    let left = (block >>> 16) & 0xffff;
    let right = block & 0xffff;

    // Same rounds, unwound - Feistel decryption is the encryption loop with
    // the halves swapped back and the keys consumed in reverse.
    for (let i = ROUND_KEYS.length - 1; i >= 0; i--) {
        const previous = (right ^ roundFunction(left, ROUND_KEYS[i])) & 0xffff;
        right = left;
        left = previous;
    }

    const id = (((left << 16) >>> 0) | right) >>> 0;

    return isEncodableId(id) ? id : null;
}

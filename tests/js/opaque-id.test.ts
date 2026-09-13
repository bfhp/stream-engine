import { describe, expect, it } from "vitest";

import { decodeId, encodeId } from "../../assets-src/site/opaque-id";

/**
 * The module directly, rather than through `CMS.encodeId` the way
 * `app.test.ts` reaches it. That file covers the round trip and the obvious
 * rejections; what is left is the part that is easy to "fix" into something
 * wrong.
 *
 * Chiefly: a well-formed but invented code decodes to *some* id. There is
 * nothing here to check it against - the transform covers the whole 32-bit
 * space, so every 7-character code is the image of some number. That is
 * deliberate and documented, and the temptation is to turn `decodeId` into a
 * validity check. It cannot be one; the server answers whether a row exists
 * and whether it is yours, and a client-side "is this real" would be both a
 * lie and a way to enumerate.
 */
describe("opaque-id", () => {
    /* ===============================
       Normalization
    =============================== */

    it("accepts a code the way a visitor might have pasted it", () => {
        const code = encodeId(42)!;

        // Trimmed and lower-cased, so a code copied out of an email with a
        // trailing space, or auto-capitalized on a phone, still resolves.
        expect(decodeId(`  ${code.toUpperCase()}  `)).toBe(42);
        expect(decodeId(code.toUpperCase())).toBe(42);
        expect(decodeId(` ${code} `)).toBe(42);
    });

    it("is not case sensitive in either direction", () => {
        for (const id of [1, 7, 42, 65_535, 4_294_967_295]) {
            const code = encodeId(id)!;

            expect(decodeId(code.toUpperCase())).toBe(id);
            expect(decodeId(code.toLowerCase())).toBe(id);
        }
    });

    /* ===============================
       The length rules
    =============================== */

    it("always produces exactly seven characters", () => {
        // Uniform width is the point - a short code for a small id would put
        // back exactly the signal the encoding removes ("this is one of the
        // first rows"). base36 of a 32-bit value never needs more than 7.
        for (const id of [1, 2, 9, 1000, 65_536, 4_294_967_295]) {
            expect(encodeId(id)).toMatch(/^[0-9a-z]{7}$/);
        }
    });

    it("pads a short block with leading zeros rather than shortening it", () => {
        // Some ids encode to a block whose base36 form is under 7 digits;
        // those get zero-padded, and the padding has to survive decoding.
        const padded = [];

        for (let id = 1; id <= 5000; id++) {
            const code = encodeId(id)!;

            if (code.startsWith("0")) {
                padded.push(id);
                expect(decodeId(code)).toBe(id);
            }
        }

        expect(padded.length).toBeGreaterThan(0);
    });

    it("accepts an eight-character code only if it is in range", () => {
        // The regex allows up to 8 characters, so the *value* check behind it
        // is what refuses an over-long one - "zzzzzzzz" parses fine in base36
        // and is far past 32 bits.
        expect(decodeId("zzzzzzzz")).toBeNull();
        expect(decodeId("zzzzzzzzz")).toBeNull();

        // The largest block that still fits.
        expect(decodeId((0xffffffff).toString(36))).not.toBeNull();
    });

    /* ===============================
       What is refused
    =============================== */

    it("refuses ids that are not ids", () => {
        expect(encodeId(0)).toBeNull();
        expect(encodeId(-1)).toBeNull();
        expect(encodeId(1.5)).toBeNull();
        expect(encodeId(Number.NaN)).toBeNull();
        expect(encodeId(Number.POSITIVE_INFINITY)).toBeNull();
        // One past the 32-bit space the Feistel network covers.
        expect(encodeId(0x1_0000_0000)).toBeNull();
    });

    it("refuses ids of the wrong type without throwing", () => {
        // Callers hand it whatever a template rendered into a dataset, which
        // is a string as often as not.
        expect(encodeId("42" as unknown as number)).toBeNull();
        expect(encodeId(null as unknown as number)).toBeNull();
        expect(encodeId(undefined as unknown as number)).toBeNull();
    });

    it("refuses a code that is not in the alphabet", () => {
        expect(decodeId("not a code!")).toBeNull();
        expect(decodeId("abc-def")).toBeNull();
        expect(decodeId("Xk3f9_")).toBeNull();
        // Cyrillic that looks like Latin - a real paste hazard.
        expect(decodeId("хk3f9аb")).toBeNull();
    });

    it("refuses an empty or absent code", () => {
        expect(decodeId("")).toBeNull();
        expect(decodeId("   ")).toBeNull();
        expect(decodeId(null)).toBeNull();
        expect(decodeId(undefined)).toBeNull();
        expect(decodeId(42 as unknown as string)).toBeNull();
    });

    it("refuses the code that would decode to id zero", () => {
        // Zero is not a valid id, so whichever code maps to it must not be
        // accepted - otherwise a lookup would go out for row 0.
        const zeroCode = encodeBlockOf(0);

        expect(decodeId(zeroCode)).toBeNull();
    });

    /* ===============================
       The documented non-guarantee
    =============================== */

    it("decodes a well-formed invented code to some id rather than null", () => {
        // This is the contract, not an oversight. Nothing client-side can say
        // whether an id exists; treating a null here as "not found" would be a
        // lie, and building one would hand an attacker an oracle.
        const invented = decodeId("1a2b3c4");

        expect(invented).not.toBeNull();
        expect(Number.isInteger(invented)).toBe(true);
        expect(invented!).toBeGreaterThan(0);
        // And it round-trips, which is what "some id" means here.
        expect(encodeId(invented!)).toBe("1a2b3c4");
    });

    it("refuses a seven-character code whose value is past the block space", () => {
        // Easy to miss: the alphabet and the length are both fine, so only the
        // range check catches it. Most 7-character base36 strings are in fact
        // *out* of range - the largest block, 0xffffffff, is only "1z141z3",
        // so anything from "1z141z4" up is refused.
        expect(decodeId("abcdefg")).toBeNull();
        expect(decodeId("zzzzzzz")).toBeNull();

        expect(decodeId("1z141z3")).not.toBeNull();
        expect(decodeId("1z141z4")).toBeNull();
    });

    /* ===============================
       The property that makes it usable at all
    =============================== */

    it("is a bijection across a long run of ids", () => {
        const codes = new Set<string>();

        for (let id = 1; id <= 20_000; id++) {
            const code = encodeId(id)!;
            codes.add(code);
            expect(decodeId(code)).toBe(id);
        }

        // No two ids share a code - guaranteed by construction, asserted
        // because "the round function looks random" is exactly the kind of
        // change that would quietly break it.
        expect(codes.size).toBe(20_000);
    });

    it("scatters neighbouring ids", () => {
        // The reason for a Feistel rather than, say, an offset: consecutive
        // rows must not produce consecutive-looking codes, or the URL still
        // says "there is a row either side of this one".
        const codes = [1, 2, 3, 4, 5].map(id => encodeId(id)!);
        const firstChars = new Set(codes.map(code => code.slice(0, 3)));

        expect(firstChars.size).toBe(5);
    });

    it("round-trips the boundaries", () => {
        for (const id of [1, 2, 65_535, 65_536, 65_537, 4_294_967_294, 4_294_967_295]) {
            expect(decodeId(encodeId(id))).toBe(id);
        }
    });
});

/**
 * Produces the code for a given *block* value, bypassing encodeId's own id
 * guard - the only way to reach the "decodes to 0" branch, since encodeId
 * refuses to hand out a code for id 0 in the first place.
 */
function encodeBlockOf(id: number): string {
    // Walk the network the same way encodeId does.
    const ROUND_KEYS = [0x9e3779b1, 0x85ebca77, 0xc2b2ae3d, 0x27d4eb2f];

    const roundFunction = (half: number, key: number): number => {
        let x = (half ^ key) >>> 0;
        x = Math.imul(x, 0x2545f491) >>> 0;
        x = (x ^ (x >>> 13)) >>> 0;
        x = Math.imul(x, 0x27220a95) >>> 0;
        x = (x ^ (x >>> 15)) >>> 0;

        return x & 0xffff;
    };

    let left = (id >>> 16) & 0xffff;
    let right = id & 0xffff;

    for (let i = 0; i < ROUND_KEYS.length; i++) {
        const next = (left ^ roundFunction(right, ROUND_KEYS[i])) & 0xffff;
        left = right;
        right = next;
    }

    return ((((left << 16) >>> 0) | right) >>> 0).toString(36).padStart(7, "0");
}

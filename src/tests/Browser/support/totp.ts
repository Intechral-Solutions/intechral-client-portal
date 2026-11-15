import { createHmac } from 'node:crypto';

/**
 * RFC 6238 TOTP (HMAC-SHA1, 30 s step, 6 digits), enough to answer Fortify's two-factor challenge in the browser suite.
 *
 * Release 1.0 made a confirmed second factor mandatory for operators, so the seeded operator personas carry one with a FIXED DEVELOPMENT SECRET
 * (`DevSeeder::E2E_TOTP_SECRET`, development seed only; `tests/Unit/DevSeederTotpParityTest.php` pins that the two agree). The secret protects nothing:
 * it exists only in the seeded test database.
 */
export const E2E_TOTP_SECRET = 'JBSWY3DPEHPK3PXP';

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32Decode(input: string): Buffer {
    let bits = '';
    for (const character of input.replace(/=+$/, '').toUpperCase()) {
        const value = ALPHABET.indexOf(character);
        if (value < 0) throw new Error(`Invalid base32 character: ${character}`);
        bits += value.toString(2).padStart(5, '0');
    }
    const bytes: number[] = [];
    for (let index = 0; index + 8 <= bits.length; index += 8) {
        bytes.push(parseInt(bits.slice(index, index + 8), 2));
    }

    return Buffer.from(bytes);
}

export const TOTP_STEP_SECONDS = 30;

export function totp(secret: string, atMs: number = Date.now(), digits = 6): string {
    const counter = Math.floor(atMs / 1000 / TOTP_STEP_SECONDS);
    const message = Buffer.alloc(8);
    message.writeBigUInt64BE(BigInt(counter));
    const hmac = createHmac('sha1', base32Decode(secret)).update(message).digest();
    const offset = hmac[hmac.length - 1] & 0x0f;
    const code =
        (((hmac[offset] & 0x7f) << 24) |
            (hmac[offset + 1] << 16) |
            (hmac[offset + 2] << 8) |
            hmac[offset + 3]) %
        10 ** digits;

    return code.toString().padStart(digits, '0');
}

/** Milliseconds until the next 30 s boundary, plus a second of margin: the point from which a fresh code is certainly a NEWER one. */
export function msUntilNextStep(nowMs: number = Date.now()): number {
    return TOTP_STEP_SECONDS * 1000 - (nowMs % (TOTP_STEP_SECONDS * 1000)) + 1000;
}

import { describe, expect, it } from 'vitest';

import { msUntilNextStep, totp } from './totp';

// RFC 6238 appendix B, SHA-1 secret "12345678901234567890" (base32 below), 6-digit truncation of the published 8-digit codes.
const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

describe('totp', () => {
    it('matches the RFC 6238 SHA-1 test vectors', () => {
        expect(totp(RFC_SECRET, 59_000)).toBe('287082');
        expect(totp(RFC_SECRET, 1_111_111_109_000)).toBe('081804');
        expect(totp(RFC_SECRET, 1_234_567_890_000)).toBe('005924');
        expect(totp(RFC_SECRET, 2_000_000_000_000)).toBe('279037');
    });

    it('is stable within a step and changes at the next one', () => {
        expect(totp(RFC_SECRET, 60_000)).toBe(totp(RFC_SECRET, 89_999));
        expect(totp(RFC_SECRET, 60_000)).not.toBe(totp(RFC_SECRET, 90_000));
    });

    it('waits past the next boundary, never less than a second', () => {
        expect(msUntilNextStep(60_000)).toBe(31_000);
        expect(msUntilNextStep(89_500)).toBe(1_500);
    });
});

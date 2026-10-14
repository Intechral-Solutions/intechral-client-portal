import { describe, expect, it } from 'vitest';

import { decidePilotRun, detectMode, pilotRequired, type PilotProbe } from './pilot-probe';

/**
 * R0.9-CS1: the pilot browser spec must not be able to skip its way to green when the server is not in pilot
 * mode. These are the cases the hosted job's "required" mode exists for.
 */

const pilot: PilotProbe = { invoices: 404, tickets: 200, projects: 200 };
const legacy: PilotProbe = { invoices: 200, tickets: 200, projects: 200 };
const closed: PilotProbe = { invoices: 404, tickets: 404, projects: 404 };

describe('detectMode', () => {
    it('tells the three modes apart by behaviour', () => {
        expect(detectMode(pilot)).toBe('pilot');
        expect(detectMode(legacy)).toBe('legacy');
        expect(detectMode(closed)).toBe('closed');
    });

    it('treats anything else as unknown', () => {
        for (const probe of [
            { invoices: 500, tickets: 500, projects: 500 },
            { invoices: 302, tickets: 302, projects: 302 },
            { invoices: 403, tickets: 200, projects: 200 },
            { invoices: 404, tickets: 200, projects: 404 },
            { invoices: 404, tickets: 500, projects: 200 },
            { invoices: 0, tickets: 0, projects: 0 },
        ]) {
            expect(detectMode(probe)).toBe('unknown');
        }
    });
});

describe('decidePilotRun when pilot mode is required', () => {
    it('runs against a pilot server', () => {
        expect(decidePilotRun(pilot, true)).toBe('run');
    });

    it.each([
        ['legacy', legacy],
        ['closed', closed],
        ['a 500', { invoices: 500, tickets: 500, projects: 500 }],
        ['a redirect', { invoices: 302, tickets: 302, projects: 302 }],
        ['a forbidden customer', { invoices: 403, tickets: 403, projects: 403 }],
        ['a half-open surface', { invoices: 404, tickets: 200, projects: 404 }],
    ])('fails, never skips, against %s', (_name, probe) => {
        expect(() => decidePilotRun(probe, true)).toThrow(/REQUIRED/);
    });

    it('names what it saw', () => {
        expect(() => decidePilotRun(legacy, true)).toThrow(/detected: legacy; \/my\/invoices 200/);
    });
});

describe('decidePilotRun when pilot mode is not required', () => {
    it('runs against a pilot server', () => {
        expect(decidePilotRun(pilot, false)).toBe('run');
    });

    it('skips only a plainly legacy server (the default development stack)', () => {
        expect(decidePilotRun(legacy, false)).toBe('skip');
    });

    it.each([
        ['closed', closed],
        ['a 500', { invoices: 500, tickets: 500, projects: 500 }],
        ['a redirect', { invoices: 302, tickets: 302, projects: 302 }],
    ])('still fails against %s instead of skipping', (_name, probe) => {
        expect(() => decidePilotRun(probe, false)).toThrow(/neither in legacy nor in pilot mode/);
    });
});

describe('pilotRequired', () => {
    it.each([
        ['1', true],
        ['true', true],
        ['yes', true],
        ['anything', true],
        [undefined, false],
        ['', false],
        ['0', false],
        ['false', false],
        ['no', false],
        ['off', false],
        [' OFF ', false],
    ])('reads %s as %s', (value, expected) => {
        expect(pilotRequired(value)).toBe(expected);
    });
});

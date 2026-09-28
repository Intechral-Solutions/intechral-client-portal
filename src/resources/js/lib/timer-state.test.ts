import { describe, expect, it } from 'vitest';

import {
    clockOffsetFrom,
    contextLine,
    elapsedSeconds,
    formatElapsed,
    formatElapsedNarrow,
    newestFirst,
    newestTimer,
    pillAccessibleName,
    pillLabel,
    trayHeading,
} from '@/lib/timer-state';
import type { ActiveTimer } from '@/types/time';

function timer(overrides: Partial<ActiveTimer> = {}): ActiveTimer {
    return {
        id: 1,
        started_at: '2026-09-28T10:00:00.000Z',
        server_now: '2026-09-28T10:00:00.000Z',
        description: null,
        context: null,
        ...overrides,
    };
}

describe('elapsedSeconds', () => {
    it('counts whole seconds from the start instant', () => {
        const now = Date.parse('2026-09-28T10:01:30.900Z');

        // 90.9s truncates to 90: a clock shows the second it has completed, not the one in progress.
        expect(elapsedSeconds('2026-09-28T10:00:00.000Z', now)).toBe(90);
    });

    it('returns null for a timestamp that is not a parseable instant', () => {
        // Distinct from 0 so a malformed payload cannot render as a plausible stopped-looking clock.
        expect(elapsedSeconds('not-a-date', Date.now())).toBeNull();
        expect(elapsedSeconds('', Date.now())).toBeNull();
    });

    it('clamps to zero when the corrected clock precedes the start', () => {
        const now = Date.parse('2026-09-28T09:59:55.000Z');

        expect(elapsedSeconds('2026-09-28T10:00:00.000Z', now)).toBe(0);
    });
});

describe('formatElapsed', () => {
    it.each([
        [0, '0:00:00'],
        [9, '0:00:09'],
        [59, '0:00:59'],
        // The minute and hour boundaries, where a padding or modulo slip shows up.
        [60, '0:01:00'],
        [3599, '0:59:59'],
        [3600, '1:00:00'],
        [3661, '1:01:01'],
        // Past a day it keeps counting hours rather than wrapping, so a forgotten timer reads honestly.
        [111720, '31:02:00'],
    ])('renders %i seconds as %s', (seconds, expected) => {
        expect(formatElapsed(seconds)).toBe(expected);
    });

    it('drops seconds in the narrow form', () => {
        expect(formatElapsedNarrow(3661)).toBe('1:01');
        expect(formatElapsedNarrow(59)).toBe('0:00');
    });
});

describe('clockOffsetFrom', () => {
    it('measures the offset against the midpoint of the request', () => {
        // Request spanned 1000ms->2000ms (midpoint 1500). The server read 4000, so it is 2500 ahead.
        const offset = clockOffsetFrom(
            timer({ server_now: new Date(4000).toISOString() }),
            1000,
            2000,
        );

        expect(offset).toBe(2500);
    });

    it('falls back to the unadjusted browser clock without a usable server_now', () => {
        expect(clockOffsetFrom(undefined, 1000, 2000)).toBe(0);
        expect(clockOffsetFrom(timer({ server_now: 'nonsense' }), 1000, 2000)).toBe(0);
    });
});

describe('newestTimer', () => {
    it('picks the most recently started timer regardless of array order', () => {
        const older = timer({ id: 1, started_at: '2026-09-28T10:00:00.000Z' });
        const newer = timer({ id: 2, started_at: '2026-09-28T11:00:00.000Z' });

        expect(newestTimer([older, newer])?.id).toBe(2);
        expect(newestTimer([newer, older])?.id).toBe(2);
    });

    it('returns null for an empty set and survives an unparseable timestamp', () => {
        expect(newestTimer([])).toBeNull();

        const good = timer({ id: 1, started_at: '2026-09-28T10:00:00.000Z' });
        const bad = timer({ id: 2, started_at: 'nonsense' });

        // There is always a timer to draw when the set is non-empty.
        expect(newestTimer([good, bad])?.id).toBe(1);
        expect(newestTimer([bad])?.id).toBe(2);
    });

    it('breaks a same-second tie on the id, consistently for the pill and the tray', () => {
        // `timer_started_at` is a MySQL `timestamp` with no fractional seconds, so two timers started
        // in the same second are byte-identical here. The id is monotonic, so the higher one is newer.
        // Chromium caught the pill and the tray disagreeing when this was left to the server's order.
        const first = timer({ id: 7, started_at: '2026-09-28T10:00:00.000Z' });
        const second = timer({ id: 8, started_at: '2026-09-28T10:00:00.000Z' });

        expect(newestTimer([first, second])?.id).toBe(8);
        expect(newestTimer([second, first])?.id).toBe(8);

        // And the tray agrees, whichever order the payload arrived in.
        expect(newestFirst([first, second]).map((entry) => entry.id)).toEqual([8, 7]);
        expect(newestFirst([second, first]).map((entry) => entry.id)).toEqual([8, 7]);
    });

    it('sorts an unparseable timestamp oldest instead of reordering the rest', () => {
        const good = timer({ id: 1, started_at: '2026-09-28T10:00:00.000Z' });
        const newer = timer({ id: 2, started_at: '2026-09-28T11:00:00.000Z' });
        const bad = timer({ id: 3, started_at: 'nonsense' });

        expect(newestFirst([good, bad, newer]).map((entry) => entry.id)).toEqual([2, 1, 3]);
    });

    it('orders tray rows newest first without mutating the input', () => {
        const older = timer({ id: 1, started_at: '2026-09-28T10:00:00.000Z' });
        const newer = timer({ id: 2, started_at: '2026-09-28T11:00:00.000Z' });
        const input = [older, newer];

        expect(newestFirst(input).map((entry) => entry.id)).toEqual([2, 1]);
        expect(input.map((entry) => entry.id)).toEqual([1, 2]);
    });
});

describe('labels', () => {
    it('prefers the context, falls back to the description, then to a constant', () => {
        expect(
            pillLabel(timer({ context: { type: 'Ticket', id: 3, label: 'TKT-9', url: '/t/3' } })),
        ).toBe('Ticket: TKT-9');
        expect(pillLabel(timer({ description: 'Refactoring' }))).toBe('Refactoring');
        expect(pillLabel(timer())).toBe('Untitled timer');
    });

    it('truncates a long label to the pill budget with an ellipsis', () => {
        const label = pillLabel(timer({ description: 'x'.repeat(60) }));

        // The cap is what keeps the pill under its ~360px ceiling (Direction D §12.1).
        expect(label).toHaveLength(28);
        expect(label.endsWith('…')).toBe(true);
    });

    it('leaves a label at exactly the limit untruncated', () => {
        const exact = 'y'.repeat(28);

        expect(pillLabel(timer({ description: exact }))).toBe(exact);
    });

    it('gives the tray an untruncated context line, or none at all', () => {
        expect(
            contextLine(
                timer({ context: { type: 'Task', id: 4, label: 'A'.repeat(50), url: null } }),
            ),
        ).toBe(`Task: ${'A'.repeat(50)}`);
        expect(contextLine(timer())).toBeNull();
    });

    it('counts running timers in the tray heading', () => {
        expect(trayHeading(1)).toBe('1 running');
        expect(trayHeading(3)).toBe('3 running');
    });
});

describe('pillAccessibleName', () => {
    it('names the start affordance when nothing runs', () => {
        expect(pillAccessibleName([])).toBe('Start timer');
    });

    it('names the shown timer and counts the others', () => {
        const older = timer({ id: 1, started_at: '2026-09-28T10:00:00.000Z' });
        const newer = timer({
            id: 2,
            started_at: '2026-09-28T11:00:00.000Z',
            description: 'Billing fix',
        });

        expect(pillAccessibleName([newer])).toBe('Running timer: Billing fix. Show timers');
        expect(pillAccessibleName([older, newer])).toBe(
            'Running timer: Billing fix, and 1 other. Show timers',
        );
        expect(pillAccessibleName([older, newer, timer({ id: 3 })])).toContain('and 2 others');
    });

    it('never includes the elapsed value', () => {
        // The name is re-read on change; a clock in it would announce every second (§13).
        const name = pillAccessibleName([timer({ description: 'Billing fix' })]);

        expect(name).not.toMatch(/\d:\d\d/);
    });
});

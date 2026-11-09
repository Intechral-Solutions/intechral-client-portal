import { describe, expect, it } from 'vitest';

import {
    COMFORT_MS,
    epochToWall,
    formatWall,
    GRACE_MS,
    isWallClock,
    relativeLabel,
    urgencyFor,
    wallToEpoch,
} from './due-deadline';

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;

/**
 * Release 1.0 (HD-C1) — the owner's exact thresholds, and the application-zone reading of a typed date.
 * The browser's own zone must never matter: every test below passes an explicit IANA zone.
 */
describe('urgencyFor — the owner thresholds', () => {
    it('is GREEN when more than 4 hours remain', () => {
        expect(urgencyFor(COMFORT_MS + 1)).toBe('ok');
        expect(urgencyFor(4 * HOUR + MINUTE)).toBe('ok');
        expect(urgencyFor(30 * 24 * HOUR)).toBe('ok');
    });

    it('is YELLOW at exactly 4 hours before the deadline (inclusive)', () => {
        expect(urgencyFor(COMFORT_MS)).toBe('soon');
        expect(urgencyFor(COMFORT_MS - 1)).toBe('soon');
    });

    it('is YELLOW up to and including 1 hour after the deadline', () => {
        expect(urgencyFor(1)).toBe('soon');
        expect(urgencyFor(0)).toBe('soon');
        expect(urgencyFor(-1)).toBe('soon');
        expect(urgencyFor(-30 * MINUTE)).toBe('soon');
        expect(urgencyFor(-GRACE_MS)).toBe('soon');
    });

    it('is RED when more than 1 hour overdue', () => {
        expect(urgencyFor(-GRACE_MS - 1)).toBe('late');
        expect(urgencyFor(-2 * HOUR)).toBe('late');
        expect(urgencyFor(-30 * 24 * HOUR)).toBe('late');
    });

    it('is NEUTRAL with no effective deadline', () => {
        expect(urgencyFor(null)).toBe('none');
        expect(urgencyFor(Number.NaN)).toBe('none');
    });
});

describe('relativeLabel — the actual time, even inside the yellow window', () => {
    it.each([
        [3 * HOUR, 'Due in 3 hours'],
        [40 * MINUTE, 'Due in 40 minutes'],
        [-30 * MINUTE, 'Overdue by 30 minutes'],
        [-2 * HOUR, 'Overdue by 2 hours'],
        [HOUR, 'Due in 1 hour'],
        [MINUTE, 'Due in 1 minute'],
        [30_000, 'Due in less than a minute'],
        [0, 'Due in less than a minute'],
        [-30_000, 'Overdue by less than a minute'],
        [3 * HOUR + 20 * MINUTE, 'Due in 3 hours 20 minutes'],
        [26 * HOUR, 'Due in 1 day 2 hours'],
        [48 * HOUR, 'Due in 2 days'],
        [-25 * HOUR, 'Overdue by 1 day 1 hour'],
    ])('%d ms → %s', (remaining, label) => {
        expect(relativeLabel(remaining)).toBe(label);
    });

    it('says so when there is no deadline', () => {
        expect(relativeLabel(null)).toBe('No due date');
    });
});

describe('wall-clock text in the application zone', () => {
    it('reads a typed date in UTC without consulting the browser zone', () => {
        expect(wallToEpoch('2026-11-20T14:30', 'UTC')).toBe(Date.UTC(2026, 10, 20, 14, 30));
    });

    it('reads the same text as a different instant in a different application zone', () => {
        // 14:30 in New York in November (EST, UTC-5) is 19:30 UTC; in Auckland (NZDT, UTC+13) it is the previous day 01:30 UTC.
        expect(wallToEpoch('2026-11-20T14:30', 'America/New_York')).toBe(
            Date.UTC(2026, 10, 20, 19, 30),
        );
        expect(wallToEpoch('2026-11-20T14:30', 'Pacific/Auckland')).toBe(
            Date.UTC(2026, 10, 20, 1, 30),
        );
    });

    it('round-trips an instant through the zone', () => {
        const instant = Date.UTC(2026, 10, 20, 19, 30);

        expect(epochToWall(instant, 'America/New_York')).toBe('2026-11-20T14:30');
        expect(epochToWall(instant, 'UTC')).toBe('2026-11-20T19:30');
    });

    it('refuses an impossible date, as DueDateInput::parse does', () => {
        expect(wallToEpoch('2026-02-31T10:00', 'UTC')).toBeNull();
        expect(wallToEpoch('2026-13-01T10:00', 'UTC')).toBeNull();
        expect(wallToEpoch('2026-11-20T25:00', 'UTC')).toBeNull();
        expect(wallToEpoch('2026-11-20 14:30', 'UTC')).toBeNull();
        expect(wallToEpoch('', 'UTC')).toBeNull();
        expect(isWallClock('2026-02-31T10:00')).toBe(false);
        expect(isWallClock('2026-02-28T10:00')).toBe(true);
    });

    it('refuses a wall-clock time skipped by a spring-forward gap', () => {
        // 2027-03-14 02:30 does not exist in New York (clocks jump 02:00 → 03:00).
        expect(wallToEpoch('2027-03-14T02:30', 'America/New_York')).toBeNull();
        expect(wallToEpoch('2027-03-14T03:30', 'America/New_York')).toBe(
            Date.UTC(2027, 2, 14, 7, 30),
        );
    });

    it('reads a time that occurs twice as its FIRST occurrence', () => {
        // 2026-11-01 01:30 happens twice in New York: 05:30 UTC (EDT) then 06:30 UTC (EST). The first wins.
        expect(wallToEpoch('2026-11-01T01:30', 'America/New_York')).toBe(
            Date.UTC(2026, 10, 1, 5, 30),
        );
    });

    it('formats the way the server prints a date (d M Y H:i)', () => {
        expect(formatWall('2026-11-05T09:05')).toBe('05 Nov 2026 09:05');
        expect(formatWall('2027-01-31T23:59')).toBe('31 Jan 2027 23:59');
        expect(formatWall('not a date')).toBe('not a date');
    });
});

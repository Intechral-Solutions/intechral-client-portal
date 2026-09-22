import { formatDate, formatTimestamp } from '@/lib/dates';

describe('formatDate', () => {
    it('formats a date-only value as the same calendar day', () => {
        const formatted = formatDate('2026-01-01');

        expect(formatted).toContain('2026');
        expect(formatted).toMatch(/\b1\b/);
        expect(formatted).not.toMatch(/Dec|31/);
    });

    it('matches a UTC-anchored formatter for every day around a year boundary', () => {
        const utc = new Intl.DateTimeFormat(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            timeZone: 'UTC',
        });

        for (const day of ['2025-12-31', '2026-01-01', '2026-03-08', '2026-11-01']) {
            expect(formatDate(day)).toBe(utc.format(new Date(`${day}T12:00:00Z`)));
        }
    });

    it('returns malformed input unchanged instead of throwing during render', () => {
        expect(formatDate('not-a-date')).toBe('not-a-date');
    });
});

describe('formatTimestamp', () => {
    it('formats an instant with a date and a time', () => {
        const instant = '2026-09-20T15:45:00Z';

        expect(formatTimestamp(instant)).toBe(
            new Intl.DateTimeFormat(undefined, {
                dateStyle: 'medium',
                timeStyle: 'short',
            }).format(new Date(instant)),
        );
        expect(formatTimestamp(instant)).toContain('2026');
    });

    it('returns malformed input unchanged instead of throwing during render', () => {
        expect(formatTimestamp('yesterday')).toBe('yesterday');
    });
});

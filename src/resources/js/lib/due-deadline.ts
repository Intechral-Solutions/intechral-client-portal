/**
 * Release 1.0 (HD-C1) — the operator Helpdesk composer's due-date arithmetic, in one pure module.
 *
 * Three jobs, none of which touch the DOM or the network:
 *
 * 1. **Urgency** of a deadline relative to "now", with the owner's exact thresholds.
 * 2. **Relative wording** for that gap ("Due in 3 hours", "Overdue by 30 minutes").
 * 3. **Wall-clock text → instant**, in the APPLICATION's timezone. The operator types a `datetime-local` value, which has no
 *    zone of its own. PHP reads it in `app.timezone` (`DueDateInput::parse`); this reads it the same way, from an IANA zone name
 *    the server hands over. It never calls `new Date(text)` or `getTimezoneOffset()`, so the browser's own zone cannot leak in.
 *
 * It decides nothing the server does not re-decide: the server remains the authority on whether a date is allowed.
 */

export type Urgency = 'none' | 'ok' | 'soon' | 'late';

const MINUTE = 60_000;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;

/** More than this much time left is comfortable (green). Exactly this much is already yellow. */
export const COMFORT_MS = 4 * HOUR;

/** Up to this long past the deadline is still yellow. Exactly this long is yellow; more is red. */
export const GRACE_MS = HOUR;

/**
 * GREEN: more than 4 hours remain. YELLOW: from 4 hours before the deadline through 1 hour after it, inclusive both ends.
 * RED: more than 1 hour overdue. NONE (neutral): no deadline.
 *
 * `remainingMs` is deadline minus now: positive before the deadline, negative after it.
 */
export function urgencyFor(remainingMs: number | null): Urgency {
    if (remainingMs === null || !Number.isFinite(remainingMs)) {
        return 'none';
    }

    if (remainingMs > COMFORT_MS) {
        return 'ok';
    }

    if (remainingMs >= -GRACE_MS) {
        return 'soon';
    }

    return 'late';
}

function plural(count: number, unit: string): string {
    return `${count} ${unit}${count === 1 ? '' : 's'}`;
}

/** A positive span as words: the two largest non-zero units, floored ("3 hours", "40 minutes", "2 days 4 hours"). */
function span(ms: number): string {
    const minutes = Math.floor(ms / MINUTE);

    if (minutes < 1) {
        return 'less than a minute';
    }

    const days = Math.floor(minutes / (24 * 60));
    const hours = Math.floor((minutes % (24 * 60)) / 60);
    const rest = minutes % 60;

    if (days > 0) {
        return hours > 0 ? `${plural(days, 'day')} ${plural(hours, 'hour')}` : plural(days, 'day');
    }

    if (hours > 0) {
        return rest > 0
            ? `${plural(hours, 'hour')} ${plural(rest, 'minute')}`
            : plural(hours, 'hour');
    }

    return plural(rest, 'minute');
}

/** The actual relative time, whatever the colour: "Due in 3 hours", "Overdue by 30 minutes". No deadline → "No due date". */
export function relativeLabel(remainingMs: number | null): string {
    if (remainingMs === null || !Number.isFinite(remainingMs)) {
        return 'No due date';
    }

    return remainingMs >= 0 ? `Due in ${span(remainingMs)}` : `Overdue by ${span(-remainingMs)}`;
}

// ── Wall-clock text in the application zone ─────────────────────────────────────────────────────────────

const WALL = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/;
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

interface WallParts {
    year: number;
    month: number;
    day: number;
    hour: number;
    minute: number;
}

/** `Y-m-d\TH:i` as numbers, or null when it is not a real calendar time (the same strictness as `DueDateInput::parse`). */
function wallParts(value: string): WallParts | null {
    const match = WALL.exec(value);

    if (!match) {
        return null;
    }

    const [year = 0, month = 0, day = 0, hour = 0, minute = 0] = match.slice(1).map(Number);
    const check = new Date(Date.UTC(year, month - 1, day, hour, minute));

    if (
        check.getUTCFullYear() !== year ||
        check.getUTCMonth() !== month - 1 ||
        check.getUTCDate() !== day ||
        check.getUTCHours() !== hour ||
        check.getUTCMinutes() !== minute
    ) {
        return null;
    }

    return { year, month, day, hour, minute };
}

export function isWallClock(value: string): boolean {
    return wallParts(value) !== null;
}

/** "2026-11-05T10:00" → "05 Nov 2026 10:00", the form the server prints (`d M Y H:i`). Unparseable text comes back unchanged. */
export function formatWall(value: string): string {
    const parts = wallParts(value);

    if (!parts) {
        return value;
    }

    const two = (n: number) => String(n).padStart(2, '0');

    return `${two(parts.day)} ${MONTHS[parts.month - 1] ?? ''} ${parts.year} ${two(parts.hour)}:${two(parts.minute)}`;
}

const formatters = new Map<string, Intl.DateTimeFormat>();

function formatterFor(timeZone: string): Intl.DateTimeFormat {
    let formatter = formatters.get(timeZone);

    if (!formatter) {
        formatter = new Intl.DateTimeFormat('en-US', {
            timeZone,
            hourCycle: 'h23',
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
        formatters.set(timeZone, formatter);
    }

    return formatter;
}

/** The zone's wall clock for an instant, as a UTC-based timestamp (so `wall - instant` is the zone's offset). */
function wallAsUtc(epochMs: number, timeZone: string): number {
    const found: Partial<Record<string, number>> = {};

    for (const part of formatterFor(timeZone).formatToParts(new Date(epochMs))) {
        if (part.type !== 'literal') {
            found[part.type] = Number(part.value);
        }
    }

    const at = (type: string) => found[type] ?? 0;

    return Date.UTC(
        at('year'),
        at('month') - 1,
        at('day'),
        at('hour') % 24,
        at('minute'),
        at('second'),
    );
}

function offsetMs(epochMs: number, timeZone: string): number {
    const whole = Math.floor(epochMs / 1000) * 1000;

    return wallAsUtc(whole, timeZone) - whole;
}

/** An instant as the `datetime-local` text in the zone. */
export function epochToWall(epochMs: number, timeZone: string): string {
    const at = new Date(wallAsUtc(epochMs, timeZone));
    const two = (n: number) => String(n).padStart(2, '0');

    return `${at.getUTCFullYear()}-${two(at.getUTCMonth() + 1)}-${two(at.getUTCDate())}T${two(at.getUTCHours())}:${two(at.getUTCMinutes())}`;
}

/**
 * The instant a `datetime-local` text means when read in `timeZone`, in epoch milliseconds, or null when the text is not a real
 * time in that zone (an impossible date, or a wall-clock time skipped by a spring-forward gap). A time that occurs twice (the
 * autumn overlap) means its FIRST occurrence, exactly as `DueDateInput::parse` decides.
 */
export function wallToEpoch(value: string, timeZone: string): number | null {
    const parts = wallParts(value);

    if (!parts) {
        return null;
    }

    const guess = Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute);
    const offsets = new Set([
        offsetMs(guess - DAY, timeZone),
        offsetMs(guess, timeZone),
        offsetMs(guess + DAY, timeZone),
    ]);
    const instants = [...offsets]
        .map((offset) => guess - offset)
        .filter((instant) => epochToWall(instant, timeZone) === value)
        .sort((a, b) => a - b);

    return instants[0] ?? null;
}

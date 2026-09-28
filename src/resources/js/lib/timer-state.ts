/**
 * EPIC-013 WP6 — the canonical timer *presentation* contract, shared by both shell renderers.
 *
 * Laravel stays the authority on what a timer is: `GET /time/timers/active` decides which timers
 * exist, when each started (`started_at`), what its description and context are, and what the server
 * clock reads (`server_now`). Nothing here asks a domain question, and nothing here mutates: no
 * "is it running", no permission, no stop authorization, no billing lock. Those live on the server
 * and stay there (§18.2 — WP6 changes no backend).
 *
 * What it does own is the handful of *derivations* the pill and the tray need in order to draw the
 * server's answer. React (`components/time/timer-pill.tsx`, `timer-tray.tsx`) and Blade
 * (`shell/blade-timer.ts`) both import this module, which is the point: the two renderers may draw
 * different markup (§26 — they share the payload, not the components), but they must not disagree
 * about which timer the pill shows, how long it has run, or how its label reads. Before WP6 they did
 * disagree — `running-timer-bar.tsx` and `timer-overlay.js` each carried their own `formatElapsed`,
 * and only the React one applied the clock offset.
 *
 * Framework-free and DOM-free on purpose, so the Blade bundle pays nothing for React and the whole
 * contract is unit-testable as pure functions.
 */

import type { ActiveTimer } from '@/types/time';

/** `H:MM:SS` and `H:MM` (Direction D §12.1) — hours are not zero-padded, minutes and seconds are. */
function pad(value: number): string {
    return String(value).padStart(2, '0');
}

/**
 * Milliseconds to add to `Date.now()` to read the server's clock.
 *
 * Estimated from `server_now` against the midpoint of the request, so half the round trip is not
 * charged to the offset. A payload without a usable `server_now` yields 0 — the browser clock
 * unadjusted — which is the honest fallback: wrong by the clock skew, never wrong by a round trip.
 */
export function clockOffsetFrom(
    timer: ActiveTimer | undefined,
    requestStartedAt: number,
    receivedAt: number,
): number {
    if (!timer) return 0;

    const serverNow = Date.parse(timer.server_now);
    if (!Number.isFinite(serverNow)) return 0;

    return serverNow - (requestStartedAt + receivedAt) / 2;
}

/**
 * Whole seconds a timer has been running, or `null` when `started_at` is not a parseable instant.
 *
 * `null` is deliberately distinct from `0`: a malformed timestamp must not render as a plausible
 * `0:00:00` that then sits there looking stopped. Callers show no elapsed value at all instead.
 *
 * Clamped at zero, because a timer started microseconds ago against a server clock the offset has
 * over-corrected would otherwise render a negative duration.
 */
export function elapsedSeconds(startedAt: string, nowMs: number): number | null {
    const started = Date.parse(startedAt);

    if (!Number.isFinite(started)) return null;

    return Math.max(0, Math.floor((nowMs - started) / 1000));
}

/** `H:MM:SS`, counting hours past 24 rather than wrapping (a forgotten timer reads `31:02:00`). */
export function formatElapsed(seconds: number): string {
    const hours = Math.floor(seconds / 3600);

    return `${hours}:${pad(Math.floor((seconds % 3600) / 60))}:${pad(seconds % 60)}`;
}

/** `H:MM` — the narrow (S) pill drops seconds so the pill still fits (Direction D §12.1). */
export function formatElapsedNarrow(seconds: number): string {
    return `${Math.floor(seconds / 3600)}:${pad(Math.floor((seconds % 3600) / 60))}`;
}

/**
 * How recently a timer started, newest first, breaking ties on the id.
 *
 * **The tie is not hypothetical.** `time_entries.timer_started_at` is a MySQL `timestamp` with no
 * fractional seconds, so two timers started within the same second carry byte-identical `started_at`
 * values. Ordering on the timestamp alone then leaves their relative order to whatever the server
 * happened to return, and the pill and the tray could disagree about which one is newest — Chromium
 * caught exactly that, with the tray listing an older timer first. The id is auto-increment and
 * therefore monotonic, so a higher id was created later: it is the only other ordering signal in the
 * payload, and it is a real one.
 *
 * An unparseable timestamp sorts oldest rather than throwing, so one malformed row cannot reorder the
 * rest or empty the tray.
 */
function compareRecency(a: ActiveTimer, b: ActiveTimer): number {
    const left = Date.parse(b.started_at);
    const right = Date.parse(a.started_at);
    const difference = (Number.isFinite(left) ? left : 0) - (Number.isFinite(right) ? right : 0);

    return difference !== 0 ? difference : b.id - a.id;
}

/**
 * The timer the pill shows: the **most recently started** one (Direction D §12.1).
 *
 * The server returns the active set oldest-first (`TimeEntryService::activeTimers` orders by
 * `timer_started_at`), but this re-derives "newest" rather than trusting the array order, so a
 * reconciled payload assembled in any order still picks the same timer on both renderers — and the
 * same one the tray lists first, because both use `compareRecency`.
 */
export function newestTimer(timers: readonly ActiveTimer[]): ActiveTimer | null {
    if (timers.length === 0) return null;

    return timers.reduce((newest, candidate) =>
        compareRecency(candidate, newest) <= 0 ? candidate : newest,
    );
}

/** Newest-first, for the tray's rows (Direction D §12.2). Never mutates the caller's array. */
export function newestFirst(timers: readonly ActiveTimer[]): ActiveTimer[] {
    return [...timers].sort(compareRecency);
}

/** The pill's context label cap (Direction D §12.1: "truncated to ~28 characters"). */
export const PILL_LABEL_LIMIT = 28;

/**
 * What the pill calls this timer: its context if it has one, else its description, else a constant.
 *
 * Truncation is by character count with an ellipsis, so the label cannot push the pill past its
 * ~360px ceiling. CSS truncation alone is not enough here — Blade and React must agree on the string
 * itself, because it also becomes the accessible name of the Stop button.
 */
export function pillLabel(timer: ActiveTimer, limit: number = PILL_LABEL_LIMIT): string {
    const raw = timer.context
        ? `${timer.context.type}: ${timer.context.label}`
        : (timer.description ?? 'Untitled timer');

    return raw.length > limit ? `${raw.slice(0, limit - 1).trimEnd()}…` : raw;
}

/**
 * The tray row's context line (Direction D §12.2), untruncated — the tray is 400–420px and wraps.
 * `null` when the timer has no context, so the row omits the line rather than printing a placeholder.
 */
export function contextLine(timer: ActiveTimer): string | null {
    return timer.context ? `${timer.context.type}: ${timer.context.label}` : null;
}

/** The tray header (Direction D §12.2: `N running`). */
export function trayHeading(count: number): string {
    return `${count} running`;
}

/**
 * The pill's accessible name. It names the state and the timer but deliberately **excludes the
 * elapsed value**, because the name is re-read on every change and the elapsed value changes every
 * second (§13 — a clock must not announce continuously). The elapsed digits stay visible text
 * outside any live region; `aria-live` is reserved for start/stop announcements.
 */
export function pillAccessibleName(timers: readonly ActiveTimer[]): string {
    const newest = newestTimer(timers);

    if (!newest) return 'Start timer';

    const others = timers.length - 1;
    const suffix = others > 0 ? `, and ${others} other${others === 1 ? '' : 's'}` : '';

    return `Running timer: ${pillLabel(newest)}${suffix}. Show timers`;
}

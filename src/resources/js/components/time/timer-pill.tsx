import * as Popover from '@radix-ui/react-popover';
import { ChevronDown, Clock3, Square } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { TimerTray } from '@/components/time/timer-tray';
import { useTimers } from '@/components/time/timer-provider';
import { Button } from '@/components/ui/button';
import {
    elapsedSeconds,
    formatElapsed,
    formatElapsedNarrow,
    newestTimer,
    pillAccessibleName,
    pillLabel,
} from '@/lib/timer-state';

/**
 * EPIC-013 WP6 — the global timer pill (Direction D §12.1), the utility bar's right-hand affordance
 * and the **one** global timer surface. It replaces `RunningTimerBar`, the full-width strip that used
 * to sit under the header on React and its `timer-overlay.js` twin on Blade.
 *
 * **The tick lives here.** This is the EPIC-011D lesson and a hard requirement (§18.3, §26): the one
 * `setInterval` is mounted inside this component, so a timer tick re-renders the pill and the open
 * tray and nothing else — not the rail, not the drawer, not the breadcrumb, not the page. A
 * `rail`-mounted sentinel asserts it in `timer-pill.test.tsx`. The interval is also not started at
 * all unless there is something to count, so an idle shell runs no timer.
 *
 * Elapsed time is derived, never stored: every tick recomputes from the server's `started_at` plus
 * `TimerProvider`'s clock offset, so the display cannot accumulate interval drift however long the
 * page stays open, and a remount reads the same answer as the tab that has been open for an hour.
 *
 * The wide and narrow forms are both rendered and CSS shows one (`app.css`), keeping the width
 * decision with the shell's width classes rather than a JS breakpoint query — the same rule WP4 and
 * WP5 follow for every other piece of shell geometry.
 */
export function TimerPill() {
    const { timers, status, starting, stopping, stopTimer, clockOffsetMs } = useTimers();
    const [open, setOpen] = useState(false);
    // Keyed by the timer it belongs to, so the message is *derived* away when that timer stops
    // rather than cleared by an effect: a stopped timer has nothing left to retry.
    const [failedStop, setFailedStop] = useState<number | null>(null);
    const [nowMs, setNowMs] = useState(() => Date.now());

    const shown = newestTimer(timers);
    const others = Math.max(0, timers.length - 1);
    const pendingStop = shown ? stopping.includes(shown.id) : false;
    const pending = starting || pendingStop;

    // Nothing to count and nothing open: no interval exists at all.
    const ticking = timers.length > 0 || open;

    useEffect(() => {
        if (!ticking) return;

        let handle = 0;

        // Re-aligned to the second boundary on every tick, so the digits change when the clock's
        // second changes and a throttled background tab resumes on the boundary rather than drifting.
        // The clock is read in the callback, never in render, so there is nothing for a future SSR
        // pass to mismatch on.
        function schedule(delay: number) {
            handle = window.setTimeout(() => {
                setNowMs(Date.now());
                schedule(1000 - (Date.now() % 1000));
            }, delay);
        }

        schedule(0);

        return () => window.clearTimeout(handle);
    }, [ticking]);

    const stopError = shown !== null && failedStop === shown.id;
    const seconds = shown ? elapsedSeconds(shown.started_at, nowMs + clockOffsetMs) : null;
    const announcement = useStartStopAnnouncement(timers.length);

    function stop() {
        if (!shown) return;

        const id = shown.id;
        setFailedStop(null);

        void stopTimer(id).catch(() => setFailedStop(id));
    }

    return (
        <Popover.Root open={open} onOpenChange={setOpen}>
            {/* `data-shell-timer` is the pill's geometry hook and the "one affordance" marker the
                browser suite asserts on. `max-w` enforces Direction D's ~360px ceiling. */}
            <div
                data-shell-timer
                data-timer-running={shown ? 'true' : 'false'}
                data-timer-count={timers.length}
                className="flex max-w-[360px] min-w-0 shrink-0 items-center gap-1"
            >
                {/* Start/stop transitions are announced; the elapsed digits above are not in any
                    live region, so a running clock never announces per second (§13). */}
                <p data-shell-timer-announce aria-live="polite" className="sr-only">
                    {announcement}
                </p>

                <Popover.Trigger asChild>
                    <button
                        type="button"
                        data-shell-timer-trigger
                        aria-label={pillAccessibleName(timers)}
                        className={`flex min-w-0 items-center gap-1.5 rounded-control border px-2 py-1 text-sm transition-[color,background-color,border-color] duration-motion-fast ease-motion hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus ${
                            stopError || status === 'error'
                                ? 'border-danger'
                                : shown
                                  ? 'border-control-edge bg-surface'
                                  : 'border-transparent'
                        }`}
                    >
                        {shown ? (
                            <span
                                data-timer-dot
                                // Hollow ring while a mutation is in flight, filled once the server
                                // has confirmed (Direction D §12.1).
                                className={`size-2 shrink-0 rounded-full ${
                                    pending
                                        ? 'border border-live bg-transparent'
                                        : 'animate-live-pulse bg-live'
                                }`}
                                aria-hidden="true"
                            />
                        ) : (
                            <Clock3
                                className="size-4 shrink-0 text-text-muted"
                                aria-hidden="true"
                            />
                        )}

                        {pending ? (
                            <span className="text-text-secondary">
                                {starting ? 'Starting…' : 'Stopping…'}
                            </span>
                        ) : shown ? (
                            <>
                                {/* Both forms render; CSS shows one (see app.css). */}
                                <span
                                    data-timer-elapsed="wide"
                                    className="font-mono font-medium text-live-text tabular-nums"
                                >
                                    {seconds === null ? '—' : formatElapsed(seconds)}
                                </span>
                                <span
                                    data-timer-elapsed="narrow"
                                    className="font-mono font-medium text-live-text tabular-nums"
                                >
                                    {seconds === null ? '—' : formatElapsedNarrow(seconds)}
                                </span>
                                <span
                                    data-timer-label
                                    className="truncate text-text-secondary"
                                    title={pillLabel(shown)}
                                >
                                    {pillLabel(shown)}
                                </span>
                            </>
                        ) : (
                            <span className="text-text-secondary">Start timer</span>
                        )}

                        {others > 0 ? (
                            <span className="shrink-0 rounded-tag bg-live-soft px-1.5 py-0.5 text-xs font-medium text-live-text">
                                +{others}
                            </span>
                        ) : null}

                        {timers.length > 1 ? (
                            <ChevronDown
                                className="size-3.5 shrink-0 text-text-muted"
                                aria-hidden="true"
                            />
                        ) : null}
                    </button>
                </Popover.Trigger>

                {/* A sibling of the trigger, never nested inside it: a button within a button is
                    invalid markup and the inner one becomes unreachable. */}
                {shown && !pending ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        data-shell-timer-stop
                        aria-label={`Stop timer: ${pillLabel(shown)}`}
                        onClick={stop}
                    >
                        <Square
                            className="size-3"
                            fill="currentColor"
                            strokeWidth={0}
                            aria-hidden="true"
                        />
                        {stopError ? 'Retry stop' : 'Stop'}
                    </Button>
                ) : null}

                {stopError ? (
                    <span role="alert" className="shrink-0 text-xs text-danger">
                        Couldn’t stop
                    </span>
                ) : null}
            </div>

            <TimerTray nowMs={nowMs + clockOffsetMs} />
        </Popover.Root>
    );
}

/**
 * "Timer started" / "Timer stopped" for screen readers (Direction D §12.2).
 *
 * Driven by the **count**, not by the elapsed value, so the live region updates a handful of times a
 * session rather than once a second. It stays empty on first render so hydrating a page with a timer
 * already running does not announce a start that did not just happen.
 */
function useStartStopAnnouncement(count: number): string {
    const [message, setMessage] = useState('');
    const previous = useRef<number | null>(null);

    useEffect(() => {
        const before = previous.current;
        previous.current = count;

        if (before === null || before === count) return;

        setMessage(count > before ? 'Timer started.' : 'Timer stopped.');
    }, [count]);

    return message;
}

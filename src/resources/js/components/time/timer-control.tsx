import { Play, Square } from 'lucide-react';
import { useState } from 'react';

import { useTimers } from '@/components/time/timer-provider';
import { Button } from '@/components/ui/button';
import type { ActiveTimer, TimerStartPayload } from '@/types/time';

/**
 * EPIC-013 WP6 — the contextual timer control (Direction D §12.3), for the surfaces that already
 * start and stop timers against a specific record. Today that is the task detail page's time panel;
 * the Time screen keeps its own richer start form, which is the cascading context selector this
 * control deliberately does not duplicate.
 *
 * It owns no timer state. `TimerProvider` remains the only client-side holder of the active set, so a
 * timer started here appears in the pill and the tray without either being told, and a stop from the
 * tray flips this control back to idle for the same reason.
 *
 * **Starting from a context never stops other timers** — concurrent timers are supported and the
 * server has no switch operation. This control therefore only ever starts or stops its own.
 *
 * States implemented: idle, running-here and pending. Direction D also describes an **unavailable**
 * state (disabled with a tooltip giving the reason — billed, archived, no permission). No current
 * surface can reach it: the callers gate the whole control on `time.log`, and the billing lock is a
 * server rejection surfaced as the error below rather than a precondition known before the click.
 * Implementing it would mean adding `@radix-ui/react-tooltip` for a state nothing can trigger, so it
 * is recorded as deferred rather than built blind.
 */
export function TimerControl({
    label,
    payload,
    running,
    className,
}: {
    /** Names the record in the control's accessible name, e.g. `Start timer: Fix the invoice run`. */
    label: string;
    /** What to start. The caller owns the context because only it knows which record this is. */
    payload: TimerStartPayload;
    /** This context's running timer, or `null`. Derived by the caller from `useTimers()`. */
    running: ActiveTimer | null;
    className?: string;
}) {
    const { startTimer, stopTimer, starting, stopping } = useTimers();
    const [error, setError] = useState<string | null>(null);
    const pending = running ? stopping.includes(running.id) : starting;

    async function act() {
        setError(null);

        try {
            if (running) {
                await stopTimer(running.id);
            } else {
                await startTimer(payload);
            }
        } catch (reason) {
            const fallback = running ? 'Unable to stop timer.' : 'Unable to start timer.';

            setError(reason instanceof Error ? reason.message : fallback);
        }
    }

    return (
        <div className={className}>
            <div className="flex items-center gap-2">
                {running ? (
                    <span className="inline-flex items-center gap-1.5 rounded-control bg-live-soft px-2 py-1 text-xs font-medium text-live-text">
                        <span
                            data-timer-dot
                            className={`size-1.5 rounded-full ${
                                pending
                                    ? 'border border-live bg-transparent'
                                    : 'animate-live-pulse bg-live'
                            }`}
                            aria-hidden="true"
                        />
                        Running
                    </span>
                ) : null}

                <Button
                    type="button"
                    variant={running ? 'secondary' : 'ghost'}
                    size="sm"
                    className="ml-auto"
                    disabled={pending}
                    aria-label={running ? `Stop timer: ${label}` : `Start timer: ${label}`}
                    onClick={() => void act()}
                >
                    {running ? (
                        <Square
                            className="size-3"
                            fill="currentColor"
                            strokeWidth={0}
                            aria-hidden="true"
                        />
                    ) : (
                        <Play className="size-3.5" aria-hidden="true" />
                    )}
                    {pending
                        ? running
                            ? 'Stopping…'
                            : 'Starting…'
                        : running
                          ? 'Stop timer'
                          : 'Start timer'}
                </Button>
            </div>

            {error ? (
                <p role="alert" className="mt-2 text-xs text-danger">
                    {error}
                </p>
            ) : null}
        </div>
    );
}

import { Clock3, Pencil, RefreshCw, Square, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { TimerContextLink } from '@/components/time/timer-context-link';
import { useTimers } from '@/components/time/timer-provider';
import type { ActiveTimer } from '@/types/time';

function formatElapsed(startedAt: string, now: number) {
    const seconds = Math.max(0, Math.floor((now - Date.parse(startedAt)) / 1000));
    const hours = String(Math.floor(seconds / 3600)).padStart(2, '0');
    const minutes = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
    const remainder = String(seconds % 60).padStart(2, '0');

    return `${hours}:${minutes}:${remainder}`;
}

function TimerDescription({ timer }: { timer: ActiveTimer }) {
    const { updateDescription, updating } = useTimers();
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState('');
    const [error, setError] = useState<string | null>(null);
    const processing = updating.includes(timer.id);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setError(null);

        try {
            await updateDescription(timer.id, value.trim() || null);
            setEditing(false);
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : 'Unable to update description.');
        }
    }

    if (editing) {
        return (
            <form onSubmit={submit} className="flex min-w-0 items-center gap-1">
                <Input
                    value={value}
                    onChange={(event) => setValue(event.target.value)}
                    maxLength={500}
                    aria-label="Timer description"
                    className="h-8 min-w-32"
                    autoFocus
                />
                <Button
                    type="submit"
                    size="icon"
                    disabled={processing}
                    aria-label="Save description"
                >
                    <Pencil aria-hidden="true" />
                </Button>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    aria-label="Cancel description editing"
                    onClick={() => {
                        setEditing(false);
                        setError(null);
                    }}
                >
                    <X aria-hidden="true" />
                </Button>
                {error ? (
                    <span className="sr-only" role="alert">
                        {error}
                    </span>
                ) : null}
            </form>
        );
    }

    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            className="max-w-52 min-w-0 justify-start"
            onClick={() => {
                // Seed from the current authoritative description: this instance is keyed by
                // timer id and survives refreshes, so a value captured at mount can be stale.
                setValue(timer.description ?? '');
                setError(null);
                setEditing(true);
            }}
            aria-label={
                timer.description
                    ? `Edit timer description: ${timer.description}`
                    : 'Add timer description'
            }
        >
            <span className="truncate">{timer.description ?? 'Add description'}</span>
            <Pencil className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        </Button>
    );
}

function TimerItem({ timer, now }: { timer: ActiveTimer; now: number }) {
    const { stopTimer, stopping } = useTimers();
    const [error, setError] = useState<string | null>(null);
    const processing = stopping.includes(timer.id);

    return (
        <div className="flex min-w-max items-center gap-2 border-r border-[var(--border-success)] pr-3 last:border-r-0">
            <Clock3 className="h-4 w-4 text-[var(--text-success)]" aria-hidden="true" />
            <span className="font-mono text-sm font-semibold text-[var(--text-success)] tabular-nums">
                {formatElapsed(timer.started_at, now)}
            </span>
            {timer.context ? (
                <TimerContextLink
                    kind={timer.context.type}
                    url={timer.context.url}
                    label={timer.context.label}
                    className="max-w-40 truncate text-xs text-muted-foreground hover:underline"
                >
                    {timer.context.type}: {timer.context.label}
                </TimerContextLink>
            ) : null}
            <TimerDescription timer={timer} />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                disabled={processing}
                aria-label="Stop timer"
                title="Stop timer"
                onClick={() => {
                    setError(null);
                    void stopTimer(timer.id).catch((reason: unknown) => {
                        setError(
                            reason instanceof Error ? reason.message : 'Unable to stop timer.',
                        );
                    });
                }}
            >
                <Square aria-hidden="true" />
            </Button>
            {error ? (
                <span className="text-xs text-destructive" role="alert">
                    {error}
                </span>
            ) : null}
        </div>
    );
}

export function RunningTimerBar() {
    const { timers, status, error, clockOffsetMs, refreshTimers } = useTimers();
    const [browserNow, setBrowserNow] = useState(() => Date.now());

    useEffect(() => {
        if (timers.length === 0) return;

        const interval = window.setInterval(() => {
            setBrowserNow(Date.now());
        }, 1000);

        return () => window.clearInterval(interval);
    }, [timers.length]);

    if (status === 'error' && timers.length === 0) {
        return (
            <div className="border-b border-[var(--border-warning)] bg-[var(--surface-warning)]">
                <div className="mx-auto flex min-h-11 max-w-7xl items-center justify-between gap-3 px-4 py-2 sm:px-6 lg:px-8">
                    <p className="text-sm text-[var(--text-warning)]">
                        {error ?? 'Active timers are temporarily unavailable.'}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => void refreshTimers()}
                    >
                        <RefreshCw aria-hidden="true" />
                        Retry
                    </Button>
                </div>
            </div>
        );
    }

    if (timers.length === 0) return null;

    return (
        <section
            aria-label="Active timers"
            aria-live="polite"
            className="border-b border-[var(--border-success)] bg-[var(--surface-success)]"
        >
            <div className="mx-auto flex max-w-7xl items-center gap-3 overflow-x-auto px-4 py-2 sm:px-6 lg:px-8">
                {timers.map((timer) => (
                    <TimerItem key={timer.id} timer={timer} now={browserNow + clockOffsetMs} />
                ))}
            </div>
        </section>
    );
}

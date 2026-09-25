import { router } from '@inertiajs/react';
import { Square } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';

import { useTimers } from '@/components/time/timer-provider';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/dates';
import type { TaskTimeSummary } from '@/types/time';

type TaskTimePanelProps = {
    taskId: number;
    /** `time.log`: the personal-time gate that shows the start/stop controls (D6). */
    canLog: boolean;
    /** `null` when the viewer holds neither `time.log` nor `time.view_all` (D6). */
    timeSummary: TaskTimeSummary | null;
};

function formatMinutes(totalMinutes: number): string {
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (hours === 0) return `${minutes}m`;
    if (minutes === 0) return `${hours}h`;

    return `${hours}h ${minutes}m`;
}

/**
 * The task time panel (EPIC-011E §11, D6), replacing the embedded Blade `x-time-tracker` with
 * the persistent React timer provider (EPIC-011D). Start/stop targets this task directly through
 * the same canonical timer mutation path the Time page uses; no second timer state is kept here.
 */
export function TaskTimePanel({ taskId, canLog, timeSummary }: TaskTimePanelProps) {
    const { timers, startTimer, stopTimer, starting, stopping } = useTimers();
    const [description, setDescription] = useState('');
    const [error, setError] = useState<string | null>(null);

    const runningTimer = timers.find(
        (timer) => timer.context?.type === 'Task' && timer.context.id === taskId,
    );
    const wasRunningRef = useRef(Boolean(runningTimer));

    useEffect(() => {
        const isRunning = Boolean(runningTimer);

        // The running timer for this task just disappeared (stopped here or from the
        // persistent timer bar): refresh only the summary, not comments or checklist.
        if (wasRunningRef.current && !isRunning) {
            router.reload({ only: ['timeSummary'] });
        }

        wasRunningRef.current = isRunning;
    }, [runningTimer]);

    if (!canLog && !timeSummary) {
        return <p className="text-sm text-muted-foreground italic">No time tracked.</p>;
    }

    async function handleStart(event: FormEvent) {
        event.preventDefault();
        setError(null);

        try {
            await startTimer({ task_id: taskId, description: description.trim() || null });
            setDescription('');
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : 'Unable to start timer.');
        }
    }

    async function handleStop() {
        if (!runningTimer) return;
        setError(null);

        try {
            await stopTimer(runningTimer.id);
        } catch (reason) {
            setError(reason instanceof Error ? reason.message : 'Unable to stop timer.');
        }
    }

    return (
        <div className="space-y-3">
            {canLog ? (
                runningTimer ? (
                    <div className="flex items-center gap-2 text-sm text-[var(--text-success)]">
                        <span
                            className="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-[var(--text-success)]"
                            aria-hidden="true"
                        />
                        Running for this task
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="ml-auto"
                            disabled={stopping.includes(runningTimer.id)}
                            onClick={() => void handleStop()}
                        >
                            <Square aria-hidden="true" />
                            Stop timer
                        </Button>
                    </div>
                ) : (
                    <form
                        onSubmit={(event) => void handleStart(event)}
                        className="flex items-center gap-2"
                    >
                        <label htmlFor="task-timer-description" className="sr-only">
                            Timer description
                        </label>
                        <Input
                            id="task-timer-description"
                            placeholder="What are you working on? (optional)"
                            value={description}
                            maxLength={500}
                            disabled={starting}
                            onChange={(event) => setDescription(event.target.value)}
                        />
                        <Button type="submit" disabled={starting}>
                            {starting ? 'Starting...' : 'Start timer'}
                        </Button>
                    </form>
                )
            ) : null}

            {error ? (
                <p role="alert" className="text-xs text-destructive">
                    {error}
                </p>
            ) : null}

            {timeSummary ? (
                <div>
                    <div className="flex items-center justify-between text-xs text-muted-foreground">
                        <span>
                            {timeSummary.scope === 'all' ? 'All time logged' : 'Your time logged'}
                        </span>
                        <span className="font-mono font-semibold text-foreground">
                            {formatMinutes(timeSummary.totalMinutes)}
                        </span>
                    </div>
                    {timeSummary.entries.length > 0 ? (
                        <ul className="mt-2 space-y-1">
                            {timeSummary.entries.map((entry) => (
                                <li
                                    key={entry.id}
                                    className="flex items-center justify-between text-xs text-muted-foreground"
                                >
                                    <span>
                                        {formatDate(entry.date)}
                                        {entry.userName ? ` · ${entry.userName}` : ''}
                                    </span>
                                    <span className="font-mono text-foreground">
                                        {formatMinutes(entry.durationMinutes)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}

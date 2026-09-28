import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';

import { TimerControl } from '@/components/time/timer-control';
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
 *
 * EPIC-013 WP6: while a timer runs for this task the running indicator and Stop are the shared
 * `TimerControl` (Direction D §12.3), so this surface, the pill and the tray all draw the running
 * state from the same component and the same tokens. The idle form stays local because it also
 * collects a description, which the generic control does not.
 */
export function TaskTimePanel({ taskId, canLog, timeSummary }: TaskTimePanelProps) {
    const { timers, startTimer, starting } = useTimers();
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

    return (
        <div className="space-y-3">
            {canLog ? (
                runningTimer ? (
                    <TimerControl
                        label="this task"
                        payload={{ task_id: taskId }}
                        running={runningTimer}
                    />
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

<?php

namespace App\Http\Presenters;

use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;

/**
 * The task time panel DTO (EPIC-011E §11, D6, replacing the embedded Blade `x-time-tracker`).
 * A viewer with `time.log` sees their own completed time on the task; `time.view_all`
 * additionally shows every user's completed time, with a display name only — no email, no
 * billing/invoice flag, no description, no other user id. Project-manager status alone grants
 * nothing here. A viewer with neither permission gets no panel at all (`null`).
 */
final class TaskTimeSummaryPresenter
{
    private const RECENT_LIMIT = 5;

    /**
     * @return array{scope: 'own'|'all', totalMinutes: int, entries: array<int, array<string, mixed>>}|null
     */
    public static function summary(Task $task, User $viewer): ?array
    {
        $viewAll = $viewer->can('time.view_all');
        $canLog = $viewer->can('time.log');

        if (! $viewAll && ! $canLog) {
            return null;
        }

        $scope = $viewAll ? 'all' : 'own';

        // Completed entries only: a running timer's minutes are not final and it is already
        // shown by the persistent timer bar (EPIC-011D). Same predicate the embedded Blade
        // tracker used and TimeEntryController's own "settled" queries use elsewhere.
        $query = TimeEntry::query()
            ->where('task_id', $task->id)
            ->whereNull('timer_started_at')
            ->when($scope === 'own', fn ($q) => $q->where('user_id', $viewer->id));

        $totalMinutes = (int) (clone $query)->sum('duration_minutes');

        $entries = (clone $query)
            ->with('user:id,name')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (TimeEntry $entry) => [
                'id' => $entry->id,
                'date' => $entry->date->toDateString(),
                'durationMinutes' => $entry->duration_minutes,
                ...($scope === 'all' ? ['userName' => $entry->user?->name ?? 'Unknown'] : []),
            ])
            ->values()
            ->all();

        return [
            'scope' => $scope,
            'totalMinutes' => $totalMinutes,
            'entries' => $entries,
        ];
    }
}

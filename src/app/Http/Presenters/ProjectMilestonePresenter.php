<?php

namespace App\Http\Presenters;

use App\Models\ProjectMilestone;

/**
 * The milestone DTO (EPIC-011E §5, §12; EPIC-015 §9.4). `item()` requires a milestone loaded
 * through `ProjectMilestone::scopeWithTaskCounts()` with `completer:id,name` eager loaded, so a
 * list costs a fixed number of queries, never a query per row.
 *
 * Two separate facts, never mixed (EPIC-015 Q2, INV-P9):
 *   - completion: `completedAt`/`completedBy`, the explicit stored state and the source of truth;
 *     `overdue` follows it (`ProjectMilestone::isOverdue()`);
 *   - task progress: `taskCount`, `doneCount`, `openTaskCount` and `completion` (the percentage of
 *     done linked tasks), informational only.
 *
 * `completedBy` is `{id, name}` of the one user who completed it, shown to every project viewer
 * including customer members: accepted provenance metadata, not a roster (§9.4). No email.
 */
final class ProjectMilestonePresenter
{
    /**
     * @return array{id: int, name: string, description: string|null, dueDate: string, taskCount: int, doneCount: int, openTaskCount: int, completion: int, completedAt: string|null, completedBy: array{id: int, name: string}|null, overdue: bool}
     */
    public static function item(ProjectMilestone $milestone): array
    {
        $taskCount = (int) $milestone->tasks_count;
        $doneCount = (int) $milestone->done_tasks_count;
        $completer = $milestone->completer;

        return [
            'id' => $milestone->id,
            'name' => $milestone->name,
            'description' => $milestone->description,
            'dueDate' => $milestone->due_date->toDateString(),
            'taskCount' => $taskCount,
            'doneCount' => $doneCount,
            'openTaskCount' => $taskCount - $doneCount,
            'completion' => $milestone->completionFromCounts(),
            'completedAt' => $milestone->completed_at?->toIso8601String(),
            'completedBy' => $completer === null ? null : ['id' => $completer->id, 'name' => $completer->name],
            'overdue' => $milestone->isOverdue(),
        ];
    }
}

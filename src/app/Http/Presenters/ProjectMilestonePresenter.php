<?php

namespace App\Http\Presenters;

use App\Models\ProjectMilestone;

/**
 * The milestone-page DTOs (EPIC-011E §5, §12). `item()` requires a milestone loaded through
 * `ProjectMilestone::scopeWithTaskCounts()` so counts come from one aggregate query, never a
 * query per row. Progress and overdue state are computed here, from the same aggregate the
 * model's own `completionPercentage()`/`isOverdueAt()` use, so React never re-implements the
 * rule and a Pest test can pin the two paths as equal.
 */
final class ProjectMilestonePresenter
{
    /**
     * @return array{id: int, name: string, description: string|null, dueDate: string, taskCount: int, doneCount: int, completion: int, overdue: bool}
     */
    public static function item(ProjectMilestone $milestone): array
    {
        $completion = $milestone->completionFromCounts();

        return [
            'id' => $milestone->id,
            'name' => $milestone->name,
            'description' => $milestone->description,
            'dueDate' => $milestone->due_date->toDateString(),
            'taskCount' => (int) $milestone->tasks_count,
            'doneCount' => (int) $milestone->done_tasks_count,
            'completion' => $completion,
            'overdue' => $milestone->isOverdueAt($completion),
        ];
    }
}

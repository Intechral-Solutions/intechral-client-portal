<?php

namespace App\Rules;

use App\Models\Project;
use App\Models\Task;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Who may be named as a board task's assignee (EPIC-011E A3, I8; EPIC-014 §7.3, INV-6/INV-7).
 * A new assignment must name a current member of the task's own project. Saving a task with its
 * assignee unchanged is not a new assignment, so someone who has since left the project keeps the
 * task rather than being silently unassigned. Null (unassigned) is left to `nullable`.
 *
 * Extracted verbatim from `ProjectTaskController::taskRules()` (EPIC-014 WP1) so the project
 * update route and later task endpoints run one rule rather than copies of it.
 */
class ProjectTaskAssignee implements ValidationRule
{
    public function __construct(
        private readonly Project $project,
        private readonly ?Task $task = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->task !== null && (int) $value === $this->task->assignee_id) {
            return;
        }

        if (! $this->project->members()->where('users.id', $value)->exists()) {
            $fail('The selected assignee is invalid.');
        }
    }
}

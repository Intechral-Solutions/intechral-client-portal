<?php

namespace App\Rules;

use App\Models\Task;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Who may be named as a standalone task's assignee when it is edited or assigned (EPIC-014 §7.3,
 * Q4): the actor themself. There is no cross-person standalone assignment. Keeping the current
 * assignee unchanged is not a new assignment, so a creator can save a task someone else holds
 * (legacy or factory data) without handing it over, the ProjectTaskAssignee (I8) precedent. Null
 * (release) is left to `nullable`.
 *
 * Creation keeps its own `Rule::in([actor])` in `TaskController::store`, unchanged since EPIC-011E.
 */
class StandaloneTaskAssignee implements ValidationRule
{
    public function __construct(
        private readonly User $actor,
        private readonly Task $task,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ((int) $value === $this->actor->id || (int) $value === $this->task->assignee_id) {
            return;
        }

        $fail('The selected assignee is invalid.');
    }
}

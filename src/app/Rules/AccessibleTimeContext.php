<?php

namespace App\Rules;

use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Gate;

class AccessibleTimeContext implements ValidationRule
{
    public function __construct(
        private readonly User $user,
        private readonly string $type,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::allows($this->user, $this->type, $value)) {
            $fail('The selected :attribute is invalid.');
        }
    }

    public static function allows(User $user, string $type, mixed $value): bool
    {
        if (! is_numeric($value)) {
            return false;
        }

        return match ($type) {
            'project' => self::canViewProject($user, Project::find($value)),
            'task' => self::canUseTask($user, Task::with(['project', 'ticket'])->find($value)),
            'ticket' => self::canViewTicket($user, Ticket::find($value)),
            default => false,
        };
    }

    /**
     * New-time eligibility for a task. The kind is classified FIRST, in this order, so no later
     * rule can rescue a row an earlier one refuses:
     *
     *  1. no such task: denied;
     *  2. MALFORMED (linked to both a project and a ticket): denied. It has no valid product kind
     *     (EPIC-015 §8.4), so neither the assignee shortcut, nor project view, nor ticket view may
     *     admit it for new time;
     *  3. board task (`project_id`): ONLY the actor's CURRENT `ProjectPolicy::view` (EPIC-015 Q8).
     *     The stored `assignee_id` grants nothing: an assignee who has left the project stays
     *     stored on the task (EPIC-014 INV-7) but cannot start a timer or log new time on it;
     *  4. ticket task (`ticket_id`): the assignee, else ticket visibility (unchanged);
     *  5. standalone: the assignee (unchanged).
     *
     * This governs NEW attribution only. An existing entry keeps its unchanged task (EPIC-014
     * decision (c), TimeEntryController::keepsTask) and a running timer can always be stopped;
     * neither comes through here.
     */
    private static function canUseTask(User $user, ?Task $task): bool
    {
        if (! $task) {
            return false;
        }

        if ($task->isMalformedKind()) {
            return false;
        }

        if ($task->project_id !== null) {
            return $task->project !== null && self::canViewProject($user, $task->project);
        }

        if ($task->ticket_id !== null) {
            return $task->assignee_id === $user->id
                || ($task->ticket && self::canViewTicket($user, $task->ticket));
        }

        return $task->assignee_id === $user->id;
    }

    private static function canViewProject(User $user, ?Project $project): bool
    {
        return $project !== null && Gate::forUser($user)->allows('view', $project);
    }

    private static function canViewTicket(User $user, ?Ticket $ticket): bool
    {
        return $ticket !== null && Gate::forUser($user)->allows('view', $ticket);
    }
}

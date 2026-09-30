<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Task authorization (EPIC-014 §7.2), one boundary over the three kinds that share the table.
 *
 * - **Board** abilities delegate to the project's own policy through the Gate: `view` is
 *   `ProjectPolicy::view`, everything structural is `ProjectPolicy::manage`. The only rule added
 *   here is Q1's: the task's current assignee, while still a project member, may Complete and
 *   Reopen it. That arm grants nothing else, in particular not `move`.
 * - **Standalone** tasks are personal (Q4): their creator or current assignee, nobody else.
 *   `tasks.view_all` does not reach them (Q3), and no organization ownership exists to consult.
 * - **Ticket** tasks may be viewed per `TicketPolicy`, for internal consistency only; every
 *   mutation is denied (Q6). Seeing one here never means a Tasks-workspace surface lists it.
 * - A malformed row linked to both a project and a ticket (INV-13) is refused everything: it is
 *   not treated as whichever kind `Task::kind()` happens to prefer.
 *
 * Assignment never grants visibility on its own: a board assignee who is not (or no longer) a
 * member is denied `view` like any outsider (§9.1.1).
 */
class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        return match ($this->kindOf($task)) {
            Task::KIND_BOARD => $this->project($user, 'view', $task),
            Task::KIND_STANDALONE => $this->ownsStandalone($user, $task),
            Task::KIND_TICKET => $task->ticket !== null && Gate::forUser($user)->allows('view', $task->ticket),
            default => false,
        };
    }

    public function update(User $user, Task $task): bool
    {
        return $this->structural($user, $task);
    }

    public function complete(User $user, Task $task): bool
    {
        return $this->completion($user, $task);
    }

    public function reopen(User $user, Task $task): bool
    {
        return $this->completion($user, $task);
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->structural($user, $task);
    }

    /** Who may set the assignee; which assignees are valid is the validation rule's concern (§7.3). */
    public function assign(User $user, Task $task): bool
    {
        return $this->structural($user, $task);
    }

    /** Arbitrary column/position change: board management alone, never the Q1 assignee arm. */
    public function move(User $user, Task $task): bool
    {
        return $this->kindOf($task) === Task::KIND_BOARD && $this->project($user, 'manage', $task);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function structural(User $user, Task $task): bool
    {
        return match ($this->kindOf($task)) {
            Task::KIND_BOARD => $this->project($user, 'manage', $task),
            Task::KIND_STANDALONE => $this->ownsStandalone($user, $task),
            default => false,
        };
    }

    private function completion(User $user, Task $task): bool
    {
        return match ($this->kindOf($task)) {
            Task::KIND_BOARD => $this->project($user, 'manage', $task) || $this->isMemberAssignee($user, $task),
            Task::KIND_STANDALONE => $this->ownsStandalone($user, $task),
            default => false,
        };
    }

    /** The task's kind, or null for a malformed dual-linked row, which no ability accepts. */
    private function kindOf(Task $task): ?string
    {
        if ($task->project_id !== null && $task->ticket_id !== null) {
            return null;
        }

        return $task->kind();
    }

    private function project(User $user, string $ability, Task $task): bool
    {
        return $task->project !== null && Gate::forUser($user)->allows($ability, $task->project);
    }

    /** Q1: the current assignee, evaluated now, who is still a member of the task's project. */
    private function isMemberAssignee(User $user, Task $task): bool
    {
        return $task->assignee_id === $user->id
            && $task->project !== null
            && $task->project->hasMember($user);
    }

    private function ownsStandalone(User $user, Task $task): bool
    {
        return $task->created_by === $user->id || $task->assignee_id === $user->id;
    }
}

<?php

namespace App\Queries;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * TaskPolicy's `complete`, `reopen` and `assign` answers for a whole page of list rows, from ONE
 * membership lookup (EPIC-014 §13.3), instead of the per-row membership queries the policy would
 * run through `ProjectPolicy`. It restates the policy's rules over preloaded facts:
 *
 * - board: project view is `projects.admin` or membership; manage is `projects.admin`, or
 *   `projects.manage` with the `manager` pivot role (ProjectPolicy); complete/reopen add Q1's
 *   member-assignee; assign is manage only;
 * - standalone: the creator or current assignee, for all three;
 * - anything else (ticket-kind, dual-linked): nothing.
 *
 * It is a projection, never an authority: every route still authorizes through TaskPolicy, and
 * TaskListPageTest compares this class with the policy row by row for every actor shape.
 */
final class TaskRowAbilities
{
    /** @param  array<int, string>  $roles  project id => the actor's pivot role there */
    private function __construct(
        private readonly User $actor,
        private readonly array $roles,
        private readonly bool $admin,
        private readonly bool $projectManager,
    ) {}

    /** @param  iterable<Task>  $tasks */
    public static function for(User $actor, iterable $tasks): self
    {
        $projectIds = [];
        foreach ($tasks as $task) {
            if ($task->project_id !== null) {
                $projectIds[$task->project_id] = true;
            }
        }

        $roles = $projectIds === [] ? [] : DB::table('project_members')
            ->where('user_id', $actor->id)
            ->whereIn('project_id', array_keys($projectIds))
            ->pluck('role', 'project_id')
            ->all();

        return new self($actor, $roles, $actor->can('projects.admin'), $actor->can('projects.manage'));
    }

    /** ProjectPolicy::view for a project on this page. */
    public function canViewProject(int $projectId): bool
    {
        return $this->admin || isset($this->roles[$projectId]);
    }

    /** @return array{complete: bool, reopen: bool, assign: bool} */
    public function of(Task $task): array
    {
        if ($task->project_id !== null && $task->ticket_id === null) {
            $manage = $this->admin || ($this->projectManager && ($this->roles[$task->project_id] ?? null) === 'manager');
            $memberAssignee = $task->assignee_id === $this->actor->id && isset($this->roles[$task->project_id]);

            return ['complete' => $manage || $memberAssignee, 'reopen' => $manage || $memberAssignee, 'assign' => $manage];
        }

        if ($task->project_id === null && $task->ticket_id === null) {
            $owns = $task->created_by === $this->actor->id || $task->assignee_id === $this->actor->id;

            return ['complete' => $owns, 'reopen' => $owns, 'assign' => $owns];
        }

        return ['complete' => false, 'reopen' => false, 'assign' => false];
    }
}

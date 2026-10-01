<?php

namespace App\Queries;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The candidate source for single-row assignment from the Tasks list (EPIC-014 §7.3, R6).
 *
 * It names people only where an assignment could legitimately be made, and only to the actor who
 * may make it:
 *
 * - `self`: the actor, the one person a standalone task may be handed to (Q4);
 * - `projects`: for each project that has a board row on this page the actor may assign (the
 *   row's `abilities.assign`, which is `TaskPolicy::assign`), that project's CURRENT members, the
 *   exact set `ProjectTaskAssignee` accepts for a new assignment. All of them come from ONE query
 *   for the whole page, so the cost never grows with the rows.
 *
 * A project the actor sees but cannot manage a row in gets no entry, so no member list is disclosed
 * to someone who could not use it, and a departed assignee is never offered: they are not a member.
 * (The row's own `assignee` still displays them; the client keeps an unchanged value selectable, the
 * I8 precedent, but a new choice is always a member.) Names and ids only, never an email.
 *
 * It is a convenience, never an authority: `PUT /tasks/{task}/assignee` re-authorizes through
 * TaskPolicy and re-validates the target through the same rules, whatever this page offered.
 */
final class TaskAssigneeOptions
{
    /**
     * @param  iterable<Task>  $tasks  one page of rows from TaskQuery
     * @return array{self: array{id: int, name: string}, projects: list<array{projectId: int, members: list<array{id: int, name: string}>}>}
     */
    public static function forPage(User $actor, iterable $tasks, TaskRowAbilities $abilities): array
    {
        $projectIds = [];
        foreach ($tasks as $task) {
            if ($task->project_id !== null && $task->ticket_id === null && $abilities->of($task)['assign']) {
                $projectIds[$task->project_id] = true;
            }
        }

        $byProject = [];
        if ($projectIds !== []) {
            $members = DB::table('project_members')
                ->join('users', 'users.id', '=', 'project_members.user_id')
                ->whereIn('project_members.project_id', array_keys($projectIds))
                ->orderBy('users.name')
                ->orderBy('users.id')
                ->get(['project_members.project_id', 'users.id', 'users.name']);

            foreach ($members as $member) {
                $byProject[$member->project_id][] = ['id' => (int) $member->id, 'name' => $member->name];
            }
        }

        return [
            'self' => ['id' => $actor->id, 'name' => $actor->name],
            'projects' => array_values(array_map(
                fn (int $projectId) => ['projectId' => $projectId, 'members' => $byProject[$projectId] ?? []],
                array_keys($projectIds),
            )),
        ];
    }
}

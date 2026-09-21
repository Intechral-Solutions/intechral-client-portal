<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Read-only audit of project and task data written before EPIC-011E hardened the write paths
 * (§16 "Existing data"). It only ever runs SELECTs: it reports counts and never rewrites rows.
 */
class ProjectIntegrityAudit
{
    /**
     * @return array<string, int>
     */
    public function run(): array
    {
        return [
            // A1: a task whose column belongs to a different project than the task itself.
            'tasks_with_column_from_another_project' => DB::table('tasks')
                ->join('project_columns', 'project_columns.id', '=', 'tasks.column_id')
                ->whereColumn('project_columns.project_id', '!=', 'tasks.project_id')
                ->count(),

            // A2: a task whose milestone belongs to a different project.
            'tasks_with_milestone_from_another_project' => DB::table('tasks')
                ->join('project_milestones', 'project_milestones.id', '=', 'tasks.milestone_id')
                ->whereColumn('project_milestones.project_id', '!=', 'tasks.project_id')
                ->count(),

            // A3: a project task assigned to someone who is not a member of that project.
            'tasks_assigned_to_non_members' => DB::table('tasks')
                ->whereNotNull('tasks.project_id')
                ->whereNotNull('tasks.assignee_id')
                ->whereNotExists(fn ($members) => $members->from('project_members')
                    ->whereColumn('project_members.project_id', 'tasks.project_id')
                    ->whereColumn('project_members.user_id', 'tasks.assignee_id'))
                ->count(),

            // Time entries pointing at a project or task row that does not exist. Foreign keys
            // normally make this impossible; a non-zero count means keys were bypassed.
            'time_entries_with_missing_project_or_task' => DB::table('time_entries')
                ->where(fn ($q) => $q
                    ->where(fn ($p) => $p->whereNotNull('project_id')
                        ->whereNotExists(fn ($sub) => $sub->from('projects')->whereColumn('projects.id', 'time_entries.project_id')))
                    ->orWhere(fn ($t) => $t->whereNotNull('task_id')
                        ->whereNotExists(fn ($sub) => $sub->from('tasks')->whereColumn('tasks.id', 'time_entries.task_id'))))
                ->count(),

            // D4: rows the new rule would now refuse to delete.
            'projects_blocked_from_delete_by_time' => DB::table('projects')
                ->where(fn ($q) => $q
                    ->whereExists(fn ($sub) => $sub->from('time_entries')->whereColumn('time_entries.project_id', 'projects.id'))
                    ->orWhereExists(fn ($sub) => $sub->from('time_entries')
                        ->join('tasks', 'tasks.id', '=', 'time_entries.task_id')
                        ->whereColumn('tasks.project_id', 'projects.id')))
                ->count(),

            'tasks_blocked_from_delete_by_time' => DB::table('tasks')
                ->whereExists(fn ($sub) => $sub->from('time_entries')->whereColumn('time_entries.task_id', 'tasks.id'))
                ->count(),
        ];
    }
}

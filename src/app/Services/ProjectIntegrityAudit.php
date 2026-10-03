<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
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

            // D4: rows the new rule would now refuse to delete. This mirrors RecordedTimeGuard, so
            // it is deliberately CONSERVATIVE: any entry naming the project directly OR through one
            // of its tasks counts, and a malformed entry naming two projects blocks both. It is NOT
            // the project-time attribution rule (TimeEntry::scopeAttributedToProject), which counts
            // an entry under one project; do not "normalize" one to the other (EPIC-015 INV-P3).
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

            // EPIC-014 INV-8: explicit Complete needs exactly one designated Done column per
            // project. Every project the application creates gets one; any other count is a
            // board Complete/Reopen would refuse. A project with no columns at all counts as none.
            'projects_with_no_done_column' => DB::table('projects')
                ->whereNotExists(fn ($sub) => $this->doneColumns($sub))
                ->count(),

            'projects_with_multiple_done_columns' => DB::table('projects')
                ->whereExists(fn ($sub) => $this->doneColumns($sub)->havingRaw('COUNT(*) > 1')->groupBy('project_columns.project_id'))
                ->count(),

            // EPIC-014 INV-13: a task is a board task or a ticket task, never both. No write path
            // produces this; Tasks-workspace queries exclude such a row rather than classify it.
            'tasks_linked_to_project_and_ticket' => DB::table('tasks')
                ->whereNotNull('project_id')
                ->whereNotNull('ticket_id')
                ->count(),

            // EPIC-015 §10.4: a time entry has ONE context. These rows are invalid legacy data; no
            // write path produces them (TimeEntryController::contextRules makes the keys exclusive).
            // Project time attributes a row with a task to the TASK's project and ignores its own
            // project_id (TimeEntry::scopeAttributedToProject), which contains the damage; this
            // audit keeps each such row visible rather than silently attributed.
            'time_entries_with_task_and_project' => DB::table('time_entries')
                ->whereNotNull('task_id')
                ->whereNotNull('project_id')
                ->count(),

            // A ticket entry that also names a project or a task. The two time-entry keys MAY
            // OVERLAP: one row carrying a ticket, a task and a project is counted by both, because
            // each key reports a different violation (task + project; ticket + another context).
            // They are deliberately not made mutually exclusive.
            'time_entries_with_ticket_and_other_context' => DB::table('time_entries')
                ->whereNotNull('ticket_id')
                ->where(fn ($other) => $other->whereNotNull('project_id')->orWhereNotNull('task_id'))
                ->count(),
        ];
    }

    /** Correlated subquery: the current project's designated Done columns. */
    private function doneColumns(Builder $query): Builder
    {
        return $query->from('project_columns')
            ->whereColumn('project_columns.project_id', 'projects.id')
            ->where('project_columns.is_done_column', true);
    }
}

<?php

namespace App\Http\Presenters;

use App\Models\CrmCompany;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\User;
use App\Policies\ProjectTimeAccess;
use App\Queries\ProjectHealth;

/**
 * Page DTOs for the project index, create and edit pages (EPIC-011E §5). Every method returns
 * plain scalars and arrays in camelCase; no model, relation or attribute the page does not
 * render is ever serialized. Nothing here queries per row: callers eager-load or aggregate.
 */
final class ProjectPresenter
{
    /**
     * The optional tabs of the project workspace navigation (EPIC-015 WP5), sent by every workspace
     * page (Overview, Board, Tasks, Milestones, Time) so the shared header renders one navigation.
     * Overview, Board, Tasks and Milestones are `ProjectPolicy::view`-gated like the page itself and
     * are always offered. Time is offered exactly when `ProjectTimeAccess` gives a scope, which is
     * when `projects.time.index` answers 200 to a viewer of the project: no tab leads to a 403.
     *
     * @return array{time: bool}
     */
    public static function workspaceTabs(User $viewer): array
    {
        return ['time' => ProjectTimeAccess::scope($viewer) !== null];
    }

    /**
     * One projects-index row (EPIC-015 §11.1, WP4). The project must come from a query that used
     * `ProjectHealth::withFacts()`, so health and progress are read from aggregates already loaded.
     * Health is the index form: count-only reasons, `earliest` always null (§8.2). The member count is
     * the existing aggregate; no member name, budget, time or Settings datum is on the index.
     *
     * @return array{id: int, name: string, status: string, health: array<string, mixed>|null, tasks: array{total: int, done: int, completion: int}, targetDate: string|null, nextMilestone: array{id: int, name: string, dueDate: string}|null, memberCount: int}
     */
    public static function row(Project $project, ?ProjectMilestone $next): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'status' => $project->status,
            'health' => ProjectHealth::forIndex($project),
            'tasks' => [
                'total' => (int) $project->tasks_count,
                'done' => (int) $project->done_tasks_count,
                'completion' => $project->completionFromCounts(),
            ],
            'targetDate' => $project->target_date?->toDateString(),
            'nextMilestone' => $next === null ? null : [
                'id' => $next->id,
                'name' => $next->name,
                'dueDate' => $next->due_date->toDateString(),
            ],
            'memberCount' => (int) $project->members_count,
        ];
    }

    /**
     * Each listed project's next milestone, the first upcoming one (not completed, not overdue) by
     * (`due_date`, `id`), the Overview's "next" rule: one query for the whole page (§11.1, §17).
     *
     * @param  array<int, int>  $projectIds
     * @return array<int, ProjectMilestone> keyed by project id
     */
    public static function nextMilestones(array $projectIds): array
    {
        if ($projectIds === []) {
            return [];
        }

        $next = [];
        $upcoming = ProjectMilestone::query()
            ->whereIn('project_id', $projectIds)
            ->upcoming()
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'project_id', 'name', 'due_date']);

        foreach ($upcoming as $milestone) {
            $next[$milestone->project_id] ??= $milestone;
        }

        return $next;
    }

    /**
     * The project's own editable fields. `budget` stays the decimal string the column casts to,
     * so the browser never turns money into a float.
     *
     * @return array{id: int, name: string, description: string|null, startDate: string|null, targetDate: string|null, status: string, budget: string|null}
     */
    public static function detail(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'startDate' => $project->start_date?->toDateString(),
            'targetDate' => $project->target_date?->toDateString(),
            'status' => $project->status,
            'budget' => $project->budget,
        ];
    }

    /**
     * Current membership, owner first. No email: the read-only list a non-admin manager sees is
     * exactly this, and only memberCandidates() (administrators) carries emails.
     *
     * @return array<int, array{id: int, name: string, role: string, isOwner: bool}>
     */
    public static function members(Project $project): array
    {
        return $project->members()
            ->orderBy('name')
            ->orderBy('users.id')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'role' => $member->pivot->role,
                'isOwner' => $member->id === $project->created_by,
            ])
            ->sortByDesc('isOwner')
            ->values()
            ->all();
    }

    /**
     * The user directory for the membership editor. D7-B: callers pass it to the page only when
     * ProjectPolicy::manageMembers allows, and omit the prop entirely otherwise.
     *
     * @return array<int, array{id: int, name: string, email: string}>
     */
    public static function memberCandidates(): array
    {
        return User::orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])
            ->all();
    }

    /**
     * Companies the actor may link, scoped by CrmCompany's own global scope.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function companyOptions(): array
    {
        return CrmCompany::orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (CrmCompany $company) => ['id' => $company->id, 'name' => $company->name])
            ->all();
    }

    /** @return array<int, int> */
    public static function linkedCompanyIds(Project $project): array
    {
        return $project->companies()->pluck('crm_companies.id')->all();
    }
}

<?php

namespace App\Http\Presenters;

use App\Models\CrmCompany;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Page DTOs for the project index, create and edit pages (EPIC-011E §5). Every method returns
 * plain scalars and arrays in camelCase; no model, relation or attribute the page does not
 * render is ever serialized. Nothing here queries per row: callers eager-load or aggregate.
 */
final class ProjectPresenter
{
    /** Long enough for the two-line card clamp; the full text stays on the edit page. */
    private const DESCRIPTION_LIMIT = 240;

    /**
     * One index card. The project must come from a query that used Project::withTaskStats().
     *
     * @return array{id: int, name: string, description: string|null, status: string, targetDate: string|null, completion: int, overdueCount: int, memberCount: int}
     */
    public static function card(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description === null
                ? null
                : Str::limit($project->description, self::DESCRIPTION_LIMIT),
            'status' => $project->status,
            'targetDate' => $project->target_date?->toDateString(),
            'completion' => $project->completionFromCounts(),
            'overdueCount' => (int) $project->overdue_tasks_count,
            'memberCount' => (int) $project->members_count,
        ];
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

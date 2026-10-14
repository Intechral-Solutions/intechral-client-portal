<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use App\Shared\Pilot\CustomerPilotGate;

/**
 * Maintenance note (EPIC-014 WP3): `view` and `manage` are restated as a batch, in memory, by
 * `App\Queries\TaskRowAbilities` (one membership query per Tasks page, by design), and `view` is
 * composed in SQL as `Project::visibleTo`. A change to these rules must keep green the parity
 * tests that pin both: `Tasks/TaskListPageTest` ("computes row abilities identical to TaskPolicy"
 * and "agrees with TaskPolicy on every task") and `Tasks/TaskQueryTest` ("equals the TaskPolicy
 * view-able surfaced rows"), extending their fixtures to any new rule.
 */
class ProjectPolicy
{
    public function create(User $user): bool
    {
        return $user->can('projects.manage') || $user->can('projects.admin');
    }

    public function view(User $user, Project $project): bool
    {
        if ($this->administers($user)) {
            return true;
        }

        return $project->hasMember($user);
    }

    public function manage(User $user, Project $project): bool
    {
        if ($this->administers($user)) {
            return true;
        }
        if ($user->can('projects.manage')) {
            return $project->members()
                ->where('user_id', $user->id)
                ->where('role', 'manager')
                ->exists();
        }

        return false;
    }

    /**
     * `projects.admin` sees and manages every project, except for a restricted customer (R0.9-CS1: an account without
     * `portal.operator` in closed or pilot mode), for whom membership is the only way in: an operational capability must
     * not widen the customer's project scope. Mirrored by {@see Project::scopeVisibleTo()}.
     */
    private function administers(User $user): bool
    {
        return $user->can('projects.admin') && ! CustomerPilotGate::restricts($user);
    }

    /**
     * Who may add or remove project members, change member roles, or supply extra initial
     * members (EPIC-011E D7-B). Deliberately narrower than manage(): only projects.admin, and
     * independent of projects.manage. Called with a Project for an existing project and with
     * the class name when a project is being created.
     */
    public function manageMembers(User $user, Project|string|null $project = null): bool
    {
        return $user->can('projects.admin');
    }
}

<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function create(User $user): bool
    {
        return $user->can('projects.manage') || $user->can('projects.admin');
    }

    public function view(User $user, Project $project): bool
    {
        if ($user->can('projects.admin')) {
            return true;
        }

        return $project->hasMember($user);
    }

    public function manage(User $user, Project $project): bool
    {
        if ($user->can('projects.admin')) {
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

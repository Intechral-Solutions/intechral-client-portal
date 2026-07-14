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
}

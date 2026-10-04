<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Effective Settings/Edit access (EPIC-015 §7, INV-P16): what `projects.edit` actually admits.
 * The route carries `can:projects.manage` middleware and the controller authorizes
 * `ProjectPolicy::manage`, so the answer is the CONJUNCTION of the two. That is stricter than the
 * policy alone for a `projects.admin` holder without `projects.manage`, and stricter than the
 * permission alone for an actor who is not a manager of this project (A9, EPIC-011E §7: accepted,
 * not redesigned).
 *
 * The one shared statement of that rule. The Board's `openSettings`, the Milestones page's
 * `manage` ability (milestone create/update/delete/complete/reopen share the same route gate) and
 * every gated Overview field (budget, names-and-roles roster, Settings ability) read it; none
 * restates the conjunction. The routes themselves still enforce it through their middleware and
 * `authorize('manage')`; `Projects/ProjectSettingsAccessTest` pins this class equal to a real
 * `projects.edit` request for every actor shape.
 */
final class ProjectSettingsAccess
{
    public static function allows(User $user, Project $project): bool
    {
        return Gate::forUser($user)->allows('manage', $project) && $user->can('projects.manage');
    }
}

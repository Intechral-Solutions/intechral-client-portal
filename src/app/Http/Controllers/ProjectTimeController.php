<?php

namespace App\Http\Controllers;

use App\Http\Presenters\ProjectPresenter;
use App\Http\Presenters\ProjectTimePresenter;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectSettingsAccess;
use App\Policies\ProjectTimeAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The project Time tab (EPIC-015 §5.2 S3, WP5): one project's attributed time, read only.
 *
 * `ProjectPolicy::view` first (403 for a non-viewer), then the time half: a viewer holding neither
 * time permission has no project time anywhere (no Overview `time` key, no Time tab), so the route
 * answers 403 to them as well rather than rendering an empty page that implies there is nothing
 * to see. No permission is added: `ProjectTimeAccess` reads `time.view_all` / `time.log`.
 */
class ProjectTimeController extends Controller
{
    public function index(Request $request, Project $project): Response
    {
        $this->authorize('view', $project);

        /** @var User $user */
        $user = $request->user();
        $scope = ProjectTimeAccess::scope($user);

        abort_if($scope === null, 403);

        return Inertia::render('projects/time/index', [
            'project' => ['id' => $project->id, 'name' => $project->name, 'status' => $project->status],
            ...ProjectTimePresenter::page($project, $user, $scope),
            'tabs' => ProjectPresenter::workspaceTabs($user),
            // The Settings link: what projects.edit actually admits (A9), as on every other tab.
            'abilities' => ProjectSettingsAccess::allows($user, $project) ? ['openSettings' => true] : [],
        ]);
    }
}

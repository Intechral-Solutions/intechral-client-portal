<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Presenters\CustomerProjectPresenter;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Shared\Pilot\CustomerPilotGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The pilot customer's project pages (Release 0.9, R0.9-CS1). `ProjectController` hands `projects.index` and
 * `projects.show` here when {@see CustomerPilotGate::restricts()} applies, so the same URLs
 * serve a customer the reduced presentation and an operator the full one.
 *
 * Authorization is unchanged: `Project::visibleTo` and `ProjectPolicy::view` (membership). What changes is the
 * data: every query selects explicit columns and every prop comes from {@see CustomerProjectPresenter}, so no
 * internal field is read into the response, let alone serialized.
 */
class CustomerProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $projects = Project::visibleTo($request->user())
            ->select(['projects.id', 'projects.name', 'projects.status', 'projects.created_at'])
            ->latest('projects.created_at')
            ->orderByDesc('projects.id')
            ->paginate(20);

        return Inertia::render('projects/customer-index', [
            'projects' => $projects->through(fn (Project $project) => CustomerProjectPresenter::row($project)),
        ]);
    }

    public function show(Project $project): Response
    {
        // A project the customer is not a member of is indistinguishable from one that does not exist: 404, not 403
        // (the route-model binding has already answered 404 for an unknown id).
        abort_unless(Gate::allows('view', $project), 404);

        $milestones = ProjectMilestone::query()
            ->where('project_id', $project->id)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get(['id', 'name', 'due_date']);

        return Inertia::render('projects/customer-show', [
            'project' => CustomerProjectPresenter::project($project),
            'milestones' => $milestones->map(fn (ProjectMilestone $milestone) => CustomerProjectPresenter::milestone($milestone))->values(),
        ]);
    }
}

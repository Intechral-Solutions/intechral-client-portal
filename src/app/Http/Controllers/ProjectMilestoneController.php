<?php

namespace App\Http\Controllers;

use App\Http\Presenters\ProjectMilestonePresenter;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Policies\ProjectSettingsAccess;
use App\Services\ProjectMilestoneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectMilestoneController extends Controller
{
    public function __construct(private ProjectMilestoneService $service) {}

    public function index(Project $project): Response
    {
        $this->authorize('view', $project);

        $milestones = $project->milestones()
            ->orderBy('id')
            ->withTaskCounts()
            ->with('completer:id,name')
            ->get()
            ->map(fn (ProjectMilestone $milestone) => ProjectMilestonePresenter::item($milestone))
            ->values();

        return Inertia::render('projects/milestones/index', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'milestones' => $milestones,
            // What the mutation routes actually admit: they require projects.manage (A9), which
            // ProjectPolicy::manage() alone does not, so a projects.admin-only actor must not be
            // offered New Milestone / Edit / Delete / Complete / Reopen only to have them 403
            // (EPIC-011E §7 note). The shared Settings-access resolver states exactly that rule.
            'abilities' => [
                'manage' => ProjectSettingsAccess::allows(auth()->user(), $project),
            ],
        ]);
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('manage', $project);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'required|date',
        ]);

        $project->milestones()->create($data);

        return redirect()->route('projects.milestones.index', $project)
            ->with('success', 'Milestone created.');
    }

    public function update(Request $request, Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $this->authorize('manage', $project);
        abort_unless($milestone->project_id === $project->id, 404);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'required|date',
        ]);

        $milestone->update($data);

        return redirect()->route('projects.milestones.index', $project)
            ->with('success', 'Milestone updated.');
    }

    public function destroy(Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $this->authorize('manage', $project);
        abort_unless($milestone->project_id === $project->id, 404);

        $milestone->delete();

        return redirect()->route('projects.milestones.index', $project)
            ->with('success', 'Milestone deleted.');
    }

    /** EPIC-015 §9.2: explicit completion, idempotent. Linked tasks are never touched (INV-P9). */
    public function complete(Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $this->authorize('manage', $project);
        abort_unless($milestone->project_id === $project->id, 404);

        $this->service->complete($milestone, auth()->user());

        return redirect()->route('projects.milestones.index', $project)
            ->with('success', 'Milestone completed.');
    }

    /** EPIC-015 §9.2: clears completion, idempotent. Linked tasks are never touched (INV-P9). */
    public function reopen(Project $project, ProjectMilestone $milestone): RedirectResponse
    {
        $this->authorize('manage', $project);
        abort_unless($milestone->project_id === $project->id, 404);

        $this->service->reopen($milestone);

        return redirect()->route('projects.milestones.index', $project)
            ->with('success', 'Milestone reopened.');
    }
}

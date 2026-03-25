<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectMilestone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectMilestoneController extends Controller
{
    public function index(Project $project): View
    {
        $this->authorize('view', $project);

        $milestones = $project->milestones()
            ->withCount('tasks')
            ->get();

        return view('projects.milestones.index', compact('project', 'milestones'));
    }

    public function store(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('manage', $project);

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date'    => 'required|date',
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
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date'    => 'required|date',
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
}

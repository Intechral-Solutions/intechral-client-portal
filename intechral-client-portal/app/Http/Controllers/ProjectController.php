<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function __construct(private ProjectService $service) {}

    public function index(): View
    {
        $user = auth()->user();

        $projects = $user->can('projects.admin')
            ? Project::with('creator')->latest()->paginate(20)
            : $user->projects()->with('creator')->latest()->paginate(20);

        return view('projects.index', compact('projects'));
    }

    public function create(): View
    {
        $this->authorize('create', Project::class);

        $members = User::orderBy('name')->get(['id', 'name', 'email']);

        return view('projects.create', compact('members'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Project::class);

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date'  => 'nullable|date',
            'target_date' => 'nullable|date|after_or_equal:start_date',
            'status'      => 'in:active,on_hold,completed,archived',
            'budget'      => 'nullable|numeric|min:0',
            'members'     => 'nullable|array',
            'members.*.user_id' => 'required|exists:users,id',
            'members.*.role'    => 'required|in:member,manager',
        ]);

        $project = $this->service->create(auth()->user(), $data);

        if (! empty($data['members'])) {
            $this->service->syncMembers($project, $data['members']);
        }

        return redirect()->route('projects.board', $project)
            ->with('success', 'Project created successfully.');
    }

    public function show(Project $project): RedirectResponse
    {
        $this->authorize('view', $project);

        return redirect()->route('projects.board', $project);
    }

    public function edit(Project $project): View
    {
        $this->authorize('manage', $project);

        $allMembers = User::orderBy('name')->get(['id', 'name', 'email']);
        $currentMembers = $project->members()->get(['users.id', 'name', 'email', 'project_members.role as pivot_role']);

        return view('projects.edit', compact('project', 'allMembers', 'currentMembers'));
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('manage', $project);

        $data = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date'  => 'nullable|date',
            'target_date' => 'nullable|date|after_or_equal:start_date',
            'status'      => 'required|in:active,on_hold,completed,archived',
            'budget'      => 'nullable|numeric|min:0',
        ]);

        $project->update($data);

        return redirect()->route('projects.edit', $project)
            ->with('success', 'Project updated.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        $this->authorize('manage', $project);

        $project->delete();

        return redirect()->route('projects.index')
            ->with('success', 'Project deleted.');
    }

    public function syncMembers(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('manage', $project);

        $data = $request->validate([
            'members'           => 'present|array',
            'members.*.user_id' => 'required|exists:users,id',
            'members.*.role'    => 'required|in:member,manager',
        ]);

        $this->service->syncMembers($project, $data['members']);

        return redirect()->route('projects.edit', $project)
            ->with('success', 'Members updated.');
    }
}

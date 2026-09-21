<?php

namespace App\Http\Controllers;

use App\Models\CrmCompany;
use App\Models\Project;
use App\Models\User;
use App\Rules\AccessibleCrmCompany;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function __construct(private ProjectService $service) {}

    public function index(): View
    {
        $projects = Project::visibleTo(auth()->user())
            ->withTaskStats()
            ->latest()
            ->paginate(20);

        return view('projects.index', compact('projects'));
    }

    public function create(): View
    {
        $this->authorize('create', Project::class);

        $data = ['companies' => CrmCompany::orderBy('name')->get(['id', 'name'])];

        // Only an actor who may manage membership receives the user directory (D7-B).
        if (Gate::allows('manageMembers', Project::class)) {
            $data['memberCandidates'] = $this->memberCandidates();
        }

        return view('projects.create', $data);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Project::class);

        // Extra initial members are a membership operation: refuse, do not silently ignore.
        if (! empty($request->input('members'))) {
            $this->authorize('manageMembers', Project::class);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'target_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'in:active,on_hold,completed,archived',
            'budget' => 'nullable|numeric|min:0',
            ...$this->memberRules(),
            'companies' => 'nullable|array',
            'companies.*' => [new AccessibleCrmCompany],
        ]);

        $project = $this->service->create(auth()->user(), $data);

        if (! empty($data['members'])) {
            $this->service->syncMembers($project, $data['members']);
        }

        if (! empty($data['companies'])) {
            $project->companies()->sync($data['companies']);
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

        // Existing membership is shown to every manager, but only as {id, name, role, isOwner}:
        // no email. The candidate directory below is what carries emails.
        $members = $project->members()
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'role' => $member->pivot->role,
                'isOwner' => $member->id === $project->created_by,
            ])
            ->all();

        $data = [
            'project' => $project,
            'members' => $members,
            'companies' => CrmCompany::orderBy('name')->get(['id', 'name']),
            'linkedCompanyIds' => $project->companies()->pluck('crm_companies.id')->all(),
        ];

        if (Gate::allows('manageMembers', $project)) {
            $data['memberCandidates'] = $this->memberCandidates();
        }

        return view('projects.edit', $data);
    }

    public function update(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('manage', $project);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start_date' => 'nullable|date',
            'target_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'required|in:active,on_hold,completed,archived',
            'budget' => 'nullable|numeric|min:0',
        ]);

        $project->update($data);

        return redirect()->route('projects.edit', $project)
            ->with('success', 'Project updated.');
    }

    public function destroy(Project $project): RedirectResponse
    {
        $this->authorize('manage', $project);

        $this->service->deleteProject($project);

        return redirect()->route('projects.index')
            ->with('success', 'Project deleted.');
    }

    public function syncMembers(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('manageMembers', $project);

        $data = $request->validate([
            ...$this->memberRules(),
            'members' => 'present|array',
        ]);

        $this->service->syncMembers($project, $data['members']);

        return redirect()->route('projects.edit', $project)
            ->with('success', 'Members updated.');
    }

    public function syncCompanies(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('manage', $project);

        $data = $request->validate([
            'companies' => 'present|array',
            'companies.*' => [new AccessibleCrmCompany],
        ]);

        $project->companies()->sync($data['companies']);

        return redirect()->route('projects.edit', $project)
            ->with('success', 'Companies updated.');
    }

    // ── Private ──────────────────────────────────────────────

    /**
     * Existing users only, each at most once, with a valid role. `users` has no active or
     * disabled state, so "an existing row" is the whole eligibility rule (D7-B).
     */
    private function memberRules(): array
    {
        return [
            'members' => 'nullable|array',
            'members.*.user_id' => 'required|integer|distinct|exists:users,id',
            'members.*.role' => 'required|in:member,manager',
        ];
    }

    /** @return array<int, array{id: int, name: string, email: string}> */
    private function memberCandidates(): array
    {
        return User::orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])
            ->all();
    }
}

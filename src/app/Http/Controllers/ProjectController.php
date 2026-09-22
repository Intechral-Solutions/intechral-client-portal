<?php

namespace App\Http\Controllers;

use App\Http\Presenters\ProjectPresenter;
use App\Models\Project;
use App\Rules\AccessibleCrmCompany;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class ProjectController extends Controller
{
    public function __construct(private ProjectService $service) {}

    public function index(): Response
    {
        $user = auth()->user();

        $projects = Project::visibleTo($user)
            ->withTaskStats()
            ->latest()
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Project $project) => ProjectPresenter::card($project));

        return Inertia::render('projects/index', [
            'projects' => $projects,
            // What projects.create actually admits: the route requires projects.manage even
            // though the create policy also allows projects.admin alone (A9), so a link built
            // from the policy alone would lead an administrator to a 403.
            'abilities' => [
                'create' => Gate::allows('create', Project::class) && $user->can('projects.manage'),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Project::class);

        $props = [
            'companies' => ProjectPresenter::companyOptions(),
            'abilities' => ['editMembers' => Gate::allows('manageMembers', Project::class)],
        ];

        // Only an actor who may manage membership receives the user directory (D7-B): the prop
        // is absent, not empty, for everyone else.
        if ($props['abilities']['editMembers']) {
            $props['memberCandidates'] = ProjectPresenter::memberCandidates();
        }

        return Inertia::render('projects/create', $props);
    }

    public function store(Request $request): SymfonyResponse
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

        // The board is still a Blade page. Redirecting an Inertia request to a non-Inertia
        // response makes Inertia show its error modal, so an Inertia request gets a location
        // visit (a full page load); every other request still gets the plain redirect. Once the
        // board is a React page (WP5) this becomes an ordinary redirect again.
        session()->flash('success', 'Project created successfully.');

        return Inertia::location(route('projects.board', $project));
    }

    public function show(Project $project): RedirectResponse
    {
        $this->authorize('view', $project);

        return redirect()->route('projects.board', $project);
    }

    public function edit(Project $project): Response
    {
        $this->authorize('manage', $project);

        $props = [
            'project' => ProjectPresenter::detail($project),
            // Existing membership goes to every manager as {id, name, role, isOwner}: no email.
            'members' => ProjectPresenter::members($project),
            'companies' => ProjectPresenter::companyOptions(),
            'linkedCompanyIds' => ProjectPresenter::linkedCompanyIds($project),
            'abilities' => [
                'delete' => Gate::allows('manage', $project),
                'editMembers' => Gate::allows('manageMembers', $project),
            ],
        ];

        if ($props['abilities']['editMembers']) {
            $props['memberCandidates'] = ProjectPresenter::memberCandidates();
        }

        return Inertia::render('projects/edit', $props);
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
}

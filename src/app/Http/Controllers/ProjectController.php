<?php

namespace App\Http\Controllers;

use App\Http\Presenters\ProjectOverviewPresenter;
use App\Http\Presenters\ProjectPresenter;
use App\Models\Project;
use App\Queries\ProjectHealth;
use App\Rules\AccessibleCrmCompany;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(private ProjectService $service) {}

    /** Lifecycle statuses, in the order the filter offers them (EPIC-015 §11.1, P5). */
    public const STATUSES = ['active' => 'Active', 'on_hold' => 'On hold', 'completed' => 'Completed', 'archived' => 'Archived'];

    /**
     * The projects index (EPIC-015 §11.1, §14.1, WP4): a scanning list, not a dashboard. Health and
     * progress come from the aggregates in the page query (`ProjectHealth::withFacts`), the next
     * milestone from one bounded query for the page's ids, so a page costs no query per project.
     *
     * The one filter is lifecycle status, absent by default (P5: every status, as before). An unknown
     * value is dropped, never a redirect; page links carry only the normalized filter.
     */
    public function index(Request $request): Response
    {
        $user = auth()->user();
        $status = is_string($request->query('status')) && array_key_exists($request->query('status'), self::STATUSES)
            ? $request->query('status')
            : null;

        $projects = ProjectHealth::withFacts(Project::visibleTo($user))
            ->when($status !== null, fn ($query) => $query->where('projects.status', $status))
            ->latest()
            ->orderByDesc('id')
            ->paginate(20)
            ->appends(array_filter(['status' => $status]));

        $next = ProjectPresenter::nextMilestones($projects->getCollection()->modelKeys());

        return Inertia::render('projects/index', [
            'projects' => $projects->through(fn (Project $project) => ProjectPresenter::row($project, $next[$project->id] ?? null)),
            'filters' => ['status' => $status],
            'filterOptions' => [
                'statuses' => array_map(fn ($value, $label) => ['value' => $value, 'label' => $label], array_keys(self::STATUSES), self::STATUSES),
            ],
            // Distinguishes "no projects at all" from "none with this status"; asked only when the
            // filtered page is empty.
            'hasProjects' => $projects->total() > 0 || ($status !== null && Project::visibleTo($user)->exists()),
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

        // One service-owned transaction: the project, its board, the creator, the extra members and
        // the company links all persist together or not at all (EPIC-015 INV-P10).
        $project = $this->service->create(auth()->user(), $data);

        // A new project lands on its Overview, the project's home (EPIC-015 Q5). An ordinary
        // redirect, so an Inertia request follows it as an ordinary Inertia visit.
        return redirect()->route('projects.show', $project)
            ->with('success', 'Project created successfully.');
    }

    /**
     * The project Overview (EPIC-015 Q5, §12): the canonical project route. Every prop comes from
     * `ProjectOverviewPresenter`, which decides each gated field from the viewer's capabilities
     * before building the array (INV-P8); this action adds nothing to it.
     */
    public function show(Project $project): Response
    {
        $this->authorize('view', $project);

        return Inertia::render('projects/show', ProjectOverviewPresenter::overview($project, auth()->user()));
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

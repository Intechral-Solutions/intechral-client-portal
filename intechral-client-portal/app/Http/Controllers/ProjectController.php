<?php

namespace App\Http\Controllers;

use App\Models\CrmCompany;
use App\Models\Project;
use App\Models\User;
use App\Rules\AccessibleCrmCompany;
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

        if ($user->can('projects.admin')) {
            $projects = Project::with('creator')->latest()->paginate(20);
        } elseif ($user->can('projects.view_org')) {
            $companyIds = $user->orgCompanyIds();
            $projects = Project::where(function ($q) use ($user, $companyIds) {
                    $q->whereHas('members', fn ($m) => $m->where('users.id', $user->id));
                    if (! empty($companyIds)) {
                        $q->orWhereHas('companies', fn ($c) => $c->whereIn('crm_companies.id', $companyIds));
                    }
                })
                ->with('creator')
                ->latest()
                ->paginate(20);
        } else {
            $projects = $user->projects()->with('creator')->latest()->paginate(20);
        }

        return view('projects.index', compact('projects'));
    }

    public function create(): View
    {
        $this->authorize('create', Project::class);

        $members   = User::orderBy('name')->get(['id', 'name', 'email']);
        $companies = CrmCompany::orderBy('name')->get(['id', 'name']);

        return view('projects.create', compact('members', 'companies'));
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
            'companies'   => 'nullable|array',
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

        $allMembers     = User::orderBy('name')->get(['id', 'name', 'email']);
        $currentMembers = $project->members()->get(['users.id', 'name', 'email', 'project_members.role as pivot_role']);
        $allCompanies   = CrmCompany::orderBy('name')->get(['id', 'name']);
        $linkedCompanies = $project->companies()->pluck('crm_companies.id')->toArray();

        return view('projects.edit', compact('project', 'allMembers', 'currentMembers', 'allCompanies', 'linkedCompanies'));
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

    public function syncCompanies(Request $request, Project $project): RedirectResponse
    {
        $this->authorize('manage', $project);

        $data = $request->validate([
            'companies'   => 'present|array',
            'companies.*' => [new AccessibleCrmCompany],
        ]);

        $project->companies()->sync($data['companies']);

        return redirect()->route('projects.edit', $project)
            ->with('success', 'Companies updated.');
    }
}

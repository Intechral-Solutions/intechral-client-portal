<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\OrganizationDirectory;
use App\Services\OrganizationListFacts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The transitional Directory pages for an Organization (Release 1.0, ORG-2). The routes keep their `crm.companies.*` names, URIs and `{company}` parameter until
 * ORG-3 builds the unified Directory; the parameter now binds to the canonical {@see Organization}, and this controller's `$company` parameters are that model
 * (route-model binding matches the NAME, so a rename here would silently stop binding). There is no second Organization editor: every write is
 * {@see OrganizationDirectory}, which authorizes it again from the tables.
 *
 * Every action asks `manageDirectory` first (an active operator holding `portal.operator` and `crm.manage`). The route's `can:crm.manage` alone would let a
 * customer who holds a stray `crm.manage` read an Organization's internal notes; this does not.
 */
class CompanyController extends Controller
{
    public function __construct(private OrganizationDirectory $directory) {}

    public function index(Request $request): View
    {
        $this->authorize('manageDirectory', Organization::class);

        // An array-shaped parameter (`?search[]=x`) is no search, never an "Array to string" error.
        $search = is_string($request->query('search')) ? $request->query('search') : null;

        $companies = Organization::query()
            ->when($search, fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '\\%_').'%'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        // Release 1.0 (DIR-1): the portal-access state of every row, and "Managed by" / "Manages N" only for an actor who may manage
        // relationships. The relationship facts are not read for anyone else (null), so nothing is merely hidden in the view (DIR-0 S7).
        $organizationIds = $companies->getCollection()->pluck('id')->all();
        $portalStatus = OrganizationListFacts::portalStatus($organizationIds);
        $relationships = OrganizationListFacts::relationships($request->user(), $organizationIds);

        return view('crm.companies.index', compact('companies', 'search', 'portalStatus', 'relationships'));
    }

    public function create(): View
    {
        $this->authorize('manageDirectory', Organization::class);

        return view('crm.companies.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('manageDirectory', Organization::class);

        $organization = $this->directory->create($request->user(), $this->validated($request));

        return redirect()->route('crm.companies.show', $organization)
            ->with('success', 'Organization created.');
    }

    public function show(Organization $company): View
    {
        $this->authorize('manageDirectory', Organization::class);

        $company->load(['people', 'members']);

        return view('crm.companies.show', compact('company'));
    }

    public function edit(Organization $company): View
    {
        $this->authorize('manageDirectory', Organization::class);

        return view('crm.companies.edit', compact('company'));
    }

    public function update(Request $request, Organization $company): RedirectResponse
    {
        $this->authorize('manageDirectory', Organization::class);

        $this->directory->update($company, $request->user(), $this->validated($request));

        return redirect()->route('crm.companies.show', $company)
            ->with('success', 'Organization updated.');
    }

    public function destroy(Organization $company): RedirectResponse
    {
        $this->authorize('manageDirectory', Organization::class);

        $this->directory->delete($company, request()->user());

        return redirect()->route('crm.companies.index')
            ->with('success', 'Organization deleted.');
    }

    /**
     * Initial enablement of portal access. The ability is `manageMembership` (portal.operator AND crm.manage, an active account), asked here as the first
     * statement and again by the service from the tables under its locks; it is not `manageRelationships`, which governs disable and re-enable (ADR-011 section 2).
     */
    public function promote(Request $request, Organization $company): RedirectResponse
    {
        $this->authorize('manageMembership', Organization::class);

        $organization = $this->directory->enablePortalAccess($company, $request->user());

        return redirect()->route('organizations.show', $organization)
            ->with('success', "Portal access is enabled for \"{$organization->name}\".");
    }

    /**
     * The Directory form's fields. Classification is deliberately NOT one (CP6, F-4): its editable control belongs to ORG-3's unified interface, so no
     * Directory request can set it; it is changed through OrganizationDirectory::changeClassification and shown read-only.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'website' => 'nullable|url|max:255',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:5000',
        ]);
    }
}

<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmCompany;
use App\Models\Organization;
use App\Services\CrmService;
use App\Services\OrganizationListFacts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function __construct(private CrmService $service) {}

    public function index(Request $request): View
    {
        // An array-shaped parameter (`?search[]=x`) is no search, never an "Array to string" error.
        $search = is_string($request->query('search')) ? $request->query('search') : null;

        $companies = CrmCompany::query()
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        // Release 1.0 (DIR-1): the portal-access state of every row, and "Managed by" / "Manages N" only for an actor who may manage
        // relationships. The relationship facts are not read for anyone else (null), so nothing is merely hidden in the view (DIR-0 S7).
        $organizationIds = $companies->getCollection()->pluck('organization_id')->all();
        $portalStatus = OrganizationListFacts::portalStatus($organizationIds);
        $relationships = OrganizationListFacts::relationships($request->user(), $organizationIds);

        return view('crm.companies.index', compact('companies', 'search', 'portalStatus', 'relationships'));
    }

    public function create(): View
    {
        return view('crm.companies.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'website' => 'nullable|url|max:255',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:5000',
        ]);

        $company = $this->service->createCompany($request->user(), $data);

        return redirect()->route('crm.companies.show', $company)
            ->with('success', 'Organization created.');
    }

    public function show(CrmCompany $company): View
    {
        $company->load(['contacts', 'organization.members']);

        return view('crm.companies.show', compact('company'));
    }

    public function edit(CrmCompany $company): View
    {
        return view('crm.companies.edit', compact('company'));
    }

    public function update(Request $request, CrmCompany $company): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'website' => 'nullable|url|max:255',
            'phone' => 'nullable|string|max:30',
            'address' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:5000',
        ]);

        $this->service->updateCompany($company, $data);

        return redirect()->route('crm.companies.show', $company)
            ->with('success', 'Organization updated.');
    }

    public function destroy(CrmCompany $company): RedirectResponse
    {
        $this->service->deleteCompany($company);

        return redirect()->route('crm.companies.index')
            ->with('success', 'Organization deleted.');
    }

    /**
     * Initial enablement of portal access. The ability is `manageMembership` (portal.operator AND crm.manage, an active account), asked here as the first
     * statement and again by the service from the tables under its locks; it is not `manageRelationships`, which governs disable and re-enable (ADR-011 section 2).
     */
    public function promote(Request $request, CrmCompany $company): RedirectResponse
    {
        $this->authorize('manageMembership', Organization::class);

        $org = $this->service->promoteToOrganization($company, $request->user());

        return redirect()->route('organizations.show', $org)
            ->with('success', "Portal access is enabled for \"{$company->name}\".");
    }
}

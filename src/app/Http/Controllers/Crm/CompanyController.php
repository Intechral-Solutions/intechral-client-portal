<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmCompany;
use App\Services\CrmService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CompanyController extends Controller
{
    public function __construct(private CrmService $service) {}

    public function index(Request $request): View
    {
        $search = $request->get('search');

        $companies = CrmCompany::with('organization')
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('crm.companies.index', compact('companies', 'search'));
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
            ->with('success', 'Company created.');
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
            ->with('success', 'Company updated.');
    }

    public function destroy(CrmCompany $company): RedirectResponse
    {
        $this->service->deleteCompany($company);

        return redirect()->route('crm.companies.index')
            ->with('success', 'Company deleted.');
    }

    public function promote(Request $request, CrmCompany $company): RedirectResponse
    {
        $org = $this->service->promoteToOrganization($company, $request->user());

        return redirect()->route('organizations.show', $org)
            ->with('success', "\"{$company->name}\" has been promoted to an organization.");
    }
}

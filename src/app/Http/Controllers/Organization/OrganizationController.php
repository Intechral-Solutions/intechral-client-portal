<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationRelationships;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function index(): View
    {
        $organizations = Organization::with(['owner', 'company'])
            ->withCount('members')
            ->orderBy('name')
            ->paginate(25);

        return view('organizations.index', compact('organizations'));
    }

    public function show(Organization $organization): View
    {
        $organization->load(['owner', 'company', 'members']);

        // Candidates to add: active accounts only (Release 0.9, H3). Existing members, suspended or not, are listed
        // from the relation above.
        $availableUsers = User::active()->whereDoesntHave('organizations', fn ($q) => $q->where('organizations.id', $organization->id)
        )->orderBy('name')->get(['id', 'name', 'email']);

        // Release 1.0 (MO-1A): the relationship panel names OTHER organizations (the partner, the customers, the candidates), so it
        // is built only for an actor who may manage relationships (portal.operator AND crm.manage). Anyone else who can open this
        // page (a customer holding a stray crm.manage) gets no relationship data at all, not a hidden section.
        $relationship = Gate::allows('manageRelationships', Organization::class) ? $this->relationshipPanel($organization) : null;

        return view('organizations.show', compact('organization', 'availableUsers', 'relationship'));
    }

    /** @return array<string, mixed> */
    private function relationshipPanel(Organization $organization): array
    {
        $isPartner = (bool) $organization->can_manage_customers;
        $manager = OrganizationRelationships::managerOf($organization);
        $companies = OrganizationRelationships::companyCount($organization->getKey());

        return [
            'isPartner' => $isPartner,
            'manager' => $manager,
            'customers' => $isPartner ? OrganizationRelationships::managedCustomers($organization) : collect(),
            'companies' => $companies,
            // Offered only where the server would accept it; the server decides regardless.
            'canDesignate' => ! $isPartner && $manager === null && $companies === 1,
            'candidateCustomers' => $isPartner ? OrganizationRelationships::eligibleCustomers($organization) : collect(),
            'candidatePartners' => ! $isPartner && $companies === 1 ? OrganizationRelationships::eligiblePartners($organization) : collect(),
        ];
    }
}

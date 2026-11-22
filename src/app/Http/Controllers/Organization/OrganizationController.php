<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Crm\CompanyController;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationInvitationList;
use App\Services\OrganizationListFacts;
use App\Services\OrganizationMembership;
use App\Services\OrganizationRelationships;
use App\Services\PartnerSupportGrants;
use App\Support\Organizations\PortalState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * System → Organization access (Release 1.0, DIR-1; corrected in ORG-3a). The route's `can:crm.manage` is only the first gate: a customer who holds a stray
 * `crm.manage` passes it, and the list and the detail carry internal administration (every member's name and address, approvals, invitations, support
 * designations). So BOTH actions ask `manageDirectory` first, an active account holding `portal.operator` AND `crm.manage`, exactly as the Directory pages do
 * ({@see CompanyController}). No ability, permission or policy method is new. The ability is asked AFTER route-model binding, which is
 * what keeps the three answers apart: an Organization a tenant scope hides is a 404 (hidden and unknown alike), a visible one is a 403 for an actor who may not.
 */
class OrganizationController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('manageDirectory', Organization::class);

        // ORG-3a: only Organizations whose portal access has EVER been enabled (Enabled or Disabled). A Directory-only Organization has nothing to administer here and is
        // not "Enabled": it lives in the Directory, and enabling portal access is what brings it into this list. The state shown is the row's own (PortalState), never a
        // default for a row the read did not classify.
        $organizations = PortalState::whereEverEnabled(Organization::query())
            ->with(['portalEnabledBy'])
            ->withCount('members')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25);

        // Release 1.0 (DIR-1): "Managed by" / "Manages N" ONLY for an actor who may manage relationships. The relationship facts are not read at all for anyone else
        // (null), so there is nothing to hide in the view (DIR-0 invariant S7).
        $relationships = OrganizationListFacts::relationships($request->user(), $organizations->getCollection()->pluck('id')->all());

        return view('organizations.index', compact('organizations', 'relationships'));
    }

    public function show(Organization $organization): View
    {
        $this->authorize('manageDirectory', Organization::class);

        $organization->load(['portalEnabledBy', 'members']);
        $state = $organization->portalState();

        // Release 1.0 (MO-2A1): membership administration is operator-only, so the candidate list (every active account that is not
        // yet a member) and the approval details are built only for an actor who may manage membership. The three abilities (Directory, membership,
        // relationships) are separate on purpose and answer alike today; an actor who passed `manageDirectory` above but may not manage membership would see
        // the plain member list and no way to change it. A customer holding a stray `crm.manage` never reaches this line (ORG-3a: refused above).
        $canManageMembership = Gate::allows('manageMembership', Organization::class);

        // Candidates to add: active accounts only (Release 0.9, H3), and only where an add would be accepted: an Enabled Organization (ORG-1 refuses it for any other state,
        // so the form is not offered for one). Existing members, suspended or not, are listed from the relation above.
        $availableUsers = $canManageMembership && $state->isEnabled()
            ? User::active()->whereDoesntHave('organizations', fn ($q) => $q->where('organizations.id', $organization->id)
            )->orderBy('name')->get(['id', 'name', 'email'])
            : collect();

        $memberships = $canManageMembership ? OrganizationMembership::rows($organization->getKey()) : null;

        // Release 1.0 (MO-1A): the relationship panel names OTHER organizations (the partner, the customers, the candidates), so it
        // is built only for an actor who may manage relationships (portal.operator AND crm.manage). Anyone else who can open this
        // page (a customer holding a stray crm.manage) gets no relationship data at all, not a hidden section.
        $relationship = Gate::allows('manageRelationships', Organization::class) ? $this->relationshipPanel($organization) : null;

        // Release 1.0 (R1-3C): the Invitations card holds invitees' addresses and the names of inviters, so it is built only for an actor who
        // may manage membership, like the member administration above. For anyone else the page has no such card at all, not a hidden one.
        $invitations = $canManageMembership ? OrganizationInvitationList::for($organization->getKey()) : null;

        // Release 1.0 (R1-4): support designations of a PARTNER's members, for an actor who may manage membership; and whether the organization is disabled.
        // Also built for a NON-partner whose memberships carry designation data (a row the application never writes, which the preflight reports), so the
        // operator can reach the Revoke control the preflight points to.
        $support = $canManageMembership && ($organization->can_manage_customers || PartnerSupportGrants::anyDesignationData($organization->getKey()))
            ? PartnerSupportGrants::rowsFor($organization->getKey())
            : null;
        $canManageRelationships = Gate::allows('manageRelationships', Organization::class);

        return view('organizations.show', compact('organization', 'state', 'availableUsers', 'relationship', 'memberships', 'invitations', 'support', 'canManageRelationships'));
    }

    /** @return array<string, mixed> */
    private function relationshipPanel(Organization $organization): array
    {
        $isPartner = (bool) $organization->can_manage_customers;
        // Offered only where the server would accept it: designating, linking a customer and linking to a partner all need an ENABLED Organization (ORG-1; the
        // service refuses both ends of a link), so a never-enabled or Disabled one is offered none of them. Unlinking and removing a designation ask nothing of the
        // state (they take authority away), so they stay offered. The server decides regardless.
        $state = $organization->portalState();
        $enabled = $state->isEnabled();
        $manager = OrganizationRelationships::managerOf($organization);

        return [
            'isPartner' => $isPartner,
            'state' => $state,
            'manager' => $manager,
            'customers' => $isPartner ? OrganizationRelationships::managedCustomers($organization) : collect(),
            'canDesignate' => $enabled && ! $isPartner && $manager === null,
            'candidateCustomers' => $enabled && $isPartner ? OrganizationRelationships::eligibleCustomers($organization) : collect(),
            'candidatePartners' => $enabled && ! $isPartner ? OrganizationRelationships::eligiblePartners($organization) : collect(),
        ];
    }
}

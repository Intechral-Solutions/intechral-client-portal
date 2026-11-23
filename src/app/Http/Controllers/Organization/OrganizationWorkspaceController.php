<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Presenters\OrganizationWorkspacePresenter;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationInvitationList;
use App\Services\OrganizationMembership;
use App\Services\OrganizationRelationships;
use App\Services\PartnerSupportGrants;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The Organization workspace (Release 1.0, ORG-3b): one Organization, three routed pages, one path each. `organizations.show` is the canonical identity URL and the Overview; People
 * and Portal access are their own routes. They share one header ({@see OrganizationWorkspacePresenter}), and the Disabled and Not-enabled states are drawn by the same badge
 * and the same per-state controls as ORG-3a.
 *
 * AUTHORIZATION. Every action asks `manageDirectory` first, AFTER route-model binding, so the three answers stay apart: an Organization a tenant scope hides is a 404 (hidden
 * and unknown alike), a visible one is a 403 for an actor who may not, and the closed and pilot customer modes keep answering 404 from the surface gate. Each page then
 * builds ONLY the data its own ability allows, and nothing it does not show: the Overview reads no member, invitation, approval or relationship row beyond one count for an actor
 * who may manage membership; the People page reads People only; the Portal access page reads membership, invitation, support and relationship data each under its own ability
 * (`manageMembership`, `manageRelationships`), which answer alike today and are separate on purpose. No Project datum is read here: the Projects tab is ORG-3c's.
 *
 * There is no write in this controller: every administrative act keeps its own security-reviewed endpoint, which returns to the page it was made from.
 */
class OrganizationWorkspaceController extends Controller
{
    /** Overview: a summary of the Organization, never a roster. Directory details, a People count and the portal state, each for its own ability. */
    public function show(Request $request, Organization $organization): View
    {
        $this->authorize('manageDirectory', Organization::class);

        $organization->loadMissing('creator');
        $state = $organization->portalState();

        // Membership facts (who enabled portal access, how many members) are membership administration data: built only for an actor who may manage membership.
        $canManageMembership = Gate::allows('manageMembership', Organization::class);
        $portal = [
            'state' => $state,
            'enabledBy' => $canManageMembership ? $organization->portalEnabledBy()->value('name') : null,
            'memberCount' => $canManageMembership ? $organization->members()->count() : null,
            'canManageMembership' => $canManageMembership,
        ];

        return view('organizations.show', [
            'organization' => $organization,
            'header' => OrganizationWorkspacePresenter::header($request->user(), $organization, OrganizationWorkspacePresenter::OVERVIEW),
            'peopleCount' => $organization->people()->count(),
            'portal' => $portal,
        ]);
    }

    /** People: the Directory records affiliated with this Organization. An affiliation grants no portal access and is not membership. */
    public function people(Request $request, Organization $organization): View
    {
        $this->authorize('manageDirectory', Organization::class);

        $people = $organization->people()
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')
            ->paginate(25);

        return view('organizations.people', [
            'organization' => $organization,
            'header' => OrganizationWorkspacePresenter::header($request->user(), $organization, OrganizationWorkspacePresenter::PEOPLE),
            'people' => $people,
        ]);
    }

    /** Portal access: the state and its lifecycle, members, approvals, partner support, invitations and the managing relationship. */
    public function portal(Request $request, Organization $organization): View
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
        // page gets no relationship data at all, not a hidden section.
        $canManageRelationships = Gate::allows('manageRelationships', Organization::class);
        $relationship = $canManageRelationships ? $this->relationshipPanel($organization) : null;

        // Release 1.0 (R1-3C): the Invitations card holds invitees' addresses and the names of inviters, so it is built only for an actor who
        // may manage membership, like the member administration above. For anyone else the page has no such card at all, not a hidden one.
        $invitations = $canManageMembership ? OrganizationInvitationList::for($organization->getKey()) : null;

        // Release 1.0 (R1-4): support designations of a PARTNER's members, for an actor who may manage membership. Also built for a NON-partner whose memberships carry
        // designation data (a row the application never writes, which the preflight reports), so the operator can reach the Revoke control the preflight points to.
        $support = $canManageMembership && ($organization->can_manage_customers || PartnerSupportGrants::anyDesignationData($organization->getKey()))
            ? PartnerSupportGrants::rowsFor($organization->getKey())
            : null;

        return view('organizations.portal', [
            'organization' => $organization,
            'header' => OrganizationWorkspacePresenter::header($request->user(), $organization, OrganizationWorkspacePresenter::PORTAL),
            'state' => $state,
            'availableUsers' => $availableUsers,
            'relationship' => $relationship,
            'memberships' => $memberships,
            'invitations' => $invitations,
            'support' => $support,
            'canManageMembership' => $canManageMembership,
            'canManageRelationships' => $canManageRelationships,
        ]);
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

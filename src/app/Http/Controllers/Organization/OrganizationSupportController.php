<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationRelationships;
use App\Services\PartnerSupportGrants;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Operator-only: designate partner staff for support access, and disable or re-enable an organization (Release 1.0, R1-4;
 * docs/product/release-1.0-r1-4-partner-support.md). Authorization is the FIRST statement of every action: granting and revoking need
 * OrganizationPolicy::manageMembership, disabling and enabling manageRelationships (each portal.operator AND crm.manage), and the services check
 * again. A customer, a partner administrator and a partner member cannot reach any of it; there is no self-service.
 *
 * The organization and the member are resolved by id, deliberately unscoped (as {@see OrganizationRelationshipController} does), so a tenant
 * scope never stands in for a decision the service takes under lock.
 */
class OrganizationSupportController extends Controller
{
    public function __construct(
        private readonly PartnerSupportGrants $grants,
        private readonly OrganizationRelationships $relationships,
    ) {}

    public function grant(Request $request, int $organizationId, int $userId): RedirectResponse
    {
        $this->authorize('manageMembership', Organization::class);
        $organization = $this->organization($organizationId);
        $member = User::findOrFail($userId);

        $changed = $this->grants->grant($organization, $member, $request->user());

        return $this->back($organization, $changed
            ? "{$member->name} can now read and reply to the requests of \"{$organization->name}\"'s managed customers."
            : "{$member->name} already has support access.");
    }

    public function revoke(Request $request, int $organizationId, int $userId): RedirectResponse
    {
        $this->authorize('manageMembership', Organization::class);
        $organization = $this->organization($organizationId);
        $member = User::findOrFail($userId);

        $changed = $this->grants->revoke($organization, $member, $request->user());

        return $this->back($organization, $changed
            ? "{$member->name}'s support access was revoked."
            : "{$member->name} had no support access.");
    }

    public function disable(Request $request, int $organizationId): RedirectResponse
    {
        $this->authorize('manageRelationships', Organization::class);
        $organization = $this->organization($organizationId);

        $changed = $this->relationships->disable($organization, $request->user());

        return $this->back($organization, $changed
            ? "\"{$organization->name}\" is disabled."
            : "\"{$organization->name}\" is already disabled.");
    }

    public function enable(Request $request, int $organizationId): RedirectResponse
    {
        $this->authorize('manageRelationships', Organization::class);
        $organization = $this->organization($organizationId);

        $changed = $this->relationships->enable($organization, $request->user());

        return $this->back($organization, $changed
            ? "\"{$organization->name}\" is enabled."
            : "\"{$organization->name}\" is not disabled.");
    }

    private function organization(int $id): Organization
    {
        return Organization::withoutGlobalScopes()->findOrFail($id);
    }

    /** Back to the page when this actor can open it (the tenant scope decides), otherwise to the index. */
    private function back(Organization $organization, string $message): RedirectResponse
    {
        $target = Organization::query()->whereKey($organization->getKey())->exists()
            ? route('organizations.show', $organization)
            : route('organizations.index');

        return redirect($target)->with('success', $message);
    }
}

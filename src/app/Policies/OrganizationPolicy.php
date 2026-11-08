<?php

namespace App\Policies;

use App\Models\User;
use App\Shared\Permissions\PermissionCatalogue;

/**
 * Who may change the administrative relationships between organizations (Release 1.0, MO-1A).
 *
 * Both capabilities are required, never either alone, and the account must be active (not suspended):
 *
 *  - `portal.operator`: operator ELIGIBILITY, the explicit designation that exempts an account from the customer boundary
 *    (R0.9-CS1). `crm.manage` is an ordinary permission that a custom role can carry, and an account holding it without
 *    eligibility is a customer;
 *  - `crm.manage`: the Directory capability the organization screens already require.
 *
 * Both are capability checks, never the `operator` role name that the tenant scopes still test, so a custom role that
 * carries the two is a legitimate operator here. Because those scopes still hide organizations from such an account, the
 * relationship operations resolve organizations by id with deliberate, unscoped queries AFTER this check passes, and never
 * through route-model binding. The ability takes no organization: who may manage relationships does not depend on which.
 *
 * Holding the relationship grants nothing: this policy answers who may EDIT it, and no policy elsewhere reads it.
 */
class OrganizationPolicy
{
    public function manageRelationships(User $user): bool
    {
        return $this->operatorWithDirectory($user);
    }

    /**
     * Who may add, promote, demote, remove and approve organization members (Release 1.0, MO-2A1). Operators only, by the same two
     * capabilities as the relationships. Deliberately NOT inputs: the global `org.*` permissions (defined, never checked, and a
     * role-level grant cannot be tied to one organization), `organizations.owner_id` (provenance), the managing-partner
     * relationship, and the actor's own membership or approval in ANY organization. An approved organization administrator has
     * no ability here; customer-side delegation is a later package with its own policy.
     */
    public function manageMembership(User $user): bool
    {
        return $this->operatorWithDirectory($user);
    }

    /**
     * The shared boundary of both abilities: an ACTIVE account holding both capabilities. A suspended operator is denied here, so
     * the rule holds for the service calls made with an explicit actor (`artisan tinker`, a job) as well as for web requests, where
     * ActiveUserProvider already refuses to authenticate a suspended account. Active means what it means everywhere else:
     * {@see User::isSuspended()}, `users.disabled_at`.
     */
    private function operatorWithDirectory(User $user): bool
    {
        return ! $user->isSuspended()
            && $user->can(PermissionCatalogue::PORTAL_OPERATOR)
            && $user->can(PermissionCatalogue::CRM_MANAGE);
    }
}

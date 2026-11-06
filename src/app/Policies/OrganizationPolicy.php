<?php

namespace App\Policies;

use App\Models\User;
use App\Shared\Permissions\PermissionCatalogue;

/**
 * Who may change the administrative relationships between organizations (Release 1.0, MO-1A).
 *
 * Both capabilities are required, never either alone:
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
        return $user->can(PermissionCatalogue::PORTAL_OPERATOR) && $user->can(PermissionCatalogue::CRM_MANAGE);
    }
}

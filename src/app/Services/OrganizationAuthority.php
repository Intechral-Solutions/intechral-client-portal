<?php

namespace App\Services;

use App\Models\User;
use App\Shared\Permissions\BuiltInRoles;
use App\Shared\Permissions\PermissionCatalogue;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one definition of an APPROVED organization administrator (Release 1.0, MO-2A1). Everything that needs to know who
 * counts (the last-administrator guard, approval eligibility, the preflight, and any later package that decides what an
 * approved administrator may do) asks this class. Nothing else may restate the rule.
 *
 * An approved administrator of an organization is a membership row that:
 *
 *  - has `role = admin`;
 *  - has a COMPLETE approval record: both `admin_approved_at` and `admin_approved_by` set (an operator approved THIS membership;
 *    see OrganizationMembership::approve()). A time without an approver, or an approver without a time, is not an approval: it
 *    is unprovable, so it fails closed and counts for nothing until an operator revokes it;
 *  - belongs to an ACTIVE account (`users.disabled_at` is null);
 *  - belongs to an account that does not hold operator eligibility (an operator acts with platform authority, never through
 *    a membership row, so an operator-held row can neither be approved nor count towards "this organization has an
 *    administrator").
 *
 * The inputs are an organization id and a user id and the membership TABLES, nothing else. Deliberately not inputs: Spatie
 * roles and permissions other than the operator exclusion (`org.admin`, `users.admin`, `crm.manage` and the rest decide
 * nothing here), `organizations.owner_id` (provenance only), and the managed-organization relationship (administrative
 * metadata). Reads go straight to the tables, never the permission cache, so they reflect the latest committed state.
 *
 * Being an approved administrator is ELIGIBILITY. No route, policy or screen in this release lets a customer act on it.
 */
final class OrganizationAuthority
{
    public const APPROVAL_NONE = 'none';

    public const APPROVAL_COMPLETE = 'complete';

    /** Exactly one of the two columns is set. Never an approval; always something to revoke. */
    public const APPROVAL_INCOMPLETE = 'incomplete';

    /** The state of one membership's approval record. The only place that decides what "complete" means. */
    public static function approvalRecord(mixed $approvedAt, mixed $approvedBy): string
    {
        return match (true) {
            $approvedAt !== null && $approvedBy !== null => self::APPROVAL_COMPLETE,
            $approvedAt === null && $approvedBy === null => self::APPROVAL_NONE,
            default => self::APPROVAL_INCOMPLETE,
        };
    }

    /**
     * Why a membership cannot be approved, or null when it can. Reasons are stable codes the service turns into messages.
     *
     * @param  object{role: string}  $membership
     * @param  object{disabled_at: mixed}  $user
     */
    public static function approvalRefusal(object $membership, object $user, bool $isOperator): ?string
    {
        return match (true) {
            $membership->role !== 'admin' => 'not_admin',
            $user->disabled_at !== null => 'suspended',
            $isOperator => 'operator',
            default => null,
        };
    }

    /** Ids of the active approved administrators of one organization, ascending. @return Collection<int, int> */
    public static function approvedAdminIds(int $organizationId): Collection
    {
        return self::approvedAdmins()
            ->where('m.organization_id', $organizationId)
            ->orderBy('m.user_id')
            ->pluck('m.user_id')
            ->map(fn ($id) => (int) $id);
    }

    public static function isApprovedAdmin(int $organizationId, int $userId): bool
    {
        return self::approvedAdmins()
            ->where('m.organization_id', $organizationId)
            ->where('m.user_id', $userId)
            ->exists();
    }

    /** The membership rows that count, as a query on `organization_members as m` joined to `users as u`. */
    public static function approvedAdmins(): Builder
    {
        return DB::table('organization_members as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.role', 'admin')
            ->whereNotNull('m.admin_approved_at')
            ->whereNotNull('m.admin_approved_by')
            ->whereNull('u.disabled_at')
            ->whereNotIn('u.id', self::operatorIds());
    }

    /**
     * Ids of the accounts that count as operators: the `portal.operator` capability (eligibility) or the `operator` role (the
     * built-in role's name, which the tenant scopes no longer test, R1-1), directly or through a role. Broader than "holds
     * portal.operator" on purpose: an account that is operator by role name but whose capability has not been synchronized yet
     * is still never an organization administrator. Straight from the tables, never the permission cache.
     *
     * @return list<int>
     */
    public static function operatorIds(): array
    {
        $morph = (new User)->getMorphClass();

        $direct = DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_type', $morph)
            ->where('permissions.name', PermissionCatalogue::PORTAL_OPERATOR)
            ->pluck('model_has_permissions.model_id');

        $viaRole = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->leftJoin('role_has_permissions', 'role_has_permissions.role_id', '=', 'roles.id')
            ->leftJoin('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_type', $morph)
            ->where(fn ($query) => $query->where('roles.name', BuiltInRoles::OPERATOR)->orWhere('permissions.name', PermissionCatalogue::PORTAL_OPERATOR))
            ->pluck('model_has_roles.model_id');

        return $direct->merge($viaRole)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public static function isOperator(int $userId): bool
    {
        return in_array($userId, self::operatorIds(), true);
    }
}

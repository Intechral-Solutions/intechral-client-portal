<?php

namespace App\Services;

use App\Models\User;
use App\Policies\OrganizationPolicy;
use App\Shared\Permissions\BuiltInRoles;
use App\Shared\Permissions\PermissionCatalogue;
use Closure;
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

    /**
     * Whether the account is, RIGHT NOW and from the TABLES ALONE, someone the policy {@see OrganizationPolicy::manageMembership}
     * would admit: ACTIVE (`users.disabled_at` null) and holding BOTH `portal.operator` and `crm.manage`, each directly or through any
     * role (Release 1.0, R1-3A). The invitation flow asks this wherever it decides inside a locked transaction or a queue job: acceptance,
     * the membership seam, the revocation hooks and the notification's send-time check.
     *
     * WHY NOT THE GATE. `can()` goes through the permission cache, and a cache MISS inside an organization-locked transaction writes the
     * cache row on that connection; ServiceRequestAccess runs `can('tickets.create')` inside the ticket transaction BEFORE it takes its own
     * organization lock, so a cache write held under the organization lock here could deadlock against it. A table read takes no lock and
     * writes nothing. It is also never stale: a Gate answer can be old for the cache's lifetime.
     *
     * Deliberately NOT {@see isOperator()}: the policy tests capabilities, never the `operator` role NAME, so this does too. The two are kept
     * equal by a parameterized drift test (OrganizationInvitationAuthorityTest); a change to either side that is not made to both fails it.
     */
    public static function isMembershipManager(int $userId): bool
    {
        return in_array($userId, self::membershipManagerIds([$userId]), true);
    }

    /**
     * The subset of the given accounts for which {@see isMembershipManager()} is true, ascending: one table query, the set version of
     * the predicate (the revocation hooks compare it before and after a mutation).
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public static function membershipManagerIds(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));

        if ($ids === []) {
            return [];
        }

        return DB::table('users')
            ->whereIn('users.id', $ids)
            ->whereNull('users.disabled_at')
            ->where(self::holds(PermissionCatalogue::PORTAL_OPERATOR))
            ->where(self::holds(PermissionCatalogue::CRM_MANAGE))
            ->orderBy('users.id')
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Release 1.0 (R1-5): the subset of the given accounts that may be the REQUESTER of a partner-originated request, ascending, from the TABLES
     * ALONE (no permission cache, no lock, so it is safe inside an organization-locked transaction for the reason {@see isMembershipManager()}
     * gives). A customer contact is an ACTIVE account that is NOT an operator (an operator is staff, never a customer contact: the operator
     * surface, not `/tickets`, is where one works) and holds the capabilities of a customer who could raise and read a request themselves:
     * `tickets.create` and `tickets.view`, each directly or through any role. Whether the contact belongs to the customer organization is a
     * separate, locked fact ({@see PartnerSupportAccess}); nothing here reads membership.
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    public static function customerContactIds(array $userIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));

        if ($ids === []) {
            return [];
        }

        return DB::table('users')
            ->whereIn('users.id', $ids)
            ->whereNull('users.disabled_at')
            ->whereNotIn('users.id', self::operatorIds() ?: [0])
            ->where(self::holds(PermissionCatalogue::TICKETS_CREATE))
            ->where(self::holds(PermissionCatalogue::TICKETS_VIEW))
            ->orderBy('users.id')
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** The account holds the permission directly or through one of its roles (the same reading as AccountAdministrators::holds). */
    private static function holds(string $permission): Closure
    {
        $tables = config('permission.table_names');
        $modelKey = config('permission.column_names.model_morph_key');
        $morphClass = (new User)->getMorphClass();
        $guard = (string) config('auth.defaults.guard');

        return fn (Builder $query) => $query
            ->whereExists(fn (Builder $direct) => $direct->selectRaw('1')
                ->from($tables['model_has_permissions'].' as mhp')
                ->join($tables['permissions'].' as p', 'p.id', '=', 'mhp.permission_id')
                ->whereColumn("mhp.{$modelKey}", 'users.id')
                ->where('mhp.model_type', $morphClass)
                ->where('p.name', $permission)
                ->where('p.guard_name', $guard))
            ->orWhereExists(fn (Builder $viaRole) => $viaRole->selectRaw('1')
                ->from($tables['model_has_roles'].' as mhr')
                ->join($tables['role_has_permissions'].' as rhp', 'rhp.role_id', '=', 'mhr.role_id')
                ->join($tables['permissions'].' as p', 'p.id', '=', 'rhp.permission_id')
                ->whereColumn("mhr.{$modelKey}", 'users.id')
                ->where('mhr.model_type', $morphClass)
                ->where('p.name', $permission)
                ->where('p.guard_name', $guard));
    }
}

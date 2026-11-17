<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The organization memberships of accounts, for System → Users and the user page (Release 1.0, DIR-1). A READ model: it never writes (the
 * only writers of `organization_members` are the membership, support-grant and promotion services), takes no lock, and reads the tables
 * directly rather than `$user->organizations`, whose tenant scope would drop rows for a viewer that is not operator-eligible.
 *
 * It carries MEMBERSHIP facts only: the organization's name and state, the member's role, the approval state and the support designation.
 * It deliberately carries no managing-relationship fact (which organization manages which, a partner flag): those are built only for an
 * actor who passes `manageRelationships` ({@see OrganizationListFacts::relationships()}, DIR-0 invariant S7). The support designation is
 * therefore reported as designated or not, and never as "in effect", because whether it is in effect depends on the organization being a
 * partner. It also carries no Directory fact (no Person, profile or note: DIR-0 invariant S3).
 *
 * Both entry points are bounded: one query for a page of accounts, one for a single account.
 */
final class UserMembershipList
{
    /** Administrator approval, as the user page words it. */
    public const APPROVED = 'approved';

    public const APPROVED_NOT_IN_EFFECT = 'approved_not_in_effect';

    public const NOT_APPROVED = 'not_approved';

    public const INCOMPLETE = 'incomplete';

    /** A member (not an administrator) that still carries an approval record, which is ignored. */
    public const IGNORED = 'ignored';

    public const SUPPORT_DESIGNATED = 'designated';

    public const SUPPORT_INCOMPLETE = 'incomplete';

    /**
     * Memberships for a page of accounts in ONE query, grouped by user id, organizations in name order. Only what a list needs: name, role and
     * whether the organization is disabled.
     *
     * @param  list<int>  $userIds
     * @return Collection<int, Collection<int, object>>
     */
    public static function forUsers(array $userIds): Collection
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));

        if ($userIds === []) {
            return collect();
        }

        return DB::table('organization_members as m')
            ->join('organizations as o', 'o.id', '=', 'm.organization_id')
            ->whereIn('m.user_id', $userIds)
            ->orderBy('o.name')->orderBy('o.id')
            ->get(['m.user_id', 'o.id as organization_id', 'o.name', 'o.disabled_at as organization_disabled_at', 'm.role'])
            ->map(function (object $row): object {
                $row->user_id = (int) $row->user_id;
                $row->organization_id = (int) $row->organization_id;
                $row->organization_disabled = $row->organization_disabled_at !== null;

                return $row;
            })
            ->groupBy('user_id');
    }

    /**
     * Every membership of one account with the states the user page words: approval, support designation and whether the organization is
     * disabled. An approval is "in effect" exactly when {@see UserMembershipList::inEffect()} says so, the same rule the organization page
     * applies to its member rows (an approved administrator that is active and not an operator).
     *
     * @return Collection<int, object>
     */
    public static function forUser(User $user): Collection
    {
        $operator = OrganizationAuthority::isOperator((int) $user->getKey());
        $suspended = $user->isSuspended();

        return DB::table('organization_members as m')
            ->join('organizations as o', 'o.id', '=', 'm.organization_id')
            ->where('m.user_id', $user->getKey())
            ->orderBy('o.name')->orderBy('o.id')
            ->get([
                'o.id as organization_id', 'o.name', 'o.disabled_at as organization_disabled_at', 'm.role',
                'm.admin_approved_at', 'm.admin_approved_by', 'm.support_granted_at', 'm.support_granted_by',
            ])
            ->map(function (object $row) use ($operator, $suspended): object {
                $record = OrganizationAuthority::approvalRecord($row->admin_approved_at, $row->admin_approved_by);

                $row->organization_id = (int) $row->organization_id;
                $row->organization_disabled = $row->organization_disabled_at !== null;
                $row->approval = match (true) {
                    $row->role === 'admin' && $record === OrganizationAuthority::APPROVAL_COMPLETE => self::inEffect($suspended, $operator) ? self::APPROVED : self::APPROVED_NOT_IN_EFFECT,
                    $row->role === 'admin' && $record === OrganizationAuthority::APPROVAL_INCOMPLETE => self::INCOMPLETE,
                    $row->role === 'admin' => self::NOT_APPROVED,
                    $record !== OrganizationAuthority::APPROVAL_NONE => self::IGNORED,
                    default => null,
                };
                $row->support = match (true) {
                    $row->support_granted_at !== null && $row->support_granted_by !== null => self::SUPPORT_DESIGNATED,
                    $row->support_granted_at !== null || $row->support_granted_by !== null => self::SUPPORT_INCOMPLETE,
                    default => null,
                };

                return $row;
            });
    }

    /** An approval counts only for an account that is active and not an operator ({@see OrganizationMembership::rows()}). */
    private static function inEffect(bool $suspended, bool $operator): bool
    {
        return ! $suspended && ! $operator;
    }
}

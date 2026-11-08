<?php

namespace App\Services;

use App\Exceptions\OrganizationMembershipException;
use App\Models\Organization;
use App\Models\User;
use App\Policies\OrganizationPolicy;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Operator-side organization membership (Release 1.0, MO-2A1): add, promote, demote, remove, and the explicit approval of an
 * administrator. The only writer of `organization_members` outside the promotion of a company (which attaches its promoting
 * operator and is unchanged) and the tests' own fixtures.
 *
 * ROLE IS NOT APPROVAL. `role = admin` grants nothing and never did. An administrator becomes an APPROVED administrator
 * only through approve(), an explicit operator act that stamps who and when; promoting never approves, and demoting always
 * clears the approval, so a demote-then-promote cannot revive an old one. What "approved" means, and who counts, is defined in
 * one place: {@see OrganizationAuthority}. Nothing here lets a customer do anything: every method needs the policy
 * {@see OrganizationPolicy::manageMembership} (portal.operator AND crm.manage), checked here as well as by the
 * controller.
 *
 * Every mutation is one transaction that takes the ORGANIZATION ROW `FOR UPDATE` first. That row is the single serialization
 * point for an organization's membership: every membership mutation takes it before reading or writing anything else, in the
 * same order the managed-organization relationship follows (organizations before companies, before memberships). The membership
 * row concerned is then read under its own lock, and every decision is made on the locked, current rows, never on a model the
 * caller loaded earlier. Reads of users and permissions go to the tables, not the permission cache.
 *
 * LAST APPROVED ADMINISTRATOR. An ordinary demotion or removal that would take an organization from at least one active approved
 * administrator to none is refused and rolled back (the same before-and-after count AccountAdministrators::guard uses), whoever
 * asks. An organization with none is a valid state (every organization starts there) and stays operator-managed. Only the
 * separate, explicit revokeApproval() with `$confirmNoAdministrator` may leave none; it is audited under its own event name.
 * Suspending an account is never blocked by this (offboarding must stay possible); the check reads the user's state as of the
 * transaction's first read, so a suspension committed at that very moment is the one race it does not close (an operator
 * recovers by approving another administrator).
 *
 * Every change is recorded in the activity log in the same transaction, with the actor and the previous and new values.
 *
 * CALL EACH MUTATION AT THE TOP LEVEL of a request or command, never inside a transaction that has already read these tables (the
 * same rule as AccountAdministrators::guard). The organization lock is only the first statement of a transaction this class opens;
 * inside an outer transaction the caller's earlier reads are an older snapshot that the lock does not refresh, so the
 * last-administrator check could decide on stale rows, and the retry on a deadlock would no longer re-run the whole unit.
 *
 * AN APPROVAL IS ONE RECORD OF TWO COLUMNS. Only a COMPLETE record (time AND approver) is an approval; a half-written one counts
 * for nothing, approve() refuses to treat it as approved, and revokeApproval() clears it ({@see OrganizationAuthority::approvalRecord()}).
 * The previous release can write neither column, so a half-written record is damage to investigate, not a rollback effect.
 */
final class OrganizationMembership
{
    public const ADDED = 'organization member added';

    public const PROMOTED = 'organization member promoted to administrator (not approved)';

    public const DEMOTED = 'organization administrator demoted to member';

    public const REMOVED = 'organization member removed';

    public const APPROVED = 'organization administrator approved';

    public const APPROVAL_REVOKED = 'organization administrator approval revoked';

    public const APPROVAL_REVOKED_LAST = 'organization administrator approval revoked (no approved administrator remains)';

    private const ATTEMPTS = 3;

    // ── Mutations ─────────────────────────────────────────

    /**
     * Add an ACTIVE account as a member. Refuses an existing member without touching them: adding never changes a role (the old
     * `syncWithoutDetaching` rewrote it, silently demoting an administrator). A role of `admin` creates an administrator row that
     * is NOT approved.
     */
    public function add(Organization $organization, User $user, string $role, User $actor): void
    {
        $this->authorize($actor);

        if (! in_array($role, ['member', 'admin'], true)) {
            throw new InvalidArgumentException("Unknown organization role \"{$role}\".");
        }

        $this->transact($organization, function (object $org) use ($user, $role, $actor, $organization): void {
            $account = $this->account($user);

            if ($account->disabled_at !== null) {
                throw OrganizationMembershipException::suspended($account->name);
            }

            if ($this->membership($org->id, $account->id) !== null) {
                throw OrganizationMembershipException::alreadyMember($account->name, $org->name);
            }

            try {
                DB::table('organization_members')->insert([
                    'organization_id' => $org->id,
                    'user_id' => $account->id,
                    'role' => $role,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw OrganizationMembershipException::alreadyMember($account->name, $org->name);
                }

                throw $e;
            }

            $this->audit($actor, $organization, self::ADDED, $account, ['old' => null, 'new' => ['role' => $role, 'approved' => false]]);
        });
    }

    /**
     * Set the administrator role. Never approves and never restores an approval; an approval record left on the member row (by
     * older code, which demoted without clearing it) is cleared and audited with its actual previous values. Returns false when the
     * member already was an administrator (nothing changed). `$clearedApproval` is set to whether approval data was actually cleared.
     */
    public function promote(Organization $organization, User $user, User $actor, ?bool &$clearedApproval = null): bool
    {
        $this->authorize($actor);
        $clearedApproval = false;

        return $this->transact($organization, function (object $org) use ($user, $actor, $organization, &$clearedApproval): bool {
            $account = $this->account($user);
            $membership = $this->requireMembership($org, $account);

            if ($membership->role === 'admin') {
                return false;
            }

            $this->setRole($org->id, $account->id, 'admin');
            $clearedApproval = $this->hasApprovalData($membership);
            $this->audit($actor, $organization, self::PROMOTED, $account, [
                'old' => ['role' => 'member'] + $this->approvalState($membership),
                'new' => ['role' => 'admin', 'approved' => false],
            ]);

            return true;
        });
    }

    /**
     * Set the member role and clear any approval in the same statement. Refused when it would leave no approved administrator.
     * Returns false when the member was not an administrator (nothing changed, including any stale approval record on the row:
     * revokeApproval() clears that). `$clearedApproval` is set to whether approval data was actually cleared.
     */
    public function demote(Organization $organization, User $user, User $actor, ?bool &$clearedApproval = null): bool
    {
        $this->authorize($actor);
        $clearedApproval = false;

        return $this->transact($organization, function (object $org) use ($user, $actor, $organization, &$clearedApproval): bool {
            $account = $this->account($user);
            $membership = $this->requireMembership($org, $account);

            if ($membership->role !== 'admin') {
                return false;
            }

            $this->guarded($org, function () use ($org, $account, $actor, $organization, $membership): void {
                $this->setRole($org->id, $account->id, 'member');
                $this->audit($actor, $organization, self::DEMOTED, $account, [
                    'old' => ['role' => 'admin'] + $this->approvalState($membership),
                    'new' => ['role' => 'member', 'approved' => false],
                ]);
            });
            $clearedApproval = $this->hasApprovalData($membership);

            return true;
        });
    }

    /** Remove the membership only: the account is neither suspended nor deleted. Refused when it would leave no approved administrator. */
    public function remove(Organization $organization, User $user, User $actor): void
    {
        $this->authorize($actor);

        $this->transact($organization, function (object $org) use ($user, $actor, $organization): void {
            $account = $this->account($user);
            $membership = $this->requireMembership($org, $account);

            $this->guarded($org, function () use ($org, $account, $actor, $organization, $membership): void {
                DB::table('organization_members')->where('organization_id', $org->id)->where('user_id', $account->id)->delete();
                $this->audit($actor, $organization, self::REMOVED, $account, [
                    'old' => ['role' => $membership->role] + $this->approvalState($membership),
                    'new' => null,
                ]);
            });
        });
    }

    /**
     * Approve this administrator, stamping who and when. Needs an ACTIVE account with `role = admin` that holds no operator
     * eligibility. Never done automatically: a historical administrator row stays unapproved until an operator calls this for
     * that exact membership. Returns false when it already was approved (its original stamp is kept). A half-written approval record
     * (a time without an approver, or the reverse) is NOT "already approved": it is refused, and the operator must revoke it first.
     * Both columns and `updated_at` get the same instant, so a later membership change is always detectable against the stamp
     * (the preflight relies on that).
     */
    public function approve(Organization $organization, User $user, User $actor): bool
    {
        $this->authorize($actor);

        return $this->transact($organization, function (object $org) use ($user, $actor, $organization): bool {
            $account = $this->account($user);
            $membership = $this->requireMembership($org, $account);

            match (OrganizationAuthority::approvalRefusal($membership, $account, OrganizationAuthority::isOperator($account->id))) {
                'not_admin' => throw OrganizationMembershipException::notAdminRole($account->name),
                'suspended' => throw OrganizationMembershipException::suspended($account->name),
                'operator' => throw OrganizationMembershipException::operatorAccount($account->name),
                default => null,
            };

            switch (OrganizationAuthority::approvalRecord($membership->admin_approved_at, $membership->admin_approved_by)) {
                case OrganizationAuthority::APPROVAL_COMPLETE:
                    return false;
                case OrganizationAuthority::APPROVAL_INCOMPLETE:
                    throw OrganizationMembershipException::incompleteApproval($account->name);
            }

            $approvedAt = now();
            DB::table('organization_members')->where('organization_id', $org->id)->where('user_id', $account->id)->update([
                'admin_approved_at' => $approvedAt,
                'admin_approved_by' => $actor->getKey(),
                'updated_at' => $approvedAt,
            ]);
            $this->audit($actor, $organization, self::APPROVED, $account, [
                'old' => ['approved' => false],
                'new' => ['approved' => true, 'approved_by' => $actor->getKey(), 'approved_at' => $approvedAt->toIso8601String()],
            ]);

            return true;
        });
    }

    /**
     * The exceptional, operator-only way to take authority away: clear the approval and keep the membership and role. It may leave an
     * organization with NO approved administrator, but only when `$confirmNoAdministrator` says the operator means that; otherwise it
     * is refused and nothing changes. It is the one path past the last-administrator rule and is audited under its own event name
     * when it leaves none. Works on a membership whose approval record is complete OR half-written (either column set): that is the
     * corrective path for a record that does not count. Returns false when neither column was set.
     */
    public function revokeApproval(Organization $organization, User $user, User $actor, bool $confirmNoAdministrator = false): bool
    {
        $this->authorize($actor);

        return $this->transact($organization, function (object $org) use ($user, $actor, $organization, $confirmNoAdministrator): bool {
            $account = $this->account($user);
            $membership = $this->requireMembership($org, $account);

            if (! $this->hasApprovalData($membership)) {
                return false;
            }

            $leavesNone = OrganizationAuthority::approvedAdminIds($org->id)->all() === [$account->id];

            if ($leavesNone && ! $confirmNoAdministrator) {
                throw OrganizationMembershipException::lastApprovalUnconfirmed($org->name);
            }

            DB::table('organization_members')->where('organization_id', $org->id)->where('user_id', $account->id)->update([
                'admin_approved_at' => null,
                'admin_approved_by' => null,
                'updated_at' => now(),
            ]);
            $this->audit($actor, $organization, $leavesNone ? self::APPROVAL_REVOKED_LAST : self::APPROVAL_REVOKED, $account, [
                'old' => $this->approvalState($membership),
                'new' => ['approved' => false],
                'leaves_no_approved_administrator' => $leavesNone,
            ]);

            return true;
        });
    }

    // ── Reads (for the operator screen; call only for an actor who passed OrganizationPolicy::manageMembership) ──

    /**
     * The organization's memberships with everything the operator screen shows about authority: role, approval and its provenance,
     * whether the account is active, whether it is an operator, why it cannot be approved (null when it can), and whether the
     * row currently counts as an approved administrator.
     *
     * @return Collection<int, object>
     */
    public static function rows(int $organizationId): Collection
    {
        $operators = OrganizationAuthority::operatorIds();

        return DB::table('organization_members as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('users as approver', 'approver.id', '=', 'm.admin_approved_by')
            ->where('m.organization_id', $organizationId)
            ->orderBy('u.name')->orderBy('u.id')
            ->get(['u.id', 'u.name', 'u.email', 'u.disabled_at', 'm.role', 'm.admin_approved_at', 'm.admin_approved_by', 'approver.name as approver_name'])
            ->map(function (object $row) use ($operators): object {
                $record = OrganizationAuthority::approvalRecord($row->admin_approved_at, $row->admin_approved_by);
                $row->id = (int) $row->id;
                $row->operator = in_array($row->id, $operators, true);
                $row->active = $row->disabled_at === null;
                $row->approved = $record === OrganizationAuthority::APPROVAL_COMPLETE;
                $row->incomplete = $record === OrganizationAuthority::APPROVAL_INCOMPLETE;
                $row->hasApprovalData = $record !== OrganizationAuthority::APPROVAL_NONE;
                $row->refusal = OrganizationAuthority::approvalRefusal($row, $row, $row->operator);
                $row->counts = $row->role === 'admin' && $row->approved && $row->active && ! $row->operator;

                return $row;
            });
    }

    // ── Internals ─────────────────────────────────────────

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize('manageMembership', Organization::class);
    }

    /**
     * One transaction whose FIRST statement locks the organization row. A missing organization is a 404: it went away between the
     * request and the lock.
     *
     * @template T
     *
     * @param  Closure(object): T  $work
     * @return T
     */
    private function transact(Organization $organization, Closure $work): mixed
    {
        return DB::transaction(function () use ($organization, $work) {
            $org = DB::table('organizations')->where('id', $organization->getKey())->lockForUpdate()->first(['id', 'name']);

            abort_if($org === null, 404);
            $org->id = (int) $org->id;

            return $work($org);
        }, self::ATTEMPTS);
    }

    /**
     * Run a mutation that could remove an approved administrator, and refuse it (rolling it back) if there was one before and none
     * is left after. Called with the organization row already locked.
     */
    private function guarded(object $org, Closure $mutation): void
    {
        $before = OrganizationAuthority::approvedAdminIds($org->id)->isNotEmpty();

        $mutation();

        if ($before && OrganizationAuthority::approvedAdminIds($org->id)->isEmpty()) {
            throw OrganizationMembershipException::lastApprovedAdmin($org->name);
        }
    }

    /** The account, read from the table (no scope, no cache). 404 when it does not exist. */
    private function account(User $user): object
    {
        $row = DB::table('users')->where('id', $user->getKey())->first(['id', 'name', 'disabled_at']);

        abort_if($row === null, 404);
        $row->id = (int) $row->id;

        return $row;
    }

    /** The membership row under its own lock, or null. */
    private function membership(int $organizationId, int $userId): ?object
    {
        return DB::table('organization_members')
            ->where('organization_id', $organizationId)->where('user_id', $userId)
            ->lockForUpdate()->first();
    }

    private function requireMembership(object $org, object $account): object
    {
        return $this->membership($org->id, $account->id)
            ?? throw OrganizationMembershipException::notMember($account->name, $org->name);
    }

    /** Change the role and clear the approval in ONE statement: a role change can never leave an approval behind. */
    private function setRole(int $organizationId, int $userId, string $role): void
    {
        DB::table('organization_members')->where('organization_id', $organizationId)->where('user_id', $userId)->update([
            'role' => $role,
            'admin_approved_at' => null,
            'admin_approved_by' => null,
            'updated_at' => now(),
        ]);
    }

    private function hasApprovalData(object $membership): bool
    {
        return $membership->admin_approved_at !== null || $membership->admin_approved_by !== null;
    }

    /**
     * What the audit records about a membership's approval as it actually was: whether it was a complete approval, and the raw
     * previous approver and time (null when unset), so a cleared stale or half-written record is never reported as "not approved".
     *
     * @return array{approved: bool, approved_by: mixed, approved_at: mixed}
     */
    private function approvalState(object $membership): array
    {
        return [
            'approved' => OrganizationAuthority::approvalRecord($membership->admin_approved_at, $membership->admin_approved_by) === OrganizationAuthority::APPROVAL_COMPLETE,
            'approved_by' => $membership->admin_approved_by,
            'approved_at' => $membership->admin_approved_at,
        ];
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(User $actor, Organization $organization, string $event, object $account, array $properties): void
    {
        activity()->causedBy($actor)->performedOn($organization)
            ->withProperties(['user_id' => $account->id, 'member_name' => $account->name] + $properties)
            ->log($event);
    }
}

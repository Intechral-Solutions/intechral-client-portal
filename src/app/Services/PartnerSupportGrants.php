<?php

namespace App\Services;

use App\Exceptions\PartnerSupportException;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Who is designated for partner support (Release 1.0, R1-4; docs/product/release-1.0-r1-4-partner-support.md). Writes `support_granted_at`
 * and `support_granted_by` on one `organization_members` row: the designation of one MEMBER of a PARTNER by one OPERATOR. What that
 * designation is worth at any moment (the account, the partner, the customer and the ticket must all qualify) is decided only by
 * {@see PartnerSupportAccess}, from the tables, on every request.
 *
 * Granting and revoking need {@see OrganizationPolicy::manageMembership} (portal.operator AND crm.manage), checked here as well as by the
 * controller, so the service calls made with an explicit actor (a job, tinker) are held to it too. A partner relationship alone grants nothing, the
 * membership role and administrator approval are not inputs, and there is no self-service: a customer cannot give or take this authority.
 *
 * LOCK ORDER is the repository's canonical one, user (shared) -> organization (exclusive) -> membership (exclusive), the order
 * {@see OrganizationInvitations} uses. The user is locked first because the grant refuses a suspended account, and a suspension holds the user row
 * exclusively before it clears grants ({@see revokeHeldBy()}); taking the user after the membership would let the two deadlock.
 *
 * Authority also ends WITHOUT this class being asked: removing a membership deletes the row, a suspension calls {@see revokeHeldBy()} in its own
 * transaction (so a reactivation cannot revive it), and removing a partner designation calls {@see revokeForPartner()} in its own (so a
 * re-designation cannot). Becoming an operator, a disabled organization and an unlinked customer end it through {@see PartnerSupportAccess} alone.
 */
final class PartnerSupportGrants
{
    public const GRANTED = 'partner support access granted';

    public const REVOKED = 'partner support access revoked';

    public const REVOKED_SUSPENSION = 'partner support access revoked (account suspended)';

    public const REVOKED_PARTNER_REMOVED = 'partner support access revoked (partner designation removed)';

    private const ATTEMPTS = 3;

    /** Designate this member. Returns false when they already hold a complete grant (the original stamp is kept). */
    public function grant(Organization $partner, User $member, User $actor): bool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($partner, $member, $actor): bool {
            // The ACTOR's row and the MEMBER's row, shared, in ascending id order, first: `support_granted_by` is a foreign key to the actor, which InnoDB
            // share-locks at the UPDATE, so taking the actor here keeps the repository's user-first order (OrganizationInvitations) and avoids a late lock.
            $users = DB::table('users')->whereIn('id', array_unique([(int) $actor->getKey(), (int) $member->getKey()]))->orderBy('id')->sharedLock()->get(['id', 'name', 'disabled_at'])->keyBy('id');
            $account = $users->get((int) $member->getKey());
            abort_if($account === null, 404);

            $org = DB::table('organizations')->where('id', $partner->getKey())->lockForUpdate()->first(['id', 'name', 'can_manage_customers', 'disabled_at']);
            abort_if($org === null, 404);

            $membership = DB::table('organization_members')
                ->where('organization_id', $org->id)->where('user_id', $account->id)
                ->lockForUpdate()->first();

            if (! $org->can_manage_customers) {
                throw PartnerSupportException::notPartner($org->name);
            }

            if ($org->disabled_at !== null) {
                throw PartnerSupportException::organizationDisabled($org->name);
            }

            if ($membership === null) {
                throw PartnerSupportException::notMember($account->name, $org->name);
            }

            if ($account->disabled_at !== null) {
                throw PartnerSupportException::suspended($account->name);
            }

            if (OrganizationAuthority::isOperator((int) $account->id)) {
                throw PartnerSupportException::operatorAccount($account->name);
            }

            if ($membership->support_granted_at !== null && $membership->support_granted_by !== null) {
                return false;
            }

            $at = now();
            DB::table('organization_members')->where('organization_id', $org->id)->where('user_id', $account->id)->update([
                'support_granted_at' => $at,
                'support_granted_by' => $actor->getKey(),
                'updated_at' => $at,
            ]);

            activity()->causedBy($actor)->performedOn($partner)->withProperties([
                'user_id' => (int) $account->id,
                'member_name' => $account->name,
                'old' => $this->state($membership),
                'new' => ['granted' => true, 'granted_by' => $actor->getKey(), 'granted_at' => $at->toIso8601String()],
            ])->log(self::GRANTED);

            return true;
        }, self::ATTEMPTS);
    }

    /**
     * Take the designation away. Always allowed (a suspended partner, a disabled organization and an operator account included), idempotent:
     * returns false when there was nothing to clear. A half-written pair is cleared too.
     */
    public function revoke(Organization $partner, User $member, User $actor): bool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($partner, $member, $actor): bool {
            $org = DB::table('organizations')->where('id', $partner->getKey())->lockForUpdate()->first(['id']);
            abort_if($org === null, 404);

            $membership = DB::table('organization_members')
                ->where('organization_id', $org->id)->where('user_id', $member->getKey())
                ->lockForUpdate()->first();

            if ($membership === null || ! $this->hasData($membership)) {
                return false;
            }

            $this->clear((int) $org->id, (int) $member->getKey());

            activity()->causedBy($actor)->performedOn($partner)->withProperties([
                'user_id' => (int) $member->getKey(),
                'member_name' => (string) DB::table('users')->where('id', $member->getKey())->value('name'),
                'old' => $this->state($membership),
                'new' => ['granted' => false],
            ])->log(self::REVOKED);

            return true;
        }, self::ATTEMPTS);
    }

    /**
     * INTERNAL to the suspension ({@see AccountLifecycleService}): clear every designation this account holds, in the caller's transaction, which
     * already holds the user row exclusively. It takes no organization lock (the user row is the serialization point against a grant, which locks the
     * user first) and writes only the two grant columns of the rows concerned. Returns how many designations were cleared.
     */
    public function revokeHeldBy(int $userId, ?User $actor): int
    {
        $rows = DB::table('organization_members')
            ->where('user_id', $userId)
            ->where(fn ($query) => $query->whereNotNull('support_granted_at')->orWhereNotNull('support_granted_by'))
            ->orderBy('organization_id')->lockForUpdate()->get();

        foreach ($rows as $row) {
            $this->clear((int) $row->organization_id, $userId);
            $this->audit($actor, (int) $row->organization_id, $userId, $row, self::REVOKED_SUSPENSION);
        }

        return $rows->count();
    }

    /**
     * INTERNAL to {@see OrganizationRelationships::removePartner()}: clear every designation on this partner's memberships, in the caller's
     * transaction, which already holds the partner's organization row exclusively. Returns how many were cleared.
     */
    public function revokeForPartner(int $partnerId, User $actor): int
    {
        $rows = DB::table('organization_members')
            ->where('organization_id', $partnerId)
            ->where(fn ($query) => $query->whereNotNull('support_granted_at')->orWhereNotNull('support_granted_by'))
            ->orderBy('user_id')->lockForUpdate()->get();

        foreach ($rows as $row) {
            $this->clear($partnerId, (int) $row->user_id);
            $this->audit($actor, $partnerId, (int) $row->user_id, $row, self::REVOKED_PARTNER_REMOVED);
        }

        return $rows->count();
    }

    /**
     * The operator screen's view of a partner's members: whether each holds a designation, who gave it and when, and, for a complete one, whether it
     * is in effect now and if not why. Call only for an actor who passed {@see OrganizationPolicy::manageMembership}.
     *
     * @return Collection<int, object> keyed by user id
     */
    public static function rowsFor(int $partnerId): Collection
    {
        $partner = DB::table('organizations')->where('id', $partnerId)->first(['can_manage_customers', 'disabled_at']);
        $operators = OrganizationAuthority::operatorIds();

        return DB::table('organization_members as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('users as g', 'g.id', '=', 'm.support_granted_by')
            ->where('m.organization_id', $partnerId)
            ->get(['m.user_id', 'm.support_granted_at', 'm.support_granted_by', 'g.name as granted_by_name', 'u.disabled_at'])
            ->mapWithKeys(function (object $row) use ($partner, $operators): array {
                $complete = $row->support_granted_at !== null && $row->support_granted_by !== null;
                $any = $row->support_granted_at !== null || $row->support_granted_by !== null;
                $why = match (true) {
                    $partner === null || ! $partner->can_manage_customers => 'the organization is not a partner',
                    $partner->disabled_at !== null => 'the organization is disabled',
                    $row->disabled_at !== null => 'the account is suspended',
                    in_array((int) $row->user_id, $operators, true) => 'the account is an operator',
                    default => null,
                };

                return [(int) $row->user_id => (object) [
                    'granted' => $complete,
                    'incomplete' => $any && ! $complete,
                    'at' => $row->support_granted_at,
                    'by' => $row->granted_by_name,
                    'inEffect' => $complete && $why === null,
                    'notInEffectBecause' => $complete ? $why : null,
                ]];
            });
    }

    /** Whether any membership of the organization carries (even half-written) designation data. */
    public static function anyDesignationData(int $organizationId): bool
    {
        return DB::table('organization_members')->where('organization_id', $organizationId)
            ->where(fn ($query) => $query->whereNotNull('support_granted_at')->orWhereNotNull('support_granted_by'))
            ->exists();
    }

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize('manageMembership', Organization::class);
    }

    private function clear(int $organizationId, int $userId): void
    {
        DB::table('organization_members')->where('organization_id', $organizationId)->where('user_id', $userId)->update([
            'support_granted_at' => null,
            'support_granted_by' => null,
            'updated_at' => now(),
        ]);
    }

    private function hasData(object $membership): bool
    {
        return $membership->support_granted_at !== null || $membership->support_granted_by !== null;
    }

    /** @return array{granted: bool, granted_by: mixed, granted_at: mixed} what the audit records of a membership's designation as it actually was */
    private function state(object $membership): array
    {
        return [
            'granted' => $membership->support_granted_at !== null && $membership->support_granted_by !== null,
            'granted_by' => $membership->support_granted_by,
            'granted_at' => $membership->support_granted_at,
        ];
    }

    private function audit(?User $actor, int $organizationId, int $userId, object $before, string $event): void
    {
        $organization = Organization::withoutGlobalScopes()->find($organizationId);

        if ($organization === null) {
            return;
        }

        $logger = activity()->performedOn($organization);

        if ($actor !== null) {
            $logger->causedBy($actor);
        }

        $logger->withProperties([
            'user_id' => $userId,
            'member_name' => (string) DB::table('users')->where('id', $userId)->value('name'),
            'old' => $this->state($before),
            'new' => ['granted' => false],
        ])->log($event);
    }
}

<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use App\Policies\TicketPolicy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The one definition of EFFECTIVE PARTNER SUPPORT AUTHORITY (Release 1.0, R1-4; docs/product/release-1.0-r1-4-partner-support.md).
 *
 * Actor `S` may support a Ticket `T` only when ALL of these hold, read from the tables at the moment of asking:
 *
 *  1. `S`'s membership of a partner organization `P` carries a COMPLETE support grant (`support_granted_at` AND `support_granted_by`;
 *     an operator designated THIS membership, see {@see PartnerSupportGrants}). A half-written pair is not a grant: it fails closed;
 *  2. `S` is active (`users.disabled_at` null) and is NOT an operator (an operator works from the operator surface, with platform
 *     authority, never through a membership row);
 *  3. `P` is still a partner (`can_manage_customers`) and is not disabled (`organizations.disabled_at`);
 *  4. the ticket's organization `C` is managed by `P` RIGHT NOW (`C.managed_by_organization_id = P.id`), is not disabled, and is the
 *     ticket's own organization (`tickets.organization_id`).
 *
 * Nothing else is an input. Deliberately NOT: the membership ROLE (admin or member), administrator APPROVAL, Spatie roles and permissions
 * other than the operator exclusion, the permission cache, CRM companies, `organizations.owner_id`. A partner relationship alone grants
 * nothing; a partner member without a grant, a member of the customer organization, the requester, an operator and a member of an unrelated
 * organization all fail. A Ticket of `P` itself is never supported by `P`'s staff (a partner is never its own customer: a partner cannot be
 * managed and an organization cannot manage itself).
 *
 * Every read goes straight to the tables (`DB::table`), unscoped. The tenant scopes hide organizations from any account that is not
 * operator-eligible, so a scoped read would let the actor's own tenant filter decide this rule. Nothing is cached, so authority ends on
 * the next request after the row that carries it changes.
 *
 * TWO READS OF ONE RULE. {@see allows()} and {@see supportedOrganizations()} are a single join, for request-time authorization and for
 * the list. {@see assertLocked()} is the same rule as step-by-step LOCKING reads, for the reply transaction, where a revocation that has
 * committed must be seen and one that has not must make the reply wait. They are kept equal by a parameterized drift test
 * (PartnerSupportAccessTest); a change to the rule must be made to both.
 *
 * Reached through {@see TicketPolicy::viewAsPartnerSupport()} and its siblings, never through `TicketPolicy::view`.
 */
final class PartnerSupportAccess
{
    /** A grant that is effective for NO customer in particular: the actor may open the support list (which may then be empty). */
    public static function holdsGrant(User|int $actor): bool
    {
        return self::grantQuery(self::id($actor))->exists() && ! OrganizationAuthority::isOperator(self::id($actor));
    }

    /** Effective authority over this Ticket (the unlocked read). */
    public static function allows(User|int $actor, Ticket $ticket): bool
    {
        return self::allowsOrganization(self::id($actor), (int) $ticket->organization_id);
    }

    public static function allowsOrganization(int $actorId, int $organizationId): bool
    {
        return self::supportedOrganizations($actorId)->where('c.id', $organizationId)->exists()
            && ! OrganizationAuthority::isOperator($actorId);
    }

    /**
     * The customer organizations the actor supports right now, as a query selecting `c.id` and `c.name`, for the list's subselect.
     * An operator account yields none (see {@see allows()}): callers that list must also test {@see holdsGrant()}.
     */
    public static function supportedOrganizations(int $actorId): Builder
    {
        return self::grantQuery($actorId)
            ->join('organizations as c', 'c.managed_by_organization_id', '=', 'p.id')
            ->whereColumn('c.id', '!=', 'p.id') // no CHECK can stop self-management (MO-1A); the rule must not depend on the service having refused it
            ->whereNull('c.disabled_at')
            ->select(['c.id', 'c.name']);
    }

    /** `organization_members m` (with a complete grant) joined to an active user and an enabled partner `p`. */
    private static function grantQuery(int $actorId): Builder
    {
        return DB::table('organization_members as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->join('organizations as p', 'p.id', '=', 'm.organization_id')
            ->where('m.user_id', $actorId)
            ->whereNotNull('m.support_granted_at')
            ->whereNotNull('m.support_granted_by')
            ->whereNull('u.disabled_at')
            ->where('p.can_manage_customers', true)
            ->whereNull('p.disabled_at');
    }

    /**
     * The same rule, as locking reads, for the reply transaction. MUST run inside the transaction that already holds the ticket row
     * (`FOR UPDATE`), and takes its own locks in the order user (S) -> the customer and partner organization rows together, ascending id (S) -> membership (S).
     * Returns false when authority is absent; the caller refuses, rolling the transaction back.
     *
     * Shared locks are enough: a revocation, a suspension, a disable or a relationship change needs an exclusive lock on one of these rows, so it
     * either committed before (and is seen: a locking read returns the latest committed row, not the transaction's snapshot) or waits for this
     * transaction to finish.
     */
    public static function assertLocked(int $actorId, int $organizationId): bool
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('PartnerSupportAccess::assertLocked() runs only inside the reply transaction.');
        }

        $user = DB::table('users')->where('id', $actorId)->sharedLock()->first(['id', 'disabled_at']);

        if ($user === null || $user->disabled_at !== null) {
            return false;
        }

        // The partner is NOMINATED by a plain read, then BOTH organization rows are locked in ONE statement in ascending id order, the order
        // OrganizationRelationships::lock() takes its exclusive locks in, so an operator re-submitting the link can never close a cycle with this reply
        // (opposite orders were a real deadlock, found by the R1-4 design challenge). What the locks then show decides; a relationship that moved
        // between the nomination and the locks fails closed.
        $nominated = DB::table('organizations')->where('id', $organizationId)->value('managed_by_organization_id');

        if ($nominated === null || (int) $nominated === $organizationId) {
            return false;
        }

        $rows = DB::table('organizations')->whereIn('id', [$organizationId, (int) $nominated])->orderBy('id')->sharedLock()
            ->get(['id', 'managed_by_organization_id', 'can_manage_customers', 'disabled_at'])->keyBy('id');
        $customer = $rows->get($organizationId);
        $partner = $rows->get((int) $nominated);

        if ($customer === null || $partner === null
            || $customer->disabled_at !== null || (int) $customer->managed_by_organization_id !== (int) $partner->id
            || ! $partner->can_manage_customers || $partner->disabled_at !== null) {
            return false;
        }

        $membership = DB::table('organization_members')
            ->where('organization_id', $partner->id)->where('user_id', $actorId)
            ->sharedLock()->first(['support_granted_at', 'support_granted_by']);

        if ($membership === null || $membership->support_granted_at === null || $membership->support_granted_by === null) {
            return false;
        }

        return ! OrganizationAuthority::isOperator($actorId);
    }

    private static function id(User|int $actor): int
    {
        return $actor instanceof User ? (int) $actor->getKey() : $actor;
    }
}

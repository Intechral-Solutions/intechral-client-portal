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

    /** The locked refusals of {@see refusal()}: the account itself may not act (suspended, an operator), the customer is not one they support, the named contact is not eligible. */
    public const REFUSED_ACTOR = 'actor';

    public const REFUSED_ORGANIZATION = 'organization';

    public const REFUSED_CONTACT = 'contact';

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
        return self::refusal($actorId, $organizationId) === null;
    }

    /**
     * Release 1.0 (R1-5): the same locked decision, for CREATING a request on a supported customer's behalf, with the named CONTACT (the requester)
     * decided in the same transaction. Runs inside the Ticket creation transaction, before the INSERT. `null` means: effective authority over the
     * customer organization AND the contact is an eligible customer contact of it right now. Otherwise the reason, so the caller can answer 404 for
     * the account itself (suspended, or an operator), and a field error for the organization or the contact. A missing or revoked grant is an
     * ORGANIZATION refusal, so an unmanaged customer, one managed by another partner and a designation revoked mid-request are indistinguishable. The contact is looked at only after the organization has been proven
     * supported, so nothing about a contact is learned for a customer the actor does not support.
     *
     * Lock order (a prefix-compatible extension of the reply's): the users S and the contact together, ascending id (shared) -> the customer and
     * partner organization rows together, ascending id (shared) -> the actor's partner membership (shared) -> the contact's customer membership
     * (shared). Every writer of these rows takes the user row, then the organization row, then the membership row, so there is no cycle.
     *
     * "Eligible contact" is {@see OrganizationAuthority::customerContactIds()} (active, not an operator, may raise and read a request: tables, no
     * cache) AND a current membership row of the customer organization. The role, administrator approval and a CRM link are not inputs.
     */
    public static function refusal(int $actorId, int $organizationId, ?int $contactId = null): ?string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('PartnerSupportAccess::refusal() runs only inside the creation or reply transaction.');
        }

        // The contact is NOMINATED by a plain read and only an EXISTING id is locked: a locking read of an id above the highest user takes a gap lock
        // on the end of the index, which a concurrent registration's INSERT (an invitation acceptance, user S -> organization X -> insert) would
        // wait on while this waits on its organization lock. Whether the contact exists is not decided here; it is decided below, after the customer
        // is proven supported, from what the locks then show.
        $nominatedContact = $contactId !== null && DB::table('users')->where('id', $contactId)->exists() ? $contactId : null;

        $users = DB::table('users')->whereIn('id', array_values(array_unique(array_filter([$actorId, $nominatedContact]))))
            ->orderBy('id')->sharedLock()->get(['id', 'disabled_at'])->keyBy('id');
        $user = $users->get($actorId);

        if ($user === null || $user->disabled_at !== null) {
            return self::REFUSED_ACTOR;
        }

        // The partner is NOMINATED by a plain read, then BOTH organization rows are locked in ONE statement in ascending id order, the order
        // OrganizationRelationships::lock() takes its exclusive locks in, so an operator re-submitting the link can never close a cycle with this reply
        // (opposite orders were a real deadlock, found by the R1-4 design challenge). What the locks then show decides; a relationship that moved
        // between the nomination and the locks fails closed.
        $nominated = DB::table('organizations')->where('id', $organizationId)->value('managed_by_organization_id');

        if ($nominated === null || (int) $nominated === $organizationId) {
            return self::REFUSED_ORGANIZATION;
        }

        $rows = DB::table('organizations')->whereIn('id', [$organizationId, (int) $nominated])->orderBy('id')->sharedLock()
            ->get(['id', 'managed_by_organization_id', 'can_manage_customers', 'disabled_at'])->keyBy('id');
        $customer = $rows->get($organizationId);
        $partner = $rows->get((int) $nominated);

        if ($customer === null || $partner === null
            || $customer->disabled_at !== null || (int) $customer->managed_by_organization_id !== (int) $partner->id
            || ! $partner->can_manage_customers || $partner->disabled_at !== null) {
            return self::REFUSED_ORGANIZATION;
        }

        $membership = DB::table('organization_members')
            ->where('organization_id', $partner->id)->where('user_id', $actorId)
            ->sharedLock()->first(['support_granted_at', 'support_granted_by']);

        // No complete grant on THIS customer's partner is "this customer is not one you support", the same answer for an unmanaged organization, one
        // managed by another partner, and a designation that was revoked while the request was in flight. Only the account itself (suspended, an
        // operator) is a refusal of the ACTOR; whether the actor holds any grant at all is the caller's own earlier, unlocked 404.
        if ($membership === null || $membership->support_granted_at === null || $membership->support_granted_by === null) {
            return self::REFUSED_ORGANIZATION;
        }

        if (OrganizationAuthority::isOperator($actorId)) {
            return self::REFUSED_ACTOR;
        }

        if ($contactId === null) {
            return null;
        }

        // The LOCKED row decides whether the contact is active: a suspension that committed after this transaction's read view began (the unlocked
        // grant read, or any earlier plain read) is seen here and not in the snapshot that customerContactIds() reads. The rest of that predicate
        // (not an operator, the two capabilities) is a table read outside the locks, the accepted residual of every permission read (design record 7).
        $contact = $users->get($contactId);

        if ($contact === null || $contact->disabled_at !== null) {
            return self::REFUSED_CONTACT;
        }

        $contactMember = DB::table('organization_members')
            ->where('organization_id', $customer->id)->where('user_id', $contactId)->sharedLock()->first(['id']);

        if ($contactMember === null || OrganizationAuthority::customerContactIds([$contactId]) !== [$contactId]) {
            return self::REFUSED_CONTACT;
        }

        return null;
    }

    /**
     * The creation page's options, for display only (an ordinary read, no lock; the creation transaction decides again): per supported customer
     * organization, its eligible contacts. Id, name and email of the contact, ordered by name, and nothing else (no role, no other membership).
     * Built from {@see supportedOrganizations()} and {@see OrganizationAuthority::customerContactIds()}, so it cannot offer a customer or contact
     * the creation rule would refuse (a stale page can only produce a refusal).
     *
     * @return list<array{id: int, name: string, contacts: list<array{id: int, name: string, email: string}>}>
     */
    public static function creationOptions(int $actorId): array
    {
        if (! self::holdsGrant($actorId)) {
            return [];
        }

        $customers = self::supportedOrganizations($actorId)->orderBy('c.name')->orderBy('c.id')->get();

        $members = DB::table('organization_members as cm')
            ->join('users as cu', 'cu.id', '=', 'cm.user_id')
            ->whereIn('cm.organization_id', $customers->pluck('id')->all())
            ->orderBy('cu.name')->orderBy('cu.id')
            ->get(['cm.organization_id', 'cu.id', 'cu.name', 'cu.email']);

        $eligible = array_flip(OrganizationAuthority::customerContactIds($members->pluck('id')->map(fn ($id) => (int) $id)->all()));

        return $customers->map(fn (object $customer) => [
            'id' => (int) $customer->id,
            'name' => (string) $customer->name,
            'contacts' => $members->where('organization_id', $customer->id)->filter(fn (object $m) => isset($eligible[(int) $m->id]))
                ->map(fn (object $m) => ['id' => (int) $m->id, 'name' => (string) $m->name, 'email' => (string) $m->email])->values()->all(),
        ])->values()->all();
    }

    private static function id(User|int $actor): int
    {
        return $actor instanceof User ? (int) $actor->getKey() : $actor;
    }
}

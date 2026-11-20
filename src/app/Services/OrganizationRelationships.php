<?php

namespace App\Services;

use App\Exceptions\OrganizationRelationshipException;
use App\Models\Organization;
use App\Models\User;
use App\Support\Organizations\PortalState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The managed-organization relationship (Release 1.0, MO-1A): an administrative link from a customer organization to the
 * partner organization that manages it. It is NOT membership and NOT a grant: nothing in the application reads it to decide
 * access to tickets, projects, time, invoices or any other organization's directory data.
 *
 * Rules, all enforced here on the server:
 *  - only a designated partner (`can_manage_customers`) may manage customers;
 *  - a partner cannot itself be managed, and an organization cannot manage itself, so there is exactly one level;
 *  - an organization has at most one managing organization;
 *  - both ends have exactly one CRM company (an unambiguous pair), so the relationship has a directory record to point at;
 *  - a partner cannot lose its designation while it still has customers.
 * One CHECK constraint backs the first rule's counterpart (a partner cannot be managed); there is no CHECK for self-management
 * (MariaDB refuses one on an AUTO_INCREMENT column). The rest span rows and only this class can hold them.
 *
 * Concurrency. Every mutation is one transaction. The organization rows it needs are locked FOR UPDATE in ascending id order
 * (one lock order for all callers, so two operations on the same pair cannot deadlock), the company pairing is read under a
 * shared lock afterwards (organizations before companies, which is also the order CrmService::deleteCompany follows), and
 * every rule is checked against the locked, current rows, never against a model the caller loaded earlier.
 *
 * Reads here are deliberately unscoped (`DB::table`, `withoutGlobalScopes`): OrganizationMembershipScope hides organizations
 * from any account that is not operator-eligible (`portal.operator`, R1-1), so a scoped read, run in a session that is not
 * eligible, would let a partner with customers look empty. They are
 * safe only because every mutation first passes {@see OrganizationPolicy} (portal.operator AND crm.manage); the read helpers
 * below must be called only for an actor who has passed it.
 *
 * PORTAL STATE (Release 1.0, ORG-1; ADR-011 sections 2, 3 and 5). Designating a partner and linking or reassigning a customer GIVE a relationship, so BOTH
 * ends must be Enabled ({@see PortalState}), read from the rows already locked above: no extra lock and no Gate call. Removing a designation and
 * unlinking take one away and ask nothing of the state. Disable and re-enable are the lifecycle transitions themselves: each is authorized (Gate,
 * outside the transaction) AND re-decided from the tables under the locks, takes the actor's row first, and refuses an invalid transition with a
 * controlled error before any CHECK could fire. Disabling also permanently revokes the Organization's open invitations in the same transaction
 * ({@see disable()}).
 *
 * Every change is recorded in the activity log, in the same transaction, with the actor and the previous and new values.
 */
final class OrganizationRelationships
{
    public const DESIGNATED = 'organization designated as partner';

    public const PARTNER_REMOVED = 'organization partner designation removed';

    public const CUSTOMER_LINKED = 'organization linked to managing partner';

    public const CUSTOMER_REASSIGNED = 'organization reassigned to another managing partner';

    public const CUSTOMER_UNLINKED = 'organization unlinked from managing partner';

    public const DISABLED = 'organization disabled';

    public const ENABLED = 'organization enabled';

    private const ATTEMPTS = 3;

    public function __construct(
        private readonly PartnerSupportGrants $supportGrants,
        private readonly OrganizationInvitations $invitations,
    ) {}

    // ── Mutations ─────────────────────────────────────────

    /** Designate an organization as a partner. Returns false when it already was. */
    public function designatePartner(Organization $organization, User $actor): bool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($organization, $actor): bool {
            $row = $this->lock([$organization->getKey()])[$organization->getKey()];

            $this->requireEnabled($row);

            if ($row->can_manage_customers) {
                return false;
            }

            if ($row->managed_by_organization_id !== null) {
                throw OrganizationRelationshipException::isManaged($row->name, $this->nameOf($row->managed_by_organization_id));
            }

            $this->assertSingleCompany($row);

            $this->write($organization, ['can_manage_customers' => true]);
            $this->audit($actor, $organization, self::DESIGNATED, [
                'old' => ['can_manage_customers' => false],
                'new' => ['can_manage_customers' => true],
            ]);

            return true;
        }, self::ATTEMPTS);
    }

    /** Remove a partner designation. Returns false when the organization was not a partner. Refused while it has customers. */
    public function removePartner(Organization $organization, User $actor): bool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($organization, $actor): bool {
            $row = $this->lock([$organization->getKey()])[$organization->getKey()];

            if (! $row->can_manage_customers) {
                return false;
            }

            // Counted AFTER the partner row is locked. Linking a customer to this partner needs the same lock, so no link
            // can be committed between this count and the update; an unlink that commits meanwhile only makes this
            // refuse once too often, which is safe. Never an actor-filtered collection: an unscoped count.
            $customers = DB::table('organizations')->where('managed_by_organization_id', $row->id)->count();

            if ($customers > 0) {
                throw OrganizationRelationshipException::hasCustomers($row->name, $customers);
            }

            $this->write($organization, ['can_manage_customers' => false]);
            $this->audit($actor, $organization, self::PARTNER_REMOVED, [
                'old' => ['can_manage_customers' => true],
                'new' => ['can_manage_customers' => false],
            ]);

            // R1-4: this partner's support designations end with the designation, so a later re-designation cannot revive them. (Access already
            // stops the moment `can_manage_customers` is false: PartnerSupportAccess reads it.) The organization row is locked exclusively above.
            $this->supportGrants->revokeForPartner($row->id, $actor);

            return true;
        }, self::ATTEMPTS);
    }

    /**
     * Disable an organization (Release 1.0, R1-4): an administrative, reversible state. A disabled organization is refused as the customer of a new
     * request ({@see ServiceRequestAccess}) and ends partner support access to its tickets, and to those of its customers when it is a partner
     * ({@see PartnerSupportAccess}). It does not remove members, change a role, delete a record or lock a requester out of their own existing tickets.
     * Returns false when it already was disabled (its original time is kept).
     *
     * ORG-1 (ruling A2): disabling permanently REVOKES every open invitation of the Organization, in this transaction
     * ({@see OrganizationInvitations::revokeOpenForDisable()}), and audits them. The sequence is the ORG-0 record's section 6.2:
     *
     *   Gate `manageRelationships` (before the transaction)  ->  actor user row (S)  ->  organization row (X)  ->  the actor's authority again, from the
     *   TABLES  ->  portal state from the locked row  ->  write `disabled_at`  ->  nominate and lock the open invitations (X, ascending)  ->  revalidate
     *   and revoke  ->  audit
     *
     * The actor's row is first because `revoked_by` is a foreign key to it and a suspension of that actor holds it exclusively while it waits for
     * invitation rows: taking it late, through the foreign key, would close a cycle. Nothing here loads the permission cache or calls a Gate inside the
     * locks. Disable mutates no role or permission, so no cache-epoch step is added (R4 does not apply).
     *
     * @throws AuthorizationException the actor is not a relationship manager (from the tables, under the lock)
     * @throws OrganizationRelationshipException the Organization has never had portal access
     */
    public function disable(Organization $organization, User $actor): bool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($organization, $actor): bool {
            $this->lockActor($actor);
            $row = $this->lockRaw($organization->getKey());
            $this->requireRelationshipManager($actor);

            $state = PortalState::of($row);

            if ($state->isNotEnabled()) {
                throw OrganizationRelationshipException::neverEnabled($row->name);
            }

            if ($state->isDisabled()) {
                return false;
            }

            // Every lock first (the invitation rows), then the writes: the Organization's own `disabled_at` is written after the sweep has locked
            // what it will revoke, so no lock is ever taken after a write of this transaction.
            $revoked = $this->invitations->revokeOpenForDisable((int) $row->id, $actor);
            $this->write($organization, ['disabled_at' => now()]);
            $this->audit($actor, $organization, self::DISABLED, [
                'old' => ['disabled' => false],
                'new' => ['disabled' => true],
                'revoked_invitation_ids' => $revoked,
            ]);

            return true;
        }, self::ATTEMPTS);
    }

    /**
     * Re-enable a disabled organization. Returns false when it was not disabled. Re-grants nothing: only designations that still exist apply again, and an
     * invitation that was revoked when it was disabled stays revoked (a new invitation is a new credential). It is NOT initial enablement: an Organization that
     * has never had portal access is refused with a controlled error, and the two acts keep separate abilities (ADR-011 section 2).
     *
     * @throws AuthorizationException the actor is not a relationship manager (from the tables, under the lock)
     * @throws OrganizationRelationshipException the Organization has never had portal access
     */
    public function enable(Organization $organization, User $actor): bool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($organization, $actor): bool {
            $this->lockActor($actor);
            $row = $this->lockRaw($organization->getKey());
            $this->requireRelationshipManager($actor);

            $state = PortalState::of($row);

            if ($state->isNotEnabled()) {
                throw OrganizationRelationshipException::neverEnabled($row->name);
            }

            if (! $state->isDisabled()) {
                return false;
            }

            $this->write($organization, ['disabled_at' => null]);
            $this->audit($actor, $organization, self::ENABLED, ['old' => ['disabled' => true], 'new' => ['disabled' => false]]);

            return true;
        }, self::ATTEMPTS);
    }

    /**
     * Link a customer organization to a partner. Returns false when it is already managed by exactly this partner.
     *
     * Linking and reassigning are different operator decisions. A plain link REFUSES a customer another partner already
     * manages (the operator's form may be stale: someone linked it since the page was drawn), and nothing changes. Moving it is
     * an explicit `$reassign = true`, audited as its own event.
     */
    public function assignCustomer(Organization $customer, Organization $partner, User $actor, bool $reassign = false): bool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($customer, $partner, $actor, $reassign): bool {
            $rows = $this->lock([$customer->getKey(), $partner->getKey()]);
            $customerRow = $rows[$customer->getKey()];
            $partnerRow = $rows[$partner->getKey()];

            if ($customerRow->id === $partnerRow->id) {
                throw OrganizationRelationshipException::self($customerRow->name);
            }

            // ORG-1: both ends Enabled. Reassigning is a link like any other (it gives the new partner a customer), so it needs both too.
            $this->requireEnabled($customerRow);
            $this->requireEnabled($partnerRow);

            if (! $partnerRow->can_manage_customers || $partnerRow->managed_by_organization_id !== null) {
                throw OrganizationRelationshipException::notPartner($partnerRow->name);
            }

            if ($customerRow->can_manage_customers) {
                throw OrganizationRelationshipException::isPartner($customerRow->name);
            }

            $this->assertSingleCompany($customerRow, $partnerRow);

            $previous = $customerRow->managed_by_organization_id;

            if ($previous === $partnerRow->id) {
                return false;
            }

            if ($previous !== null && ! $reassign) {
                throw OrganizationRelationshipException::alreadyManaged($customerRow->name, $this->nameOf($previous));
            }

            $this->write($customer, ['managed_by_organization_id' => $partnerRow->id]);
            $this->audit($actor, $customer, $previous === null ? self::CUSTOMER_LINKED : self::CUSTOMER_REASSIGNED, [
                'old' => ['managed_by_organization_id' => $previous, 'managed_by_name' => $previous === null ? null : $this->nameOf($previous)],
                'new' => ['managed_by_organization_id' => $partnerRow->id, 'managed_by_name' => $partnerRow->name],
            ]);

            return true;
        }, self::ATTEMPTS);
    }

    /** Remove a customer's managing partner. Returns false when it had none. */
    public function unlinkCustomer(Organization $customer, User $actor): bool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($customer, $actor): bool {
            $row = $this->lock([$customer->getKey()])[$customer->getKey()];
            $previous = $row->managed_by_organization_id;

            if ($previous === null) {
                return false;
            }

            $this->write($customer, ['managed_by_organization_id' => null]);
            $this->audit($actor, $customer, self::CUSTOMER_UNLINKED, [
                'old' => ['managed_by_organization_id' => $previous, 'managed_by_name' => $this->nameOf($previous)],
                'new' => ['managed_by_organization_id' => null, 'managed_by_name' => null],
            ]);

            return true;
        }, self::ATTEMPTS);
    }

    // ── Reads (unscoped: call only for an actor who passed OrganizationPolicy::manageRelationships) ──

    /** The customers a partner manages, by name. */
    public static function managedCustomers(Organization $partner): Collection
    {
        return Organization::withoutGlobalScopes()
            ->where('managed_by_organization_id', $partner->getKey())
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name', 'slug']);
    }

    /** The organization that manages this one, or null. */
    public static function managerOf(Organization $customer): ?Organization
    {
        $id = DB::table('organizations')->where('id', $customer->getKey())->value('managed_by_organization_id');

        return $id === null ? null : Organization::withoutGlobalScopes()->find($id, ['id', 'name', 'slug']);
    }

    /** Organizations that could be linked to this partner now: not partners, not managed, one company, not itself. */
    public static function eligibleCustomers(Organization $partner): Collection
    {
        return PortalState::whereEnabled(Organization::withoutGlobalScopes()
            ->where('can_manage_customers', false)
            ->whereNull('managed_by_organization_id')
            ->whereKeyNot($partner->getKey()))
            ->whereIn('id', self::singleCompanyOrganizations())
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name']);
    }

    /** Partners this organization could be linked to now (the server still decides): not itself, one company. */
    public static function eligiblePartners(Organization $customer): Collection
    {
        return PortalState::whereEnabled(Organization::withoutGlobalScopes()
            ->where('can_manage_customers', true)
            ->whereKeyNot($customer->getKey()))
            ->whereIn('id', self::singleCompanyOrganizations())
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name']);
    }

    /** How many CRM companies point at the organization (unscoped). Exactly one is an unambiguous pair. */
    public static function companyCount(int $organizationId): int
    {
        return DB::table('crm_companies')->where('organization_id', $organizationId)->count();
    }

    // ── Internals ─────────────────────────────────────────

    private function authorize(User $actor): void
    {
        Gate::forUser($actor)->authorize('manageRelationships', Organization::class);
    }

    /**
     * Lock the organization rows, ascending id, and return them as they are NOW, keyed by id. A missing id is a 404: the
     * organization went away (or never existed) between the request and the lock.
     *
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function lock(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        $rows = DB::table('organizations')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($ids as $id) {
            abort_unless($rows->has($id), 404);
        }

        return $rows->map(function (object $row): object {
            $row->id = (int) $row->id;
            $row->can_manage_customers = (bool) $row->can_manage_customers;
            $row->managed_by_organization_id = $row->managed_by_organization_id === null ? null : (int) $row->managed_by_organization_id;

            return $row;
        })->all();
    }

    /** Every given organization must have exactly one CRM company; read under a shared lock so none can be deleted meanwhile. */
    private function assertSingleCompany(object ...$rows): void
    {
        $counts = DB::table('crm_companies')
            ->whereIn('organization_id', array_map(fn (object $row) => $row->id, $rows))
            ->orderBy('id')
            ->sharedLock()
            ->get(['id', 'organization_id'])
            ->countBy('organization_id');

        foreach ($rows as $row) {
            $companies = (int) ($counts[$row->id] ?? 0);

            if ($companies !== 1) {
                throw OrganizationRelationshipException::companyPairing($row->name, $companies);
            }
        }
    }

    /** The organization row, exclusively locked, with its name and its portal-state columns as they are now. */
    private function lockRaw(int $id): object
    {
        $row = DB::table('organizations')->where('id', $id)->lockForUpdate()->first(['id', 'name', ...PortalState::columns()]);
        abort_if($row === null, 404);

        return $row;
    }

    /** The acting operator's user row, shared: the FIRST statement of a lifecycle transaction (`revoked_by` is a foreign key to it; a suspension holds it exclusively). */
    private function lockActor(User $actor): void
    {
        DB::table('users')->where('id', $actor->getKey())->sharedLock()->value('id');
    }

    /** The Gate answered before the transaction; under the locks the answer comes from the tables (never stale, never a cache load). */
    private function requireRelationshipManager(User $actor): void
    {
        if (! OrganizationAuthority::isRelationshipManager((int) $actor->getKey())) {
            throw new AuthorizationException;
        }
    }

    /** ORG-1: a relationship act that gives something needs an Enabled Organization, read from a row this transaction has locked. */
    private function requireEnabled(object $row): void
    {
        $state = PortalState::of($row);

        if (! $state->isEnabled()) {
            throw OrganizationRelationshipException::portalRefused((string) $row->name, $state);
        }
    }

    /** @param  array<string, mixed>  $changes */
    private function write(Organization $organization, array $changes): void
    {
        DB::table('organizations')->where('id', $organization->getKey())->update($changes + ['updated_at' => now()]);

        // Keep the caller's model truthful without marking anything dirty.
        $organization->forceFill($changes)->syncOriginal();
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(User $actor, Organization $organization, string $event, array $properties): void
    {
        activity()->causedBy($actor)->performedOn($organization)->withProperties($properties)->log($event);
    }

    private function nameOf(int $organizationId): string
    {
        return (string) DB::table('organizations')->where('id', $organizationId)->value('name');
    }

    /** Subquery: organization ids that have exactly one company. */
    private static function singleCompanyOrganizations(): \Closure
    {
        return fn ($query) => $query->select('organization_id')->from('crm_companies')
            ->whereNotNull('organization_id')->groupBy('organization_id')->havingRaw('count(*) = 1');
    }
}

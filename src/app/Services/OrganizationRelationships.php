<?php

namespace App\Services;

use App\Exceptions\OrganizationRelationshipException;
use App\Models\Organization;
use App\Models\User;
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
 * from any account that lacks the `operator` ROLE, so a scoped read would let a partner with customers look empty. They are
 * safe only because every mutation first passes {@see OrganizationPolicy} (portal.operator AND crm.manage); the read helpers
 * below must be called only for an actor who has passed it.
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

    private const ATTEMPTS = 3;

    // ── Mutations ─────────────────────────────────────────

    /** Designate an organization as a partner. Returns false when it already was. */
    public function designatePartner(Organization $organization, User $actor): bool
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($organization, $actor): bool {
            $row = $this->lock([$organization->getKey()])[$organization->getKey()];

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
        return Organization::withoutGlobalScopes()
            ->where('can_manage_customers', false)
            ->whereNull('managed_by_organization_id')
            ->whereKeyNot($partner->getKey())
            ->whereIn('id', self::singleCompanyOrganizations())
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name']);
    }

    /** Partners this organization could be linked to now (the server still decides): not itself, one company. */
    public static function eligiblePartners(Organization $customer): Collection
    {
        return Organization::withoutGlobalScopes()
            ->where('can_manage_customers', true)
            ->whereKeyNot($customer->getKey())
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

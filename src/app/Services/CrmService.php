<?php

namespace App\Services;

use App\Exceptions\CompanyHasProjectsException;
use App\Exceptions\OrganizationRelationshipException;
use App\Models\CrmCompany;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\User;
use App\Support\Organizations\PortalState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CrmService
{
    /** Audit event of initial enablement (subject: the new Organization). */
    public const PORTAL_ENABLED = 'organization portal access enabled';

    // ── Companies ─────────────────────────────────────────

    public function createCompany(User $creator, array $data): CrmCompany
    {
        return CrmCompany::create([
            'name' => $data['name'],
            'website' => $data['website'] ?? null,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $creator->id,
        ]);
    }

    public function updateCompany(CrmCompany $company, array $data): CrmCompany
    {
        $company->update([
            'name' => $data['name'] ?? $company->name,
            'website' => $data['website'] ?? $company->website,
            'phone' => $data['phone'] ?? $company->phone,
            'address' => $data['address'] ?? $company->address,
            'notes' => $data['notes'] ?? $company->notes,
        ]);

        return $company->fresh();
    }

    /**
     * Delete a company that no project refers to. The `project_company` association is protected by the database
     * (ON DELETE RESTRICT, R0.9-1B): the pre-check gives the operator a business-level refusal instead of a database
     * error, and the engine remains the arbiter. A project linked between the check and the delete (a stale check)
     * makes the delete itself fail, and that failure is translated into the same refusal. Nothing else is touched
     * before the delete, so a refusal has no side effect. Not a company archival or project policy (H6).
     *
     * Release 1.0 (MO-1A): a company whose organization is designated a partner, or is managed by one, is refused too,
     * because deleting it would leave the relationship pointing at an organization with no directory record. The
     * organization row is locked FOR UPDATE first (the order OrganizationRelationships uses: organizations before
     * companies) and the flags are read under that lock, so a relationship being created concurrently either commits before
     * the check, and is refused here, or waits until this transaction is done and then finds no company.
     *
     * @throws CompanyHasProjectsException
     * @throws OrganizationRelationshipException
     */
    public function deleteCompany(CrmCompany $company): void
    {
        // A locking read when `$fresh`: inside a transaction a plain read sees the snapshot taken at its first read and would
        // miss a project linked since, which is exactly what the recount after a refused delete has to see.
        $linked = fn (bool $fresh = false): int => DB::table('project_company')
            ->where('crm_company_id', $company->getKey())
            ->when($fresh, fn ($query) => $query->sharedLock())
            ->count();

        // The organization is learned BEFORE the transaction so its row can be locked first (organizations before companies,
        // the order OrganizationRelationships follows). If promotion links one in the meantime, the company is re-read under a
        // row lock and the whole thing is tried again (a promoted company has no relationship yet, so the second pass always
        // settles). With no organization, the shared company-row lock is what stops a promotion committing between the read and the delete.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $organizationId = DB::table('crm_companies')->where('id', $company->getKey())->value('organization_id');

            $settled = DB::transaction(function () use ($company, $organizationId, $linked): bool {
                $organization = $organizationId === null ? null
                    : DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->first();

                // A SHARED lock: with no organization yet, promotion (which takes this row FOR UPDATE first) cannot commit between
                // this read and the delete. Shared, not exclusive, because linking a project inserts into project_company, whose
                // foreign key needs a shared lock on this very row: an exclusive lock would make the project guard below wait
                // on a link it exists to refuse. Two concurrent deletes of one company can deadlock here; the engine aborts one
                // and the retry (below) finds the company already gone.
                $current = DB::table('crm_companies')->where('id', $company->getKey())->sharedLock()->value('organization_id');

                if (($current === null ? null : (int) $current) !== ($organizationId === null ? null : (int) $organizationId)) {
                    return false;
                }

                if ($organization !== null && ($organization->can_manage_customers || $organization->managed_by_organization_id !== null)) {
                    throw OrganizationRelationshipException::companyInRelationship($company->name, $organization->name);
                }

                if (($projects = $linked()) > 0) {
                    throw new CompanyHasProjectsException($company->name, $projects);
                }

                try {
                    $company->delete();
                } catch (QueryException $e) {
                    // MariaDB/MySQL 1451: a row in another table still refers to this one (here, a project linked just now).
                    if (($e->errorInfo[1] ?? null) === 1451 && ($projects = $linked(true)) > 0) {
                        throw new CompanyHasProjectsException($company->name, $projects);
                    }

                    throw $e;
                }

                return true;
            }, 2);

            if ($settled) {
                return;
            }
        }

        throw new \RuntimeException('The company was linked to an organization while it was being deleted; try again.');
    }

    // ── Contacts ──────────────────────────────────────────

    public function createContact(User $creator, array $data): CrmContact
    {
        return CrmContact::create([
            'crm_company_id' => $data['crm_company_id'] ?? null,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'job_title' => $data['job_title'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $creator->id,
        ]);
    }

    public function updateContact(CrmContact $contact, array $data): CrmContact
    {
        $contact->update([
            'crm_company_id' => array_key_exists('crm_company_id', $data) ? $data['crm_company_id'] : $contact->crm_company_id,
            'first_name' => $data['first_name'] ?? $contact->first_name,
            'last_name' => $data['last_name'] ?? $contact->last_name,
            'email' => $data['email'] ?? $contact->email,
            'phone' => $data['phone'] ?? $contact->phone,
            'job_title' => $data['job_title'] ?? $contact->job_title,
            'notes' => $data['notes'] ?? $contact->notes,
        ]);

        return $contact->fresh();
    }

    public function deleteContact(CrmContact $contact): void
    {
        $contact->delete();
    }

    // ── Organizations ─────────────────────────────────────

    /**
     * Initial enablement of portal access: promote a CRM company to an Organization (Release 1.0; ORG-1 hardens it, ADR-011 section 2). It stays the
     * existing Company-promotion path in ORG-1; the Directory-only state transition is ORG-2.
     *
     * AUTHORITY is `manageMembership` (ADR-011: initial enablement is membership authority; disable and re-enable are `manageRelationships`, and the two are
     * separate abilities that must not be collapsed): the Gate is asked HERE, before the transaction, and again from the TABLES under the locks
     * ({@see OrganizationAuthority::isMembershipManager()}), so a call made with an explicit actor (a job, tinker) is held to it exactly like the route, and
     * a suspension that committed first is seen. The route's `can:crm.manage` is only the first gate.
     *
     * One transaction. Lock order: the actor's user row (shared; `portal_enabled_by` and the admin membership both reference it) -> the company row
     * (exclusive, the existing serialization point) -> the new Organization row is INSERTED already Enabled. No Gate and no permission-cache load happens after
     * the first lock. The Organization is created with `portal_enabled_at` = `created_at` = one instant, the enabling operator is recorded in
     * `portal_enabled_by` (provenance, never authority), and the audit entry carries that same instant.
     *
     * Idempotent for an already-promoted company whose Organization is Enabled: returns it, writes nothing (two operators promoting at the same moment, or a
     * stale model). An already-promoted company whose Organization is DISABLED is refused with a controlled error: enabling never clears `disabled_at`, which only
     * Re-enable does. A company linked to an Organization that is Not enabled is refused too (nothing creates one in ORG-1). The already-promoted branch reads the
     * Organization WITHOUT locking it, as before: it writes nothing, and CrmService::deleteCompany locks Organization then company, so locking the other way round
     * here would close a cycle.
     *
     * Release 1.0 (MO-1A): one transaction under a lock on the company row, so two operators promoting the same company at the same moment cannot each create an
     * organization (one would be left orphaned): the second waits, finds the link and returns the first's organization. The organization is read unscoped, so the
     * answer never depends on who asks.
     *
     * @throws AuthorizationException the actor may not enable portal access
     * @throws OrganizationRelationshipException the company's Organization is Disabled (or not Enabled)
     */
    public function promoteToOrganization(CrmCompany $company, User $actor): Organization
    {
        Gate::forUser($actor)->authorize('manageMembership', Organization::class);

        return DB::transaction(function () use ($company, $actor): Organization {
            DB::table('users')->where('id', $actor->getKey())->sharedLock()->value('id');
            $locked = CrmCompany::withoutGlobalScopes()->lockForUpdate()->findOrFail($company->getKey());

            if (! OrganizationAuthority::isMembershipManager((int) $actor->getKey())) {
                throw new AuthorizationException;
            }

            if ($locked->organization_id !== null) {
                $existing = Organization::withoutGlobalScopes()->findOrFail($locked->organization_id);
                $state = PortalState::of($existing);

                if (! $state->isEnabled()) {
                    throw OrganizationRelationshipException::enablementRefused($existing->name, $state);
                }

                $company->forceFill(['organization_id' => $locked->organization_id])->syncOriginal();

                return $existing;
            }

            $now = now();
            $org = Organization::withoutGlobalScopes()->forceCreate([
                'name' => $locked->name,
                'slug' => $this->uniqueSlug($locked->name),
                'portal_enabled_at' => $now,
                'portal_enabled_by' => $actor->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $locked->update(['organization_id' => $org->id]);
            $company->forceFill(['organization_id' => $org->id, 'updated_at' => $locked->updated_at])->syncOriginal();

            // Add the enabling operator as an admin member (Q3: unchanged until the operator raise-on-behalf question is ruled on).
            $org->members()->attach($actor->id, ['role' => 'admin']);

            // The audit entry shares the enablement instant: `created_at` of the entry is `portal_enabled_at`.
            activity()->causedBy($actor)->performedOn($org)
                ->withProperties([
                    'old' => ['portal_state' => PortalState::NotEnabled->value],
                    'new' => ['portal_state' => PortalState::Enabled->value, 'portal_enabled_at' => $now->toIso8601String(), 'portal_enabled_by' => $actor->id],
                    'crm_company_id' => $locked->id,
                ])
                ->createdAt($now)
                ->log(self::PORTAL_ENABLED);

            return $org;
        });
    }

    // Organization membership (add, promote, demote, remove, approve) lives in App\Services\OrganizationMembership (MO-2A1): it takes
    // the organization lock, keeps the last approved administrator, and audits. There is deliberately no second way to write it here.

    // ── Private ───────────────────────────────────────────

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;

        // Unscoped: a slug is unique across every tenant, and the membership scope would hide other tenants' slugs.
        while (Organization::withoutGlobalScopes()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}

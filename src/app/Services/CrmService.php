<?php

namespace App\Services;

use App\Exceptions\CompanyHasProjectsException;
use App\Exceptions\OrganizationRelationshipException;
use App\Models\CrmCompany;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CrmService
{
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
     * Promote a CRM company to an Organization.
     * Idempotent — returns the existing org if already promoted.
     *
     * Release 1.0 (MO-1A): one transaction under a lock on the company row, so two operators promoting the same company at the
     * same moment cannot each create an organization (one would be left orphaned): the second waits, finds the link and
     * returns the first's organization. The organization is read unscoped, so the answer never depends on who asks.
     */
    public function promoteToOrganization(CrmCompany $company, User $owner): Organization
    {
        return DB::transaction(function () use ($company, $owner): Organization {
            $locked = CrmCompany::withoutGlobalScopes()->lockForUpdate()->findOrFail($company->getKey());

            if ($locked->organization_id !== null) {
                $company->forceFill(['organization_id' => $locked->organization_id])->syncOriginal();

                return Organization::withoutGlobalScopes()->findOrFail($locked->organization_id);
            }

            $org = Organization::create([
                'name' => $locked->name,
                'slug' => $this->uniqueSlug($locked->name),
                'owner_id' => $owner->id,
            ]);

            $locked->update(['organization_id' => $org->id]);
            $company->forceFill(['organization_id' => $org->id, 'updated_at' => $locked->updated_at])->syncOriginal();

            // Add the owner as an admin member
            $org->members()->attach($owner->id, ['role' => 'admin']);

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

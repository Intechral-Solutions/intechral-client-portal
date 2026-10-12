<?php

namespace App\Services;

use App\Exceptions\CompanyHasProjectsException;
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
     * before the delete, so a refusal has no side effects. Not a company archival or project policy (H6).
     *
     * @throws CompanyHasProjectsException
     */
    public function deleteCompany(CrmCompany $company): void
    {
        $linked = fn (): int => DB::table('project_company')->where('crm_company_id', $company->getKey())->count();

        if (($projects = $linked()) > 0) {
            throw new CompanyHasProjectsException($company->name, $projects);
        }

        try {
            $company->delete();
        } catch (QueryException $e) {
            // MariaDB/MySQL 1451: a row in another table still refers to this one (here, a project linked just now).
            if (($e->errorInfo[1] ?? null) === 1451 && ($projects = $linked()) > 0) {
                throw new CompanyHasProjectsException($company->name, $projects);
            }

            throw $e;
        }
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
     */
    public function promoteToOrganization(CrmCompany $company, User $owner): Organization
    {
        if ($company->isPromoted()) {
            return $company->organization;
        }

        $org = Organization::create([
            'name' => $company->name,
            'slug' => $this->uniqueSlug($company->name),
            'owner_id' => $owner->id,
        ]);

        $company->update(['organization_id' => $org->id]);

        // Add the owner as an admin member
        $org->members()->attach($owner->id, ['role' => 'admin']);

        return $org;
    }

    public function addMember(Organization $org, User $user, string $role = 'member'): void
    {
        $org->members()->syncWithoutDetaching([$user->id => ['role' => $role]]);
    }

    public function updateMemberRole(Organization $org, User $user, string $role): void
    {
        $org->members()->updateExistingPivot($user->id, ['role' => $role]);
    }

    public function removeMember(Organization $org, User $user): void
    {
        $org->members()->detach($user->id);
    }

    // ── Private ───────────────────────────────────────────

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}

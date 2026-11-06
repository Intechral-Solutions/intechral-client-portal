<?php

namespace App\Services;

use App\Models\User;
use App\Shared\Permissions\BuiltInRoles;
use App\Shared\Permissions\PermissionCatalogue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only report on the organization, company and membership data the managed-organization work (Release 1.0, MO-1A, and the
 * packages after it) will meet. It exists to be run against a copy or a rehearsal of real data BEFORE the migrations, and
 * again after. It never writes, repairs, merges or deletes anything, and it reads the tables directly: it must not populate
 * the permission cache, and no tenant scope may filter what it counts.
 *
 * `failures` are conditions that block the work or mean the data is already inconsistent (a duplicate company-to-organization
 * link stops the UNIQUE migration; a relationship that breaks a rule). `warnings` are facts a person must decide about
 * (existing organization admin rows, which MO-2 would turn from inert into authority; operator-held rows; users in several
 * organizations; tickets that already carry a company).
 */
final class OrganizationPreflight
{
    /** @return array<string, mixed> */
    public function report(): array
    {
        $failures = [];
        $warnings = [];

        $duplicates = DB::table('crm_companies')
            ->select('organization_id', DB::raw('group_concat(id order by id) as company_ids'))
            ->whereNotNull('organization_id')->groupBy('organization_id')->havingRaw('count(*) > 1')
            ->orderBy('organization_id')->get()
            ->map(fn ($row) => ['organization_id' => (int) $row->organization_id, 'company_ids' => array_map('intval', explode(',', $row->company_ids))])->all();

        if ($duplicates !== []) {
            $failures[] = count($duplicates).' organization(s) are linked to more than one CRM company. The unique-link migration will refuse to run, and no relationship can involve them. Decide for each which company keeps the organization; nothing was changed.';
        }

        $withoutCompany = DB::table('organizations')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('crm_companies')->whereColumn('crm_companies.organization_id', 'organizations.id'))
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($withoutCompany !== []) {
            $warnings[] = count($withoutCompany).' organization(s) have no CRM company. They cannot be designated as partners or linked as customers until they have exactly one.';
        }

        $companiesWithoutOrganization = DB::table('crm_companies')->whereNull('organization_id')->count();

        $operators = $this->operatorIds();
        $admins = DB::table('organization_members')
            ->join('users', 'users.id', '=', 'organization_members.user_id')
            ->where('organization_members.role', 'admin')
            ->orderBy('organization_members.organization_id')->orderBy('users.id')
            ->get(['organization_members.organization_id', 'users.id as user_id', 'users.email', 'users.disabled_at'])
            ->map(fn ($row) => [
                'organization_id' => (int) $row->organization_id,
                'user_id' => (int) $row->user_id,
                'email' => $row->email,
                'active' => $row->disabled_at === null,
                'operator' => in_array((int) $row->user_id, $operators, true),
            ])->all();

        $operatorHeld = array_values(array_filter($admins, fn (array $admin) => $admin['operator']));

        if ($admins !== []) {
            $warnings[] = count($admins).' organization admin membership(s) exist ('.count($operatorHeld).' held by an operator). The role grants nothing today; it must be reviewed before anything starts to honour it.';
        }

        $multiOrganization = DB::table('organization_members')
            ->join('users', 'users.id', '=', 'organization_members.user_id')
            ->groupBy('users.id', 'users.email')->havingRaw('count(*) > 1')->orderBy('users.id')
            ->get(['users.id', 'users.email', DB::raw('count(*) as organizations')])
            ->map(fn ($row) => ['user_id' => (int) $row->id, 'email' => $row->email, 'organizations' => (int) $row->organizations])->all();

        if ($multiOrganization !== []) {
            $warnings[] = count($multiOrganization).' user(s) belong to more than one organization.';
        }

        $attributed = [
            'tickets' => DB::table('tickets')->whereNotNull('company_id')->count(),
            'companies' => DB::table('tickets')->whereNotNull('company_id')->distinct()->count('company_id'),
        ];

        if ($attributed['tickets'] > 0) {
            $warnings[] = $attributed['tickets'].' ticket(s) already carry a company attribution.';
        }

        $relationships = $this->relationships($failures);

        return [
            'duplicateLinks' => $duplicates,
            'organizationsWithoutCompany' => $withoutCompany,
            'companiesWithoutOrganization' => $companiesWithoutOrganization,
            'organizations' => DB::table('organizations')->count(),
            'companies' => DB::table('crm_companies')->count(),
            'adminMemberships' => $admins,
            'operatorAdminMemberships' => $operatorHeld,
            'multiOrganizationUsers' => $multiOrganization,
            'attributedTickets' => $attributed,
            'relationships' => $relationships,
            'failures' => $failures,
            'warnings' => $warnings,
        ];
    }

    /**
     * The managed relationships, or null before the MO-1A migration. Every rule the service holds, checked against the data.
     *
     * @param  list<string>  $failures
     * @return array<string, mixed>|null
     */
    private function relationships(array &$failures): ?array
    {
        if (! Schema::hasColumn('organizations', 'managed_by_organization_id') || ! Schema::hasColumn('organizations', 'can_manage_customers')) {
            return null;
        }

        $violations = [
            'managedByNonPartner' => $this->ids('select o.id from organizations o join organizations m on m.id = o.managed_by_organization_id where m.can_manage_customers = 0'),
            'partnerThatIsManaged' => $this->ids('select id from organizations where can_manage_customers = 1 and managed_by_organization_id is not null'),
            'managesItself' => $this->ids('select id from organizations where managed_by_organization_id = id'),
            'withoutSingleCompany' => $this->ids(
                'select o.id from organizations o where (o.can_manage_customers = 1 or o.managed_by_organization_id is not null or exists (select 1 from organizations c where c.managed_by_organization_id = o.id))
                 and (select count(*) from crm_companies k where k.organization_id = o.id) <> 1'
            ),
        ];

        foreach ($violations as $rule => $ids) {
            if ($ids !== []) {
                $failures[] = "Managed-organization rule broken ({$rule}): organization(s) ".implode(', ', $ids).'.';
            }
        }

        return [
            'partners' => DB::table('organizations')->where('can_manage_customers', true)->count(),
            'managed' => DB::table('organizations')->whereNotNull('managed_by_organization_id')->count(),
            'violations' => $violations,
        ];
    }

    /** @return list<int> */
    private function ids(string $sql): array
    {
        return array_map(fn ($row) => (int) $row->id, DB::select($sql));
    }

    /**
     * Ids of the accounts that count as operators: the `portal.operator` capability (eligibility) or the `operator` role (the
     * name the tenant scopes still test), directly or through a role. Straight from the tables, never the permission cache.
     *
     * @return list<int>
     */
    private function operatorIds(): array
    {
        $direct = DB::table('model_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'model_has_permissions.permission_id')
            ->where('model_has_permissions.model_type', User::class)
            ->where('permissions.name', PermissionCatalogue::PORTAL_OPERATOR)
            ->pluck('model_has_permissions.model_id');

        $viaRole = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->leftJoin('role_has_permissions', 'role_has_permissions.role_id', '=', 'roles.id')
            ->leftJoin('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('model_has_roles.model_type', User::class)
            ->where(fn ($query) => $query->where('roles.name', BuiltInRoles::OPERATOR)->orWhere('permissions.name', PermissionCatalogue::PORTAL_OPERATOR))
            ->pluck('model_has_roles.model_id');

        return $direct->merge($viaRole)->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}

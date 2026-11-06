<?php

use App\Models\CrmCompany;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OrganizationPreflight;
use App\Services\OrganizationRelationships;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Release 1.0 (MO-1A): `organizations:preflight`, the read-only report on the organization, company and membership data the
 * managed-organization work meets. It must count what is there without any tenant scope or permission cache in the way,
 * change nothing, and fail only on conditions that block or contradict the work. The duplicate-link failure, which needs the
 * unique key lifted, is exercised in ManagedOrganizationMigrationTest.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

it('passes on an empty database, writes nothing, and says the relationship columns exist', function () {
    $this->artisan('organizations:preflight')
        ->expectsOutputToContain('Organizations: 0')
        ->expectsOutputToContain('Managed relationships: 0 partner(s), 0 managed organization(s)')
        ->expectsOutputToContain('No blocking findings. Nothing was written.')
        ->assertSuccessful();
});

it('reports what the later packages will meet: admin rows, operator-held rows, multi-organization users, organizations without a company, attributed tickets', function () {
    $operator = User::factory()->create(['email' => 'op@example.test'])->assignRole('operator');
    $custom = Role::create(['name' => 'directory-lead', 'guard_name' => 'web']);
    $custom->givePermissionTo('portal.operator');
    $lead = User::factory()->create(['email' => 'lead@example.test'])->assignRole($custom);
    $customer = User::factory()->create(['email' => 'cust@example.test'])->assignRole('user');
    $suspended = User::factory()->create(['email' => 'gone@example.test', 'disabled_at' => now()])->assignRole('user');

    $acme = Organization::factory()->create(['name' => 'Acme', 'owner_id' => $operator->id]);
    $globex = Organization::factory()->create(['name' => 'Globex', 'owner_id' => $operator->id]);
    $orphan = Organization::factory()->create(['name' => 'No Company', 'owner_id' => $operator->id]);
    $acmeCompany = CrmCompany::factory()->create(['organization_id' => $acme->id, 'created_by' => $operator->id]);
    CrmCompany::factory()->create(['organization_id' => $globex->id, 'created_by' => $operator->id]);
    CrmCompany::factory()->create(['organization_id' => null, 'created_by' => $operator->id]);

    $acme->members()->attach($operator, ['role' => 'admin']);
    $acme->members()->attach($customer, ['role' => 'admin']);
    $acme->members()->attach($suspended, ['role' => 'admin']);
    $globex->members()->attach($customer, ['role' => 'member']);
    $globex->members()->attach($lead, ['role' => 'admin']);
    Ticket::factory()->create(['user_id' => $customer->id, 'company_id' => $acmeCompany->id]);
    Ticket::factory()->create(['user_id' => $customer->id, 'company_id' => null]);

    // Read straight from the tables: it must not warm the permission cache as a side effect.
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $writes = [DB::table('organizations')->count(), DB::table('activity_log')->count(), DB::table('organization_members')->count()];

    $report = app(OrganizationPreflight::class)->report();

    expect($report['organizations'])->toBe(3)->and($report['companies'])->toBe(3)->and($report['companiesWithoutOrganization'])->toBe(1)
        ->and($report['organizationsWithoutCompany'])->toBe([$orphan->id])
        ->and($report['duplicateLinks'])->toBe([])
        ->and(collect($report['adminMemberships'])->pluck('email')->sort()->values()->all())->toBe(['cust@example.test', 'gone@example.test', 'lead@example.test', 'op@example.test'])
        ->and(collect($report['operatorAdminMemberships'])->pluck('email')->sort()->values()->all())->toBe(['lead@example.test', 'op@example.test'], 'the role-name operator and the capability-only operator both count')
        ->and(collect($report['adminMemberships'])->firstWhere('email', 'gone@example.test')['active'])->toBeFalse()
        ->and($report['multiOrganizationUsers'])->toBe([['user_id' => $customer->id, 'email' => 'cust@example.test', 'organizations' => 2]])
        ->and($report['attributedTickets'])->toBe(['tickets' => 1, 'companies' => 1])
        ->and($report['failures'])->toBe([])
        ->and(count($report['warnings']))->toBe(4);

    expect([DB::table('organizations')->count(), DB::table('activity_log')->count(), DB::table('organization_members')->count()])->toBe($writes);

    $this->artisan('organizations:preflight')->expectsOutputToContain('[operator]')->assertSuccessful();
    $this->artisan('organizations:preflight --json')->assertSuccessful();
});

it('counts relationships and fails on a state the service can never produce', function () {
    Gate::before(fn () => true);
    $operator = User::factory()->create()->assignRole('operator');
    $make = function (string $name) use ($operator): Organization {
        $organization = Organization::factory()->create(['name' => $name, 'owner_id' => $operator->id]);
        CrmCompany::factory()->create(['organization_id' => $organization->id, 'created_by' => $operator->id]);

        return $organization;
    };
    $partner = $make('Partner');
    $customer = $make('Customer');
    $service = app(OrganizationRelationships::class);
    $service->designatePartner($partner, $operator);
    $service->assignCustomer($customer, $partner, $operator);

    $report = app(OrganizationPreflight::class)->report();
    expect($report['relationships']['partners'])->toBe(1)->and($report['relationships']['managed'])->toBe(1)
        ->and(array_filter($report['relationships']['violations']))->toBe([])
        ->and($report['failures'])->toBe([]);

    // Corrupt it the only way left, bypassing the service (the CHECK stops the others): a manager that is not a partner.
    DB::table('organizations')->where('id', $customer->id)->update(['managed_by_organization_id' => $make('Plain')->id]);
    DB::table('crm_companies')->where('organization_id', $partner->id)->update(['organization_id' => null]);

    $broken = app(OrganizationPreflight::class)->report();
    expect($broken['relationships']['violations']['managedByNonPartner'])->toBe([$customer->id])
        ->and($broken['relationships']['violations']['withoutSingleCompany'])->not->toBeEmpty()
        ->and($broken['failures'])->not->toBeEmpty();

    $this->artisan('organizations:preflight')->assertFailed();
});

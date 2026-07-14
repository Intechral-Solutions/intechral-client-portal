<?php

use App\Models\CrmCompany;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

it('shows a member only companies contacts and organizations from their organization', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $member = User::factory()->create();
    $member->assignRole('user');

    $orgA = Organization::factory()->create(['owner_id' => $operator->id, 'name' => 'Org A']);
    $orgB = Organization::factory()->create(['owner_id' => $operator->id, 'name' => 'Org B']);
    $orgA->members()->attach($member, ['role' => 'member']);

    $companyA = CrmCompany::factory()->create([
        'created_by' => $operator->id,
        'organization_id' => $orgA->id,
        'name' => 'Company A',
    ]);
    $companyB = CrmCompany::factory()->create([
        'created_by' => $operator->id,
        'organization_id' => $orgB->id,
        'name' => 'Company B',
    ]);
    $contactA = CrmContact::factory()->create([
        'created_by' => $operator->id,
        'crm_company_id' => $companyA->id,
    ]);
    CrmContact::factory()->create([
        'created_by' => $operator->id,
        'crm_company_id' => $companyB->id,
    ]);

    $this->actingAs($member);

    expect(CrmCompany::pluck('id')->all())->toBe([$companyA->id])
        ->and(CrmContact::pluck('id')->all())->toBe([$contactA->id])
        ->and(Organization::pluck('id')->all())->toBe([$orgA->id]);
});

it('returns an empty tenant view for a user without organization memberships', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $user = User::factory()->create();
    $user->assignRole('user');

    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    $company = CrmCompany::factory()->create([
        'created_by' => $operator->id,
        'organization_id' => $organization->id,
    ]);
    CrmContact::factory()->create([
        'created_by' => $operator->id,
        'crm_company_id' => $company->id,
    ]);

    $this->actingAs($user);

    expect(CrmCompany::count())->toBe(0)
        ->and(CrmContact::count())->toBe(0)
        ->and(Organization::count())->toBe(0);
});

it('shows records from every organization joined by a multi-organization user', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $member = User::factory()->create();
    $member->assignRole('user');

    $organizations = Organization::factory()->count(2)->create(['owner_id' => $operator->id]);
    foreach ($organizations as $organization) {
        $organization->members()->attach($member, ['role' => 'member']);
    }

    $companies = $organizations->map(fn (Organization $organization) => CrmCompany::factory()->create([
        'created_by' => $operator->id,
        'organization_id' => $organization->id,
    ]));
    $contacts = $companies->map(fn (CrmCompany $company) => CrmContact::factory()->create([
        'created_by' => $operator->id,
        'crm_company_id' => $company->id,
    ]));

    $this->actingAs($member);

    expect(CrmCompany::pluck('id')->all())->toEqualCanonicalizing($companies->modelKeys())
        ->and(CrmContact::pluck('id')->all())->toEqualCanonicalizing($contacts->modelKeys())
        ->and(Organization::pluck('id')->all())->toEqualCanonicalizing($organizations->modelKeys());
});

it('retains cross-tenant and unassigned record visibility for operators', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $organizations = Organization::factory()->count(2)->create(['owner_id' => $operator->id]);
    foreach ($organizations as $organization) {
        $company = CrmCompany::factory()->create([
            'created_by' => $operator->id,
            'organization_id' => $organization->id,
        ]);
        CrmContact::factory()->create([
            'created_by' => $operator->id,
            'crm_company_id' => $company->id,
        ]);
    }

    CrmCompany::factory()->create(['created_by' => $operator->id]);
    CrmContact::factory()->create(['created_by' => $operator->id]);

    $this->actingAs($operator);

    expect(CrmCompany::count())->toBe(3)
        ->and(CrmContact::count())->toBe(3)
        ->and(Organization::count())->toBe(2);
});

it('scopes CRM route model binding and list results for privileged non-operators', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $member = User::factory()->create();
    $member->assignRole('user');
    $member->givePermissionTo('crm.manage');

    $orgA = Organization::factory()->create(['owner_id' => $operator->id]);
    $orgB = Organization::factory()->create(['owner_id' => $operator->id]);
    $orgA->members()->attach($member, ['role' => 'admin']);

    $companyA = CrmCompany::factory()->create([
        'created_by' => $operator->id,
        'organization_id' => $orgA->id,
        'name' => 'Visible Tenant Company',
    ]);
    $companyB = CrmCompany::factory()->create([
        'created_by' => $operator->id,
        'organization_id' => $orgB->id,
        'name' => 'Hidden Tenant Company',
    ]);

    $this->actingAs($member)
        ->get(route('crm.companies.index'))
        ->assertOk()
        ->assertSee($companyA->name)
        ->assertDontSee($companyB->name);

    $this->actingAs($member)
        ->get(route('crm.companies.show', $companyB))
        ->assertNotFound();
});

it('prevents organization member management across tenant boundaries', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $manager = User::factory()->create();
    $manager->assignRole('user');
    $manager->givePermissionTo('crm.manage');
    $newMember = User::factory()->create();

    $orgA = Organization::factory()->create(['owner_id' => $operator->id]);
    $orgB = Organization::factory()->create(['owner_id' => $operator->id]);
    $orgA->members()->attach($manager, ['role' => 'admin']);

    $this->actingAs($manager)
        ->post(route('organizations.members.store', $orgB), [
            'user_id' => $newMember->id,
            'role' => 'member',
        ])
        ->assertNotFound();

    expect($orgB->members()->whereKey($newMember->id)->exists())->toBeFalse();

    $this->actingAs($manager)
        ->post(route('organizations.members.store', $orgA), [
            'user_id' => $newMember->id,
            'role' => 'member',
        ])
        ->assertRedirect();

    expect($orgA->members()->whereKey($newMember->id)->exists())->toBeTrue();
});

it('rejects cross-tenant company identifiers during contact ticket and project writes', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $member = User::factory()->create();
    $member->assignRole('user');
    $member->givePermissionTo('crm.manage');
    $member->givePermissionTo('projects.manage');

    $orgA = Organization::factory()->create(['owner_id' => $operator->id]);
    $orgB = Organization::factory()->create(['owner_id' => $operator->id]);
    $orgA->members()->attach($member, ['role' => 'member']);
    $companyB = CrmCompany::factory()->create([
        'created_by' => $operator->id,
        'organization_id' => $orgB->id,
    ]);
    $project = Project::factory()->create(['created_by' => $operator->id]);
    $project->members()->attach($member, ['role' => 'manager']);

    $this->actingAs($member)
        ->post(route('crm.contacts.store'), [
            'crm_company_id' => $companyB->id,
            'first_name' => 'Cross',
            'last_name' => 'Tenant',
        ])
        ->assertSessionHasErrors('crm_company_id');

    $this->actingAs($member)
        ->post(route('tickets.store'), [
            'company_id' => $companyB->id,
            'title' => 'Cross-tenant ticket',
            'description' => 'Must not attach to another tenant.',
            'category' => 'General',
            'priority' => 'medium',
        ])
        ->assertSessionHasErrors('company_id');

    $this->actingAs($member)
        ->put(route('projects.companies.sync', $project), [
            'companies' => [$companyB->id],
        ])
        ->assertSessionHasErrors('companies.0');

    expect(CrmContact::withoutGlobalScopes()->where('first_name', 'Cross')->exists())->toBeFalse()
        ->and(Ticket::where('title', 'Cross-tenant ticket')->exists())->toBeFalse()
        ->and(DB::table('project_company')
            ->where('project_id', $project->id)
            ->where('crm_company_id', $companyB->id)
            ->exists())->toBeFalse();
});

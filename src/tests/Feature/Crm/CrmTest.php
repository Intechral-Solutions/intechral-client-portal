<?php

use App\Models\CrmCompany;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\User;
use App\Services\CrmService;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

// ── Access control ────────────────────────────────────────────────────────────

it('redirects guests from crm to login', function () {
    $this->get(route('crm.companies.index'))->assertRedirect('/login');
});

it('returns 403 for users without crm.manage on company list', function () {
    $user = User::factory()->create();
    $user->assignRole('user'); // user role does not have crm.manage

    $this->actingAs($user)->get(route('crm.companies.index'))->assertForbidden();
});

it('allows operators to access the CRM company list', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->get(route('crm.companies.index'))->assertOk();
});

// ── Companies ─────────────────────────────────────────────────────────────────

it('creates a company', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->post(route('crm.companies.store'), [
        'name' => 'Acme Corp',
        'website' => 'https://acme.com',
        'phone' => '555-1234',
    ])->assertRedirect();

    expect(CrmCompany::where('name', 'Acme Corp')->exists())->toBeTrue();
});

it('validates company name is required', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->post(route('crm.companies.store'), [
        'name' => '',
    ])->assertSessionHasErrors('name');
});

it('validates company website must be a url', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->post(route('crm.companies.store'), [
        'name' => 'Bad URL Co',
        'website' => 'not-a-url',
    ])->assertSessionHasErrors('website');
});

it('updates a company', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $company = CrmCompany::factory()->create(['created_by' => $operator->id]);

    $this->actingAs($operator)->put(route('crm.companies.update', $company), [
        'name' => 'Updated Name',
        'phone' => '999-0000',
    ])->assertRedirect();

    expect($company->fresh()->name)->toBe('Updated Name');
});

it('deletes a company', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $company = CrmCompany::factory()->create(['created_by' => $operator->id]);

    $this->actingAs($operator)->delete(route('crm.companies.destroy', $company))->assertRedirect();

    expect(CrmCompany::find($company->id))->toBeNull();
});

// ── Contacts ──────────────────────────────────────────────────────────────────

it('allows operators to access the contacts list', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->get(route('crm.contacts.index'))->assertOk();
});

it('creates a contact', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->post(route('crm.contacts.store'), [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
    ])->assertRedirect();

    expect(CrmContact::where('email', 'jane@example.com')->exists())->toBeTrue();
});

it('validates contact first and last name are required', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->post(route('crm.contacts.store'), [
        'first_name' => '',
        'last_name' => '',
    ])->assertSessionHasErrors(['first_name', 'last_name']);
});

it('links a contact to a company', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $company = CrmCompany::factory()->create(['created_by' => $operator->id]);

    $this->actingAs($operator)->post(route('crm.contacts.store'), [
        'first_name' => 'John',
        'last_name' => 'Smith',
        'crm_company_id' => $company->id,
    ])->assertRedirect();

    $contact = CrmContact::where('first_name', 'John')->first();
    expect($contact->crm_company_id)->toBe($company->id);
});

it('updates a contact', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $contact = CrmContact::factory()->create(['created_by' => $operator->id]);

    $this->actingAs($operator)->put(route('crm.contacts.update', $contact), [
        'first_name' => 'Updated',
        'last_name' => 'Contact',
        'email' => 'updated@example.com',
    ])->assertRedirect();

    expect($contact->fresh()->first_name)->toBe('Updated');
});

it('deletes a contact', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $contact = CrmContact::factory()->create(['created_by' => $operator->id]);

    $this->actingAs($operator)->delete(route('crm.contacts.destroy', $contact))->assertRedirect();

    expect(CrmContact::find($contact->id))->toBeNull();
});

// ── Promote to organization ───────────────────────────────────────────────────

it('promotes a company to an organization', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $company = CrmCompany::factory()->create(['created_by' => $operator->id, 'name' => 'Promote Me']);

    $this->actingAs($operator)->post(route('crm.companies.promote', $company))->assertRedirect();

    $company->refresh();
    expect($company->isPromoted())->toBeTrue();
    expect($company->organization)->not->toBeNull();
    expect($company->organization->name)->toBe('Promote Me');
});

it('promoting twice returns the same organization', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $company = CrmCompany::factory()->create(['created_by' => $operator->id]);

    $service = app(CrmService::class);
    $org1 = $service->promoteToOrganization($company, $operator);
    $org2 = $service->promoteToOrganization($company->fresh(), $operator);

    expect($org1->id)->toBe($org2->id);
    expect(Organization::count())->toBe(1);
});

// ── Organization member management ───────────────────────────────────────────

it('allows operators to view organization list', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->get(route('organizations.index'))->assertOk();
});

it('adds a member to an organization', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $org = Organization::factory()->create(['owner_id' => $operator->id]);
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($operator)->post(route('organizations.members.store', $org), [
        'user_id' => $user->id,
        'role' => 'member',
    ])->assertRedirect();

    expect($org->hasMember($user))->toBeTrue();
});

it('removes a member from an organization', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $org = Organization::factory()->create(['owner_id' => $operator->id]);
    $user = User::factory()->create();

    $org->members()->attach($user->id, ['role' => 'member']);

    $this->actingAs($operator)->delete(route('organizations.members.destroy', [$org, $user]))->assertRedirect();

    expect($org->hasMember($user))->toBeFalse();
});

it('updates a member role', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $org = Organization::factory()->create(['owner_id' => $operator->id]);
    $user = User::factory()->create();

    $org->members()->attach($user->id, ['role' => 'member']);

    $this->actingAs($operator)->put(route('organizations.members.role', [$org, $user]), [
        'role' => 'admin',
    ])->assertRedirect();

    $pivot = $org->members()->where('user_id', $user->id)->first()->pivot;
    expect($pivot->role)->toBe('admin');
});

it('returns 404 removing a user who is not a member', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $org = Organization::factory()->create(['owner_id' => $operator->id]);
    $user = User::factory()->create();

    $this->actingAs($operator)->delete(route('organizations.members.destroy', [$org, $user]))->assertNotFound();
});

// ── CrmService helpers ────────────────────────────────────────────────────────

it('generates a unique slug on promotion', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $service = app(CrmService::class);

    $company1 = CrmCompany::factory()->create(['created_by' => $operator->id, 'name' => 'Same Name']);
    $company2 = CrmCompany::factory()->create(['created_by' => $operator->id, 'name' => 'Same Name']);

    $org1 = $service->promoteToOrganization($company1, $operator);
    $org2 = $service->promoteToOrganization($company2, $operator);

    expect($org1->slug)->not->toBe($org2->slug);
});

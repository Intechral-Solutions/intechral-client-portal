<?php

use App\Exceptions\OrganizationRelationshipException;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationDirectory;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

// ── Access control ────────────────────────────────────────────────────────────

it('redirects guests from crm to login', function () {
    $this->get(route('organizations.index'))->assertRedirect('/login');
});

it('returns 403 for users without crm.manage on company list', function () {
    $user = User::factory()->create();
    $user->assignRole('user'); // user role does not have crm.manage

    $this->actingAs($user)->get(route('organizations.index'))->assertForbidden();
});

it('allows operators to access the CRM company list', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->get(route('organizations.index'))->assertOk();
});

// ── Companies ─────────────────────────────────────────────────────────────────

it('creates an Organization from the Directory page', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->post(route('organizations.store'), [
        'name' => 'Acme Corp',
        'website' => 'https://acme.com',
        'phone' => '555-1234',
    ])->assertRedirect();

    $organization = Organization::withoutGlobalScopes()->where('name', 'Acme Corp')->first();

    // One entity: created Directory-only (never enabled), by the operator, with the default classification.
    expect($organization)->not->toBeNull()
        ->and($organization->portal_enabled_at)->toBeNull()
        ->and($organization->portalState()->isNotEnabled())->toBeTrue()
        ->and($organization->created_by)->toBe($operator->id)
        ->and($organization->classification->value)->toBe('general');
});

it('validates the Organization name is required', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->post(route('organizations.store'), [
        'name' => '',
    ])->assertSessionHasErrors('name');
});

it('validates the Organization website must be a url', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->post(route('organizations.store'), [
        'name' => 'Bad URL Co',
        'website' => 'not-a-url',
    ])->assertSessionHasErrors('website');
});

it('updates an Organization', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $company = Organization::factory()->directoryOnly()->create(['created_by' => $operator->id]);

    $this->actingAs($operator)->put(route('organizations.update', $company), [
        'name' => 'Updated Name',
        'phone' => '999-0000',
    ])->assertRedirect();

    expect($company->fresh()->name)->toBe('Updated Name')->and($company->fresh()->phone)->toBe('999-0000');
});

it('deletes a never-enabled Organization', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $company = Organization::factory()->directoryOnly()->create(['created_by' => $operator->id]);

    $this->actingAs($operator)->delete(route('organizations.destroy', $company))->assertRedirect();

    expect(Organization::withoutGlobalScopes()->find($company->id))->toBeNull();
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

it('affiliates a Person with an Organization', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $company = Organization::factory()->directoryOnly()->create(['created_by' => $operator->id]);

    $this->actingAs($operator)->post(route('crm.contacts.store'), [
        'first_name' => 'John',
        'last_name' => 'Smith',
        'organization_id' => $company->id,
    ])->assertRedirect();

    $contact = CrmContact::where('first_name', 'John')->first();
    expect($contact->organization_id)->toBe($company->id);
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

// ── Initial enablement of portal access ───────────────────────────────────────

it('enables portal access for a Directory-only Organization, keeping its row, its id and its creator', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $creator = User::factory()->create();

    $company = Organization::factory()->directoryOnly()->create(['created_by' => $creator->id, 'name' => 'Promote Me']);

    $this->actingAs($operator)->post(route('organizations.promote', $company))->assertRedirect(route('organizations.portal', $company));

    $company->refresh();
    expect($company->portalState()->isEnabled())->toBeTrue()
        ->and($company->name)->toBe('Promote Me')
        ->and($company->portal_enabled_by)->toBe($operator->id)
        ->and($company->created_by)->toBe($creator->id, 'enablement never rewrites who created the record')
        ->and($company->portal_enabled_at)->not->toBeNull()
        ->and(Organization::withoutGlobalScopes()->count())->toBe(1, 'no second row is created')
        ->and($company->hasMember($operator))->toBeTrue('the enabling operator is the automatic admin member');
});

it('refuses initial enablement of an already Enabled Organization with a controlled already_enabled result and writes nothing', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $company = Organization::factory()->directoryOnly()->create(['created_by' => $operator->id]);

    $service = app(OrganizationDirectory::class);
    $service->enablePortalAccess($company, $operator);
    $before = [(array) DB::table('organizations')->where('id', $company->id)->first(), DB::table('activity_log')->count(), DB::table('organization_members')->count()];

    try {
        $service->enablePortalAccess($company->fresh(), $operator);
        $this->fail('a second initial enablement must be refused');
    } catch (OrganizationRelationshipException $e) {
        expect($e->reason)->toBe(OrganizationRelationshipException::ALREADY_ENABLED);
    }

    expect([(array) DB::table('organizations')->where('id', $company->id)->first(), DB::table('activity_log')->count(), DB::table('organization_members')->count()])->toBe($before);
});

// ── Organization member management ───────────────────────────────────────────

it('allows operators to view organization list', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->get(route('organizations.access'))->assertOk();
});

it('adds a member to an organization', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $org = Organization::factory()->create(['portal_enabled_by' => $operator->id]);
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

    $org = Organization::factory()->create(['portal_enabled_by' => $operator->id]);
    $user = User::factory()->create();

    $org->members()->attach($user->id, ['role' => 'member']);

    $this->actingAs($operator)->delete(route('organizations.members.destroy', [$org, $user]))->assertRedirect();

    expect($org->hasMember($user))->toBeFalse();
});

it('updates a member role', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $org = Organization::factory()->create(['portal_enabled_by' => $operator->id]);
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

    $org = Organization::factory()->create(['portal_enabled_by' => $operator->id]);
    $user = User::factory()->create();

    $this->actingAs($operator)->delete(route('organizations.members.destroy', [$org, $user]))->assertNotFound();
});

// ── Directory service helpers ────────────────────────────────────────────────

it('generates a unique slug when two Organizations share a name', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $service = app(OrganizationDirectory::class);

    $org1 = $service->create($operator, ['name' => 'Same Name']);
    $org2 = $service->create($operator, ['name' => 'Same Name']);

    expect($org1->slug)->toBe('same-name')->and($org2->slug)->toBe('same-name-2');
});

<?php

use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ServiceRequestAccess;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

it('shows a member only the Organizations they belong to, and no People at all (owner ruling D7)', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $member = User::factory()->create();
    $member->assignRole('user');

    $orgA = Organization::factory()->create(['portal_enabled_by' => $operator->id, 'name' => 'Org A']);
    $orgB = Organization::factory()->create(['portal_enabled_by' => $operator->id, 'name' => 'Org B']);
    $directoryOnly = Organization::factory()->directoryOnly()->create(['created_by' => $operator->id, 'name' => 'Directory Only']);
    $orgA->members()->attach($member, ['role' => 'member']);

    // Before ORG-2 a member saw the People of their Organization's Company. A Person is a Directory record visible only to operators now,
    // whichever Organization they are affiliated with.
    CrmContact::factory()->create(['created_by' => $operator->id, 'organization_id' => $orgA->id]);
    CrmContact::factory()->create(['created_by' => $operator->id, 'organization_id' => $orgB->id]);
    CrmContact::factory()->create(['created_by' => $operator->id, 'organization_id' => $directoryOnly->id]);

    $this->actingAs($member);

    expect(Organization::pluck('id')->all())->toBe([$orgA->id])
        ->and(CrmContact::count())->toBe(0)
        ->and(CrmContact::with('organization')->get())->toBeEmpty();
});

it('returns an empty tenant view for a user without organization memberships', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $user = User::factory()->create();
    $user->assignRole('user');

    $organization = Organization::factory()->create(['portal_enabled_by' => $operator->id]);
    CrmContact::factory()->create([
        'created_by' => $operator->id,
        'organization_id' => $organization->id,
    ]);

    $this->actingAs($user);

    expect(CrmContact::count())->toBe(0)
        ->and(Organization::count())->toBe(0);
});

it('shows every Organization joined by a multi-organization user, and still no People', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $member = User::factory()->create();
    $member->assignRole('user');

    $organizations = Organization::factory()->count(2)->create(['portal_enabled_by' => $operator->id]);
    foreach ($organizations as $organization) {
        $organization->members()->attach($member, ['role' => 'member']);
        CrmContact::factory()->create(['created_by' => $operator->id, 'organization_id' => $organization->id]);
    }

    $this->actingAs($member);

    expect(Organization::pluck('id')->all())->toEqualCanonicalizing($organizations->modelKeys())
        ->and(CrmContact::count())->toBe(0);
});

it('retains cross-tenant and unaffiliated record visibility for operators, Directory-only Organizations included', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $organizations = Organization::factory()->count(2)->create(['portal_enabled_by' => $operator->id]);
    foreach ($organizations as $organization) {
        CrmContact::factory()->create([
            'created_by' => $operator->id,
            'organization_id' => $organization->id,
        ]);
    }

    Organization::factory()->directoryOnly()->create(['created_by' => $operator->id]);
    CrmContact::factory()->create(['created_by' => $operator->id]);

    $this->actingAs($operator);

    expect(CrmContact::count())->toBe(3)
        ->and(Organization::count())->toBe(3);
});

it('answers every Directory page for a privileged non-operator with a refusal, and never confirms a hidden Organization exists', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $member = User::factory()->create();
    $member->assignRole('user');
    $member->givePermissionTo('crm.manage');

    $orgA = Organization::factory()->create(['portal_enabled_by' => $operator->id, 'name' => 'Visible Tenant Company', 'notes' => 'SENTINEL-NOTE-A']);
    $orgB = Organization::factory()->create(['portal_enabled_by' => $operator->id, 'name' => 'Hidden Tenant Company', 'notes' => 'SENTINEL-NOTE-B']);
    $orgA->members()->attach($member, ['role' => 'admin']);

    // `crm.manage` alone is not Directory authority: the Directory pages ask `manageDirectory` (portal.operator AND crm.manage), so a customer who holds a
    // stray `crm.manage` reads no internal note and no People, even of their own Organization.
    $this->actingAs($member)->get(route('organizations.index'))->assertForbidden();
    $this->actingAs($member)->get(route('organizations.show', $orgA))->assertForbidden()->assertDontSee('SENTINEL-NOTE-A');
    $this->actingAs($member)->get(route('organizations.edit', $orgA))->assertForbidden()->assertDontSee('SENTINEL-NOTE-A');
    $this->actingAs($member)->get(route('crm.contacts.index'))->assertForbidden();

    // A hidden Organization is not found at all (the membership scope resolves the binding first), exactly as one that does not exist.
    $this->actingAs($member)->get(route('organizations.show', $orgB))->assertNotFound()->assertDontSee('SENTINEL-NOTE-B');
    $this->actingAs($member)->get(route('organizations.show', 999999))->assertNotFound();
});

it('prevents organization member management across tenant boundaries', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $manager = User::factory()->create();
    $manager->assignRole('user');
    $manager->givePermissionTo('crm.manage');
    $newMember = User::factory()->create();

    $orgA = Organization::factory()->create(['portal_enabled_by' => $operator->id]);
    $orgB = Organization::factory()->create(['portal_enabled_by' => $operator->id]);
    $orgA->members()->attach($manager, ['role' => 'admin']);

    $this->actingAs($manager)
        ->post(route('organizations.members.store', $orgB), [
            'user_id' => $newMember->id,
            'role' => 'member',
        ])
        ->assertNotFound();

    expect($orgB->members()->whereKey($newMember->id)->exists())->toBeFalse();

    // MO-2A1: membership is operator-only (portal.operator AND crm.manage). Before, a customer holding crm.manage who was an
    // administrator of the organization could add members to it (and make them administrators). Role is not authority.
    $this->actingAs($manager)
        ->post(route('organizations.members.store', $orgA), [
            'user_id' => $newMember->id,
            'role' => 'member',
        ])
        ->assertForbidden();

    expect($orgA->members()->whereKey($newMember->id)->exists())->toBeFalse();
});

it('rejects cross-tenant Organization identifiers during contact, ticket and project writes', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $member = User::factory()->create();
    $member->assignRole('user');
    $member->givePermissionTo('crm.manage');
    $member->givePermissionTo('projects.manage');

    $orgA = Organization::factory()->create(['portal_enabled_by' => $operator->id]);
    $orgB = Organization::factory()->create(['portal_enabled_by' => $operator->id]);
    $orgA->members()->attach($member, ['role' => 'member']);
    $project = Project::factory()->create(['created_by' => $operator->id]);
    $project->members()->attach($member, ['role' => 'manager']);

    // A Person is a Directory write: refused for a non-operator whatever Organization it names, and nothing is written.
    $this->actingAs($member)
        ->post(route('crm.contacts.store'), [
            'organization_id' => $orgB->id,
            'first_name' => 'Cross',
            'last_name' => 'Tenant',
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('tickets.store'), [
            // R1-2: the organization is decided from the member's CURRENT membership; naming another tenant's is refused (the same answer as
            // for an organization that does not exist). A company id is no longer an input at all.
            'organization_id' => $orgB->id,
            'title' => 'Cross-tenant ticket',
            'description' => 'Must not attach to another tenant.',
            'category' => 'General',
            'priority' => 'medium',
        ])
        ->assertSessionHasErrors(['organization_id' => ServiceRequestAccess::NOT_AVAILABLE]);

    $this->actingAs($member)
        ->put(route('projects.companies.sync', $project), [
            'companies' => [$orgB->id],
        ])
        ->assertSessionHasErrors('companies.0');

    expect(CrmContact::withoutGlobalScopes()->where('first_name', 'Cross')->exists())->toBeFalse()
        ->and(Ticket::where('title', 'Cross-tenant ticket')->exists())->toBeFalse()
        ->and(DB::table('project_organization')
            ->where('project_id', $project->id)
            ->where('organization_id', $orgB->id)
            ->exists())->toBeFalse();
});

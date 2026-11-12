<?php

/*
 * Release 1.0 (R1-3B): how the admin user page says where an account came from. An account created by accepting an organization invitation
 * (`organization_invitations.accepted_user_id`, through User::organizationInvitation()) says so; every other account says nothing. The retired
 * organization-less invitation (table and `users.invitation_id`) was removed outright, so there is no historical presentation to test.
 */

require_once __DIR__.'/../Auth/InvitationTestHelpers.php';

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->viewer = User::factory()->create()->assignRole('operator');
});

function provenanceAcceptedBy(User $user, Organization $organization, User $issuer): OrganizationInvitation
{
    return OrganizationInvitation::factory()
        ->forAddress($user->email)
        ->accepted($user)
        ->state(['organization_id' => $organization->id, 'invited_by' => $issuer->id])
        ->create();
}

it('shows the organization and the inviter for an account created by an organization invitation', function () {
    $issuer = regInvIssuer(['name' => 'Ivy Issuer']);
    $organization = Organization::factory()->create(['name' => 'Acme Holdings', 'owner_id' => $issuer->id]);
    $user = User::factory()->create(['email' => 'joined@example.test']);
    provenanceAcceptedBy($user, $organization, $issuer);

    $page = $this->actingAs($this->viewer)->get(route('users.show', $user))->assertOk()
        ->assertSee('Joined by invitation')
        ->assertSee('Acme Holdings')
        ->assertSee('by invitation from Ivy Issuer')
        ->assertDontSee('Invited by');

    // An operator may open the organization, so the name links to it.
    expect($page->getContent())->toContain(route('organizations.show', $organization));
});

it('neither names nor links the organization to a viewer the organization tenant scope hides it from', function () {
    $issuer = regInvIssuer(['name' => 'Ivy Issuer']);
    $organization = Organization::factory()->create(['name' => 'Acme Holdings', 'owner_id' => $issuer->id]);
    $user = User::factory()->create();
    provenanceAcceptedBy($user, $organization, $issuer);
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('users.view');

    // Organizations are tenant-scoped (operators see all, others only their own): the page must not widen that.
    $content = $this->actingAs($viewer)->get(route('users.show', $user))->assertOk()
        ->assertSee('by invitation from Ivy Issuer')->getContent();

    expect($content)->not->toContain('Acme Holdings')->not->toContain(route('organizations.show', $organization))
        ->and(preg_replace('/\\s+/', ' ', strip_tags($content)))->toContain('Joined an organization by invitation from Ivy Issuer');
});

it('escapes an organization or inviter name that contains markup', function () {
    $issuer = regInvIssuer(['name' => '<b>Evil</b> Issuer']);
    $organization = Organization::factory()->create(['name' => '<script>alert(1)</script> Org', 'owner_id' => $issuer->id]);
    $user = User::factory()->create();
    provenanceAcceptedBy($user, $organization, $issuer);

    $content = $this->actingAs($this->viewer)->get(route('users.show', $user))->assertOk()->getContent();

    expect($content)->not->toContain('<script>alert(1)</script>')->not->toContain('<b>Evil</b>')
        ->and($content)->toContain('&lt;script&gt;alert(1)&lt;/script&gt; Org');
});

it('shows no historical invitation block: the retired organization-less invitation has no presentation, only invited_by remains as data', function () {
    $inviter = User::factory()->create(['name' => 'Lena Legacy']);
    // An account that records who invited it but has no accepted organization invitation (the only shape a pre-R1-3 account can now have).
    $user = User::factory()->create(['invited_by' => $inviter->id]);

    $this->actingAs($this->viewer)->get(route('users.show', $user))->assertOk()
        ->assertDontSee('Invited by')
        ->assertDontSee('Lena Legacy')
        ->assertDontSee('Joined by invitation');
});

it('shows no invitation provenance for an account that was never invited', function () {
    $user = User::factory()->create();

    $this->actingAs($this->viewer)->get(route('users.show', $user))->assertOk()
        ->assertDontSee('Invited by')
        ->assertDontSee('Joined by invitation');
});

it('resolves the relation through accepted_user_id, one way, and is empty for an unaccepted invitation of the same address', function () {
    $issuer = regInvIssuer();
    $organization = Organization::factory()->create(['owner_id' => $issuer->id]);
    $user = User::factory()->create(['email' => 'same@example.test']);
    $accepted = provenanceAcceptedBy($user, $organization, $issuer);

    // An open invitation that names the same address in another organization is not provenance.
    $other = Organization::factory()->create(['owner_id' => $issuer->id]);
    OrganizationInvitation::factory()->forAddress('same@example.test')->state(['organization_id' => $other->id, 'invited_by' => $issuer->id])->create();

    expect($user->organizationInvitation->is($accepted))->toBeTrue()
        ->and(User::factory()->create()->organizationInvitation)->toBeNull();
});

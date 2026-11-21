<?php

use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OrganizationDirectory;
use App\Services\OrganizationPreflight;
use App\Services\OrganizationRelationships;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Release 1.0 (ORG-2, CP5): `organizations:preflight` on the CANONICAL schema, where `crm_companies` is gone. The historical run of the same command (the
 * Company sections) is tests/Historical/OrganizationPreflightTest.php; this file holds the sections that do not depend on a Company: the report's shape, the portal
 * state and the Directory-only invariant (S11), the relationships, and the canonical section (creator, Classification catalogue). Deliberately invalid rows are
 * written by raw inserts with the relevant guards lifted; production constraints are not weakened to do it.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create()->assignRole('operator');
});

it('passes on an empty canonical database, writes nothing, and has no Company sections', function () {
    $report = app(OrganizationPreflight::class)->report();

    expect($report['failures'])->toBe([])
        ->and($report['companies'])->toBe(0)
        ->and($report['canonical'])->toBe(['byClassification' => [], 'violations' => ['createdByMissing' => [], 'unknownClassification' => []]]);

    $this->artisan('organizations:preflight')
        ->expectsOutputToContain('Organizations: 0')
        ->expectsOutputToContain('No blocking findings. Nothing was written.')
        ->assertSuccessful();
});

it('does not print the retired Company record as if it were current (CP7): the text report names the one Organization record only', function () {
    Artisan::call('organizations:preflight');
    $output = Artisan::output();

    expect($output)->toContain('Organizations: 0')->toContain('Classification: none')
        ->not->toContain('CRM companies')->not->toContain('Companies without an organization');
});

it('counts portal states and classifications of valid data, and passes', function () {
    $service = app(OrganizationDirectory::class);
    $service->create($this->operator, ['name' => 'Directory One']);
    $service->create($this->operator, ['name' => 'Directory Two', 'classification' => 'client']);
    $enabled = $service->enablePortalAccess($service->create($this->operator, ['name' => 'Enabled Vendor', 'classification' => 'vendor']), $this->operator);
    app(OrganizationRelationships::class)->disable(Organization::factory()->create(['portal_enabled_by' => $this->operator->id]), $this->operator);

    $report = app(OrganizationPreflight::class)->report();

    expect($report['failures'])->toBe([])
        ->and($report['portalState'])->toMatchArray(['notEnabled' => 2, 'enabled' => 1, 'disabled' => 1])
        ->and($report['canonical']['byClassification'])->toBe(['client' => 1, 'general' => 2, 'vendor' => 1])
        ->and($enabled->portalState()->isEnabled())->toBeTrue();

    $this->artisan('organizations:preflight')->expectsOutputToContain('Classification')->assertSuccessful();
});

it('fails on a Directory-only Organization with an invitation, a ticket, a member or a relationship, naming ids and never an address', function (string $rule, Closure $violate) {
    $directoryOnly = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);
    $violate($directoryOnly, $this->operator);

    $report = app(OrganizationPreflight::class)->report();
    $failures = implode("\n", $report['failures']);

    expect($report['failures'])->not->toBeEmpty()
        ->and($failures)->toContain("({$rule})")->toContain((string) $directoryOnly->id)
        ->and($failures)->not->toContain('invitee@example.test');

    $this->artisan('organizations:preflight')->assertFailed();
})->with([
    'an invitation' => ['directoryOnlyWithInvitations', function (Organization $o, User $operator) {
        DB::table('organization_invitations')->insert([
            'organization_id' => $o->id, 'email' => 'invitee@example.test', 'live_email' => 'invitee@example.test', 'token_hash' => str_repeat('a', 64),
            'invited_by' => $operator->id, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }],
    'a ticket' => ['directoryOnlyWithTickets', fn (Organization $o) => Ticket::factory()->inOrganization($o)->create()],
    'a member' => ['directoryOnlyWithMembers', fn (Organization $o) => DB::table('organization_members')->insert(['organization_id' => $o->id, 'user_id' => User::factory()->create()->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()])],
    'a managed customer' => ['directoryOnlyWithRelationships', fn (Organization $o) => DB::table('organizations')->where('id', Organization::factory()->create()->id)->update(['managed_by_organization_id' => $o->id])],
]);

it('fails on a managed relationship recorded on an Organization that never had portal access', function () {
    $partner = Organization::factory()->create(['portal_enabled_by' => $this->operator->id]);
    app(OrganizationRelationships::class)->designatePartner($partner, $this->operator);
    $directoryOnly = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);

    // The CHECK that states it is lifted for the fixture only (a dump restored by an engine that did not enforce it).
    DB::statement('alter table organizations drop constraint organizations_managed_requires_portal_check');
    DB::table('organizations')->where('id', $directoryOnly->id)->update(['managed_by_organization_id' => $partner->id]);

    $failures = implode("\n", app(OrganizationPreflight::class)->report()['failures']);
    expect($failures)->toContain('(managedWithoutPortal)')->toContain('(directoryOnlyWithRelationships)')->toContain((string) $directoryOnly->id);
})->skip(fn () => DB::selectOne("select count(*) as n from information_schema.check_constraints where constraint_schema = database() and constraint_name = 'organizations_managed_requires_portal_check'")->n === 0, 'the managed-requires-portal CHECK is not present on this schema');

it('fails on a classification outside the catalogue and on an Organization whose creator is not a user, and says so by id', function () {
    $bad = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);
    DB::table('organizations')->where('id', $bad->id)->update(['classification' => 'enterprise']);

    DB::statement('set foreign_key_checks = 0');
    $orphan = Organization::factory()->directoryOnly()->create(['created_by' => 777777]);
    DB::statement('set foreign_key_checks = 1');

    $report = app(OrganizationPreflight::class)->report();

    expect($report['canonical']['violations']['unknownClassification'])->toBe([$bad->id])
        ->and($report['canonical']['violations']['createdByMissing'])->toBe([$orphan->id])
        ->and(implode("\n", $report['failures']))->toContain('(unknownClassification)')->toContain('(createdByMissing)');

    $this->artisan('organizations:preflight')->assertFailed();
});

it('reads no lock and writes nothing', function () {
    Organization::factory()->create(['portal_enabled_by' => $this->operator->id]);
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query->sql) || str_contains($query->sql, 'for update') || str_contains($query->sql, 'lock in share mode')) {
            $writes[] = $query->sql;
        }
    });

    app(OrganizationPreflight::class)->report();

    expect($writes)->toBe([]);
});

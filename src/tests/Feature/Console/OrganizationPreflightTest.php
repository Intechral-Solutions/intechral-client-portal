<?php

use App\Models\CrmCompany;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OrganizationPreflight;
use App\Services\OrganizationRelationships;
use Illuminate\Support\Carbon;
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
    // Two tickets in Acme (the customer is a member), one in Globex whose requester has since left it.
    Ticket::factory()->create(['user_id' => $customer->id, 'organization_id' => $acme->id]);
    Ticket::factory()->create(['user_id' => $customer->id, 'organization_id' => $acme->id]);
    Ticket::factory()->create(['user_id' => $lead->id, 'organization_id' => $orphan->id]);

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
        ->and($report['ticketAttribution'])->toBe([
            'attributed' => true,
            'tickets' => 3,
            'perOrganization' => [['organization_id' => $acme->id, 'tickets' => 2], ['organization_id' => $orphan->id, 'tickets' => 1]],
            'requesterNoLongerMember' => 1,
        ])
        ->and($report['failures'])->toBe([])
        ->and(count($report['warnings']))->toBe(5);   // MO-2A1 added the "administrators are not approved" warning; R1-2 replaced "tickets carry a company" with "requester no longer a member"

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

it('inventories administrator approval by ids only: unapproved, approved, suspended, operator-held, stale, incomplete, uncovered organizations, multi-organization administrators', function () {
    $operator = User::factory()->create(['email' => 'op@example.test'])->assignRole('operator');
    $custA = User::factory()->create(['email' => 'a@example.test'])->assignRole('user');
    $custB = User::factory()->create(['email' => 'b@example.test'])->assignRole('user');
    $suspended = User::factory()->create(['email' => 'gone@example.test', 'disabled_at' => now()])->assignRole('user');
    $promoted = User::factory()->create(['email' => 'promoted@example.test'])->assignRole('user');
    $stale = User::factory()->create(['email' => 'stale@example.test'])->assignRole('user');
    $broken = User::factory()->create(['email' => 'broken@example.test'])->assignRole('user');

    $acme = Organization::factory()->create(['name' => 'Acme', 'owner_id' => $operator->id]);
    $globex = Organization::factory()->create(['name' => 'Globex', 'owner_id' => $operator->id]);
    $empty = Organization::factory()->create(['name' => 'Empty', 'owner_id' => $operator->id]);

    // ONE instant for every timestamp of every fixture row: the changed-after-approval check compares updated_at with
    // admin_approved_at, and two now() calls straddling a second boundary would flake it.
    $stamp = now()->startOfSecond();

    $row = fn (Organization $organization, User $user, string $role, ?User $approvedBy = null, bool $half = false) => DB::table('organization_members')->insert([
        'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role,
        'admin_approved_at' => ($approvedBy || $half) ? $stamp : null, 'admin_approved_by' => $approvedBy?->id,
        'created_at' => $stamp, 'updated_at' => $stamp,
    ]);

    $row($acme, $operator, 'admin');                       // operator-held, unapproved
    $row($acme, $custA, 'admin', $operator);               // approved, active
    $row($globex, $custA, 'admin', $operator);             // the same administrator in a second organization
    $row($acme, $custB, 'admin');                          // historical, unapproved
    $row($acme, $suspended, 'admin', $operator);           // approved but suspended
    $row($globex, $promoted, 'admin', $operator);          // approved, then made an operator
    $promoted->assignRole('operator');
    $row($globex, $stale, 'member', $operator);            // approval left on a member row
    $row($globex, $broken, 'member', null, half: true);    // approval time without an approver

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $writes = [DB::table('organization_members')->count(), DB::table('activity_log')->count(), DB::table('organization_members')->whereNotNull('admin_approved_at')->count()];

    $report = app(OrganizationPreflight::class)->report();
    $approval = $report['adminApproval'];
    $pair = fn (Organization $organization, User $user) => ['organization_id' => $organization->id, 'user_id' => $user->id];

    expect($approval['unapprovedAdministrators'])->toEqual([$pair($acme, $operator), $pair($acme, $custB)])
        ->and($approval['approvedActiveCustomerAdministrators'])->toEqual([$pair($acme, $custA), $pair($globex, $custA)])
        ->and($approval['approvedSuspendedAdministrators'])->toEqual([$pair($acme, $suspended)])
        ->and($approval['operatorHeldAdministrators'])->toEqual([$pair($acme, $operator), $pair($globex, $promoted)])
        ->and($approval['approvedButNowOperator'])->toEqual([$pair($globex, $promoted)])
        ->and($approval['approvedButNotAdministrator'])->toEqual([$pair($globex, $stale), $pair($globex, $broken)])
        ->and($approval['incompleteApprovals'])->toEqual([$pair($globex, $broken)])
        ->and($approval['organizationsWithoutApprovedAdministrator'])->toBe([$empty->id])
        ->and($approval['multiOrganizationAdministrators'])->toBe([['user_id' => $custA->id, 'organizations' => 2]])
        ->and($approval['changedAfterApproval'])->toBe([], 'every fixture row shares one instant')
        ->and($report['failures'])->toHaveCount(2)
        ->and($report['failures'][0])->toContain('approval time without an approver')
        ->and($report['failures'][1])->toContain('carry an approval record but are not administrators');

    // Ids only. (Pest's second argument to not->toContain is another needle, not a message, so assert the string directly.)
    expect(str_contains(json_encode($approval), '@'))->toBeFalse('the inventory holds ids only, never an email');

    expect([DB::table('organization_members')->count(), DB::table('activity_log')->count(), DB::table('organization_members')->whereNotNull('admin_approved_at')->count()])
        ->toBe($writes, 'read-only: nobody was approved, demoted or removed');

    $this->artisan('organizations:preflight')->expectsOutputToContain('Administrator approval: 2 unapproved, 2 approved and active, 1 approved but suspended, 2 operator-held; 1 organization(s) without an approved administrator')->assertFailed();
    $this->artisan('organizations:preflight --json')->assertFailed();
});

it('passes cleanly with historical administrators that simply are not approved yet, and says so', function () {
    $operator = User::factory()->create()->assignRole('operator');
    $customer = User::factory()->create()->assignRole('user');
    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    $organization->members()->attach($customer, ['role' => 'admin']);

    $report = app(OrganizationPreflight::class)->report();

    expect($report['adminApproval']['unapprovedAdministrators'])->toBe([['organization_id' => $organization->id, 'user_id' => $customer->id]])
        ->and($report['adminApproval']['approvedActiveCustomerAdministrators'])->toBe([])
        ->and($report['failures'])->toBe([])
        ->and(collect($report['warnings'])->contains(fn (string $warning) => str_contains($warning, 'not approved and hold no delegated authority')))->toBeTrue();

    $this->artisan('organizations:preflight')->assertSuccessful();
});

/** One membership row for the integrity tests below; every timestamp is the one given, so nothing depends on the clock. */
function integrityRow(Organization $organization, User $user, string $role, ?Carbon $approvedAt, ?User $approvedBy, ?Carbon $updatedAt = null): void
{
    DB::table('organization_members')->insert([
        'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $role,
        'admin_approved_at' => $approvedAt, 'admin_approved_by' => $approvedBy?->id,
        'created_at' => $approvedAt ?? $updatedAt, 'updated_at' => $updatedAt ?? $approvedAt,
    ]);
}

it('fails on a member row that carries an approval, and on nothing else: the damage an older release leaves after a demotion', function () {
    $operator = User::factory()->create()->assignRole('operator');
    $customer = User::factory()->create()->assignRole('user');
    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    $at = now()->startOfSecond();
    integrityRow($organization, $customer, 'member', $at, $operator);

    $report = app(OrganizationPreflight::class)->report();
    $pair = [['organization_id' => $organization->id, 'user_id' => $customer->id]];

    expect($report['adminApproval']['approvedButNotAdministrator'])->toBe($pair)
        ->and($report['adminApproval']['incompleteApprovals'])->toBe([])
        ->and($report['adminApproval']['changedAfterApproval'])->toBe([])
        ->and($report['failures'])->toHaveCount(1)
        ->and($report['failures'][0])->toContain('carry an approval record but are not administrators');

    $this->artisan('organizations:preflight')->assertFailed();
});

it('fails on a half-written approval record in either direction, and such a row never counts as an approved administrator', function () {
    $operator = User::factory()->create()->assignRole('operator');
    $timeOnly = User::factory()->create()->assignRole('user');
    $approverOnly = User::factory()->create()->assignRole('user');
    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    $at = now()->startOfSecond();
    integrityRow($organization, $timeOnly, 'admin', $at, null);
    integrityRow($organization, $approverOnly, 'admin', null, $operator, $at);

    $report = app(OrganizationPreflight::class)->report();
    $incomplete = $report['adminApproval']['incompleteApprovals'];

    expect($incomplete)->toEqual([
        ['organization_id' => $organization->id, 'user_id' => $timeOnly->id],
        ['organization_id' => $organization->id, 'user_id' => $approverOnly->id],
    ])
        ->and($report['adminApproval']['approvedActiveCustomerAdministrators'])->toBe([])
        ->and($report['adminApproval']['organizationsWithoutApprovedAdministrator'])->toBe([$organization->id])
        ->and($report['failures'])->toHaveCount(1)
        ->and($report['failures'][0])->toContain('approval time without an approver, or the reverse');

    $this->artisan('organizations:preflight')->assertFailed();
});

it('fails on an approved administrator row changed after its approval, as diagnostic evidence, and passes at the same instant or earlier', function () {
    $operator = User::factory()->create()->assignRole('operator');
    $revived = User::factory()->create()->assignRole('user');
    $same = User::factory()->create()->assignRole('user');
    $earlier = User::factory()->create()->assignRole('user');
    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    $at = now()->startOfSecond();

    integrityRow($organization, $revived, 'admin', $at, $operator, $at->copy()->addSecond());   // older code re-promoted it
    integrityRow($organization, $same, 'admin', $at, $operator, $at);                           // what the service writes
    integrityRow($organization, $earlier, 'admin', $at, $operator, $at->copy()->subSecond());

    $report = app(OrganizationPreflight::class)->report();

    expect($report['adminApproval']['changedAfterApproval'])->toBe([['organization_id' => $organization->id, 'user_id' => $revived->id]])
        ->and($report['adminApproval']['approvedButNotAdministrator'])->toBe([])
        ->and($report['adminApproval']['incompleteApprovals'])->toBe([])
        ->and($report['failures'])->toHaveCount(1)
        ->and($report['failures'][0])->toContain('updated_at is later than admin_approved_at')
        ->and($report['failures'][0])->toContain('proves nothing');

    $this->artisan('organizations:preflight')->assertFailed();

    // Without the one flagged row the same data is clean: the other two never trip it.
    DB::table('organization_members')->where('user_id', $revived->id)->delete();
    expect(app(OrganizationPreflight::class)->report()['failures'])->toBe([]);
    $this->artisan('organizations:preflight')->assertSuccessful();
});

// ── Release 1.0 (R1-3): organization invitations ─────────────────────────────

/** An invitation row written straight to the table (the service is the only writer in app/; this is a fixture). */
function preflightInvitation(Organization $organization, User $issuer, string $email, array $state = []): int
{
    return (int) DB::table('organization_invitations')->insertGetId(array_merge([
        'organization_id' => $organization->id, 'email' => $email, 'live_email' => $email, 'token_hash' => hash('sha256', $email.random_int(1, PHP_INT_MAX)),
        'invited_by' => $issuer->id, 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now(),
    ], $state));
}

it('reports the invitation counts by derived state, ids only, and passes on data the service could have written', function () {
    $operator = User::factory()->create();
    $operator->givePermissionTo(['portal.operator', 'crm.manage']);
    $acme = Organization::factory()->create(['owner_id' => $operator->id]);
    $globex = Organization::factory()->create(['owner_id' => $operator->id]);
    $member = User::factory()->create();

    $openA = preflightInvitation($acme, $operator, 'a@example.test');
    $openB = preflightInvitation($acme, $operator, 'b@example.test');
    $openC = preflightInvitation($globex, $operator, 'c@example.test');
    // Expired by the clock, and a hook-revoked row that KEEPS its slot: both are legitimate.
    preflightInvitation($acme, $operator, 'old@example.test', ['expires_at' => now()->subDay()]);
    preflightInvitation($acme, $operator, 'hooked@example.test', ['revoked_at' => now(), 'revoked_reason' => 'issuer_suspended']);
    preflightInvitation($acme, $operator, 'gone@example.test', ['live_email' => null, 'revoked_at' => now(), 'revoked_by' => $operator->id, 'revoked_reason' => 'operator']);
    preflightInvitation($acme, $operator, 'in@example.test', ['live_email' => null, 'accepted_at' => now(), 'accepted_user_id' => $member->id]);

    $report = app(OrganizationPreflight::class)->report();
    $invitations = $report['invitations'];

    expect($invitations)->toMatchArray(['total' => 7, 'open' => 3, 'accepted' => 1, 'revoked' => 2, 'expired' => 1])
        ->and($invitations['openIds'])->toBe([$openA, $openB, $openC])
        ->and($invitations['openPerOrganization'])->toBe([['organization_id' => $acme->id, 'open' => 2], ['organization_id' => $globex->id, 'open' => 1]])
        ->and($invitations['openIssuerNotMembershipManager'])->toBe([])->and($invitations['openAddressAlreadyAnAccount'])->toBe([])
        ->and(array_filter($invitations['violations']))->toBe([])
        ->and($report['failures'])->toBe([])->and(collect($report['warnings'])->filter(fn (string $warning) => str_contains($warning, 'nvitation')))->toBeEmpty()
        // Ids and counts only: no address, token or hash anywhere in the report.
        ->and(json_encode($report))->not->toContain('@example.test')->and(json_encode($report))->not->toMatch('/[0-9a-f]{64}/');

    $this->artisan('organizations:preflight')->expectsOutputToContain('Organization invitations: 3 open, 1 accepted, 2 revoked, 1 expired (7 in total)')->assertSuccessful();
    $this->artisan('organizations:preflight', ['--json' => true])->assertSuccessful();
});

it('warns, without failing, about an open invitation that cannot be accepted: the address is an account, or the issuer lost the authority', function () {
    $operator = User::factory()->create();
    $operator->givePermissionTo(['portal.operator', 'crm.manage']);
    $lapsed = User::factory()->create();
    $lapsed->givePermissionTo(['portal.operator', 'crm.manage']);
    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    $taken = preflightInvitation($organization, $operator, 'taken@example.test');
    User::factory()->create(['email' => 'Taken@Example.test']);
    $orphan = preflightInvitation($organization, $lapsed, 'orphan@example.test');
    preflightInvitation($organization, $operator, 'fine@example.test');
    DB::table('model_has_permissions')->where('model_id', $lapsed->id)->delete();   // a path no hook covers

    $report = app(OrganizationPreflight::class)->report();

    expect($report['failures'])->toBe([])
        ->and($report['invitations']['openAddressAlreadyAnAccount'])->toBe([$taken])
        ->and($report['invitations']['openIssuerNotMembershipManager'])->toBe([$orphan])
        ->and(collect($report['warnings'])->filter(fn (string $warning) => str_contains($warning, 'open invitation(s)')))->toHaveCount(2)
        ->and(implode(' ', $report['warnings']))->toContain('no longer a membership manager')->and(implode(' ', $report['warnings']))->toContain('already has an account');

    $this->artisan('organizations:preflight')->assertSuccessful();
});

it('fails on an invitation row the service can never write, naming the rule and the id, and never the address', function (string $rule, array $state) {
    $operator = User::factory()->create();
    $operator->givePermissionTo(['portal.operator', 'crm.manage']);
    $member = User::factory()->create();
    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    $id = preflightInvitation($organization, $operator, 'x@example.test', array_map(fn ($value) => match ($value) {
        '@member' => $member->id, '@operator' => $operator->id, '@now' => now(), default => $value
    }, $state));

    $report = app(OrganizationPreflight::class)->report();

    expect($report['invitations']['violations'][$rule])->toBe([$id])
        ->and($report['failures'])->toHaveCount(1)->and($report['failures'][0])->toContain($rule)->toContain((string) $id)->not->toContain('x@example.test');

    $this->artisan('organizations:preflight')->assertFailed();

    // The same data without the one broken row is clean.
    DB::table('organization_invitations')->delete();
    expect(app(OrganizationPreflight::class)->report()['failures'])->toBe([]);
})->with([
    'an open row that holds no slot' => ['openWithoutSlot', ['live_email' => null]],
    'a slot that is not the address' => ['slotIsNotTheAddress', ['live_email' => 'other@example.test']],
    'an accepted row still holding the slot' => ['acceptedStillHoldingSlot', ['accepted_at' => '@now', 'accepted_user_id' => '@member']],
    'accepted and revoked at once' => ['acceptedAndRevoked', ['live_email' => null, 'accepted_at' => '@now', 'accepted_user_id' => '@member', 'revoked_at' => '@now', 'revoked_reason' => 'operator']],
    'an acceptance without its account' => ['acceptanceHalfRecorded', ['live_email' => null, 'accepted_at' => '@now']],
    'a revocation reason without a revocation' => ['revocationDetailWithoutRevocation', ['revoked_reason' => 'operator']],
    'an actor without a revocation' => ['revocationDetailWithoutRevocation', ['revoked_by' => '@operator']],
]);

it('warns about a revocation code it does not know, and reads the row as revoked', function () {
    $operator = User::factory()->create();
    $operator->givePermissionTo(['portal.operator', 'crm.manage']);
    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    $id = preflightInvitation($organization, $operator, 'x@example.test', ['live_email' => null, 'revoked_at' => now(), 'revoked_reason' => 'something_new']);

    $report = app(OrganizationPreflight::class)->report();

    expect($report['invitations']['revokedWithUnknownReason'])->toBe([$id])->and($report['invitations']['revoked'])->toBe(1)->and($report['invitations']['open'])->toBe(0)
        ->and($report['failures'])->toBe([])->and(collect($report['warnings'])->filter(fn (string $warning) => str_contains($warning, 'revocation reason')))->toHaveCount(1);
});

it('reads no lock and writes nothing while it counts invitations', function () {
    $operator = User::factory()->create();
    $operator->givePermissionTo(['portal.operator', 'crm.manage']);
    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    preflightInvitation($organization, $operator, 'x@example.test');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $before = DB::table('organization_invitations')->get()->toJson();

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(OrganizationPreflight::class)->report();
    $statements = collect(DB::getQueryLog())->pluck('query')->map(fn (string $sql) => strtolower(ltrim($sql)));
    DB::disableQueryLog();

    expect($statements->filter(fn (string $sql) => ! str_starts_with($sql, 'select') && ! str_starts_with($sql, 'show')))->toBeEmpty()
        ->and($statements->filter(fn (string $sql) => str_contains($sql, 'for update') || str_contains($sql, 'lock in share mode') || str_contains($sql, 'for share')))->toBeEmpty()
        ->and($statements->filter(fn (string $sql) => str_contains($sql, '`cache`')))->toBeEmpty()
        ->and(DB::table('organization_invitations')->get()->toJson())->toBe($before);
});

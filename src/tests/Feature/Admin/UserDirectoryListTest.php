<?php

/*
 * Release 1.0 (DIR-1): System → Users gains an Organizations column, an account-type filter and a status filter, and the user page gains a
 * read-only Memberships section. Presentation and read queries only: nothing here changes a role, a membership, a permission or the
 * account lifecycle.
 *
 * "Operator" means the `portal.operator` capability (CustomerPilotGate::isOperator), never a role literally named `operator`. Every other account
 * is a customer by the product's own boundary, including an internal account whose roles lack the capability; the parity test below pins that.
 */

use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\User;
use App\Services\UserMembershipList;
use App\Shared\Pilot\CustomerPilotGate;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->admin = User::factory()->create(['name' => 'Ada Admin', 'email' => 'ada@example.test'])->assignRole('operator');
});

/** A customer: the built-in `user` role, as an invitation assigns it. */
function dir1Customer(string $name, ?string $email = null, array $attributes = []): User
{
    return User::factory()->create(['name' => $name, 'email' => $email ?? str($name)->slug().'@example.test'] + $attributes)->assignRole('user');
}

/** An operator by CAPABILITY ONLY: a custom role carrying `portal.operator`, never the built-in `operator` role. */
function dir1CustomRoleOperator(string $name, array $permissions = ['portal.operator'], array $attributes = []): User
{
    $role = Role::firstOrCreate(['name' => 'dir1-'.str($name)->slug(), 'guard_name' => 'web']);
    $role->syncPermissions($permissions);

    return User::factory()->create(['name' => $name, 'email' => str($name)->slug().'@example.test'] + $attributes)->assignRole($role);
}

function dir1Membership(User $user, Organization $organization, string $role = 'member', array $extra = []): void
{
    DB::table('organization_members')->insert([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'role' => $role,
        'created_at' => now(),
        'updated_at' => now(),
    ] + $extra);
}

/** @return list<string> the names the list shows, in order */
function dir1ListedNames($response): array
{
    return $response->viewData('users')->getCollection()->pluck('name')->all();
}

// ── Search ─────────────────────────────────────────────────────────────────────

it('searches by name and by email', function () {
    dir1Customer('Ngozi Okafor', 'ngozi@acme.test');
    dir1Customer('Pieter Botha', 'pieter@globex.test');

    expect(dir1ListedNames($this->actingAs($this->admin)->get(route('users.index', ['search' => 'Ngozi']))))->toBe(['Ngozi Okafor'])
        ->and(dir1ListedNames($this->actingAs($this->admin)->get(route('users.index', ['search' => 'globex']))))->toBe(['Pieter Botha'])
        ->and(dir1ListedNames($this->actingAs($this->admin)->get(route('users.index', ['search' => 'nobody-matches']))))->toBe([]);
});

it('groups the name and email alternatives so a filter cannot be bypassed through either branch', function () {
    // A customer whose NAME matches and an operator whose EMAIL matches. Without grouping the predicate reads
    // `name like ? or (email like ? and <filter>)`, and the name branch would let the customer through an operator filter.
    dir1Customer('Needle Customer', 'plain@example.test');
    dir1CustomRoleOperator('Haystack Operator')->forceFill(['email' => 'needle@example.test'])->save();
    dir1CustomRoleOperator('Needle Operator', attributes: ['disabled_at' => now()]);

    $operatorsOnly = $this->actingAs($this->admin)->get(route('users.index', ['search' => 'needle', 'type' => 'operator']));
    $customersOnly = $this->actingAs($this->admin)->get(route('users.index', ['search' => 'needle', 'type' => 'customer']));
    $activeOnly = $this->actingAs($this->admin)->get(route('users.index', ['search' => 'needle', 'status' => 'active']));
    $suspendedOnly = $this->actingAs($this->admin)->get(route('users.index', ['search' => 'needle', 'status' => 'suspended']));

    expect(dir1ListedNames($operatorsOnly))->toBe(['Haystack Operator', 'Needle Operator'])
        ->and(dir1ListedNames($customersOnly))->toBe(['Needle Customer'])
        ->and(dir1ListedNames($activeOnly))->toBe(['Haystack Operator', 'Needle Customer'])
        ->and(dir1ListedNames($suspendedOnly))->toBe(['Needle Operator']);
});

// ── Filters ────────────────────────────────────────────────────────────────────

it('filters by account type and by status, and combines the two with a search', function () {
    dir1Customer('Active Customer');
    dir1Customer('Suspended Customer', attributes: ['disabled_at' => now()]);
    dir1CustomRoleOperator('Active Operator');
    dir1CustomRoleOperator('Suspended Operator', attributes: ['disabled_at' => now()]);

    $names = fn (array $query) => dir1ListedNames($this->actingAs($this->admin)->get(route('users.index', $query)));

    expect($names(['type' => 'operator']))->toBe(['Active Operator', 'Ada Admin', 'Suspended Operator'])
        ->and($names(['type' => 'customer']))->toBe(['Active Customer', 'Suspended Customer'])
        ->and($names(['status' => 'suspended']))->toBe(['Suspended Customer', 'Suspended Operator'])
        ->and($names(['status' => 'active']))->toBe(['Active Customer', 'Active Operator', 'Ada Admin'])
        ->and($names(['type' => 'customer', 'status' => 'suspended']))->toBe(['Suspended Customer'])
        ->and($names(['type' => 'operator', 'status' => 'active']))->toBe(['Active Operator', 'Ada Admin'])
        ->and($names(['type' => 'operator', 'status' => 'active', 'search' => 'ada']))->toBe(['Ada Admin'])
        ->and($names(['type' => 'customer', 'status' => 'active', 'search' => 'ada']))->toBe([])
        // An unknown value is ignored, never an error and never a silent "no results".
        ->and($names(['type' => 'bogus', 'status' => 'bogus']))->toBe(['Active Customer', 'Active Operator', 'Ada Admin', 'Suspended Customer', 'Suspended Operator']);
});

it('classifies operators by the portal.operator capability, not by a role named operator', function () {
    $direct = User::factory()->create(['name' => 'Direct Grant'])->assignRole('user');
    $direct->givePermissionTo('portal.operator');
    dir1CustomRoleOperator('Role Granted');
    // An internal account that administers users but lacks the capability: a customer by the product's own boundary.
    dir1CustomRoleOperator('Internal Admin', ['users.view', 'users.manage', 'crm.manage']);
    dir1Customer('Plain Customer');

    // The built-in `operator` role NAME without the capability (an account the synchronization has not reached) is not an operator either.
    Role::findByName('operator', 'web')->revokePermissionTo('portal.operator');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $nameOnly = User::factory()->create(['name' => 'Name Only'])->assignRole('operator');

    $operators = dir1ListedNames($this->actingAs($this->admin)->get(route('users.index', ['type' => 'operator'])));
    $customers = dir1ListedNames($this->actingAs($this->admin)->get(route('users.index', ['type' => 'customer'])));

    expect($operators)->toBe(['Direct Grant', 'Role Granted'])
        ->and($customers)->toBe(['Ada Admin', 'Internal Admin', 'Name Only', 'Plain Customer']);

    // Parity: the filter agrees, user by user, with the one test the rest of the product applies.
    foreach (User::all() as $user) {
        expect(in_array($user->name, $operators, true))->toBe(CustomerPilotGate::isOperator($user->fresh()), $user->name);
    }

    expect($nameOnly->hasRole('operator'))->toBeTrue();
});

it('ignores a repeated or array-shaped parameter like an unknown filter value instead of failing', function () {
    dir1Customer('Array Safe');

    $names = dir1ListedNames($this->actingAs($this->admin)->get(route('users.index').'?search[]=x&type[]=operator&status[]=active'));

    expect($names)->toContain('Array Safe', 'Ada Admin');
});

it('labels the account-type rule on the page', function () {
    $this->actingAs($this->admin)->get(route('users.index'))
        ->assertOk()
        ->assertSee('An operator holds the portal.operator permission; every other account is a customer account.');
});

// ── Pagination ─────────────────────────────────────────────────────────────────

it('keeps every filter in the pagination links and pages the filtered set', function () {
    foreach (range(1, 30) as $n) {
        dir1Customer(sprintf('Paged Customer %02d', $n));
    }
    dir1Customer('Paged Suspended', attributes: ['disabled_at' => now()]);
    dir1CustomRoleOperator('Paged Operator');

    $query = ['search' => 'Paged', 'type' => 'customer', 'status' => 'active'];
    $first = $this->actingAs($this->admin)->get(route('users.index', $query));
    $second = $this->actingAs($this->admin)->get(route('users.index', $query + ['page' => 2]));

    expect($first->viewData('users')->total())->toBe(30)
        ->and(count(dir1ListedNames($first)))->toBe(25)
        ->and(dir1ListedNames($second))->toBe(['Paged Customer 26', 'Paged Customer 27', 'Paged Customer 28', 'Paged Customer 29', 'Paged Customer 30']);

    $html = html_entity_decode($first->getContent());
    expect($html)->toContain('search=Paged&type=customer&status=active&page=2')
        ->and(html_entity_decode($second->getContent()))->toContain('search=Paged&type=customer&status=active');
});

// ── The Organizations column ───────────────────────────────────────────────────

it('shows each account\'s organizations, accurately', function () {
    $acme = Organization::factory()->create(['name' => 'Acme Holdings']);
    $globex = Organization::factory()->create(['name' => 'Globex Ltd']);
    $both = dir1Customer('Both Orgs');
    $one = dir1Customer('One Org');
    $none = dir1Customer('No Orgs');
    dir1Membership($both, $acme);
    dir1Membership($both, $globex, 'admin');
    dir1Membership($one, $globex);

    $response = $this->actingAs($this->admin)->get(route('users.index', ['type' => 'customer']))->assertOk();
    $memberships = $response->viewData('memberships');

    expect($memberships->get($both->id)->pluck('name')->all())->toBe(['Acme Holdings', 'Globex Ltd'])
        ->and($memberships->get($one->id)->pluck('name')->all())->toBe(['Globex Ltd'])
        ->and($memberships->has($none->id))->toBeFalse();

    $response->assertSee('Acme Holdings')->assertSee('Globex Ltd');
});

it('loads memberships in one query however many accounts are listed', function () {
    $organization = Organization::factory()->create(['name' => 'Shared Org']);

    $queriesFor = function (int $accounts) use ($organization): int {
        foreach (range(1, $accounts) as $n) {
            dir1Membership(User::factory()->create(['email' => "u{$n}@n{$accounts}.test"])->assignRole('user'), $organization);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get(route('users.index', ['search' => "@n{$accounts}.test"]))->assertOk();
        $log = collect(DB::getQueryLog());
        DB::disableQueryLog();

        return $log->filter(fn (array $query) => str_contains($query['query'], 'organization_members'))->count();
    };

    $small = $queriesFor(2);
    $large = $queriesFor(20);

    // The same number of membership queries for 2 accounts and for 20: one per page, not one per row.
    expect($large)->toBe($small)->and($large)->toBeGreaterThanOrEqual(1)->and($large)->toBeLessThanOrEqual(2);
});

it('links an organization only for a viewer who may open it', function () {
    $organization = Organization::factory()->create(['name' => 'Linked Org']);
    $member = dir1Customer('Member One');
    dir1Membership($member, $organization);
    $target = route('organizations.show', $organization->id);

    // users.view without crm.manage: the name is plain text and no organization destination is offered.
    $viewer = dir1CustomRoleOperator('Users Viewer', ['portal.operator', 'users.view']);

    $this->actingAs($viewer)->get(route('users.index'))
        ->assertOk()->assertSee('Linked Org')->assertDontSee($target, false);
    $this->actingAs($viewer)->get(route('users.show', $member))
        ->assertOk()->assertSee('Linked Org')->assertDontSee($target, false);

    $this->actingAs($this->admin)->get(route('users.index'))->assertSee($target, false);
    $this->actingAs($this->admin)->get(route('users.show', $member))->assertSee($target, false);
});

// ── The Memberships section of the user page ──────────────────────────────────

it('lists an account\'s memberships with role, approval and support designation, read-only', function () {
    $approved = Organization::factory()->create(['name' => 'Approved Org']);
    $plain = Organization::factory()->create(['name' => 'Plain Org']);
    $disabled = Organization::factory()->create(['name' => 'Disabled Org', 'disabled_at' => now()]);
    $broken = Organization::factory()->create(['name' => 'Half Approved Org']);
    $subject = dir1Customer('Subject Person');

    dir1Membership($subject, $approved, 'admin', ['admin_approved_at' => now(), 'admin_approved_by' => $this->admin->id, 'support_granted_at' => now(), 'support_granted_by' => $this->admin->id]);
    dir1Membership($subject, $plain, 'member');
    dir1Membership($subject, $disabled, 'admin');
    dir1Membership($subject, $broken, 'admin', ['admin_approved_at' => now()]);

    $page = $this->actingAs($this->admin)->get(route('users.show', $subject))->assertOk();
    $text = html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags($page->getContent()))));

    $page->assertSee('Memberships')
        ->assertSee('Approved administrator')
        ->assertSee('Designated for partner support')
        ->assertSee('Administrator not approved: holds no delegated authority.')
        ->assertSee('Incomplete approval record')
        ->assertSee('This organization is disabled.');

    expect($text)->toContain('Approved Org')->toContain('Plain Org')->toContain('Disabled Org')
        // Only the rows that carry a designation say so.
        ->and(substr_count($text, 'Designated for partner support'))->toBe(1);

    // Read-only: the section offers no control of its own.
    $section = explode('Recent Activity', explode('id="user-memberships-heading"', $page->getContent())[1])[0];
    expect($section)->not->toContain('<form')->not->toContain('<button')->not->toContain('<input');
});

/** A subject with two memberships carrying a complete and a half-written support designation, plus an ordinary approved administrator. */
function dir1SubjectWithSupport(User $approver): User
{
    $partner = Organization::factory()->create(['name' => 'Support Partner Org']);
    $half = Organization::factory()->create(['name' => 'Half Support Org']);
    $plain = Organization::factory()->create(['name' => 'Ordinary Org']);
    $subject = dir1Customer('Support Subject');

    dir1Membership($subject, $partner, 'admin', ['admin_approved_at' => now(), 'admin_approved_by' => $approver->id, 'support_granted_at' => now(), 'support_granted_by' => $approver->id]);
    dir1Membership($subject, $half, 'member', ['support_granted_at' => now()]);
    dir1Membership($subject, $plain, 'member');

    return $subject;
}

it('shows the support designation only to a viewer who passes manageMembership, and never reads it for anyone else', function (string $viewerKind, bool $sees) {
    $subject = dir1SubjectWithSupport($this->admin);

    $viewer = match ($viewerKind) {
        // users.view and nothing that grants organization administration.
        'users.view only' => dir1CustomRoleOperator('Plain Viewer', ['users.view']),
        'users.view and crm.manage' => dir1CustomRoleOperator('Directory Viewer', ['users.view', 'crm.manage']),
        'users.view and portal.operator' => dir1CustomRoleOperator('Eligible Viewer', ['users.view', 'portal.operator']),
        'custom operator' => dir1CustomRoleOperator('Custom Operator', ['users.view', 'portal.operator', 'crm.manage']),
        'built-in operator' => $this->admin,
    };

    DB::flushQueryLog();
    DB::enableQueryLog();
    $page = $this->actingAs($viewer)->get(route('users.show', $subject))->assertOk();
    $membershipQueries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'admin_approved_at') && str_contains($sql, 'organization_members'));
    DB::disableQueryLog();

    // General membership information is visible to every users.view viewer.
    $page->assertSee('Support Partner Org')->assertSee('Half Support Org')->assertSee('Ordinary Org')
        ->assertSee('Approved administrator');

    expect($membershipQueries)->toHaveCount(1);

    if ($sees) {
        $page->assertSee('Designated for partner support')->assertSee('Incomplete support record');
        expect($membershipQueries->first())->toContain('support_granted_at');
    } else {
        $page->assertDontSee('Designated for partner support')->assertDontSee('Incomplete support record')->assertDontSee('partner support');
        // Not fetched and hidden: the designation columns are not in the query at all.
        expect($membershipQueries->first())->not->toContain('support_granted');
    }
})->with([
    'users.view only' => ['users.view only', false],
    'users.view and crm.manage' => ['users.view and crm.manage', false],
    'users.view and portal.operator' => ['users.view and portal.operator', false],
    'custom operator' => ['custom operator', true],
    'built-in operator' => ['built-in operator', true],
]);

it('refuses the designation in the read model itself, for a suspended operator and a customer as well', function () {
    $subject = dir1SubjectWithSupport($this->admin);
    $suspended = User::factory()->create(['disabled_at' => now()])->assignRole('operator');
    $customer = dir1Customer('Curious Viewer');

    $supports = fn (User $viewer) => UserMembershipList::forUser($subject, $viewer)->pluck('support')->filter()->values()->all();

    expect($supports($this->admin))->toEqualCanonicalizing(['designated', 'incomplete'])
        ->and($supports($suspended))->toBe([])
        ->and($supports($customer))->toBe([])
        ->and($supports(dir1CustomRoleOperator('Lone Viewer', ['users.view'])))->toBe([]);
});

it('words an approval that is not in effect, and a suspended account, without changing either', function () {
    $organization = Organization::factory()->create(['name' => 'Approved Org']);
    $subject = dir1Customer('Suspended Admin', attributes: ['disabled_at' => now()]);
    dir1Membership($subject, $organization, 'admin', ['admin_approved_at' => now(), 'admin_approved_by' => $this->admin->id]);
    $before = DB::table('organization_members')->get()->all();

    $this->actingAs($this->admin)->get(route('users.show', $subject))
        ->assertOk()
        ->assertSee('Approved, not in effect')
        ->assertSee('This account is suspended; the membership is kept.');

    expect(DB::table('organization_members')->get()->all())->toEqual($before);
});

it('says so when an account has no membership', function () {
    $this->actingAs($this->admin)->get(route('users.show', dir1Customer('Lonely User')))
        ->assertOk()->assertSee('This account is not a member of any organization.');
});

it('shows no Directory person, profile or note on the user page or in the list', function () {
    $subject = dir1Customer('Linked Look', 'linked.look@example.test');
    CrmContact::factory()->create(['email' => 'linked.look@example.test', 'first_name' => 'Directory', 'last_name' => 'Twin', 'notes' => 'DIRECTORY-SECRET-NOTE']);

    foreach ([route('users.show', $subject), route('users.index', ['search' => 'Linked'])] as $url) {
        $this->actingAs($this->admin)->get($url)->assertOk()
            ->assertDontSee('DIRECTORY-SECRET-NOTE')->assertDontSee('Directory Twin');
    }
});

it('changes nothing in the database by viewing the list or a user', function () {
    $organization = Organization::factory()->create();
    $member = dir1Customer('Quiet Member');
    dir1Membership($member, $organization);

    $snapshot = fn () => [
        DB::table('users')->orderBy('id')->get()->all(),
        DB::table('organization_members')->get()->all(),
        DB::table('model_has_roles')->get()->all(),
        DB::table('activity_log')->count(),
    ];
    $before = $snapshot();

    $this->actingAs($this->admin)->get(route('users.index', ['type' => 'customer', 'status' => 'active']))->assertOk();
    $this->actingAs($this->admin)->get(route('users.show', $member))->assertOk();

    expect($snapshot())->toEqual($before);
});

// ── Customer isolation (DIR-0 S2), in the modes that restrict ─────────────────

it('answers a customer 404 on System → Users in closed and pilot modes, and never lists anything', function (string $mode) {
    config(['portal.customer_surface_mode' => $mode]);
    $customer = dir1Customer('Curious Customer');
    $other = dir1Customer('Other Customer');

    foreach ([route('users.index'), route('users.index', ['type' => 'operator']), route('users.show', $other)] as $url) {
        $this->actingAs($customer)->get($url)->assertNotFound();
    }
})->with(['closed', 'pilot']);

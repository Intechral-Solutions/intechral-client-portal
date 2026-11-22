<?php

/*
 * Release 1.0 (ORG-3a): the portal-access PRESENTATION of an Organization, and the read authorization of the two pages that carry it.
 *
 *  1. THE BADGE. One component (`<x-organization.portal-state>`) draws the three-state PortalState, on both Organization lists and the access detail. It has no
 *     default arm: a Directory-only Organization is never drawn as Enabled, and a value that is not a PortalState throws.
 *  2. THE SYSTEM LIST lists only Organizations whose portal access has EVER been enabled (Enabled and Disabled). A Directory-only Organization is not on it.
 *  3. THE DETAIL draws exactly the controls the services would accept for the Organization's state, and says why the others are absent. The matrix below is the
 *     set of (method, path) pairs of every form on the page, per state: it is compared EXACTLY, so a control added, dropped or offered in the wrong state fails
 *     here. What a control does is the services' concern (their own suites); this file proves only what is OFFERED. No service or lifecycle rule is changed.
 *  4. AUTHORIZATION. Both pages ask `manageDirectory` (an active account with `portal.operator` AND `crm.manage`), not the route's `can:crm.manage` alone. A
 *     customer holding a stray `crm.manage` is refused the list and the detail (403 for an Organization they can see, 404 for one a tenant scope hides, 404 for an
 *     unknown id), and in the closed and pilot customer modes keeps getting the 404 they always did. No permission, role or policy method is new.
 */

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\OrganizationInvitations;
use App\Services\OrganizationMembership;
use App\Services\OrganizationRelationships;
use App\Services\PartnerSupportGrants;
use App\Support\Organizations\PortalState;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\ViewException;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create(['name' => 'Olive Operator'])->assignRole('operator');
});

/** The text a person reads: tags removed, whitespace collapsed. */
function oa_text(string $html): string
{
    return html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags($html))));
}

/**
 * Every form on the page that targets an Organization route, as `METHOD /organizations/#/...` (ids replaced by `#`), sorted and unique. The method is the one
 * the form really sends (a `_method` field wins). A PUT to a member's role also names the role it sends, so a promotion and a demotion are different controls.
 *
 * @return list<string>
 */
function oa_controls(string $html): array
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $controls = [];

    foreach ($xpath->query('//form') as $form) {
        $path = (string) parse_url($form->getAttribute('action'), PHP_URL_PATH);

        if (! str_starts_with($path, '/organizations/')) {
            continue;
        }

        $method = strtoupper($form->getAttribute('method') ?: 'GET');
        $override = $xpath->query('.//input[@name="_method"]', $form)->item(0);
        $method = $override !== null ? strtoupper($override->getAttribute('value')) : $method;
        $role = $xpath->query('.//input[@name="role"]', $form)->item(0);
        $pattern = $method.' '.preg_replace('#/\d+#', '/#', $path);

        if (str_ends_with($path, '/role') && $role !== null) {
            $pattern .= ' ['.$role->getAttribute('value').']';
        }

        $controls[$pattern] = $pattern;
    }

    ksort($controls);

    return array_values($controls);
}

/**
 * A partner with everything the detail can offer a control for: a plain member, an unapproved administrator, two approved administrators, a member with a
 * support designation and one without, an open invitation, a managed customer, a spare Enabled organization to link, and an account that could be added.
 * Built while Enabled, through the services, so it is a state the application can really reach.
 *
 * @return array<string, mixed>
 */
function oa_world(User $operator): array
{
    Notification::fake();
    $memberships = app(OrganizationMembership::class);
    $relationships = app(OrganizationRelationships::class);

    $partner = Organization::factory()->create(['name' => 'Acme Partner', 'portal_enabled_by' => $operator->id]);
    $customer = Organization::factory()->create(['name' => 'Managed Customer', 'portal_enabled_by' => $operator->id]);
    $spare = Organization::factory()->create(['name' => 'Spare Customer', 'portal_enabled_by' => $operator->id]);
    $relationships->designatePartner($partner, $operator);
    $relationships->assignCustomer($customer, $partner->refresh(), $operator);

    $users = [];
    foreach (['member' => 'member', 'pending' => 'admin', 'approvedOne' => 'admin', 'approvedTwo' => 'admin', 'unsupported' => 'member'] as $key => $role) {
        $users[$key] = User::factory()->create(['name' => 'Person '.$key])->assignRole('user');
        $memberships->add($partner, $users[$key], $role, $operator);
    }
    $memberships->approve($partner, $users['approvedOne'], $operator);
    $memberships->approve($partner, $users['approvedTwo'], $operator);
    app(PartnerSupportGrants::class)->grant($partner, $users['member'], $operator);
    $candidate = User::factory()->create(['name' => 'Candidate Account'])->assignRole('user');

    app(OrganizationInvitations::class)->issue($partner, 'new.person@example.test', $operator);

    return $users + compact('partner', 'customer', 'spare', 'candidate');
}

/** The exact controls of an Enabled partner's page. */
function oa_enabledControls(): array
{
    return [
        'DELETE /organizations/#/invitations/#',
        'DELETE /organizations/#/manager',
        'DELETE /organizations/#/members/#',
        'DELETE /organizations/#/members/#/approval',
        'DELETE /organizations/#/members/#/support',
        'POST /organizations/#/customers',
        'POST /organizations/#/disable',
        'POST /organizations/#/invitations',
        'POST /organizations/#/members',
        'POST /organizations/#/members/#/approval',
        'POST /organizations/#/members/#/support',
        'PUT /organizations/#/members/#/role [admin]',
        'PUT /organizations/#/members/#/role [member]',
    ];
}

// ── 1. The badge ─────────────────────────────────────────────────────────────

it('draws each PortalState with its own label, glyph and tone, and nothing else', function (PortalState $state, string $label, string $glyph, string $tone) {
    $html = Blade::render('<x-organization.portal-state :state="$state" />', ['state' => $state]);

    expect(oa_text($html))->toBe($label)
        ->and($html)->toContain('data-portal-state="'.$state->value.'"')
        ->and($html)->toContain('data-glyph="'.$glyph.'"')
        ->and($html)->toContain($tone);
})->with([
    'enabled' => [PortalState::Enabled, 'Enabled', 'check', 'text-success'],
    'disabled' => [PortalState::Disabled, 'Disabled', 'triangle', 'text-warning'],
    'not enabled' => [PortalState::NotEnabled, 'Not enabled', 'dashed', 'text-text-muted'],
]);

it('refuses to draw anything that is not a PortalState, so there is no default to fall into', function (mixed $value) {
    expect(fn () => Blade::render('<x-organization.portal-state :state="$state" />', ['state' => $value]))->toThrow(ViewException::class, 'needs a PortalState');
})->with(['a string' => ['enabled'], 'null' => [null], 'a boolean' => [true]]);

it('agrees with the label PortalState itself gives each state', function () {
    foreach (PortalState::cases() as $state) {
        expect(oa_text(Blade::render('<x-organization.portal-state :state="$state" />', ['state' => $state])))->toBe($state->label());
    }
});

// ── 2. The lists ─────────────────────────────────────────────────────────────

/** @return array<string, string> the portal-state badge each row of the list draws, keyed by the Organization's name */
function oa_rowStates(string $html): array
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $states = [];

    foreach ((new DOMXPath($dom))->query('//tbody/tr') as $row) {
        $badge = (new DOMXPath($dom))->query('.//*[@data-portal-state]', $row)->item(0);
        $link = (new DOMXPath($dom))->query('.//a', $row)->item(0);

        if ($badge !== null && $link !== null) {
            $states[trim($link->textContent)] = $badge->getAttribute('data-portal-state');
        }
    }

    return $states;
}

function oa_landscape(User $operator): array
{
    return [
        'enabled' => Organization::factory()->create(['name' => 'Alpha Enabled', 'portal_enabled_by' => $operator->id]),
        'disabled' => Organization::factory()->disabled()->create(['name' => 'Bravo Disabled', 'portal_enabled_by' => $operator->id]),
        'directory' => Organization::factory()->directoryOnly()->create(['name' => 'Charlie Directory Only', 'created_by' => $operator->id]),
    ];
}

it('lists on System → Organization access only Organizations whose portal access has been enabled, Directory-only ones never', function () {
    $world = oa_landscape($this->operator);

    $page = $this->actingAs($this->operator)->get(route('organizations.index'))->assertOk();

    // The Directory-only Organization is not on the list at all (the fixture the earlier "never Not enabled" test did not exclude): not by name, not by link.
    $page->assertSee('Alpha Enabled')->assertSee('Bravo Disabled')
        ->assertDontSee('Charlie Directory Only')
        ->assertDontSee(route('organizations.show', $world['directory']), false);

    expect(oa_rowStates($page->getContent()))->toBe(['Alpha Enabled' => 'enabled', 'Bravo Disabled' => 'disabled'])
        ->and($page->viewData('organizations')->total())->toBe(2);
});

it('does not draw a Directory-only Organization as Enabled anywhere on the System list, even when it is the only Organization', function () {
    $directory = Organization::factory()->directoryOnly()->create(['name' => 'Only Directory', 'created_by' => $this->operator->id]);

    $page = $this->actingAs($this->operator)->get(route('organizations.index'))->assertOk();

    $page->assertSee('No organization has portal access yet.')->assertDontSee('Only Directory')
        ->assertDontSee('data-portal-state="enabled"', false);
    expect($directory->fresh()->portalState())->toBe(PortalState::NotEnabled);
});

it('draws all three states on the Directory Organizations list, each from the row itself', function () {
    oa_landscape($this->operator);

    $page = $this->actingAs($this->operator)->get(route('crm.companies.index'))->assertOk();

    expect(oa_rowStates($page->getContent()))->toBe([
        'Alpha Enabled' => 'enabled',
        'Bravo Disabled' => 'disabled',
        'Charlie Directory Only' => 'not_enabled',
    ]);
});

it('keeps the Directory list free of an access link for a Directory-only Organization, and gives every other row one', function () {
    $world = oa_landscape($this->operator);

    $page = $this->actingAs($this->operator)->get(route('crm.companies.index'))->assertOk();

    $page->assertSee(route('organizations.show', $world['enabled']), false)
        ->assertSee(route('organizations.show', $world['disabled']), false)
        ->assertDontSee(route('organizations.show', $world['directory']), false);
});

it('paginates the System list over the filtered set, not over every Organization', function () {
    foreach (range(1, 26) as $n) {
        Organization::factory()->create(['name' => sprintf('Enabled %02d', $n), 'portal_enabled_by' => $this->operator->id]);
    }
    foreach (range(1, 30) as $n) {
        Organization::factory()->directoryOnly()->create(['name' => sprintf('Directory %02d', $n), 'created_by' => $this->operator->id]);
    }

    $first = $this->actingAs($this->operator)->get(route('organizations.index'))->assertOk();
    $second = $this->actingAs($this->operator)->get(route('organizations.index', ['page' => 2]))->assertOk();

    expect($first->viewData('organizations')->total())->toBe(26)
        ->and(count(oa_rowStates($first->getContent())))->toBe(25)
        ->and(array_keys(oa_rowStates($second->getContent())))->toBe(['Enabled 26']);
});

// ── 3. The detail: exactly the controls the state allows ─────────────────────

it('offers an Enabled partner every control, and nothing the state forbids is missing', function () {
    $w = oa_world($this->operator);

    $page = $this->actingAs($this->operator)->get(route('organizations.show', $w['partner']))->assertOk();

    expect(oa_controls($page->getContent()))->toBe(oa_enabledControls());
    $page->assertSee('data-portal-state="enabled"', false)
        ->assertDontSee('Members can be added only while portal access is enabled.')
        ->assertDontSee('Invitations can be sent only while portal access is enabled.')
        ->assertDontSee('Enable organization');
});

it('offers a Disabled partner only what takes authority away, and says why the rest is absent', function () {
    $w = oa_world($this->operator);
    app(OrganizationRelationships::class)->disable($w['partner'], $this->operator);
    // An invitation row that is still open (the disable sweep revoked the real one): the service lets an operator revoke it in any state, so the control stays.
    OrganizationInvitation::factory()->create(['organization_id' => $w['partner']->id, 'invited_by' => $this->operator->id, 'email' => 'left.open@example.test', 'live_email' => 'left.open@example.test']);

    $page = $this->actingAs($this->operator)->get(route('organizations.show', $w['partner']))->assertOk();

    expect(oa_controls($page->getContent()))->toBe([
        'DELETE /organizations/#/invitations/#',     // revoke an open invitation
        'DELETE /organizations/#/manager',           // unlink a managed customer
        'DELETE /organizations/#/members/#',         // remove
        'DELETE /organizations/#/members/#/approval', // revoke an approval
        'DELETE /organizations/#/members/#/support', // revoke a support designation
        'POST /organizations/#/enable',              // re-enable (the only state change)
        'PUT /organizations/#/members/#/role [member]', // demote, and only demote
    ]);

    $text = oa_text($page->getContent());
    $page->assertSee('data-portal-state="disabled"', false)
        ->assertDontSee('Disable organization')
        ->assertDontSee('Send invitation')
        ->assertDontSee('Approve administrator')
        ->assertDontSee('Grant support access')
        ->assertDontSee('Link customer')
        ->assertDontSee('Resend');
    expect($text)->toContain('no member can be added, promoted or approved')
        ->and($text)->toContain('Members can be added only while portal access is enabled.')
        ->and($text)->toContain('Invitations can be sent only while portal access is enabled.')
        ->and($text)->toContain('Customers can be linked only while this organization\'s portal access is enabled. Existing customers can still be unlinked.');
});

it('offers an Organization that has never had portal access nothing to change its state with, only cleanup of what it holds', function () {
    $organization = Organization::factory()->directoryOnly()->create(['name' => 'Never Enabled', 'created_by' => $this->operator->id]);
    // The application cannot write these onto a Directory-only Organization; the preflight reports them when they exist (damage). Cleanup must stay possible.
    $member = User::factory()->create(['name' => 'Stray Member'])->assignRole('user');
    DB::table('organization_members')->insert(['organization_id' => $organization->id, 'user_id' => $member->id, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
    User::factory()->create()->assignRole('user');   // an account that could be added, were adding offered

    $page = $this->actingAs($this->operator)->get(route('organizations.show', $organization))->assertOk();

    expect(oa_controls($page->getContent()))->toBe([
        'DELETE /organizations/#/members/#',             // remove
        'PUT /organizations/#/members/#/role [member]',  // demote
    ]);

    $page->assertSee('data-portal-state="not_enabled"', false)
        ->assertSee('has never had portal access')
        ->assertSee(route('crm.companies.show', $organization), false)
        ->assertDontSee('Enable organization')->assertDontSee('Disable organization')
        ->assertDontSee('Send invitation')->assertDontSee('Add</button>', false);
    expect(oa_text($page->getContent()))->toContain('Portal access has never been enabled. It is enabled once, from the Directory profile.');
});

it('offers a managed customer only an unlink when it is Disabled, and the link controls when it is Enabled', function () {
    $w = oa_world($this->operator);
    $url = route('organizations.show', $w['customer']);

    $enabled = $this->actingAs($this->operator)->get($url)->assertOk();
    expect(oa_controls($enabled->getContent()))->toContain('DELETE /organizations/#/manager', 'PUT /organizations/#/manager', 'POST /organizations/#/disable');

    app(OrganizationRelationships::class)->disable($w['customer'], $this->operator);
    $disabled = $this->actingAs($this->operator)->get($url)->assertOk();

    expect(oa_controls($disabled->getContent()))->toBe(['DELETE /organizations/#/manager', 'POST /organizations/#/enable']);
    $disabled->assertDontSee('Reassign')->assertDontSee('Link to partner')->assertDontSee('Designate as partner');
    expect(oa_text($disabled->getContent()))->toContain('can be linked to a partner or designated as one only while its portal access is enabled. It can still be unlinked.');
});

it('offers a Disabled organization that has no partner relationship no relationship control at all', function () {
    $w = oa_world($this->operator);
    app(OrganizationRelationships::class)->disable($w['spare'], $this->operator);

    $page = $this->actingAs($this->operator)->get(route('organizations.show', $w['spare']))->assertOk();

    expect(oa_controls($page->getContent()))->toBe(['POST /organizations/#/enable']);
    $page->assertDontSee('Designate as partner')->assertDontSee('Link to partner')->assertDontSee('No partner is available yet.');
});

it('lets a Disabled partner without customers still have its partner designation removed', function () {
    $w = oa_world($this->operator);
    app(OrganizationRelationships::class)->unlinkCustomer($w['customer'], $this->operator);
    app(OrganizationRelationships::class)->disable($w['partner'], $this->operator);

    $page = $this->actingAs($this->operator)->get(route('organizations.show', $w['partner']))->assertOk();

    expect(oa_controls($page->getContent()))->toContain('DELETE /organizations/#/partner')
        ->not->toContain('POST /organizations/#/customers');
});

it('asks for confirmation before re-enabling and says previously revoked invitations do not return', function () {
    $w = oa_world($this->operator);
    app(OrganizationRelationships::class)->disable($w['partner'], $this->operator);

    $html = $this->actingAs($this->operator)->get(route('organizations.show', $w['partner']))->assertOk()->getContent();

    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $form = (new DOMXPath($dom))->query('//form[contains(@action, "/enable")]')->item(0);

    expect($form)->not->toBeNull();
    $confirm = $form->getAttribute('onsubmit');
    expect($confirm)->toContain('confirm(')->toContain('Acme Partner')
        ->and($confirm)->toContain('Invitations that were revoked when it was disabled do not return');
});

it('draws the Disable confirmation with the consequence it really has: the open invitations are permanently revoked and enabling again does not restore them', function () {
    $w = oa_world($this->operator);
    $relationships = app(OrganizationRelationships::class);
    $open = fn () => OrganizationInvitation::query()->where('organization_id', $w['partner']->id)->whereNull('revoked_at')->whereNull('accepted_at')->count();

    $html = $this->actingAs($this->operator)->get(route('organizations.show', $w['partner']))->assertOk()->getContent();

    $dom = new DOMDocument;
    @$dom->loadHTML($html);
    $form = (new DOMXPath($dom))->query('//form[contains(@action, "/disable")]')->item(0);
    expect($form)->not->toBeNull();
    expect($form->getAttribute('onsubmit'))->toContain('Its open invitations are permanently revoked')
        ->toContain('enabling it again does not restore them')
        ->not->toContain('Its open invitations are revoked.');

    // ...and that is what the service does, so the text is not a promise the code does not keep.
    expect($open())->toBeGreaterThan(0);
    $relationships->disable($w['partner'], $this->operator);
    expect($open())->toBe(0);
    $revoked = OrganizationInvitation::query()->where('organization_id', $w['partner']->id)->whereNotNull('revoked_at')->count();
    expect($revoked)->toBeGreaterThan(0);

    $relationships->enable($w['partner']->fresh(), $this->operator);
    expect($open())->toBe(0)
        ->and(OrganizationInvitation::query()->where('organization_id', $w['partner']->id)->whereNotNull('revoked_at')->count())->toBe($revoked);
});

it('does not say a Disabled Organization loses nothing: the Disabled alert names the revoked invitations and what is kept', function () {
    $w = oa_world($this->operator);
    app(OrganizationRelationships::class)->disable($w['partner'], $this->operator);

    $text = oa_text($this->actingAs($this->operator)->get(route('organizations.show', $w['partner']))->assertOk()->getContent());

    expect($text)->not->toContain('Nothing else is removed')
        ->and($text)->toContain('Its open invitations were permanently revoked when it was disabled and do not return if portal access is enabled again')
        ->and($text)->toContain('its members, approvals and relationships are not removed');
});

it('does not take any action by being viewed: opening the detail in every state writes nothing', function () {
    $w = oa_world($this->operator);
    $before = fn () => [DB::table('organizations')->orderBy('id')->get()->all(), DB::table('organization_members')->get()->all(), DB::table('organization_invitations')->get()->all(), DB::table('activity_log')->count()];

    $snapshot = $before();
    $this->actingAs($this->operator)->get(route('organizations.show', $w['partner']))->assertOk();
    app(OrganizationRelationships::class)->disable($w['partner'], $this->operator);
    $afterDisable = $before();
    $this->actingAs($this->operator)->get(route('organizations.show', $w['partner']))->assertOk();
    $this->actingAs($this->operator)->get(route('organizations.index'))->assertOk();

    expect($before())->toEqual($afterDisable)->and($snapshot)->not->toEqual($afterDisable);
});

// ── 4. Authorization ─────────────────────────────────────────────────────────

/** A customer account that holds a stray `crm.manage` (a custom role can carry it) and belongs to the Organization. */
function oa_stray(Organization $organization, array $permissions = ['crm.manage']): User
{
    $role = Role::create(['name' => 'oa-stray-'.uniqid(), 'guard_name' => 'web']);
    $role->givePermissionTo($permissions);
    $user = User::factory()->create(['name' => 'Stray Customer', 'email' => 'stray.'.uniqid().'@example.test'])->assignRole($role);
    DB::table('organization_members')->insert(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);

    return $user;
}

it('refuses a customer with a stray crm.manage the System list and the detail of their own Organization, and shows no member', function () {
    config(['portal.customer_surface_mode' => 'legacy']);
    $w = oa_world($this->operator);
    $stray = oa_stray($w['partner']);

    $this->actingAs($stray)->get(route('organizations.index'))->assertForbidden();
    $response = $this->actingAs($stray)->get(route('organizations.show', $w['partner']))->assertForbidden();

    // Nothing of the administration leaks into the refusal.
    foreach (['Person member', 'Person pending', 'new.person@example.test', 'Acme Partner', 'Managed Customer', 'Approved administrator'] as $secret) {
        expect($response->getContent())->not->toContain($secret);
    }
});

it('keeps the three answers apart for that customer: 403 for what they can see, 404 for what a tenant scope hides, 404 for an unknown id', function () {
    config(['portal.customer_surface_mode' => 'legacy']);
    $w = oa_world($this->operator);
    $stray = oa_stray($w['partner']);

    $this->actingAs($stray)->get(route('organizations.show', $w['partner']))->assertForbidden();       // theirs: visible, not permitted
    $this->actingAs($stray)->get(route('organizations.show', $w['customer']))->assertNotFound();       // not theirs: hidden
    $this->actingAs($stray)->get(route('organizations.show', 999999))->assertNotFound();                // unknown
});

it('refuses the operator-side abilities separately: portal.operator without crm.manage never reaches the pages, crm.manage without portal.operator is refused them', function () {
    config(['portal.customer_surface_mode' => 'legacy']);
    $w = oa_world($this->operator);

    $operatorOnly = oa_stray($w['partner'], ['portal.operator']);
    $this->actingAs($operatorOnly)->get(route('organizations.index'))->assertForbidden();           // the route's own `can:crm.manage`
    $this->actingAs($operatorOnly)->get(route('organizations.show', $w['partner']))->assertForbidden();

    $directoryOnly = oa_stray($w['partner'], ['crm.manage']);
    $this->actingAs($directoryOnly)->get(route('organizations.index'))->assertForbidden();           // `manageDirectory` (new in ORG-3a)
});

it('lets an active operator with both capabilities through, on a custom role as well as the operator role', function () {
    config(['portal.customer_surface_mode' => 'legacy']);
    $w = oa_world($this->operator);
    $role = Role::create(['name' => 'oa-custom-operator', 'guard_name' => 'web']);
    $role->givePermissionTo(['portal.operator', 'crm.manage']);
    $custom = User::factory()->create()->assignRole($role);

    foreach ([$this->operator, $custom] as $actor) {
        $this->actingAs($actor)->get(route('organizations.index'))->assertOk()->assertSee('Acme Partner');
        $this->actingAs($actor)->get(route('organizations.show', $w['partner']))->assertOk()->assertSee('Person member');
    }
});

it('answers a customer with a stray crm.manage 404, as before, in the closed and pilot modes', function (string $mode) {
    config(['portal.customer_surface_mode' => $mode]);
    $w = oa_world($this->operator);
    $stray = oa_stray($w['partner']);

    $this->actingAs($stray)->get(route('organizations.index'))->assertNotFound();
    $this->actingAs($stray)->get(route('organizations.show', $w['partner']))->assertNotFound();
    $this->actingAs($stray)->get(route('crm.companies.index'))->assertNotFound();
})->with(['closed', 'pilot']);

it('builds no administrative data for a refused actor: the member rows, the invitation list and the relationship facts are never read', function () {
    config(['portal.customer_surface_mode' => 'legacy']);
    $w = oa_world($this->operator);
    $stray = oa_stray($w['partner']);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($stray)->get(route('organizations.show', $w['partner']))->assertForbidden();
    $this->actingAs($stray)->get(route('organizations.index'))->assertForbidden();
    $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    DB::disableQueryLog();

    expect($queries)->not->toContain('organization_invitations')
        ->and($queries)->not->toContain('managed_by_organization_id')
        // (`support_granted_at` is not asserted: the shell's own navigation reads the ACTOR'S support grant, as OrganizationVisibilityTest notes.)
        ->and($queries)->not->toContain('admin_approved_at');
});

it('leaves the mutation routes as they were: an operator still administers, a stray customer still cannot', function () {
    config(['portal.customer_surface_mode' => 'legacy']);
    $w = oa_world($this->operator);
    $stray = oa_stray($w['partner']);

    $this->actingAs($stray)->post(route('organizations.disable', $w['partner']->id))->assertForbidden();
    $this->actingAs($stray)->post(route('organizations.members.store', $w['partner']), ['user_id' => $w['candidate']->id, 'role' => 'member'])->assertForbidden();
    expect($w['partner']->fresh()->portalState())->toBe(PortalState::Enabled);

    $this->actingAs($this->operator)->post(route('organizations.disable', $w['partner']->id))->assertRedirect();
    expect($w['partner']->fresh()->portalState())->toBe(PortalState::Disabled);
});

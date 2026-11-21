<?php

/*
 * Release 1.0 (DIR-1): what the two Organization lists and the Organization page show. Presentation and read queries only.
 *
 *  - both lists show the portal-access state (Not enabled, Enabled, Disabled);
 *  - "Managed by" and "Manages N" are built ONLY for an actor who passes `manageRelationships` (DIR-0 invariant S7): the proof is that
 *    nothing relationship-shaped is read for anyone else, not merely that the markup is hidden;
 *  - a member row links to the System user page only for a viewer who holds `users.view`;
 *  - "Owner" is relabelled for what the value is, the operator who enabled portal access, and carries no authority;
 *  - the Directory deletion warning states what OrganizationDirectory::delete really does.
 */

use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationListFacts;
use App\Services\OrganizationRelationships;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create(['name' => 'Olive Operator'])->assignRole('operator');
});

/** An Enabled Organization, the unit a managed relationship needs (one entity since ORG-2: no separate Directory profile row). */
function dir1Pair(string $name, ?User $enabledBy = null): Organization
{
    $owner = $enabledBy ?? User::factory()->create(['name' => 'Enabler of '.$name]);

    return Organization::factory()->create(['name' => $name, 'portal_enabled_by' => $owner->id]);
}

/** A partner Alpha that manages Beta, a disabled organization Gamma, an unmanaged Delta, and a Directory profile with no portal access. */
function dir1Landscape(User $operator): array
{
    $alpha = dir1Pair('Alpha Partner', $operator);
    $beta = dir1Pair('Beta Customer', $operator);
    $gamma = dir1Pair('Gamma Offline', $operator);
    $delta = dir1Pair('Delta Standalone', $operator);
    $plain = Organization::factory()->directoryOnly()->create(['name' => 'Epsilon Profile Only', 'created_by' => $operator->id]);

    $service = app(OrganizationRelationships::class);
    $service->designatePartner($alpha, $operator);
    $service->assignCustomer($beta, $alpha->refresh(), $operator);
    $service->disable($gamma, $operator);

    return compact('alpha', 'beta', 'gamma', 'delta', 'plain');
}

/** The text a person reads: tags removed, screen-reader-only spans removed, whitespace collapsed. */
function dir1VisibleText(string $html): string
{
    $html = preg_replace('#<span class="sr-only">.*?</span>#s', '', $html);

    return html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags($html))));
}

/** crm.manage WITHOUT portal.operator: passes the route's `can:crm.manage` but not `manageRelationships`. */
function dir1DirectoryOnly(array $extra = []): User
{
    $role = Role::create(['name' => 'dir1-directory-'.uniqid(), 'guard_name' => 'web']);
    $role->givePermissionTo(['crm.manage', ...$extra]);

    return User::factory()->create(['name' => 'Directory Only'])->assignRole($role);
}

// ── Portal-access state on both lists ─────────────────────────────────────────

it('shows Not enabled, Enabled and Disabled on the Directory Organizations list', function () {
    $world = dir1Landscape($this->operator);

    $page = $this->actingAs($this->operator)->get(route('crm.companies.index'))->assertOk();
    $status = $page->viewData('portalStatus');

    expect($status[$world['alpha']->id])->toBe(OrganizationListFacts::ENABLED)
        ->and($status[$world['gamma']->id])->toBe(OrganizationListFacts::DISABLED)
        // A Directory profile with no portal organization has no state to report; the list words that as Not enabled.
        ->and($status)->toHaveCount(4);

    $rows = collect(explode('<tr', $page->getContent()))->mapWithKeys(function (string $row) {
        $text = html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags('<tr'.$row))));

        return [$text => $text];
    });
    $row = fn (string $name) => $rows->first(fn ($text) => str_starts_with($text, $name));

    expect($row('Alpha Partner'))->toContain('Enabled')
        ->and($row('Gamma Offline'))->toContain('Disabled')
        ->and($row('Epsilon Profile Only'))->toContain('Not enabled')
        ->and($row('Epsilon Profile Only'))->not->toContain('Manage');
});

it('shows Enabled or Disabled on Organization access, never Not enabled, and names the operator who enabled access', function () {
    dir1Landscape($this->operator);

    $page = $this->actingAs($this->operator)->get(route('organizations.index'))->assertOk();

    $page->assertSee('Organization access')
        ->assertSee('Enabled by')
        ->assertSee('Olive Operator')
        ->assertDontSee('Not enabled')
        ->assertDontSee('>Owner<', false);

    $text = html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags($page->getContent()))));
    expect($text)->toMatch('/Gamma Offline\s+\S+\s+Disabled/')
        ->and($text)->toMatch('/Delta Standalone\s+\S+\s+Enabled/');
});

it('links Organization access back to the Directory Organizations list with the glossary wording', function () {
    $this->actingAs($this->operator)->get(route('organizations.index'))
        ->assertOk()
        ->assertSee('View Organizations')
        ->assertSee('Organizations with portal access enabled')
        ->assertDontSee('Promoted from the CRM')
        ->assertDontSee('View Companies')
        ->assertDontSee('Promote a company');
});

// ── Managing relationships (DIR-0 S7) ─────────────────────────────────────────

it('shows Managed by and Manages N on both lists for an operator who may manage relationships', function () {
    $world = dir1Landscape($this->operator);

    foreach ([route('organizations.index'), route('crm.companies.index')] as $url) {
        $page = $this->actingAs($this->operator)->get($url)->assertOk();
        $facts = $page->viewData('relationships');

        expect($facts[$world['alpha']->id])->toBe(['managedBy' => null, 'isPartner' => true, 'manages' => 1])
            ->and($facts[$world['beta']->id])->toBe(['managedBy' => ['id' => $world['alpha']->id, 'name' => 'Alpha Partner'], 'isPartner' => false, 'manages' => 0])
            ->and($facts[$world['delta']->id])->toBe(['managedBy' => null, 'isPartner' => false, 'manages' => 0]);

        $page->assertSee('Managed by')->assertSee('Manages');

        $text = dir1VisibleText($page->getContent());
        // The partner's own row, and Beta's "Managed by" cell.
        expect($text)->toMatch('/Beta Customer.*?Alpha Partner/')
            ->and(substr_count($text, 'Alpha Partner'))->toBe(2);
    }
});

it('builds no relationship fact for an actor who may not manage relationships, on either list', function () {
    $world = dir1Landscape($this->operator);
    // Make the organizations visible to this actor: tenant scopes filter an account that is not operator-eligible.
    $actor = dir1DirectoryOnly();
    foreach ($world as $organization) {
        if ($organization instanceof Organization) {
            DB::table('organization_members')->insert(['organization_id' => $organization->id, 'user_id' => $actor->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // ORG-2: the Directory Organizations list asks `manageDirectory` (portal.operator AND crm.manage), so this actor, who holds `crm.manage` only, is refused it
    // outright: no relationship fact is built for them there either, and no read of the relationship column happens at all.
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($actor)->get(route('crm.companies.index'))->assertForbidden();
    expect(collect(DB::getQueryLog())->pluck('query')->implode("\n"))->not->toContain('managed_by_organization_id');
    DB::disableQueryLog();

    foreach ([route('organizations.index')] as $url) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page = $this->actingAs($actor)->get($url)->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        // Not merely hidden: the data is never built, so no query reads the managing-relationship column and the view receives null.
        // (`can_manage_customers` is deliberately not asserted: the shell's own navigation reads the ACTOR'S support grant with it.)
        expect($page->viewData('relationships'))->toBeNull()
            ->and($queries)->not->toContain('managed_by_organization_id');

        $page->assertDontSee('Managed by')->assertDontSee('Manages');
        // Beta is managed by Alpha. Alpha's name appears exactly once, in Alpha's own row: nowhere does it appear as Beta's manager.
        $text = dir1VisibleText($page->getContent());
        expect(substr_count($text, 'Alpha Partner'))->toBe(1);
    }
});

it('refuses relationship facts in the builder itself, for a non-operator, a suspended operator and a customer', function () {
    $world = dir1Landscape($this->operator);
    $ids = [$world['alpha']->id, $world['beta']->id];

    $suspended = User::factory()->create(['disabled_at' => now()])->assignRole('operator');
    $customer = User::factory()->create()->assignRole('user');

    expect(OrganizationListFacts::relationships(dir1DirectoryOnly(), $ids))->toBeNull()
        ->and(OrganizationListFacts::relationships($suspended, $ids))->toBeNull()
        ->and(OrganizationListFacts::relationships($customer, $ids))->toBeNull()
        ->and(OrganizationListFacts::relationships($this->operator, $ids))->toHaveCount(2)
        // An empty page asks nothing and answers nothing, for an authorized actor.
        ->and(OrganizationListFacts::relationships($this->operator, []))->toBe([]);
});

it('reads relationship facts for a page of organizations in two queries, not one per row', function () {
    $world = dir1Landscape($this->operator);
    $ids = array_values(array_map(fn (Organization $o) => $o->id, array_filter($world, fn ($v) => $v instanceof Organization)));

    DB::flushQueryLog();
    DB::enableQueryLog();
    OrganizationListFacts::relationships($this->operator, $ids);
    $relationshipQueries = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'managed_by_organization_id'))->count();
    DB::disableQueryLog();

    expect($relationshipQueries)->toBe(2);
});

// ── Member rows link to the user page only with users.view ────────────────────

it('links a member to the System user page only for a viewer who holds users.view', function () {
    $organization = dir1Pair('Linked Members Org', $this->operator);
    $member = User::factory()->create(['name' => 'Mary Member'])->assignRole('user');
    DB::table('organization_members')->insert(['organization_id' => $organization->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
    $target = route('users.show', $member->id);

    // Operator rows (manageMembership): with and without users.view.
    $withView = dir1CustomOperator('Operator With View', ['users.view']);
    $withoutView = dir1CustomOperator('Operator Without View', []);

    $this->actingAs($this->operator)->get(route('organizations.show', $organization))->assertOk()->assertSee($target, false);
    $this->actingAs($withView)->get(route('organizations.show', $organization))->assertOk()->assertSee($target, false);
    $this->actingAs($withoutView)->get(route('organizations.show', $organization))
        ->assertOk()->assertSee('Mary Member')->assertDontSee($target, false);

    // The plain member list (an actor who may not manage membership): same rule.
    foreach ([['users.view', true], [null, false]] as [$permission, $linked]) {
        $viewer = dir1DirectoryOnly($permission === null ? [] : [$permission]);
        DB::table('organization_members')->insert(['organization_id' => $organization->id, 'user_id' => $viewer->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);

        $page = $this->actingAs($viewer)->get(route('organizations.show', $organization))->assertOk()->assertSee('Mary Member');
        $linked ? $page->assertSee($target, false) : $page->assertDontSee($target, false);
    }
});

function dir1CustomOperator(string $name, array $extra): User
{
    $role = Role::create(['name' => 'dir1-op-'.uniqid(), 'guard_name' => 'web']);
    $role->givePermissionTo(['portal.operator', 'crm.manage', ...$extra]);

    return User::factory()->create(['name' => $name])->assignRole($role);
}

// ── Wording: the enabler is not an owner ──────────────────────────────────────

it('describes the stored owner as the operator who enabled portal access, and changes none of the underlying data', function () {
    $organization = dir1Pair('Provenance Org', $this->operator);
    $before = [
        DB::table('organizations')->orderBy('id')->get()->all(),
        DB::table('organization_members')->get()->all(),
        DB::table('activity_log')->count(),
    ];

    $page = $this->actingAs($this->operator)->get(route('organizations.show', $organization))->assertOk();
    $page->assertSee('Portal access enabled by')->assertSee('Olive Operator')
        ->assertSee('View Directory profile')
        ->assertDontSee('View CRM Record')
        ->assertDontSee('>Owner<', false);

    $this->actingAs($this->operator)->get(route('organizations.index'))->assertOk();
    $this->actingAs($this->operator)->get(route('crm.companies.index'))->assertOk();

    expect([
        DB::table('organizations')->orderBy('id')->get()->all(),
        DB::table('organization_members')->get()->all(),
        DB::table('activity_log')->count(),
    ])->toEqual($before)
        ->and($organization->fresh()->portal_enabled_by)->toBe($this->operator->id);
});

// ── The Directory deletion warning ────────────────────────────────────────────

it('warns that deleting an Organization keeps its People, and promises nothing the code does not do', function () {
    $company = Organization::factory()->directoryOnly()->create(['name' => 'Warned Org', 'created_by' => $this->operator->id]);

    $page = $this->actingAs($this->operator)->get(route('crm.companies.edit', $company))->assertOk();

    $page->assertSee('Delete Organization')
        ->assertSee('Its People are kept: they stay in the Directory and lose their affiliation with it.')
        ->assertSee('Only an Organization that never had portal access can be deleted, and not while projects are linked to it.')
        // The retired, false claim, and the later DIR-2 rule that does not exist yet.
        ->assertDontSee('and all its contacts')
        ->assertDontSee('permanently remove the company')
        ->assertDontSee('portal access exists');
});

it('deletes the Organization record and keeps its People, exactly as the warning says', function () {
    $company = Organization::factory()->directoryOnly()->create(['name' => 'Soon Gone', 'created_by' => $this->operator->id]);
    $person = CrmContact::factory()->create(['organization_id' => $company->id]);

    $this->actingAs($this->operator)->delete(route('crm.companies.destroy', $company))
        ->assertRedirect(route('crm.companies.index'))
        ->assertSessionHas('success', 'Organization deleted.');

    expect(Organization::withoutGlobalScopes()->find($company->id))->toBeNull()
        ->and($person->fresh())->not->toBeNull()
        ->and($person->fresh()->organization_id)->toBeNull();
});

it('still refuses to delete the Organization of a partner, with the refusal the warning describes', function () {
    $world = dir1Landscape($this->operator);
    $company = $world['alpha'];

    $this->actingAs($this->operator)->delete(route('crm.companies.destroy', $company))
        ->assertSessionHasErrors('company');

    expect(Organization::withoutGlobalScopes()->find($company->id))->not->toBeNull();
});

// ── Customer isolation (DIR-0 S2), in the modes that restrict ─────────────────

it('answers a customer 404 on every Directory and Organization route in closed and pilot modes', function (string $mode) {
    config(['portal.customer_surface_mode' => $mode]);
    $world = dir1Landscape($this->operator);
    $customer = User::factory()->create()->assignRole('user');
    DB::table('organization_members')->insert(['organization_id' => $world['alpha']->id, 'user_id' => $customer->id, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
    $company = $world['alpha'];
    $person = CrmContact::factory()->create(['organization_id' => $company->id]);

    foreach ([
        route('crm.companies.index'), route('crm.companies.show', $company), route('crm.companies.edit', $company), route('crm.companies.create'),
        route('crm.contacts.index'), route('crm.contacts.show', $person), route('crm.contacts.create'),
        route('organizations.index'), route('organizations.show', $world['alpha']),
    ] as $url) {
        $this->actingAs($customer)->get($url)->assertNotFound();
    }

    $this->actingAs($customer)->delete(route('crm.companies.destroy', $company))->assertNotFound();
    $this->actingAs($customer)->post(route('crm.companies.promote', $world['plain']))->assertNotFound();
})->with(['closed', 'pilot']);

it('renders the empty states of both lists, and ignores an array-shaped search instead of failing', function () {
    $this->actingAs($this->operator)->get(route('organizations.index'))
        ->assertOk()
        ->assertSee('No organization has portal access yet.')
        ->assertSee('colspan="8"', false);

    $this->actingAs($this->operator)->get(route('crm.companies.index').'?search[]=x')->assertOk();
    $this->actingAs($this->operator)->get(route('crm.contacts.index').'?search[]=x')->assertOk();
});

it('counts only organizations with enabled portal access in the dashboard "With portal access" figure', function () {
    Organization::factory()->create();
    Organization::factory()->create(['disabled_at' => now()]);

    $this->actingAs($this->operator)->get(route('dashboard'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('crmSummary.orgs', 1));
});

it('wraps each expanded administration list in a named, focusable, horizontally scrollable region with the focus ring', function () {
    foreach ([
        [route('crm.companies.index'), 'Organizations list'],
        [route('organizations.index'), 'Organizations with portal access'],
        [route('users.index'), 'Users list'],
    ] as [$url, $name]) {
        $html = $this->actingAs($this->operator)->get($url)->assertOk()->getContent();

        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $region = (new DOMXPath($dom))->query('//div[@role="region"][@aria-label="'.$name.'"]');

        expect($region->length)->toBe(1, $name);
        $classes = preg_split('/\s+/', $region->item(0)->getAttribute('class'));

        // Focusable (a keyboard user can reach and scroll it), scrolls inside itself, and carries the shared focus ring.
        expect($region->item(0)->getAttribute('tabindex'))->toBe('0')
            ->and($classes)->toContain('overflow-x-auto', 'focus-visible:outline-2', 'focus-visible:outline-offset-2', 'focus-visible:outline-focus')
            ->and($classes)->not->toContain('overflow-hidden');
    }
});

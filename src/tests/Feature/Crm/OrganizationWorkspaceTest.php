<?php

/*
 * Release 1.0 (ORG-3b): the Organization workspace. One Organization, three routed pages (Overview `organizations.show`, People `organizations.people`, Portal access
 * `organizations.portal`), one header, one set of page links. What is asserted here, and what is not:
 *
 *  1. THE FRAME: one header (name, Classification, the one three-state badge, partner and managed-by facts only for an actor who may manage relationships), the shell
 *     breadcrumb Directory / Organizations / name, and page navigation that is a labelled `nav` of ordinary links with `aria-current="page"` (no ARIA tablist). There is no Projects
 *     tab: it is ORG-3c's, and a tab that does not exist is not drawn as a placeholder.
 *  2. WHAT EACH PAGE SHOWS, AND ONLY THAT: the Overview summarizes (details, a People count, the portal state) and lists no roster; People lists the affiliated Directory
 *     records and no portal member; Portal access holds the lifecycle, members, approval, support, invitation and relationship sections. No page reads or names a Project.
 *  3. AUTHORIZATION: every page asks `manageDirectory` (403 visible / 404 hidden or unknown / 404 in the closed and pilot customer modes), and builds only the data its own ability
 *     allows: an actor who passes `manageDirectory` but not `manageMembership` or `manageRelationships` is not read, and not shown, the data those abilities guard.
 *  4. THE ACTS KEEP THEIR ENDPOINTS AND RETURN TO THE PAGE THEY WERE MADE FROM: the Portal access page, with a refusal or a validation error beside the form.
 *  5. CONFIRMATION TEXT IS A JSON STRING: an Organization name holding a quote, a backslash, a newline or markup cannot end the JavaScript string or the HTML attribute early.
 *
 * The per-state CONTROLS of the Portal access page are compared exactly in OrganizationAccessPresentationTest (ORG-3a); the services' own rules are their own suites'.
 */

use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\User;
use App\Policies\OrganizationPolicy;
use App\Services\OrganizationInvitations;
use App\Services\OrganizationMembership;
use App\Services\OrganizationRelationships;
use App\Services\PartnerSupportGrants;
use App\Support\Organizations\Classification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create(['name' => 'Olive Operator'])->assignRole('operator');
});

const WS_PAGES = ['organizations.show', 'organizations.people', 'organizations.portal'];

function ws_xpath(string $html): DOMXPath
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html);

    return new DOMXPath($dom);
}

/** The text a person reads: tags removed, whitespace collapsed. */
function ws_text(string $html): string
{
    return html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags($html))));
}

/** An Organization in the named state ('not_enabled', 'enabled', 'disabled'), built through the services where the state needs them. */
function ws_org(User $operator, string $state, array $attributes = []): Organization
{
    Notification::fake();

    $organization = match ($state) {
        'not_enabled' => Organization::factory()->directoryOnly()->create(['created_by' => $operator->id] + $attributes),
        default => Organization::factory()->create(['portal_enabled_by' => $operator->id] + $attributes),
    };

    if ($state === 'disabled') {
        app(OrganizationRelationships::class)->disable($organization, $operator);
    }

    return $organization->fresh();
}

/**
 * A policy that separates the three abilities (the same device as OrganizationVisibilityTest): `manageDirectory` always passes, and the other two answer as configured.
 * They answer alike in production today and are separate on purpose, so the plain branches of the pages are reached only through a policy like this one.
 */
class WsSeparatedPolicy extends OrganizationPolicy
{
    public static bool $membership = false;

    public static bool $relationships = false;

    public function manageDirectory(User $user): bool
    {
        return true;
    }

    public function manageMembership(User $user): bool
    {
        return self::$membership;
    }

    public function manageRelationships(User $user): bool
    {
        return self::$relationships;
    }
}

/** An actor who passes `manageDirectory` and, as configured, nothing else. */
function ws_directoryOnlyPolicy(bool $membership = false, bool $relationships = false): void
{
    WsSeparatedPolicy::$membership = $membership;
    WsSeparatedPolicy::$relationships = $relationships;
    Gate::policy(Organization::class, WsSeparatedPolicy::class);
}

/** Every table a SELECT or write names while $callback runs. @return list<string> */
function ws_tablesRead(Closure $callback): array
{
    $sql = [];
    DB::listen(function ($query) use (&$sql) {
        $sql[] = strtolower($query->sql);
    });
    $callback();

    return $sql;
}

// ── 1. The frame ─────────────────────────────────────────────────────────────────────────────

it('draws one header on every page: the name, the Classification and the one three-state badge, for every state', function (string $route, string $state) {
    $organization = ws_org($this->operator, $state, ['name' => 'Frame Co', 'classification' => Classification::Vendor->value]);

    $html = $this->actingAs($this->operator)->get(route($route, $organization))->assertOk()->getContent();
    $xpath = ws_xpath($html);

    expect($xpath->query('//h1'))->toHaveCount(1)
        ->and(trim($xpath->query('//h1')->item(0)->textContent))->toBe('Frame Co')
        ->and(ws_text($html))->toContain(Classification::Vendor->label())
        // Exactly one badge in the header, carrying the row's own state, and the same state on every page.
        ->and($xpath->query('//header//*[@data-portal-state]'))->toHaveCount(1)
        ->and($xpath->query('//header//*[@data-portal-state="'.$state.'"]'))->toHaveCount(1);
})->with(WS_PAGES)->with(['not_enabled', 'enabled', 'disabled']);

it('draws page navigation as a labelled nav of ordinary links with aria-current on the current page, and no tablist and no Projects tab', function (string $route, string $current) {
    $organization = ws_org($this->operator, 'enabled', ['name' => 'Nav Co']);
    $html = $this->actingAs($this->operator)->get(route($route, $organization))->assertOk()->getContent();
    $xpath = ws_xpath($html);

    $nav = $xpath->query('//nav[@aria-label="Organization sections"]');
    expect($nav)->toHaveCount(1);

    $links = $xpath->query('.//a', $nav->item(0));
    $labels = [];
    foreach ($links as $link) {
        $labels[trim($link->textContent)] = $link->getAttribute('href');
    }

    expect($labels)->toBe([
        'Overview' => route('organizations.show', $organization),
        'People' => route('organizations.people', $organization),
        'Portal access' => route('organizations.portal', $organization),
    ]);

    $currentLinks = $xpath->query('.//a[@aria-current="page"]', $nav->item(0));
    expect($currentLinks)->toHaveCount(1)->and(trim($currentLinks->item(0)->textContent))->toBe($current);

    // Page navigation, not an ARIA tab widget.
    expect($xpath->query('//*[@role="tablist" or @role="tab" or @aria-selected]'))->toHaveCount(0);
    expect(ws_text($html))->not->toContain('Projects tab');
    foreach ($links as $link) {
        expect(trim($link->textContent))->not->toBe('Projects');
    }
})->with([
    'overview' => ['organizations.show', 'Overview'],
    'people' => ['organizations.people', 'People'],
    'portal access' => ['organizations.portal', 'Portal access'],
]);

it('names the Organization in the shell breadcrumb after Directory and Organizations, ending on the current page, and draws no second breadcrumb', function (string $route) {
    $organization = ws_org($this->operator, 'enabled', ['name' => 'Crumb Co']);
    $xpath = ws_xpath($this->actingAs($this->operator)->get(route($route, $organization))->assertOk()->getContent());

    $crumbs = $xpath->query('//nav[@aria-label="Breadcrumb"]');
    expect($crumbs)->toHaveCount(1);

    $trail = [];
    foreach ($xpath->query('.//a | .//span[@aria-current="page"]', $crumbs->item(0)) as $crumb) {
        $trail[] = [trim($crumb->textContent), $crumb->getAttribute('href') ?: null, $crumb->getAttribute('aria-current') ?: null];
    }

    expect($trail)->toBe([
        ['Directory', route('crm.contacts.index'), null],
        ['Organizations', route('organizations.index'), null],
        ['Crumb Co', null, 'page'],
    ]);
})->with(WS_PAGES);

it('names the edit and create pages in the same trail', function () {
    $organization = ws_org($this->operator, 'not_enabled', ['name' => 'Trail Co']);

    $trail = function (string $url) {
        $xpath = ws_xpath($this->actingAs($this->operator)->get($url)->assertOk()->getContent());
        $out = [];
        foreach ($xpath->query('//nav[@aria-label="Breadcrumb"]//a | //nav[@aria-label="Breadcrumb"]//span[@aria-current="page"]') as $crumb) {
            $out[] = trim($crumb->textContent);
        }

        return $out;
    };

    expect($trail(route('organizations.edit', $organization)))->toBe(['Directory', 'Organizations', 'Trail Co', 'Edit'])
        ->and($trail(route('organizations.create')))->toBe(['Directory', 'Organizations', 'New Organization'])
        ->and($trail(route('organizations.index')))->toBe(['Directory', 'Organizations']);
});

it('shows the partner and managed-by facts in the header only to an actor who may manage relationships', function () {
    $partner = ws_org($this->operator, 'enabled', ['name' => 'Header Partner']);
    $customer = ws_org($this->operator, 'enabled', ['name' => 'Header Customer']);
    app(OrganizationRelationships::class)->designatePartner($partner, $this->operator);
    app(OrganizationRelationships::class)->assignCustomer($customer, $partner->fresh(), $this->operator);

    $partnerHeader = ws_text($this->actingAs($this->operator)->get(route('organizations.show', $partner))->getContent());
    $customerHeader = ws_text($this->actingAs($this->operator)->get(route('organizations.show', $customer))->getContent());

    expect($partnerHeader)->toContain('Partner: manages 1 customer')
        ->and($customerHeader)->toContain('Managed by Header Partner');

    // An actor who passes manageDirectory but not manageRelationships: nothing was read, so nothing is drawn.
    ws_directoryOnlyPolicy(membership: false, relationships: false);
    $queries = ws_tablesRead(function () use (&$page, $customer) {
        $page = $this->actingAs($this->operator)->get(route('organizations.show', $customer))->assertOk();
    });

    expect(ws_text($page->getContent()))->not->toContain('Managed by')->not->toContain('Header Partner')
        ->and(array_filter($queries, fn ($sql) => str_contains($sql, 'managed_by_organization_id')))->toBe([]);
});

// ── 2. What each page shows, and only that ───────────────────────────────────────────────────

it('summarizes on the Overview: details, a People count and the portal state, and lists no roster', function () {
    $organization = ws_org($this->operator, 'enabled', [
        'name' => 'Overview Co', 'website' => 'https://overview.example', 'phone' => '+27 11 555 0100', 'address' => "1 Main Road\nTownship", 'notes' => 'Private commentary.',
    ]);
    $person = CrmContact::factory()->create(['organization_id' => $organization->id, 'first_name' => 'Pat', 'last_name' => 'Person', 'email' => 'pat.person@overview.example', 'created_by' => $this->operator->id]);
    $member = User::factory()->create(['name' => 'Mia Member', 'email' => 'mia.member@overview.example'])->assignRole('user');
    app(OrganizationMembership::class)->add($organization, $member, 'member', $this->operator);
    app(OrganizationInvitations::class)->issue($organization, 'invited.person@overview.example', $this->operator);

    $html = $this->actingAs($this->operator)->get(route('organizations.show', $organization))->assertOk()->getContent();
    $text = ws_text($html);

    expect($text)->toContain('overview.example')->toContain('+27 11 555 0100')->toContain('1 Main Road')->toContain('Private commentary.')
        ->toContain('1 person affiliated')->toContain('Affiliation records where a person works. It grants no portal access.')
        ->toContain('1 member · enabled by Olive Operator')
        // A summary, never a roster: no Person, no member, no invitation by name or address.
        ->not->toContain('Pat Person')->not->toContain('pat.person@overview.example')
        ->not->toContain('Mia Member')->not->toContain('mia.member@overview.example')->not->toContain('invited.person@overview.example');

    $xpath = ws_xpath($html);
    expect($xpath->query('//a[@href="'.route('organizations.people', $organization).'"][contains(., "View people")]'))->toHaveCount(1)
        ->and($xpath->query('//a[@href="'.route('organizations.portal', $organization).'"][contains(., "Open portal access")]'))->toHaveCount(1)
        // No lifecycle act on the Overview: each act has one place, the Portal access page.
        ->and(array_values(array_filter(array_map(fn ($f) => $f->getAttribute('action'), iterator_to_array($xpath->query('//form[contains(@action, "/organizations/")]'))), fn ($a) => true)))->toBe([]);
    expect($person->exists)->toBeTrue();
});

it('says what each state means on the Overview, with one way in, and never offers the state change itself', function (string $state, string $sentence, string $link) {
    $organization = ws_org($this->operator, $state, ['name' => 'State Co']);

    $html = $this->actingAs($this->operator)->get(route('organizations.show', $organization))->assertOk()->getContent();

    expect(ws_text($html))->toContain($sentence)
        ->and(ws_xpath($html)->query('//a[contains(., "'.$link.'")]'))->not->toHaveCount(0);
    expect(DB::table('organizations')->where('id', $organization->id)->value('portal_enabled_at') === null)->toBe($state === 'not_enabled');
})->with([
    'not enabled' => ['not_enabled', 'This Organization is in the Directory only. Portal access has never been enabled.', 'Manage portal access'],
    'enabled' => ['enabled', 'Its members can sign in and file requests for it.', 'Open portal access'],
    'disabled' => ['disabled', 'has been disabled: no new request can be filed for it.', 'Open portal access'],
]);

it('lists on People only the affiliated Directory records, none of the portal members, paginated, with the affiliation microcopy', function () {
    $organization = ws_org($this->operator, 'enabled', ['name' => 'People Co']);
    $other = ws_org($this->operator, 'enabled', ['name' => 'Other Co']);
    foreach (range(1, 27) as $n) {
        CrmContact::factory()->create(['organization_id' => $organization->id, 'first_name' => sprintf('Person%02d', $n), 'last_name' => 'Affiliated', 'created_by' => $this->operator->id]);
    }
    CrmContact::factory()->create(['organization_id' => $other->id, 'first_name' => 'Elsewhere', 'last_name' => 'Person', 'created_by' => $this->operator->id]);
    $member = User::factory()->create(['name' => 'Portal Only Member'])->assignRole('user');
    app(OrganizationMembership::class)->add($organization, $member, 'member', $this->operator);

    $first = $this->actingAs($this->operator)->get(route('organizations.people', $organization))->assertOk();
    $second = $this->actingAs($this->operator)->get(route('organizations.people', ['organization' => $organization, 'page' => 2]))->assertOk();

    expect($first->viewData('people')->total())->toBe(27)->and(count($first->viewData('people')->items()))->toBe(25)
        ->and(count($second->viewData('people')->items()))->toBe(2);
    $first->assertSee('Person01 Affiliated')->assertDontSee('Person26 Affiliated')->assertSee('Affiliation records where a person works. It grants no portal access.')
        ->assertDontSee('Elsewhere')->assertDontSee('Portal Only Member')
        ->assertSee(route('crm.contacts.create', ['company_id' => $organization->id]), false);
    $second->assertSee('Person26 Affiliated')->assertSee('Person27 Affiliated');
});

it('says so when an Organization has no people, and shows the same People page for a Not enabled Organization', function () {
    $organization = ws_org($this->operator, 'not_enabled', ['name' => 'Lonely Co']);

    $this->actingAs($this->operator)->get(route('organizations.people', $organization))->assertOk()->assertSee('No people yet.')
        ->assertSee('data-portal-state="not_enabled"', false);
});

it('reads no portal data on the People page', function () {
    $organization = ws_org($this->operator, 'enabled', ['name' => 'Quiet Co']);
    CrmContact::factory()->create(['organization_id' => $organization->id, 'created_by' => $this->operator->id]);

    $sql = ws_tablesRead(fn () => $this->actingAs($this->operator)->get(route('organizations.people', $organization))->assertOk());

    expect(array_values(array_filter($sql, fn ($q) => str_contains($q, 'organization_invitations') || str_contains($q, 'organization_members'))))->toBe([]);
});

it('draws the Portal access sections in order for an Enabled partner, and only those that apply otherwise', function (string $state, array $headings) {
    $organization = ws_org($this->operator, $state, ['name' => 'Sections Co']);
    $member = User::factory()->create(['name' => 'Sam Section'])->assignRole('user');
    $enabled = $state === 'not_enabled' ? null : $organization;

    if ($state === 'enabled') {
        app(OrganizationMembership::class)->add($organization, $member, 'admin', $this->operator);
        app(OrganizationRelationships::class)->designatePartner($organization, $this->operator);
    }

    $xpath = ws_xpath($this->actingAs($this->operator)->get(route('organizations.portal', $organization))->assertOk()->getContent());
    $found = [];
    foreach ($xpath->query('//main//section[@aria-labelledby]') as $section) {
        $found[] = trim($xpath->query('.//*[@id="'.$section->getAttribute('aria-labelledby').'"]', $section)->item(0)->textContent);
    }

    expect($found)->toBe($headings);
    expect($enabled === null || $enabled->exists)->toBeTrue();
})->with([
    'enabled partner' => ['enabled', ['Lifecycle', 'Members', 'Administrator approval', 'Partner support access', 'Invitations', 'Partner relationship']],
    'disabled' => ['disabled', ['Lifecycle', 'Members', 'Administrator approval', 'Invitations', 'Partner relationship']],
    'not enabled, no member' => ['not_enabled', ['Lifecycle']],
]);

it('gives every section a unique, resolvable heading id and every scrolling table region an accessible name', function (string $route) {
    $organization = ws_org($this->operator, 'enabled', ['name' => 'A11y Co']);
    $member = User::factory()->create()->assignRole('user');
    app(OrganizationMembership::class)->add($organization, $member, 'admin', $this->operator);
    app(OrganizationRelationships::class)->designatePartner($organization, $this->operator);
    CrmContact::factory()->create(['organization_id' => $organization->id, 'created_by' => $this->operator->id]);

    $xpath = ws_xpath($this->actingAs($this->operator)->get(route($route, $organization))->assertOk()->getContent());

    $ids = [];
    foreach ($xpath->query('//*[@id]') as $element) {
        $ids[] = $element->getAttribute('id');
    }
    expect($ids)->toBe(array_values(array_unique($ids)), 'duplicate ids on '.$route);

    foreach ($xpath->query('//*[@aria-labelledby]') as $labelled) {
        expect($xpath->query('//*[@id="'.$labelled->getAttribute('aria-labelledby').'"]'))->toHaveCount(1);
    }

    foreach ($xpath->query('//*[@role="region"]') as $region) {
        expect(trim($region->getAttribute('aria-label')))->not->toBe('')->and($region->getAttribute('tabindex'))->toBe('0');
    }

    expect($xpath->query('//h1'))->toHaveCount(1);
})->with(WS_PAGES);

it('names no Project, and reads no project link, on any workspace page', function () {
    $organization = ws_org($this->operator, 'enabled', ['name' => 'Linked Co']);
    $project = Project::factory()->create(['created_by' => $this->operator->id, 'name' => 'Secret Roadmap Alpha']);
    $project->organizations()->attach($organization->id);
    $hidden = Project::factory()->create(['created_by' => $this->operator->id, 'name' => 'Hidden Roadmap Beta']);
    $hidden->organizations()->attach($organization->id);

    foreach (WS_PAGES as $route) {
        $sql = [];
        $html = '';
        $sql = ws_tablesRead(function () use ($route, $organization, &$html) {
            $html = $this->actingAs($this->operator)->get(route($route, $organization))->assertOk()->getContent();
        });

        expect($html)->not->toContain('Secret Roadmap Alpha')->not->toContain('Hidden Roadmap Beta')->not->toContain('linked project')
            ->and(array_values(array_filter($sql, fn ($q) => str_contains($q, 'project_organization') || str_contains($q, 'from `projects`') || str_contains($q, 'from "projects"'))))->toBe([], "{$route} read a project");
    }
});

// ── 3. Authorization ─────────────────────────────────────────────────────────────────────────

it('answers a stray-crm.manage customer 403 for their own Organization and 404 for a hidden or unknown one, on every page', function (string $route) {
    $own = ws_org($this->operator, 'enabled', ['name' => 'Their Own Co']);
    $hidden = ws_org($this->operator, 'enabled', ['name' => 'Hidden Co']);
    $stray = User::factory()->create()->assignRole('user')->givePermissionTo('crm.manage');
    $own->members()->attach($stray, ['role' => 'admin']);

    $response = $this->actingAs($stray)->get(route($route, $own))->assertForbidden();
    expect($response->getContent())->not->toContain('Their Own Co')->not->toContain('Olive Operator');
    $this->actingAs($stray)->get(route($route, $hidden))->assertNotFound();
    $this->actingAs($stray)->get(route($route, 999999))->assertNotFound();
})->with(WS_PAGES);

it('answers a customer 404 in the closed and pilot modes on every workspace route, and 403 in legacy mode', function (string $mode, string $route) {
    config(['portal.customer_surface_mode' => $mode]);
    $organization = ws_org($this->operator, 'enabled', ['name' => 'Mode Co']);
    $customer = User::factory()->create()->assignRole('user');
    $organization->members()->attach($customer, ['role' => 'member']);

    $status = $this->actingAs($customer)->get(route($route, $organization))->getStatusCode();

    expect($status)->toBe($mode === 'legacy' ? 403 : 404);
})->with(['legacy', 'closed', 'pilot'])->with([...WS_PAGES, 'organizations.edit']);

it('lets an operator and a custom-role operator open every page, and refuses everyone short of both capabilities', function () {
    $organization = ws_org($this->operator, 'enabled', ['name' => 'Gate Co']);
    $custom = User::factory()->create()->givePermissionTo('portal.operator', 'crm.manage');
    $operatorOnly = User::factory()->create()->givePermissionTo('portal.operator');
    $crmOnly = User::factory()->create()->givePermissionTo('crm.manage');

    foreach (WS_PAGES as $route) {
        $this->actingAs($this->operator)->get(route($route, $organization))->assertOk();
        $this->actingAs($custom)->get(route($route, $organization))->assertOk();
        $this->actingAs($operatorOnly)->get(route($route, $organization))->assertForbidden();   // the route's own can:crm.manage
        $this->actingAs($crmOnly)->get(route($route, $organization))->assertNotFound();         // not operator-eligible: the tenant scope hides it
    }

    auth()->logout();
    $this->get(route('organizations.portal', $organization))->assertRedirect('/login');
});

it('refuses a stray-crm.manage customer every Directory write, and changes nothing', function () {
    $organization = ws_org($this->operator, 'not_enabled', ['name' => 'Write Gate Co']);
    $stray = User::factory()->create()->assignRole('user')->givePermissionTo('crm.manage');
    $organization->members()->attach($stray, ['role' => 'admin']);   // can see it
    $before = DB::table('organizations')->orderBy('id')->get()->all();

    $this->actingAs($stray)->post(route('organizations.store'), ['name' => 'Forged'])->assertForbidden();
    $this->actingAs($stray)->put(route('organizations.update', $organization), ['name' => 'Forged'])->assertForbidden();
    $this->actingAs($stray)->delete(route('organizations.destroy', $organization))->assertForbidden();
    $this->actingAs($stray)->post(route('organizations.promote', $organization))->assertForbidden();
    $this->actingAs($stray)->get(route('organizations.create'))->assertForbidden();
    $this->actingAs($stray)->get(route('organizations.edit', $organization))->assertForbidden();

    expect(DB::table('organizations')->orderBy('id')->get()->all())->toEqual($before);
});

it('builds no membership, invitation, approval or support data for an actor who passes manageDirectory and not manageMembership', function () {
    $organization = ws_org($this->operator, 'enabled', ['name' => 'Plain Co']);
    $member = User::factory()->create(['name' => 'Plain Member', 'email' => 'plain.member@plain.example'])->assignRole('user');
    app(OrganizationMembership::class)->add($organization, $member, 'admin', $this->operator);
    app(OrganizationInvitations::class)->issue($organization, 'invited@plain.example', $this->operator);
    ws_directoryOnlyPolicy(membership: false, relationships: false);

    foreach (['organizations.show', 'organizations.portal'] as $route) {
        $html = '';
        $sql = ws_tablesRead(function () use ($route, $organization, &$html) {
            $html = $this->actingAs($this->operator)->get(route($route, $organization))->assertOk()->getContent();
        });

        expect(array_values(array_filter($sql, fn ($q) => str_contains($q, 'organization_invitations'))))->toBe([], "{$route} read invitations")
            ->and($html)->not->toContain('invited@plain.example')->not->toContain('Send invitation')->not->toContain('Approve administrator')
            ->not->toContain('Administrator approval')->not->toContain('Partner support access')->not->toContain('Partner relationship');
    }

    // The Overview carries no member fact at all; the Portal access page carries the plain member list and no control.
    $overview = ws_text($this->actingAs($this->operator)->get(route('organizations.show', $organization))->getContent());
    expect($overview)->not->toContain('members ·')->not->toContain('enabled by Olive Operator');

    $portal = $this->actingAs($this->operator)->get(route('organizations.portal', $organization))->assertOk();
    $portal->assertSee('Plain Member')->assertDontSee('Add</button>', false);
    expect(ws_xpath($portal->getContent())->query('//main//form[contains(@action, "/members") or contains(@action, "/invitations") or contains(@action, "/disable") or contains(@action, "/promote")]'))->toHaveCount(0);
});

it('draws no Disable or Enable control for an actor who may not manage relationships, and no initial-enable control for one who may not manage membership', function () {
    $enabled = ws_org($this->operator, 'enabled', ['name' => 'Rel Co']);
    $never = ws_org($this->operator, 'not_enabled', ['name' => 'Never Co']);
    ws_directoryOnlyPolicy(membership: true, relationships: false);

    $this->actingAs($this->operator)->get(route('organizations.portal', $enabled))->assertOk()
        ->assertDontSee('Disable portal access')->assertDontSee('Partner relationship');
    $this->actingAs($this->operator)->get(route('organizations.portal', $never))->assertOk()->assertSee('Enable portal access');

    ws_directoryOnlyPolicy(membership: false, relationships: true);
    $this->actingAs($this->operator)->get(route('organizations.portal', $never))->assertOk()->assertDontSee('Enable portal access');
    $this->actingAs($this->operator)->get(route('organizations.portal', $enabled))->assertOk()->assertSee('Disable portal access');
});

// ── 4. The acts keep their endpoints and return to the Portal access page ────────────────────

it('enables portal access once, from the Portal access page, and returns there showing the new state and its controls', function () {
    $organization = ws_org($this->operator, 'not_enabled', ['name' => 'Enable Me Co']);

    $html = $this->actingAs($this->operator)->get(route('organizations.portal', $organization))->assertOk()->getContent();
    $form = ws_xpath($html)->query('//form[@action="'.route('organizations.promote', $organization).'"]');
    expect($form)->toHaveCount(1)->and(trim($form->item(0)->textContent))->toBe('Enable portal access');

    $this->actingAs($this->operator)->from(route('organizations.portal', $organization))
        ->post(route('organizations.promote', $organization))
        ->assertRedirect(route('organizations.portal', $organization))
        ->assertSessionHas('success', 'Portal access is enabled for "Enable Me Co".');

    $after = $this->actingAs($this->operator)->get(route('organizations.portal', $organization))->assertOk();
    $after->assertSee('data-portal-state="enabled"', false)->assertSee('Disable portal access')->assertDontSee('Enable portal access');
    expect($organization->fresh()->portalState()->value)->toBe('enabled');
});

it('returns every administrative act to the Portal access page of the Organization it concerned', function () {
    $partner = ws_org($this->operator, 'enabled', ['name' => 'Return Partner']);
    $customer = ws_org($this->operator, 'enabled', ['name' => 'Return Customer']);
    $account = User::factory()->create()->assignRole('user');
    $portal = route('organizations.portal', $partner);
    $as = fn () => $this->actingAs($this->operator);

    // Every act, in an order each can succeed in. Each one is a real success path, and each redirects to the Portal access page.
    $as()->put(route('organizations.partner.designate', $partner->id))->assertRedirect($portal);
    $as()->post(route('organizations.customers.link', $partner->id), ['customer_id' => $customer->id])->assertRedirect($portal);
    $as()->post(route('organizations.members.store', $partner), ['user_id' => $account->id, 'role' => 'member'])->assertRedirect($portal);
    $as()->put(route('organizations.members.role', [$partner, $account->id]), ['role' => 'admin'])->assertRedirect($portal);
    $as()->post(route('organizations.members.approve', [$partner, $account->id]))->assertRedirect($portal);
    $as()->post(route('organizations.members.support.grant', [$partner->id, $account->id]))->assertRedirect($portal);
    $as()->delete(route('organizations.members.support.revoke', [$partner->id, $account->id]))->assertRedirect($portal);
    $as()->delete(route('organizations.members.approval.revoke', [$partner, $account->id]), ['confirm_no_administrator' => 1])->assertRedirect($portal);
    $as()->put(route('organizations.members.role', [$partner, $account->id]), ['role' => 'member'])->assertRedirect($portal);
    $as()->post(route('organizations.invitations.store', $partner), ['email' => 'return@example.test'])->assertRedirect($portal);
    $invitation = OrganizationInvitation::query()->where('organization_id', $partner->id)->firstOrFail();
    $as()->delete(route('organizations.invitations.destroy', [$partner, $invitation->id]))->assertRedirect($portal);
    $as()->delete(route('organizations.members.destroy', [$partner, $account->id]))->assertRedirect($portal);
    $as()->delete(route('organizations.manager.unlink', $customer->id))->assertRedirect(route('organizations.portal', $customer));
    $as()->put(route('organizations.manager.assign', $customer->id), ['partner_id' => $partner->id])->assertRedirect(route('organizations.portal', $customer));
    $as()->delete(route('organizations.manager.unlink', $customer->id))->assertRedirect(route('organizations.portal', $customer));
    $as()->delete(route('organizations.partner.remove', $partner->id))->assertRedirect($portal);
    $as()->post(route('organizations.disable', $partner->id))->assertRedirect($portal);
    $as()->post(route('organizations.enable', $partner->id))->assertRedirect($portal);
});

it('returns a validation error to the Portal access page with the error beside its form', function () {
    $organization = ws_org($this->operator, 'enabled', ['name' => 'Error Co']);
    $portal = route('organizations.portal', $organization);

    // Invitation: a malformed address is refused by the controller, and the page it returns to shows the message and keeps what was typed.
    $page = $this->actingAs($this->operator)->followingRedirects()->from($portal)
        ->post(route('organizations.invitations.store', $organization), ['email' => 'not-an-address'])
        ->assertOk();
    expect(ws_xpath($page->getContent())->query('//nav[@aria-label="Organization sections"]//a[@aria-current="page"][contains(., "Portal access")]'))->toHaveCount(1)
        ->and(ws_text($page->getContent()))->toContain('email');

    // Member add: a missing account.
    $page = $this->actingAs($this->operator)->followingRedirects()->from($portal)
        ->post(route('organizations.members.store', $organization), ['user_id' => '', 'role' => 'nonsense'])
        ->assertOk();
    expect(ws_xpath($page->getContent())->query('//nav[@aria-label="Organization sections"]//a[@aria-current="page"][contains(., "Portal access")]'))->toHaveCount(1);

    // Enabling an Organization that already has portal access: a controlled refusal, shown on the Portal access page, nothing changed.
    $before = DB::table('organizations')->where('id', $organization->id)->first();
    $page = $this->actingAs($this->operator)->followingRedirects()->from($portal)
        ->post(route('organizations.promote', $organization))
        ->assertOk();
    expect(ws_text($page->getContent()))->toContain('already has portal access')
        ->and(DB::table('organizations')->where('id', $organization->id)->first())->toEqual($before);
});

it('returns Directory writes to the page they belong to, with the message on the Overview', function () {
    $this->actingAs($this->operator)->post(route('organizations.store'), ['name' => 'Fresh Co'])
        ->assertRedirect()->assertSessionHas('success', 'Organization created.');
    $created = Organization::query()->where('name', 'Fresh Co')->firstOrFail();

    $this->actingAs($this->operator)->get(route('organizations.show', $created))->assertOk();
    $this->actingAs($this->operator)->put(route('organizations.update', $created), ['name' => 'Fresh Co Ltd'])->assertRedirect(route('organizations.show', $created));
    $this->actingAs($this->operator)->followingRedirects()->put(route('organizations.update', $created), ['name' => 'Fresh Co Renamed'])->assertSee('Organization updated.');

    // A refused edit returns to the edit page.
    $this->actingAs($this->operator)->from(route('organizations.edit', $created))->put(route('organizations.update', $created->id), ['name' => ''])
        ->assertRedirect(route('organizations.edit', $created))->assertSessionHasErrors('name');

    $this->actingAs($this->operator)->delete(route('organizations.destroy', $created))->assertRedirect(route('organizations.index'));
});

// ── 5. Confirmation text is a JSON string ────────────────────────────────────────────────────

const WS_EVIL_NAME = "O'Brien \"Quote\" \\ Back\nSlash </script><b>&amp; \u{2028}End";

/**
 * Every inline confirmation on the page: the JavaScript argument of `return confirm(...)`, decoded. `@js` writes a string as a single-quoted JavaScript literal in which every quote,
 * angle bracket, ampersand and line separator is a \uXXXX escape, so the literal holds no raw quote, markup or newline: that is asserted, and then it is read as the JSON string it is.
 *
 * @return list<string>
 */
function ws_confirmations(string $html): array
{
    $found = [];
    foreach (ws_xpath($html)->query('//*[@onsubmit or @onclick]') as $element) {
        $code = $element->getAttribute('onsubmit') ?: $element->getAttribute('onclick');

        if (! preg_match("/^return confirm\\('(.*)'\\)$/s", $code, $match)) {
            continue;
        }

        expect($match[1])->not->toContain("'")->not->toContain('"')->not->toContain('<')->not->toContain('>')->not->toContain("\n")->not->toContain("\u{2028}");
        $decoded = json_decode('"'.$match[1].'"');
        expect($decoded)->toBeString('the confirmation is not a string literal: '.$code);
        $found[] = $decoded;
    }

    return $found;
}

it('writes every confirmation as an escaped string literal, so an Organization name with a quote, backslash, newline or markup cannot break out', function () {
    $partner = ws_org($this->operator, 'enabled', ['name' => WS_EVIL_NAME]);
    $customer = ws_org($this->operator, 'enabled', ['name' => "Customer ' \" \\ Co"]);
    app(OrganizationRelationships::class)->designatePartner($partner, $this->operator);
    app(OrganizationRelationships::class)->assignCustomer($customer, $partner->fresh(), $this->operator);
    $member = User::factory()->create(['name' => "Member ' \" \\ Name"])->assignRole('user');
    app(OrganizationMembership::class)->add($partner, $member, 'member', $this->operator);
    $never = ws_org($this->operator, 'not_enabled', ['name' => WS_EVIL_NAME.' Never']);

    $pages = [
        'partner portal' => route('organizations.portal', $partner),
        'customer portal' => route('organizations.portal', $customer),
        'never portal' => route('organizations.portal', $never),
        'edit' => route('organizations.edit', $never),
    ];

    $all = [];
    foreach ($pages as $label => $url) {
        $html = $this->actingAs($this->operator)->get($url)->assertOk()->getContent();
        // No JavaScript-quoted fragment of the name survives in markup: the apostrophe and the markup are escaped, never raw.
        expect($html)->not->toContain("O'Brien")->not->toContain('</script><b>');
        $all[$label] = ws_confirmations($html);
    }

    // Disable and the partner-designation removal name the partner; Unlink names both customer and partner; Remove names the member; Enable portal access and Delete name the Never-enabled Organization.
    expect(collect($all['partner portal'])->contains(fn ($c) => str_starts_with($c, 'Disable portal access for '.WS_EVIL_NAME.'?')))->toBeTrue()
        ->and(collect($all['partner portal'])->contains(fn ($c) => $c === 'Remove the partner designation from '.WS_EVIL_NAME.'?'))->toBeFalse()   // a partner with customers has no removal control
        ->and(collect($all['partner portal'])->contains(fn ($c) => $c === "Unlink Customer ' \" \\ Co from ".WS_EVIL_NAME.'?'))->toBeTrue()
        ->and(collect($all['partner portal'])->contains(fn ($c) => $c === "Remove Member ' \" \\ Name?"))->toBeTrue()
        ->and(collect($all['customer portal'])->contains(fn ($c) => $c === "Unlink Customer ' \" \\ Co from its managing partner?"))->toBeTrue()
        ->and($all['never portal'])->toContain('Enable portal access for '.WS_EVIL_NAME.' Never?')
        ->and($all['edit'])->toContain('Delete '.WS_EVIL_NAME.' Never? This cannot be undone.');

    // Re-enable and partner-designation removal, on a Disabled Organization and a partner with no customers.
    app(OrganizationRelationships::class)->unlinkCustomer($customer->fresh(), $this->operator);
    $lone = $this->actingAs($this->operator)->get(route('organizations.portal', $partner))->getContent();
    expect(ws_confirmations($lone))->toContain('Remove the partner designation from '.WS_EVIL_NAME.'?');

    app(OrganizationRelationships::class)->disable($partner->fresh(), $this->operator);
    $disabled = $this->actingAs($this->operator)->get(route('organizations.portal', $partner))->getContent();
    expect(collect(ws_confirmations($disabled))->contains(fn ($c) => str_starts_with($c, 'Re-enable portal access for '.WS_EVIL_NAME.'?')))->toBeTrue();
});

it('has no inline confirmation built with addslashes on any Organization page', function () {
    foreach (glob(resource_path('views/organizations/*.blade.php')) as $view) {
        expect(file_get_contents($view))->not->toContain('addslashes', basename($view));
    }
});

// ── Support, and the links between the pages ─────────────────────────────────────────────────

it('draws the Partner support access section only for a partner, with the grant control only while Enabled', function () {
    $partner = ws_org($this->operator, 'enabled', ['name' => 'Support Partner']);
    app(OrganizationRelationships::class)->designatePartner($partner, $this->operator);
    $staff = User::factory()->create(['name' => 'Sue Support'])->assignRole('user');
    app(OrganizationMembership::class)->add($partner, $staff, 'member', $this->operator);

    $enabled = $this->actingAs($this->operator)->get(route('organizations.portal', $partner))->assertOk();
    $enabled->assertSee('Partner support access')->assertSee('Grant support access');

    app(PartnerSupportGrants::class)->grant($partner, $staff, $this->operator);
    app(OrganizationRelationships::class)->disable($partner->fresh(), $this->operator);

    $disabled = $this->actingAs($this->operator)->get(route('organizations.portal', $partner))->assertOk();
    $disabled->assertSee('Revoke support access')->assertDontSee('Grant support access');
});

it('resolves every internal link on the workspace pages and the two lists, with no refusal and no error', function () {
    $partner = ws_org($this->operator, 'enabled', ['name' => 'Link Partner']);
    $customer = ws_org($this->operator, 'enabled', ['name' => 'Link Customer']);
    app(OrganizationRelationships::class)->designatePartner($partner, $this->operator);
    app(OrganizationRelationships::class)->assignCustomer($customer, $partner->fresh(), $this->operator);
    $member = User::factory()->create()->assignRole('user');
    app(OrganizationMembership::class)->add($partner, $member, 'admin', $this->operator);
    CrmContact::factory()->create(['organization_id' => $partner->id, 'created_by' => $this->operator->id]);

    $pages = [
        route('organizations.index'), route('organizations.access'), route('organizations.create'),
        route('organizations.show', $partner), route('organizations.people', $partner), route('organizations.portal', $partner),
        route('organizations.show', $customer), route('organizations.portal', $customer), route('organizations.edit', $partner),
    ];
    $checked = [];

    foreach ($pages as $page) {
        $xpath = ws_xpath($this->actingAs($this->operator)->get($page)->assertOk()->getContent());

        foreach ($xpath->query('//a[@href]') as $link) {
            $href = $link->getAttribute('href');
            $path = parse_url($href, PHP_URL_PATH);

            if (! str_starts_with($href, config('app.url')) && ! str_starts_with($href, '/')) {
                continue;
            }
            if (in_array($path, ['/logout', '#'], true) || isset($checked[$href])) {
                continue;
            }

            $checked[$href] = true;
            $status = $this->actingAs($this->operator)->get($href)->getStatusCode();
            expect($status)->toBeIn([200, 301, 302], "{$page} links to {$href}");
        }
    }

    expect(count($checked))->toBeGreaterThan(15);
});

it('keeps the System list a list: each row opens the Portal access page, and the Directory list opens the Overview', function () {
    $organization = ws_org($this->operator, 'enabled', ['name' => 'Row Co']);

    $access = $this->actingAs($this->operator)->get(route('organizations.access'))->assertOk();
    $directory = $this->actingAs($this->operator)->get(route('organizations.index'))->assertOk();

    // (The attribute is matched whole: the Overview URL is a prefix of the Portal access URL.)
    $href = fn (string $route) => 'href="'.route($route, $organization).'"';
    $access->assertSee($href('organizations.portal'), false)->assertDontSee($href('organizations.show'), false);
    $directory->assertSee($href('organizations.show'), false)->assertSee($href('organizations.portal'), false);
    expect(ws_xpath($access->getContent())->query('//main//form'))->toHaveCount(0);
});

it('links an Organization from the People list and a Person page to its Overview, and a member from the user pages to its Portal access', function () {
    $organization = ws_org($this->operator, 'enabled', ['name' => 'Cross Co']);
    $person = CrmContact::factory()->create(['organization_id' => $organization->id, 'created_by' => $this->operator->id]);
    $member = User::factory()->create()->assignRole('user');
    app(OrganizationMembership::class)->add($organization, $member, 'member', $this->operator);

    $this->actingAs($this->operator)->get(route('crm.contacts.show', $person))->assertOk()->assertSee(route('organizations.show', $organization), false);
    $this->actingAs($this->operator)->get(route('crm.contacts.index'))->assertOk()->assertSee(route('organizations.show', $organization), false);
    $this->actingAs($this->operator)->get(route('users.show', $member))->assertOk()->assertSee(route('organizations.portal', $organization), false);
});

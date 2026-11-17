<?php

/*
 * Release 1.0 (DIR-1): the operator-facing Directory vocabulary (DIR-0 section 3). Directory, Person / People, Organization, Portal access,
 * Enable portal access, Organization access, Linked organizations. Never "Company", "CRM", "Promote" or "Org", and never "Contact" for a
 * Directory record. "Contact" keeps its Helpdesk meaning (the customer contact a partner raises a request for), which these pages do not use.
 *
 * Technical names (CrmContact, crm_* tables, crm.* routes, crm.manage, Organization) are NOT asserted away: only what a person reads is.
 */

use App\Models\CrmCompany;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\User;
use App\Services\CrmService;
use App\Services\ProjectService;
use App\Shared\Navigation\NavigationBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create(['name' => 'Olive Operator'])->assignRole('operator');
});

/** The retired words, as whole words. `Contact` is checked separately because it has a legitimate Helpdesk meaning elsewhere. */
const DIR1_RETIRED = '/\b(Compan(?:y|ies)|CRM|Promot(?:e|ed|es|ing|ion)|Org)\b|\bContacts?\b/';

/** What a person reads: scripts, styles and SVG removed, tags removed, entities decoded, whitespace collapsed. */
function dir1Readable(string $html): string
{
    $html = preg_replace('#<(script|style|svg)\b.*?</\1>#is', ' ', $html);

    return html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags($html))));
}

function dir1Words(string $text): array
{
    preg_match_all(DIR1_RETIRED, $text, $matches);

    return array_values(array_unique($matches[0]));
}

// ── Every touched page, as rendered ───────────────────────────────────────────

it('uses no retired word on any Directory, Organization access, User or Role page an operator reads', function () {
    $promoted = CrmCompany::factory()->create(['name' => 'Acme Holdings', 'created_by' => $this->operator->id]);
    $organization = app(CrmService::class)->promoteToOrganization($promoted, $this->operator);
    $bare = CrmCompany::factory()->create(['name' => 'Globex Group', 'created_by' => $this->operator->id]);
    $person = CrmContact::factory()->create(['first_name' => 'Pat', 'last_name' => 'Doe', 'crm_company_id' => $promoted->id, 'email' => 'pat@acme.test']);
    $drifter = CrmContact::factory()->create(['first_name' => 'Sam', 'last_name' => 'Roe', 'crm_company_id' => null]);
    $member = User::factory()->create(['name' => 'Mary Member'])->assignRole('user');
    DB::table('organization_members')->insert(['organization_id' => $organization->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
    $role = Role::create(['name' => 'dir1-vocab', 'guard_name' => 'web']);

    $pages = [
        'people' => route('crm.contacts.index'),
        'person' => route('crm.contacts.show', $person),
        'person without an organization' => route('crm.contacts.show', $drifter),
        'new person' => route('crm.contacts.create'),
        'edit person' => route('crm.contacts.edit', $person),
        'organizations' => route('crm.companies.index'),
        'organization with portal access' => route('crm.companies.show', $promoted),
        'organization without portal access' => route('crm.companies.show', $bare),
        'new organization' => route('crm.companies.create'),
        'edit organization' => route('crm.companies.edit', $bare),
        'organization access' => route('organizations.index'),
        'organization administration' => route('organizations.show', $organization),
        'users' => route('users.index'),
        'user' => route('users.show', $member),
        'roles' => route('roles.index'),
        'new role' => route('roles.create'),
        'edit role' => route('roles.edit', $role),
    ];

    foreach ($pages as $label => $url) {
        $text = dir1Readable($this->actingAs($this->operator)->get($url)->assertOk()->getContent());

        expect(dir1Words($text))->toBe([], "$label ($url) shows a retired word");
    }
});

it('scans an empty search result and the empty states too', function () {
    foreach ([route('crm.contacts.index', ['search' => 'zzz']), route('crm.companies.index', ['search' => 'zzz']), route('users.index', ['search' => 'zzz'])] as $url) {
        expect(dir1Words(dir1Readable($this->actingAs($this->operator)->get($url)->assertOk()->getContent())))->toBe([], $url);
    }

    // Organization access with nothing yet.
    expect(dir1Words(dir1Readable($this->actingAs($this->operator)->get(route('organizations.index'))->getContent())))->toBe([]);
});

// ── Every state, from the source (a render cannot reach them all) ─────────────

it('uses no retired word in the visible text of any Directory, Organization access, User or Role view', function () {
    $directories = ['crm', 'organizations', 'admin/users', 'admin/roles'];
    $violations = [];

    foreach ($directories as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path("views/$directory"), FilesystemIterator::SKIP_DOTS)) as $file) {
            $source = file_get_contents($file->getPathname());
            // Remove what a reader never sees: Blade comments, `{{ }}` and `{!! !!}` expressions, `@php` blocks, single-line directives.
            $source = preg_replace('/\{\{--.*?--\}\}/s', ' ', $source);
            $source = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', ' ', $source);
            $source = preg_replace('/@php.*?@endphp/s', ' ', $source);
            $source = preg_replace('/^\s*@\w+.*$/m', ' ', $source);
            $source = preg_replace('/<!--.*?-->/s', ' ', $source);

            preg_match_all('/>([^<>]+)</', $source, $texts);
            preg_match_all('/\b(?:placeholder|aria-label|title|alt|value)="([^"{}]*)"/', $source, $attributes);
            // Strings inside onclick/onsubmit confirm() dialogs are read by a person as well.
            preg_match_all("/confirm\\('([^']*)'/", $source, $confirmations);

            foreach ([...$texts[1], ...$attributes[1], ...$confirmations[1]] as $text) {
                if (dir1Words($text) !== []) {
                    $violations[] = str_replace(resource_path('views/'), '', $file->getPathname()).': "'.trim($text).'"';
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

// ── Navigation labels and page titles agree ───────────────────────────────────

it('opens, from each Directory and System navigation entry, a page whose heading is that entry\'s label', function () {
    $request = Request::create(route('dashboard', [], false));
    $request->setUserResolver(fn () => $this->operator);
    $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName('dashboard'));

    $navigation = app(NavigationBuilder::class)->build($request);
    $workspaces = collect($navigation['workspaces'])->keyBy('key');
    $entries = collect(['directory', 'system'])
        ->flatMap(fn (string $key) => collect($workspaces[$key]['context'])->flatMap(fn ($section) => $section['items']))
        ->keyBy('key');

    expect($entries->pluck('label', 'key')->only(['directory.people', 'directory.organizations', 'system.organization-access', 'system.users', 'system.roles'])->all())
        ->toBe([
            'directory.people' => 'People',
            'directory.organizations' => 'Organizations',
            'system.users' => 'Users',
            'system.roles' => 'Roles',
            'system.organization-access' => 'Organization access',
        ]);

    foreach (['directory.people', 'directory.organizations', 'system.organization-access', 'system.users', 'system.roles'] as $key) {
        $html = $this->actingAs($this->operator)->get($entries[$key]['href'])->assertOk()->getContent();

        preg_match('#<h1[^>]*>(.*?)</h1>#s', $html, $heading);
        preg_match('#<title>(.*?)</title>#s', $html, $title);

        expect(trim(html_entity_decode(strip_tags($heading[1] ?? ''))))->toBe($entries[$key]['label'], "$key heading")
            ->and(html_entity_decode($title[1] ?? ''))->toContain($entries[$key]['label']);
    }
});

// ── Flashes and validation messages ───────────────────────────────────────────

it('uses the glossary in every Directory flash message', function () {
    $this->actingAs($this->operator);

    $this->post(route('crm.companies.store'), ['name' => 'Flash Holdings'])->assertSessionHas('success', 'Organization created.');
    $company = CrmCompany::where('name', 'Flash Holdings')->firstOrFail();

    $this->put(route('crm.companies.update', $company), ['name' => 'Flash Holdings Ltd'])->assertSessionHas('success', 'Organization updated.');
    $this->post(route('crm.companies.promote', $company))->assertSessionHas('success', 'Portal access is enabled for "Flash Holdings Ltd".');

    $this->post(route('crm.contacts.store'), ['first_name' => 'Ann', 'last_name' => 'Lee', 'crm_company_id' => $company->id])->assertSessionHas('success', 'Person created.');
    $person = CrmContact::where('last_name', 'Lee')->firstOrFail();
    $this->put(route('crm.contacts.update', $person), ['first_name' => 'Anne', 'last_name' => 'Lee'])->assertSessionHas('success', 'Person updated.');
    $this->delete(route('crm.contacts.destroy', $person))->assertSessionHas('success', 'Person deleted.');

    $other = CrmCompany::factory()->create(['created_by' => $this->operator->id]);
    $this->delete(route('crm.companies.destroy', $other))->assertSessionHas('success', 'Organization deleted.');
});

it('names an invalid organization as an organization in validation messages, on People and on Projects', function () {
    $this->actingAs($this->operator);

    $this->post(route('crm.contacts.store'), ['first_name' => 'No', 'last_name' => 'Org', 'crm_company_id' => 999999])
        ->assertSessionHasErrors(['crm_company_id' => 'The selected organization is invalid.']);

    $project = app(ProjectService::class)->create($this->operator, ['name' => 'Linked Project']);

    $this->put(route('projects.companies.sync', $project), ['companies' => [999999]])
        ->assertSessionHasErrors(['companies.0' => 'The selected organization is invalid.']);
    $this->put(route('projects.companies.sync', $project), [])
        ->assertSessionHasErrors(['companies' => 'The linked organizations field must be present.']);

    $this->put(route('projects.companies.sync', $project), ['companies' => []])->assertSessionHas('success', 'Linked organizations updated.');
});

it('keeps the Helpdesk meaning of "contact" untouched', function () {
    // The customer contact a partner raises a request for is a User and keeps its wording; this package never edits that text.
    expect(file_get_contents(app_path('Services/ServiceRequestAccess.php')))->toContain('That contact is not available for this request.');
    expect(file_get_contents(app_path('Notifications/TicketCreatedNotification.php')))->toContain("'a contact'");
});

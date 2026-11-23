<?php

/*
 * Release 1.0 (ORG-2, CP5): what the Directory profile of an Organization must NEVER reach. The Directory columns (website, phone, address, notes), the internal
 * columns (`created_by`, `classification`) and the People affiliated with it are visible to authorized operators on the Directory pages and nowhere else
 * (owner rulings 1 and 10). This file does not rely on `$hidden` alone: it writes distinctive SENTINEL values into every one of them and reads the REAL
 * responses (page HTML including the Inertia page JSON, the shared props, mails, exports and the activity presentation) for the sentinels.
 *
 * A negative control proves the scan can see: the same sentinels ARE present on the Directory page an authorized operator opens.
 */

use App\Http\Presenters\ProjectPresenter;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Notifications\TicketCreatedNotification;
use App\Services\OrganizationDirectory;
use App\Services\OrganizationInvitations;
use App\Services\OrganizationRelationships;
use App\Services\PartnerSupportGrants;
use App\Support\Organizations\Classification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

const LEAK_SENTINELS = [
    'website' => 'sentinel-website.example',
    'phone' => 'SENTINEL-PHONE-0100',
    'address' => 'SENTINEL-ADDRESS-LINE',
    'notes' => 'SENTINEL-NOTES-INTERNAL',
    'creator' => 'SENTINEL-CREATOR-NAME',
    'person' => 'SENTINEL-PERSON',
];

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->creator = User::factory()->create(['name' => LEAK_SENTINELS['creator'], 'email' => 'sentinel-creator@leak.test'])->assignRole('operator');
    $this->operator = User::factory()->create(['name' => 'Olive Operator'])->assignRole('operator');

    $this->organization = Organization::factory()->create([
        'name' => 'Leak Co', 'portal_enabled_by' => $this->operator->id, 'created_by' => $this->creator->id, 'classification' => Classification::Vendor->value,
        'website' => 'https://'.LEAK_SENTINELS['website'], 'phone' => LEAK_SENTINELS['phone'], 'address' => LEAK_SENTINELS['address'], 'notes' => LEAK_SENTINELS['notes'],
    ]);
    $this->person = CrmContact::factory()->create([
        'organization_id' => $this->organization->id, 'first_name' => LEAK_SENTINELS['person'], 'last_name' => 'Doe', 'email' => 'sentinel-person@leak.test', 'created_by' => $this->operator->id,
    ]);

    $this->member = User::factory()->create(['name' => 'Mary Member'])->assignRole('user');
    $this->organization->members()->attach($this->member, ['role' => 'member']);
    $this->customerProject = Project::factory()->create(['created_by' => $this->operator->id, 'name' => 'Customer Project']);
    $this->customerProject->members()->attach($this->member->id, ['role' => 'manager']);
    $this->customerProject->organizations()->attach($this->organization->id);
    $this->member->givePermissionTo('projects.manage');
    $this->ticket = Ticket::factory()->open()->for($this->member, 'user')->create(['organization_id' => $this->organization->id, 'title' => 'Leak ticket']);
});

/** Every sentinel the content must not hold, plus the internal column names as JSON keys. @return list<string> */
function leakedIn(string $content): array
{
    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);
    $found = array_values(array_filter(LEAK_SENTINELS, fn (string $needle) => str_contains($content, $needle)));

    foreach (['"classification"', '"created_by"'] as $key) {
        // `created_by` is a column of other records too (a ticket, a task); only the Organization's own is the subject here, so it is looked for beside an organization.
        if ($key === '"classification"' && str_contains($content, $key)) {
            $found[] = $key;
        }
    }

    return $found;
}

function expectNoLeak(TestResponse $response, string $label): void
{
    expect($response->getStatusCode())->toBeIn([200, 302, 403, 404], "{$label} answered unexpectedly");
    expect(leakedIn((string) $response->getContent()))->toBe([], "{$label} leaks Directory data");
}

it('proves the scan can see: an authorized operator\'s Overview and People pages show every sentinel', function () {
    // ORG-3b: the Directory columns are on the Overview, and the affiliated People on their own page. Together they are the whole of what the scan must see.
    $read = fn (string $route) => html_entity_decode($this->actingAs($this->operator)->get(route($route, $this->organization))->assertOk()->getContent(), ENT_QUOTES | ENT_HTML5);
    $overview = $read('organizations.show');
    $people = $read('organizations.people');

    foreach (['website', 'phone', 'address', 'notes', 'creator'] as $key) {
        expect(str_contains($overview, LEAK_SENTINELS[$key]))->toBeTrue("the negative control cannot see {$key} on the Overview");
    }
    expect(str_contains($people, LEAK_SENTINELS['person']))->toBeTrue('the negative control cannot see the Person on the People page');
    // The Overview is a summary: it counts People and names none.
    expect(str_contains($overview, LEAK_SENTINELS['person']))->toBeFalse();
});

it('keeps every Directory column off every page a customer member reads', function () {
    $pages = [
        'dashboard' => route('dashboard'),
        'tickets index' => route('tickets.index'),
        'tickets create' => route('tickets.create'),
        'ticket show' => route('tickets.show', $this->ticket),
        'projects index' => route('projects.index'),
        'project show' => route('projects.show', $this->customerProject),
        'project edit' => route('projects.edit', $this->customerProject),
        'projects create' => route('projects.create'),
        'tasks' => route('tasks.index', ['view' => 'all', 'organization' => $this->organization->id]),
    ];

    foreach ($pages as $label => $url) {
        $response = $this->actingAs($this->member)->get($url);
        $response->assertSuccessful();
        expectNoLeak($response, $label);
    }

    // The ONE place the Organization appears to its own member is its name: on the project form's options and on the ticket.
    $edit = $this->actingAs($this->member)->get(route('projects.edit', $this->customerProject))->getContent();
    expect($edit)->toContain('Leak Co');
});

it('keeps the shared page props free of the Directory columns for a member and for a customer holding a stray crm.manage', function () {
    $stray = User::factory()->create()->assignRole('user')->givePermissionTo('crm.manage');
    $this->organization->members()->attach($stray, ['role' => 'admin']);

    foreach ([$this->member, $stray] as $user) {
        foreach ([route('dashboard'), route('tickets.index')] as $url) {
            expectNoLeak($this->actingAs($user)->get($url), $url);
        }
    }

    // (The stray crm.manage holder's refusal from the Directory and from the Organization access pages, with its exact status per surface mode, is the tests below.)
});

it('keeps them off the partner-support pages of the partner that manages this Organization', function () {
    $partner = Organization::factory()->create(['portal_enabled_by' => $this->operator->id, 'name' => 'Partner Co']);
    app(OrganizationRelationships::class)->designatePartner($partner, $this->operator);
    app(OrganizationRelationships::class)->assignCustomer($this->organization, $partner->fresh(), $this->operator);
    $staff = User::factory()->create()->assignRole('user');
    $partner->members()->attach($staff, ['role' => 'member']);
    app(PartnerSupportGrants::class)->grant($partner->fresh(), $staff, $this->operator);

    foreach ([route('support.index'), route('support.show', $this->ticket)] as $url) {
        $response = $this->actingAs($staff)->get($url);
        expect($response->getStatusCode())->toBe(200, $url);
        expectNoLeak($response, $url);
    }
});

it('keeps them out of the mails: the request confirmation and the invitation name the Organization and nothing else of it', function () {
    $mail = (new TicketCreatedNotification($this->ticket, TicketCreatedNotification::FOR_REQUESTER))->toMail($this->member);
    expect(leakedIn($mail->render()->toHtml().implode(' ', $mail->introLines ?? [])))->toBe([]);

    Notification::fake();
    $invitation = app(OrganizationInvitations::class)->issue($this->organization, 'invitee@leak.test', $this->operator);
    $token = null;
    Notification::assertSentOnDemand(OrganizationInvitationNotification::class, function ($notification) use (&$token) {
        $token = (new ReflectionProperty($notification, 'token'))->getValue($notification);

        return true;
    });
    expect($invitation->exists)->toBeTrue();

    expect($token)->not->toBeNull();
    $rendered = (new OrganizationInvitationNotification($invitation->id, $token))->toMail(new AnonymousNotifiable);
    expect(leakedIn($rendered->render()->toHtml()))->toBe([]);

    // The guest invitation page, opened with that credential by someone who is not signed in.
    $page = $this->get(route('invitation.show', ['token' => $token]));
    expect($page->getStatusCode())->toBeIn([200, 302]);
    expectNoLeak($page, 'the guest invitation page');
});

it('keeps them out of the operator export and out of the activity presentation, which names the Organization and says that notes changed', function () {
    $csv = $this->actingAs($this->operator)->get(route('operator.tickets.export'));
    expect($csv->getStatusCode())->toBe(200);
    expectNoLeak($csv, 'the operator request export');

    $directory = app(OrganizationDirectory::class);
    $directory->update($this->organization, $this->operator, ['notes' => 'SENTINEL-NOTES-INTERNAL changed again', 'name' => 'Leak Co Ltd']);

    $log = json_encode(DB::table('activity_log')->get()->all());
    expect($log)->not->toContain('SENTINEL-NOTES-INTERNAL');

    // The one page that presents an operator's audit trail (the user administration page) shows no notes text either.
    $page = $this->actingAs($this->operator)->get(route('users.show', $this->operator))->assertOk();
    expect(html_entity_decode($page->getContent()))->not->toContain('SENTINEL-NOTES-INTERNAL');
});

it('keeps the internal columns out of every generic serialization of an Organization and of a Person list', function () {
    $organization = Organization::withoutGlobalScopes()->find($this->organization->id);

    foreach ([$organization->toArray(), json_decode($organization->toJson(), true), json_decode(Organization::withoutGlobalScopes()->get()->toJson(), true)[0], json_decode(json_encode(['org' => $organization]), true)['org']] as $serialized) {
        expect($serialized)->not->toHaveKeys(['notes', 'created_by', 'classification'])
            ->and(json_encode($serialized))->not->toContain(LEAK_SENTINELS['notes']);
    }

    // The columns are still there for the code that asks for them by name.
    expect($organization->notes)->toBe(LEAK_SENTINELS['notes'])->and($organization->classification)->toBe(Classification::Vendor)->and($organization->created_by)->toBe($this->creator->id);

    // What the project form is given: id and name only.
    $options = ProjectPresenter::companyOptions();
    $this->actingAs($this->member);
    expect(ProjectPresenter::companyOptions())->toBe([['id' => $this->organization->id, 'name' => 'Leak Co']]);
});

/**
 * The exact status a customer gets for an operator-only route, per customer surface mode (CP6, F-5). In `closed` and `pilot` the customer-surface gate answers 404
 * for anything not on the customer allowlist, so the route's existence is not confirmed. In `legacy` (the separately accepted semantics) there is no gate: a route
 * with no record in it is refused by its own `can:` middleware or the policy (403), and a route that binds a record the customer's scope hides (a Person: owner
 * ruling D7) cannot bind it, so it is 404 before any authorization. Never "either": a test that accepts both would pass through an unrelated failure.
 *
 * @param  bool  $bindsHiddenRecord  the route binds a record this customer cannot see
 */
function expectedSurfaceStatus(string $mode, bool $bindsHiddenRecord): int
{
    return $mode === 'legacy' && ! $bindsHiddenRecord ? 403 : 404;
}

it('answers a customer exactly 404 (closed, pilot; legacy for a Person) or 403 (legacy, no record) for the People pages and never confirms a Person exists', function (string $mode) {
    config(['portal.customer_surface_mode' => $mode]);

    $pages = [
        [route('crm.contacts.index'), false],
        [route('crm.contacts.create'), false],
        [route('crm.contacts.show', $this->person), true],
        [route('crm.contacts.edit', $this->person), true],
    ];

    foreach ($pages as [$url, $bindsHiddenRecord]) {
        // The control: the SAME route and mode answer an operator, so the refusal below is the customer's, not a broken route.
        $this->actingAs($this->operator)->get($url)->assertOk();

        $response = $this->actingAs($this->member)->get($url);
        expect($response->getStatusCode())->toBe(expectedSurfaceStatus($mode, $bindsHiddenRecord), "{$mode}: {$url}");
        expectNoLeak($response, $url);
    }
})->with(['closed', 'pilot', 'legacy']);

it('refuses a customer holding only a stray crm.manage the Directory with the exact status of the mode, and leaks nothing', function (string $mode) {
    config(['portal.customer_surface_mode' => $mode]);

    $stray = User::factory()->create()->assignRole('user')->givePermissionTo('crm.manage');
    $this->organization->members()->attach($stray, ['role' => 'admin']);

    // The Organization is visible to this member (it binds); a Person is not (D7).
    $pages = [
        [route('organizations.show', $this->organization), false],
        [route('organizations.people', $this->organization), false],
        [route('organizations.portal', $this->organization), false],
        [route('organizations.edit', $this->organization), false],
        [route('organizations.index'), false],
        [route('crm.contacts.index'), false],
        [route('crm.contacts.show', $this->person), true],
    ];

    foreach ($pages as [$url, $bindsHiddenRecord]) {
        $this->actingAs($this->operator)->get($url)->assertOk();   // the control

        $response = $this->actingAs($stray)->get($url);
        expect($response->getStatusCode())->toBe(expectedSurfaceStatus($mode, $bindsHiddenRecord), "{$mode}: {$url}");
        expectNoLeak($response, $url);
    }
})->with(['closed', 'pilot', 'legacy']);

it('refuses a customer holding only a stray crm.manage the Organization access list and every workspace page with the exact status of the mode: 403 for its own Organization, 404 for a hidden or unknown one, and no membership, invitation or relationship data', function (string $mode) {
    config(['portal.customer_surface_mode' => $mode]);

    // ORG-3a: the access pages ask `manageDirectory` (`portal.operator` AND `crm.manage`), so `crm.manage` alone no longer opens the member list. The refusal must be the
    // exact status, and the body must hold nothing of the Organization's members, invitations or relationships (the content the page would show an operator).
    $stray = User::factory()->create(['name' => 'Stray Admin Person'])->assignRole('user')->givePermissionTo('crm.manage');
    $this->organization->members()->attach($stray, ['role' => 'admin']);
    $hidden = Organization::factory()->create(['name' => 'Hidden Leak Co', 'portal_enabled_by' => $this->operator->id]);
    $partner = Organization::factory()->create(['name' => 'Partner Leak Co', 'portal_enabled_by' => $this->operator->id]);
    app(OrganizationRelationships::class)->designatePartner($partner, $this->operator);
    app(OrganizationRelationships::class)->assignCustomer($this->organization, $partner->fresh(), $this->operator);
    app(OrganizationInvitations::class)->issue($this->organization, 'pending.invitee@leak.test', $this->operator);

    $private = ['Mary Member', $this->member->email, 'pending.invitee@leak.test', 'Partner Leak Co', 'Approve administrator', 'Send invitation', 'Remove', 'Disable portal access'];

    // [url, binds a record this customer cannot see (a hidden or unknown Organization is not in the customer's scope: 404 before any authorization)]
    $pages = [
        [route('organizations.access'), false],
        [route('organizations.show', $this->organization), false],
        [route('organizations.people', $this->organization), false],
        [route('organizations.portal', $this->organization), false],
        [route('organizations.show', $hidden), true],
        [route('organizations.people', $hidden), true],
        [route('organizations.portal', $hidden), true],
        [route('organizations.show', 999999), true],
        [route('organizations.people', 999999), true],
        [route('organizations.portal', 999999), true],
    ];

    foreach ($pages as [$url, $bindsHiddenRecord]) {
        // The control: the SAME page and mode answer an operator, so the refusal below is the customer's and not a broken route (the unknown id is the exception).
        if (! str_contains($url, '/999999')) {
            $this->actingAs($this->operator)->get($url)->assertOk();
        }

        $response = $this->actingAs($stray)->get($url);
        $expected = expectedSurfaceStatus($mode, $bindsHiddenRecord);
        expect($response->getStatusCode())->toBe($expected, "{$mode}: {$url}");
        expectNoLeak($response, $url);

        $body = html_entity_decode((string) $response->getContent(), ENT_QUOTES | ENT_HTML5);
        foreach ($private as $needle) {
            // (`Remove` and the other control labels are anything a rendered administration page carries; a refusal page must hold none of them.)
            expect(str_contains($body, $needle))->toBeFalse("{$mode}: {$url} contains `{$needle}`");
        }
    }

    // The exact statuses the mode implies, spelled out so the table above cannot drift into "either": legacy distinguishes forbidden from hidden, the others hide the route.
    expect(expectedSurfaceStatus($mode, false))->toBe($mode === 'legacy' ? 403 : 404)->and(expectedSurfaceStatus($mode, true))->toBe(404);
})->with(['closed', 'pilot', 'legacy']);

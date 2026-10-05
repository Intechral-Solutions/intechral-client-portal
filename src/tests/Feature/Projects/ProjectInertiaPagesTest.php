<?php

use App\Models\CrmCompany;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E WP3: the project index, create and edit pages are Inertia pages with explicit DTOs.
 * These tests pin the page contracts (component, props, abilities, data minimization) and the
 * mixed Blade/Inertia redirect contract. Authorization itself is pinned by the WP1 matrix and
 * member-management suites; the assertions here are about what the pages are given.
 */

// FLIPPED IN EPIC-015 WP4: the index card DTO (id, name, description, status, targetDate, completion,
// overdueCount, memberCount) became the §11.1 row, with health, task progress and the next milestone.
const ROW_KEYS = ['id', 'name', 'status', 'health', 'tasks', 'targetDate', 'nextMilestone', 'memberCount'];
const PROJECT_DETAIL_KEYS = ['id', 'name', 'description', 'startDate', 'targetDate', 'status', 'budget'];

function pageProps($response): array
{
    return $response->viewData('page')['props'];
}

/**
 * The errors a form sees after a failed submission. Laravel stores validation errors in the
 * default session bag; Inertia re-keys them under the form's error bag when it shares props on
 * the follow-up request, which carries the same X-Inertia-Error-Bag header (a browser follows
 * the redirect with the original headers).
 */
function errorsSeenBy(string $bag, string $url): array
{
    // withHeaders() persists for the rest of the test: drop X-Inertia so this is a full page.
    $errors = pageProps(test()->withoutHeader('X-Inertia')->withHeaders(['X-Inertia-Error-Bag' => $bag])->get($url))['errors'];

    // The shared prop is an object (so an empty one serializes as {}); compare it as an array.
    return json_decode(json_encode($errors), true);
}

/** A company the given non-operator can see: CrmCompany is scoped to the viewer's organizations. */
function companyVisibleTo(User $viewer, string $name, array $attributes = []): CrmCompany
{
    $org = Organization::factory()->create(['owner_id' => makeUser('operator')->id]);
    $org->members()->attach($viewer->id, ['role' => 'member']);

    return CrmCompany::factory()->create([...$attributes, 'name' => $name, 'organization_id' => $org->id, 'created_by' => $org->owner_id]);
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->admin = makeUser('operator', ['name' => 'Ada Admin']);
    $this->project = makeProject($this->admin, 'Alpha');
    $this->manager = projectActor('project_manager', $this->project);
    $this->candidate = makeUser('user', ['name' => 'Candy Date', 'email' => 'candy.date@example.test']);
    $this->bystander = makeUser('user', ['name' => 'Bystander Bee', 'email' => 'bystander.bee@example.test']);
});

// ── Index ────────────────────────────────────────────────────────────────────

it('renders the index as the projects/index component with a minimal row DTO', function () {
    $this->project->update([
        'description' => 'Short description', 'target_date' => '2026-12-31', 'budget' => '9999.50',
    ]);
    $this->project->members()->attach($this->candidate->id, ['role' => 'member']);
    makeTask($this->project->columns[1], ['due_date' => today()->subDay(), 'position' => 0]);
    makeTask($this->project->columns[4], ['due_date' => today()->subDay(), 'position' => 0]);
    $this->project->milestones()->create(['name' => 'Late', 'due_date' => today()->subDays(2)]);
    $next = $this->project->milestones()->create(['name' => 'Go-live', 'due_date' => today()->addWeek()]);
    $this->project->milestones()->create(['name' => 'Later', 'due_date' => today()->addWeeks(2)]);

    $response = $this->actingAs($this->admin)->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/index')
            ->has('projects.data', 1)
            ->where('projects.current_page', 1)
            ->where('filters', ['status' => null])
            ->where('hasProjects', true)
            ->where('abilities.create', true));

    $row = pageProps($response)['projects']['data'][0];

    expect(array_keys($row))->toBe(ROW_KEYS)
        ->and($row)->toMatchArray([
            'id' => $this->project->id,
            'name' => 'Alpha',
            'status' => 'active',
            // One of two tasks sits in the done column; the done task is not overdue.
            'tasks' => ['total' => 2, 'done' => 1, 'completion' => 50],
            'targetDate' => '2026-12-31',
            // The first upcoming milestone: the overdue one is skipped, as on the Overview.
            'nextMilestone' => ['id' => $next->id, 'name' => 'Go-live', 'dueDate' => today()->addWeek()->toDateString()],
            'memberCount' => 3,     // the creator, the project manager and the candidate
        ])
        // The index form of ProjectHealth: count-only reasons, `earliest` null by design (§8.2).
        ->and($row['health'])->toBe([
            'state' => 'off_track',
            'label' => 'Off track',
            'reasons' => [
                ['code' => 'milestones_overdue', 'count' => 1, 'earliest' => null],
                ['code' => 'tasks_overdue', 'count' => 1],
            ],
        ]);
});

it('sends no derived health for an on-hold project, whose lifecycle status carries the meaning', function () {
    $this->project->update(['status' => 'on_hold']);

    $row = pageProps($this->actingAs($this->admin)->get(route('projects.index')))['projects']['data'][0];

    expect($row['status'])->toBe('on_hold')
        ->and($row)->toHaveKey('health')
        ->and($row['health'])->toBeNull()
        ->and($row['nextMilestone'])->toBeNull();
});

it('never puts raw model attributes, creators, members, companies, budgets or emails on the index', function () {
    $this->project->companies()->attach(CrmCompany::factory()->create(['created_by' => $this->admin->id, 'name' => 'Linked Client Co']));
    $this->project->members()->attach($this->candidate->id, ['role' => 'member']);
    $this->project->update(['budget' => '4321.00']);

    $json = json_encode(pageProps($this->actingAs($this->admin)->get(route('projects.index')))['projects']);

    foreach (['created_by', 'creator', 'client_id', 'budget', '4321', 'updated_at', 'created_at', 'members', 'companies', 'Linked Client Co', 'candy.date@example.test', 'Candy Date', 'start_date'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }
});

// FLIPPED IN EPIC-015 WP4: the card sent a description truncated on the server; the §11.1 row has no
// description column, so none is sent at all (it stays on the Overview and the Settings page).
it('sends no description on the index', function () {
    $this->project->update(['description' => 'Private-ish narrative']);

    $json = json_encode(pageProps($this->actingAs($this->admin)->get(route('projects.index')))['projects']);

    expect($json)->not->toContain('Private-ish narrative')
        ->and($json)->not->toContain('"description"');
});

it('gives every viewer the same minimal row, with no names, emails, budget, time or Settings datum (WP4)', function (string $who) {
    $customer = makeUser('user', ['name' => 'Cara Customer', 'email' => 'cara@example.test']);
    $this->project->members()->attach($customer->id, ['role' => 'member']);
    $this->project->members()->attach($this->candidate->id, ['role' => 'member']);
    $this->project->update(['budget' => '4321.00', 'description' => 'Internal narrative']);
    $milestone = $this->project->milestones()->create(['name' => 'Shipped', 'due_date' => today()->subDay()]);
    $milestone->forceFill(['completed_at' => now(), 'completed_by' => $this->candidate->id])->save();
    makeProject($this->admin, 'Invisible to members');
    $viewer = match ($who) {
        'customer member' => $customer,
        'project manager' => $this->manager,
        'operator' => $this->admin,
    };

    $props = pageProps($this->actingAs($viewer)->get(route('projects.index')));
    $rows = collect($props['projects']['data']);
    $json = json_encode($props['projects']);

    expect($rows->every(fn (array $row) => array_keys($row) === ROW_KEYS))->toBeTrue()
        ->and($rows->firstWhere('name', 'Alpha')['memberCount'])->toBe(4);
    foreach (['Candy Date', 'Cara Customer', 'Ada Admin', '@example.test', '4321', 'budget', 'Internal narrative', 'openSettings', 'completedBy', 'minutes'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }
    // Visibility is unchanged: a member sees only their own projects.
    expect($rows->pluck('name')->contains('Invisible to members'))->toBe($who === 'operator');
})->with(['customer member', 'project manager', 'operator']);

it('derives abilities.create from what the create route actually admits (A9)', function (string $actor, bool $expected) {
    $user = projectActor($actor, $this->project);

    $this->actingAs($user)->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page->where('abilities.create', $expected));
})->with([
    'plain member' => ['member', false],
    'outsider' => ['outsider', false],
    'manager pivot role without permission' => ['manager_role', false],
    'project manager' => ['project_manager', true],
    'administrator (all permissions)' => ['admin', true],
    // policy create() allows projects.admin, but the route middleware needs projects.manage
    'projects.admin alone' => ['admin_only', false],
]);

it('lists only policy-visible projects and orders newest first with a stable tiebreak', function () {
    $second = makeProject($this->admin, 'Bravo');
    $third = makeProject($this->admin, 'Charlie');
    foreach ([$this->project, $second, $third] as $project) {
        $project->forceFill(['created_at' => '2026-01-01 00:00:00'])->save();
    }
    $member = makeUser();
    $second->members()->attach($member->id, ['role' => 'member']);
    $third->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($member)->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects.data', 2)
            ->where('projects.data.0.id', $third->id)
            ->where('projects.data.1.id', $second->id));
});

it('does not list a project that is only linked to the viewer\'s company', function () {
    $orgUser = makeUser('user');
    $org = Organization::factory()->create(['owner_id' => $this->admin->id]);
    $org->members()->attach($orgUser, ['role' => 'member']);
    $company = CrmCompany::factory()->create(['created_by' => $this->admin->id, 'organization_id' => $org->id]);
    $this->project->companies()->attach($company);

    $this->actingAs($orgUser)->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page->component('projects/index')->has('projects.data', 0));
});

// FLIPPED IN EPIC-015 WP4: page links carried the raw query string (`withQueryString`); they now carry
// only the normalized status filter, so an unknown parameter never rides along.
it('paginates twenty per page and keeps only the normalized filter on page links', function () {
    for ($i = 0; $i < 24; $i++) {
        makeProject($this->admin, "Bulk {$i}");
    }

    $first = pageProps($this->actingAs($this->admin)->get(route('projects.index', ['q' => 'x', 'status' => 'active'])))['projects'];
    $second = pageProps($this->actingAs($this->admin)->get(route('projects.index', ['page' => 2])))['projects'];

    expect($first['data'])->toHaveCount(20)
        ->and($first['total'])->toBe(25)
        ->and($first['last_page'])->toBe(2)
        ->and($first['next_page_url'])->toContain('page=2')->toContain('status=active')->not->toContain('q=x')
        ->and($first['prev_page_url'])->toBeNull()
        ->and($second['data'])->toHaveCount(5)
        ->and($second['prev_page_url'])->not->toBeNull();
});

it('lists every status by default and narrows to one lifecycle status on request (P5)', function () {
    $held = makeProject($this->admin, 'Held');
    $held->update(['status' => 'on_hold']);
    $done = makeProject($this->admin, 'Done');
    $done->update(['status' => 'completed']);

    $names = fn (array $query) => collect(pageProps($this->actingAs($this->admin)->get(route('projects.index', $query)))['projects']['data'])
        ->pluck('name')->sort()->values()->all();

    expect($names([]))->toBe(['Alpha', 'Done', 'Held'])
        ->and($names(['status' => 'on_hold']))->toBe(['Held'])
        ->and($names(['status' => 'completed']))->toBe(['Done'])
        // An unknown or malformed value is dropped (every status), never a redirect.
        ->and($names(['status' => 'deleted']))->toBe(['Alpha', 'Done', 'Held'])
        ->and($names(['status' => ['active']]))->toBe(['Alpha', 'Done', 'Held']);

    $props = pageProps($this->actingAs($this->admin)->get(route('projects.index', ['status' => 'deleted'])));
    expect($props['filters'])->toBe(['status' => null])
        ->and(array_column($props['filterOptions']['statuses'], 'value'))->toBe(['active', 'on_hold', 'completed', 'archived']);
});

it('tells an empty status filter from having no projects at all', function () {
    $outsider = makeUser('user');

    $none = pageProps($this->actingAs($outsider)->get(route('projects.index')));
    $filtered = pageProps($this->actingAs($this->admin)->get(route('projects.index', ['status' => 'archived'])));

    expect($none['hasProjects'])->toBeFalse()
        ->and($none['projects']['data'])->toBe([])
        ->and($filtered['hasProjects'])->toBeTrue()
        ->and($filtered['projects']['data'])->toBe([]);
});

// ── Create ───────────────────────────────────────────────────────────────────

it('gives a non-admin manager the create page without any member directory', function () {
    companyVisibleTo($this->manager, 'Acme Co');
    CrmCompany::factory()->create(['created_by' => $this->admin->id, 'name' => 'Other Tenant Co']); // not theirs

    $response = $this->actingAs($this->manager)->get(route('projects.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/create')
            ->where('abilities.editMembers', false)
            ->missing('memberCandidates')
            ->has('companies', 1));

    $json = json_encode(pageProps($response));
    foreach (['candy.date@example.test', 'bystander.bee@example.test', 'Candy Date', 'Bystander Bee'] as $leak) {
        expect($json)->not->toContain($leak);
    }
});

it('gives projects.admin the create page with a minimal candidate directory', function () {
    $response = $this->actingAs($this->admin)->get(route('projects.create'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/create')
            ->where('abilities.editMembers', true)
            ->has('memberCandidates'));

    $candidates = collect(pageProps($response)['memberCandidates']);
    expect($candidates->pluck('id')->all())->toContain($this->candidate->id, $this->bystander->id, $this->admin->id)
        ->and($candidates->every(fn ($c) => array_keys($c) === ['id', 'name', 'email']))->toBeTrue()
        ->and($candidates->pluck('name')->all())->toBe($candidates->pluck('name')->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all());
});

it('serializes companies as id and name only', function () {
    companyVisibleTo($this->manager, 'Acme Co', ['website' => 'https://secret.acme.test', 'phone' => '555-0100']);

    $response = $this->actingAs($this->manager)->get(route('projects.create'));

    $companies = pageProps($response)['companies'];
    expect($companies)->toHaveCount(1)
        ->and(array_keys($companies[0]))->toBe(['id', 'name'])
        ->and(json_encode($companies))->not->toContain('secret.acme.test')->not->toContain('555-0100');
});

it('sends a successful create to the Overview as an ordinary Inertia redirect', function () {
    // FLIPPED IN EPIC-015 WP2 (Q5, §16.3): creation used to land on the board
    // (route('projects.board', $project)); it now lands on the project's Overview, projects.show.
    // Still an ordinary redirect, so an Inertia request follows it as an Inertia visit.
    $response = $this->actingAs($this->manager)->withHeaders(['X-Inertia' => 'true'])
        ->post(route('projects.store'), ['name' => 'Inertia Created', 'status' => 'active']);

    $project = Project::where('name', 'Inertia Created')->firstOrFail();

    $response->assertRedirect(route('projects.show', $project));
    expect(session('success'))->toBe('Project created successfully.')
        ->and($project->members()->pluck('project_members.role', 'users.id')->all())->toBe([$this->manager->id => 'manager']);
});

it('redirects a plain create to the Overview', function () {
    // FLIPPED IN EPIC-015 WP2 (Q5): previously route('projects.board', ...).
    $this->actingAs($this->manager)->post(route('projects.store'), ['name' => 'Plain Created', 'status' => 'active'])
        ->assertRedirect(route('projects.show', Project::where('name', 'Plain Created')->firstOrFail()));
});

it('refuses a forged members payload from a non-admin over an Inertia request as well', function () {
    $this->actingAs($this->manager)->withHeaders(['X-Inertia' => 'true'])
        ->post(route('projects.store'), [
            'name' => 'Forged', 'status' => 'active',
            'members' => [['user_id' => $this->candidate->id, 'role' => 'manager']],
        ])->assertForbidden();

    expect(Project::where('name', 'Forged')->exists())->toBeFalse();
});

it('returns create validation errors in nested form keys under the create form\'s error bag', function () {
    $this->actingAs($this->admin)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Error-Bag' => 'createProject'])
        ->from(route('projects.create'))
        ->post(route('projects.store'), [
            'name' => '', 'status' => 'active', 'start_date' => '2026-05-02', 'target_date' => '2026-05-01',
            'members' => [['user_id' => 999999, 'role' => 'member']],
        ])->assertRedirect(route('projects.create'));

    // (Read the errors through the follow-up page: assertSessionHasErrors() would restart the
    // session store and consume the flashed errors before that request sees them.)
    $errors = errorsSeenBy('createProject', route('projects.create'));
    expect(array_keys($errors))->toBe(['createProject'])
        ->and(array_keys($errors['createProject']))->toContain('name', 'target_date', 'members.0.user_id');
    expect(Project::where('name', '')->exists())->toBeFalse();
});

// ── Edit ─────────────────────────────────────────────────────────────────────

it('renders the edit page with explicit project, member, company and ability props for a manager', function () {
    $this->project->update([
        'description' => 'Desc', 'start_date' => '2026-01-05', 'target_date' => '2026-06-30', 'budget' => '1200.5', 'status' => 'completed',
    ]);
    $this->project->members()->attach($this->candidate->id, ['role' => 'member']);
    $linked = companyVisibleTo($this->manager, 'Linked Co');
    companyVisibleTo($this->manager, 'Other Co');
    $this->project->companies()->attach($linked);

    $response = $this->actingAs($this->manager)->get(route('projects.edit', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/edit')
            ->where('abilities', ['delete' => true, 'editMembers' => false])
            ->where('linkedCompanyIds', [$linked->id])
            ->has('companies', 2)
            ->missing('memberCandidates'));

    $props = pageProps($response);

    expect(array_keys($props['project']))->toBe(PROJECT_DETAIL_KEYS)
        ->and($props['project'])->toMatchArray([
            'id' => $this->project->id, 'name' => 'Alpha', 'description' => 'Desc',
            'startDate' => '2026-01-05', 'targetDate' => '2026-06-30', 'status' => 'completed',
            'budget' => '1200.50',   // a decimal string, never a JSON number
        ])
        ->and($props['project']['budget'])->toBeString()
        ->and(collect($props['companies'])->every(fn ($c) => array_keys($c) === ['id', 'name']))->toBeTrue()
        ->and(collect($props['members'])->every(fn ($m) => array_keys($m) === ['id', 'name', 'role', 'isOwner']))->toBeTrue()
        ->and(collect($props['members'])->firstWhere('isOwner', true)['id'])->toBe($this->admin->id)
        ->and(collect($props['members'])->pluck('id')->all())->toContain($this->manager->id, $this->candidate->id);
});

it('keeps null optional project fields null and never invents a budget', function () {
    $props = pageProps($this->actingAs($this->manager)->get(route('projects.edit', $this->project)))['project'];

    expect($props['description'])->toBeNull()
        ->and($props['startDate'])->toBeNull()
        ->and($props['targetDate'])->toBeNull()
        ->and($props['budget'])->toBeNull();
});

it('gives only projects.admin the candidate directory on the edit page and no email to anyone else', function () {
    $adminResponse = $this->actingAs($this->admin)->get(route('projects.edit', $this->project))
        ->assertInertia(fn (Assert $page) => $page->where('abilities.editMembers', true)->has('memberCandidates'));
    expect(collect(pageProps($adminResponse)['memberCandidates'])->every(fn ($c) => array_keys($c) === ['id', 'name', 'email']))->toBeTrue();

    // Everything the page contributes, apart from the shared props (which carry the viewer's own
    // email in auth.user, unrelated to this page).
    $pageProps = Arr::only(pageProps($this->actingAs($this->manager)->get(route('projects.edit', $this->project))), [
        'project', 'members', 'companies', 'linkedCompanyIds', 'abilities', 'memberCandidates',
    ]);
    $json = json_encode($pageProps);

    foreach (['candy.date@example.test', 'bystander.bee@example.test', 'Bystander Bee', '@example.test', '"email"'] as $leak) {
        expect($json)->not->toContain($leak);
    }
    expect($pageProps)->not->toHaveKey('memberCandidates');
});

it('passes hostile names through untouched as data so React can render them as text', function () {
    $hostile = '</select><img src=x onerror="window.__xss=1">';
    $member = makeUser('user', ['name' => $hostile, 'email' => 'hostile@example.test']);
    $this->project->members()->attach($member->id, ['role' => 'member']);

    $props = pageProps($this->actingAs($this->admin)->get(route('projects.edit', $this->project)));

    expect(collect($props['members'])->pluck('name')->all())->toContain($hostile)
        ->and(collect($props['memberCandidates'])->pluck('name')->all())->toContain($hostile);
});

it('refuses the edit page to actors the manage boundary excludes', function (string $actor) {
    $this->actingAs(projectActor($actor, $this->project))->get(route('projects.edit', $this->project))->assertForbidden();
})->with(['outsider', 'member', 'manager_role', 'admin_only']);

it('gives every independent edit form its own error bag', function () {
    $from = route('projects.edit', $this->project);
    $submit = fn (string $bag, string $method, string $url, array $data) => $this->actingAs($this->admin)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Error-Bag' => $bag])->from($from)->{$method}($url, $data);

    $submit('updateProject', 'put', route('projects.update', $this->project), ['name' => '', 'status' => 'active'])
        ->assertRedirect($from);
    $seen = errorsSeenBy('updateProject', $from);
    expect(array_keys($seen))->toBe(['updateProject'])
        ->and(array_keys($seen['updateProject']))->toBe(['name']);

    $this->flushSession();
    $submit('syncMembers', 'put', route('projects.members.sync', $this->project), ['members' => [['user_id' => 999999, 'role' => 'member']]])
        ->assertRedirect($from);
    $seen = errorsSeenBy('syncMembers', $from);
    expect(array_keys($seen))->toBe(['syncMembers'])
        ->and(array_keys($seen['syncMembers']))->toBe(['members.0.user_id']);

    $this->flushSession();
    $submit('syncCompanies', 'put', route('projects.companies.sync', $this->project), ['companies' => [999999]])
        ->assertRedirect($from);
    $seen = errorsSeenBy('syncCompanies', $from);
    expect(array_keys($seen))->toBe(['syncCompanies']);
});

it('saves each edit section on its own without touching the others', function () {
    $company = CrmCompany::factory()->create(['created_by' => $this->admin->id]);

    $this->actingAs($this->admin)->put(route('projects.companies.sync', $this->project), ['companies' => [$company->id]])->assertRedirect();
    expect($this->project->fresh()->name)->toBe('Alpha')
        ->and($this->project->members()->count())->toBe(2);

    $this->actingAs($this->admin)->put(route('projects.update', $this->project), ['name' => 'Alpha 2', 'status' => 'active'])->assertRedirect();
    expect($this->project->companies()->pluck('crm_companies.id')->all())->toBe([$company->id])
        ->and($this->project->members()->count())->toBe(2);
});

it('answers an Inertia delete of an unreferenced project with a redirect to the index', function () {
    $this->actingAs($this->manager)->withHeaders(['X-Inertia' => 'true'])
        ->delete(route('projects.destroy', $this->project))
        ->assertRedirect(route('projects.index'));

    expect(Project::find($this->project->id))->toBeNull();
});

it('keeps a project whose time history blocks deletion and reports it in the delete error bag', function (string $reference) {
    $task = makeTask($this->project->columns[1]);
    $entry = TimeEntry::factory()->create(array_merge(
        ['user_id' => $this->manager->id, 'duration_minutes' => 30],
        $reference === 'project' ? ['project_id' => $this->project->id] : ['task_id' => $task->id],
    ));
    $from = route('projects.edit', $this->project);

    $this->actingAs($this->manager)->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Error-Bag' => 'deleteProject'])
        ->from($from)->delete(route('projects.destroy', $this->project))
        ->assertRedirect($from);

    expect(errorsSeenBy('deleteProject', $from)['deleteProject']['delete'])
        ->toBe('This project has recorded time and cannot be deleted.');

    expect(Project::find($this->project->id))->not->toBeNull()
        ->and($entry->fresh()->only(['project_id', 'task_id', 'duration_minutes']))
        ->toBe($entry->only(['project_id', 'task_id', 'duration_minutes']));
})->with(['direct project time' => ['project'], 'time on one of its tasks' => ['task']]);

it('renders projects.show as the Overview instead of redirecting to the board', function () {
    // FLIPPED IN EPIC-015 WP2 (Q5, §16.3): this used to assert a redirect to projects.board.
    // The full Overview page contract is pinned in ProjectOverviewPageTest.
    $this->actingAs($this->admin)->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('projects/show'));
});

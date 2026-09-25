<?php

use App\Models\CrmCompany;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E D2 (§16): a company link is metadata and grants no project access. The projects
 * index, the /tasks org tab and every rendered link must agree with ProjectPolicy::view.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->owner = makeUser('operator');
    $this->orgUser = makeUser('user', ['name' => 'Org User']); // role user: projects.view_org + tasks.view_org
    $this->org = Organization::factory()->create(['owner_id' => $this->owner->id]);
    $this->org->members()->attach($this->orgUser, ['role' => 'member']);
    $this->company = CrmCompany::factory()->create(['created_by' => $this->owner->id, 'organization_id' => $this->org->id]);

    $this->linkedNotMember = Project::factory()->create(['created_by' => $this->owner->id, 'name' => 'Linked Not Member']);
    $this->linkedMember = Project::factory()->create(['created_by' => $this->owner->id, 'name' => 'Linked And Member']);
    $this->memberOnly = Project::factory()->create(['created_by' => $this->owner->id, 'name' => 'Member Only']);
    $this->unrelated = Project::factory()->create(['created_by' => $this->owner->id, 'name' => 'Unrelated']);
    foreach ([$this->linkedNotMember, $this->linkedMember] as $project) {
        $project->companies()->attach($this->company);
    }
    foreach ([$this->linkedMember, $this->memberOnly] as $project) {
        $project->members()->attach($this->orgUser, ['role' => 'member']);
    }
});

function indexedProjectIds($response): array
{
    return collect($response->viewData('page')['props']['projects']['data'])->pluck('id')->sort()->values()->all();
}

it('lists on the index exactly the projects ProjectPolicy::view allows, for every kind of user', function () {
    $noOrgPermission = makeUser('user'); // a plain member; projects.view_org no longer matters
    $noOrgPermission->projects()->attach($this->memberOnly, ['role' => 'member']);
    $adminUser = makeUser('operator');

    foreach ([$this->orgUser, $noOrgPermission, $adminUser, makeUser()] as $user) {
        $expected = Project::all()
            ->filter(fn (Project $project) => Gate::forUser($user)->allows('view', $project))
            ->pluck('id')->sort()->values()->all();

        $response = $this->actingAs($user)->get(route('projects.index'))->assertOk();

        expect(indexedProjectIds($response))->toBe($expected, "index for {$user->name}");
    }
});

it('never lists a company-linked non-member project and never grants access through it', function () {
    $response = $this->actingAs($this->orgUser)->get(route('projects.index'))->assertOk();

    expect(indexedProjectIds($response))->toBe(collect([$this->linkedMember->id, $this->memberOnly->id])->sort()->values()->all());
    expect(json_encode($response->viewData('page')['props']['projects']))
        ->not->toContain('Linked Not Member')->not->toContain('Unrelated');

    $this->actingAs($this->orgUser)->get(route('projects.board', $this->linkedNotMember))->assertForbidden();
    $this->actingAs($this->orgUser)->get(route('projects.milestones.index', $this->linkedNotMember))->assertForbidden();
});

it('exposes a shared visibleTo scope equal to the policy', function () {
    foreach ([$this->orgUser, $this->owner, makeUser()] as $user) {
        $expected = Project::all()
            ->filter(fn (Project $project) => Gate::forUser($user)->allows('view', $project))
            ->pluck('id')->sort()->values()->all();

        expect(Project::visibleTo($user)->pluck('id')->sort()->values()->all())->toBe($expected);
    }
});

it('adds only manageMembers to ProjectPolicy and leaves view, manage and create as they were', function () {
    $methods = collect((new ReflectionClass(ProjectPolicy::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn (ReflectionMethod $m) => $m->class === ProjectPolicy::class)
        ->map->getName()->sort()->values()->all();

    expect($methods)->toBe(['create', 'manage', 'manageMembers', 'view']);

    $outsider = makeUser();
    expect((new ProjectPolicy)->view($this->orgUser, $this->linkedNotMember))->toBeFalse()
        ->and((new ProjectPolicy)->view($this->orgUser, $this->linkedMember))->toBeTrue()
        ->and((new ProjectPolicy)->view($this->owner, $this->unrelated))->toBeTrue()
        ->and((new ProjectPolicy)->manage($outsider, $this->unrelated))->toBeFalse();
});

// ── /tasks (org tab and links, EPIC-011E WP8: now an Inertia page) ───────────

function boardTask(Project $project, array $attributes = []): Task
{
    $column = $project->columns()->first() ?? $project->columns()->create(['name' => 'To Do', 'position' => 0, 'is_done_column' => false]);

    return makeTask($column, ['title' => 'Task in '.$project->name, ...$attributes]);
}

/** @return array<int, array<string, mixed>> the `tasks.data` rows, as the page receives them */
function taskIndexRows($response): array
{
    return $response->viewData('page')['props']['tasks']['data'];
}

function taskIndexRowByTitle($response, string $title): ?array
{
    return collect(taskIndexRows($response))->firstWhere('title', $title);
}

it('limits the org tab to company-linked projects the viewer can actually open', function () {
    boardTask($this->linkedNotMember);
    $visible = boardTask($this->linkedMember);
    boardTask($this->memberOnly, ['title' => 'Member only, not linked']);
    boardTask($this->unrelated);

    $response = $this->actingAs($this->orgUser)->get(route('tasks.index', ['view' => 'org']))->assertOk();
    $titles = collect(taskIndexRows($response))->pluck('title')->all();

    expect($titles)->toBe([$visible->title]);
});

it('gives an admin the org tab of every company-linked project', function () {
    $admin = makeUser('operator');
    $admin->organizations()->attach($this->org, ['role' => 'member']);
    boardTask($this->linkedNotMember);
    boardTask($this->linkedMember);
    boardTask($this->unrelated);

    $titles = collect(taskIndexRows(
        $this->actingAs($admin)->get(route('tasks.index', ['view' => 'org']))
    ))->pluck('title')->sort()->values()->all();

    expect($titles)->toBe(['Task in Linked And Member', 'Task in Linked Not Member']);
});

it('renders no link to a project or task page the viewer cannot open', function () {
    boardTask($this->unrelated, ['title' => 'Assigned before leaving', 'assignee_id' => $this->orgUser->id]);
    boardTask($this->memberOnly, ['title' => 'Assigned and member', 'assignee_id' => $this->orgUser->id]);

    $response = $this->actingAs($this->orgUser)->get(route('tasks.index'))->assertOk();

    $legacyRow = taskIndexRowByTitle($response, 'Assigned before leaving');
    expect($legacyRow)->not->toBeNull();
    expect($legacyRow['url'])->toBeNull();
    expect($legacyRow['context']['url'])->toBeNull();

    $okRow = taskIndexRowByTitle($response, 'Assigned and member');
    expect($okRow['url'])->toBe(route('projects.tasks.show', [$this->memberOnly, $okRow['id']]));
    expect($okRow['context']['url'])->toBe(route('projects.board', $this->memberOnly));
});

it('renders no ticket link that TicketPolicy would deny', function () {
    $foreignTicket = Ticket::factory()->create(['user_id' => $this->owner->id, 'company_id' => $this->company->id, 'ticket_number' => 'TKT-7001']);
    $ownTicket = Ticket::factory()->create(['user_id' => $this->orgUser->id, 'company_id' => $this->company->id, 'ticket_number' => 'TKT-7002']);
    Task::factory()->standalone()->create(['ticket_id' => $foreignTicket->id, 'title' => 'On foreign ticket']);
    Task::factory()->standalone()->create(['ticket_id' => $ownTicket->id, 'title' => 'On own ticket']);

    $response = $this->actingAs($this->orgUser)->get(route('tasks.index', ['view' => 'org']))->assertOk();

    // Both rows stay listed (current list behavior; flagged for EPIC-011F, C8) ...
    $foreignRow = taskIndexRowByTitle($response, 'On foreign ticket');
    $ownRow = taskIndexRowByTitle($response, 'On own ticket');
    expect($foreignRow['context'])->toBe(['kind' => 'ticket', 'label' => 'TKT-7001', 'url' => null]);
    // ... but only the ticket the viewer may open is a link.
    expect($ownRow['context'])->toBe(['kind' => 'ticket', 'label' => 'TKT-7002', 'url' => route('tickets.show', $ownTicket)]);
    // A ticket-derived row never gets the project-task detail URL (kind-aware, not a fake one).
    expect($foreignRow['url'])->toBeNull();
    expect($ownRow['url'])->toBeNull();
});

it('keeps standalone tasks unlinked and the mine tab scoped to the assignee', function () {
    Task::factory()->standalone()->create(['title' => 'Standalone mine', 'assignee_id' => $this->orgUser->id]);
    Task::factory()->standalone()->create(['title' => 'Standalone theirs', 'assignee_id' => $this->owner->id]);

    $response = $this->actingAs($this->orgUser)->get(route('tasks.index'))->assertOk();
    $rows = taskIndexRows($response);

    expect(collect($rows)->pluck('title')->all())->toBe(['Standalone mine']);
    expect($rows[0]['context'])->toBe(['kind' => 'standalone', 'label' => 'Standalone', 'url' => null]);
    expect($rows[0]['url'])->toBeNull();
});

it('shows no org rows to a user without tasks.view_org, and hides the org tab', function () {
    $user = User::factory()->create(); // no role: no tasks.view_org
    $this->org->members()->attach($user, ['role' => 'member']);
    $this->linkedMember->members()->attach($user, ['role' => 'member']);
    boardTask($this->linkedMember);

    $response = $this->actingAs($user)->get(route('tasks.index', ['view' => 'org']))->assertOk();

    expect(taskIndexRows($response))->toHaveCount(0);
    expect($response->viewData('page')['props']['canViewOrg'])->toBeFalse();
});

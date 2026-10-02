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
 * index, the /tasks list (org tab until EPIC-014 WP3; now the organization filter) and every rendered
 * link must agree with ProjectPolicy::view.
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

// ── /tasks (organization filter and links; EPIC-011E WP8, EPIC-014 WP3) ──────

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

// The org tab is retired (EPIC-014 Q5): organization is a filter that narrows and never grants,
// and `view=org` clamps to My Tasks. The cases below were rewritten in WP3 (§16.3), each keeping
// the D2 guarantee it used to pin.

it('narrows by organization only within projects the viewer can actually open', function () {
    $this->orgUser->givePermissionTo('tasks.view_all');
    boardTask($this->linkedNotMember);
    $visible = boardTask($this->linkedMember);
    boardTask($this->memberOnly, ['title' => 'Member only, not linked']);
    boardTask($this->unrelated);

    $response = $this->actingAs($this->orgUser)->get(route('tasks.index', ['view' => 'all', 'organization' => $this->company->id]))->assertOk();
    $titles = collect(taskIndexRows($response))->pluck('title')->all();

    expect($titles)->toBe([$visible->title]);

    // Without the filter, All Tasks is every visible project's rows: still never the linked
    // non-member project, however the company link reads.
    $all = collect(taskIndexRows($this->actingAs($this->orgUser)->get(route('tasks.index', ['view' => 'all']))))->pluck('title')->sort()->values()->all();
    expect($all)->toBe(['Member only, not linked', 'Task in Linked And Member']);
});

it('gives an admin the organization filter over every company-linked project', function () {
    $admin = makeUser('operator');
    $admin->organizations()->attach($this->org, ['role' => 'member']);
    boardTask($this->linkedNotMember);
    boardTask($this->linkedMember);
    boardTask($this->unrelated);

    $titles = collect(taskIndexRows(
        $this->actingAs($admin)->get(route('tasks.index', ['view' => 'all', 'organization' => $this->company->id]))
    ))->pluck('title')->sort()->values()->all();

    expect($titles)->toBe(['Task in Linked And Member', 'Task in Linked Not Member']);
});

it('lists no row for a project the viewer cannot open, even one still assigned to them', function () {
    // Until WP3 the former member's row was listed unlinked. A stored assignment now grants no
    // visibility at all (§9.1.1, §7.2), in either view.
    $this->orgUser->givePermissionTo('tasks.view_all');
    boardTask($this->unrelated, ['title' => 'Assigned before leaving', 'assignee_id' => $this->orgUser->id]);
    boardTask($this->memberOnly, ['title' => 'Assigned and member', 'assignee_id' => $this->orgUser->id]);

    foreach (['mine', 'all'] as $view) {
        $response = $this->actingAs($this->orgUser)->get(route('tasks.index', ['view' => $view]))->assertOk();

        expect(taskIndexRowByTitle($response, 'Assigned before leaving'))->toBeNull();

        $okRow = taskIndexRowByTitle($response, 'Assigned and member');
        expect($okRow['url'])->toBe(route('projects.tasks.show', [$this->memberOnly, $okRow['id']]));
        expect($okRow['context']['url'])->toBe(route('projects.board', $this->memberOnly));
    }
});

it('lists no ticket-kind task, whether or not TicketPolicy would open the ticket (Q6)', function () {
    // Until WP3 both rows were listed through the org tab, only the openable ticket linked.
    $this->orgUser->givePermissionTo('tasks.view_all');
    $foreignTicket = Ticket::factory()->create(['user_id' => $this->owner->id, 'company_id' => $this->company->id, 'ticket_number' => 'TKT-7001']);
    $ownTicket = Ticket::factory()->create(['user_id' => $this->orgUser->id, 'company_id' => $this->company->id, 'ticket_number' => 'TKT-7002']);
    Task::factory()->standalone()->create(['ticket_id' => $foreignTicket->id, 'title' => 'On foreign ticket', 'assignee_id' => $this->orgUser->id]);
    Task::factory()->standalone()->create(['ticket_id' => $ownTicket->id, 'title' => 'On own ticket', 'assignee_id' => $this->orgUser->id, 'created_by' => $this->orgUser->id]);

    foreach (['mine', 'org', 'all'] as $view) {
        $response = $this->actingAs($this->orgUser)->get(route('tasks.index', ['view' => $view, 'completion' => 'any']))->assertOk();

        expect(taskIndexRows($response))->toBe([], $view);
        expect(json_encode($response->viewData('page')['props']['tasks']))->not->toContain('TKT-700');
    }
});

it('links standalone tasks to their own page and keeps My Tasks to what is mine under Q4', function () {
    Task::factory()->standalone()->create(['title' => 'Standalone mine', 'assignee_id' => $this->orgUser->id]);
    Task::factory()->standalone()->create(['title' => 'Standalone created, unassigned', 'created_by' => $this->orgUser->id, 'assignee_id' => null]);
    Task::factory()->standalone()->create(['title' => 'Standalone created, handed on', 'created_by' => $this->orgUser->id, 'assignee_id' => $this->owner->id]);
    Task::factory()->standalone()->create(['title' => 'Standalone theirs', 'assignee_id' => $this->owner->id]);

    $response = $this->actingAs($this->orgUser)->get(route('tasks.index', ['completion' => 'any']))->assertOk();
    $rows = collect(taskIndexRows($response))->keyBy('title');

    expect($rows->keys()->sort()->values()->all())->toBe(['Standalone created, unassigned', 'Standalone mine']);
    // WP5: a standalone row opens tasks.show (its creator/assignee may view it); its context stays unlinked.
    expect($rows['Standalone mine']['context'])->toBe(['kind' => 'standalone', 'label' => 'Standalone', 'url' => null]);
    expect($rows['Standalone mine']['url'])->toBe(route('tasks.show', $rows['Standalone mine']['id']));
});

it('clamps the retired org view to My Tasks for everyone, and a company link grants no row', function () {
    $user = User::factory()->create(); // no role at all
    $this->org->members()->attach($user, ['role' => 'member']);
    $this->linkedMember->members()->attach($user, ['role' => 'member']);
    boardTask($this->linkedMember);

    foreach ([$user, $this->orgUser] as $viewer) {
        $response = $this->actingAs($viewer)->get(route('tasks.index', ['view' => 'org']))->assertOk();
        $props = $response->viewData('page')['props'];

        expect(taskIndexRows($response))->toHaveCount(0)
            ->and($props['view'])->toBe('mine')
            ->and($props['canViewAll'])->toBeFalse()
            ->and($props)->not->toHaveKey('canViewOrg');
    }
});

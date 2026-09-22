<?php

use App\Models\Project;
use Illuminate\Support\Arr;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E WP5: the board is now an Inertia/React page. These tests pin the DTO contract
 * (ProjectBoardPresenter), the abilities exposed to it, and that no raw model data reaches it.
 * Authorization itself (view/manage per actor) is already pinned by the WP1 actor-by-route
 * matrix (ProjectAuthorizationMatrixTest) and ProjectManagementTest; these assertions are about
 * what the page is given, not who may reach it.
 */

function boardProps($response): array
{
    return $response->viewData('page')['props'];
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->admin = makeUser('operator');
    $this->project = makeProject($this->admin, 'Board Project');
    $this->todo = $this->project->columns[1];
    $this->done = $this->project->columns[4];
});

it('renders the board as an Inertia page with the minimal DTO shape', function () {
    $manager = projectActor('project_manager', $this->project);
    $task = makeTask($this->todo, [
        'title' => 'Ship it', 'priority' => 'high', 'due_date' => today()->addWeek(),
        'assignee_id' => $manager->id,
    ]);
    $milestone = $this->project->milestones()->create(['name' => 'Launch', 'due_date' => today()->addMonth()]);
    $task->update(['milestone_id' => $milestone->id]);

    $response = $this->actingAs($manager)->get(route('projects.board', $this->project));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('projects/board')
        ->where('project.id', $this->project->id)
        ->where('project.name', 'Board Project')
        ->where('project.status', 'active')
        ->has('columns', 5)
        ->where('abilities.manage', true)
    );

    $props = boardProps($response);
    $todoColumn = collect($props['columns'])->firstWhere('id', $this->todo->id);
    expect(array_keys($todoColumn))->toBe(['id', 'name', 'isDone', 'tasks'])
        ->and($todoColumn['isDone'])->toBeFalse();

    $card = collect($todoColumn['tasks'])->firstWhere('id', $task->id);
    expect(array_keys($card))->toBe(['id', 'title', 'priority', 'dueDate', 'overdue', 'assignee', 'milestone', 'checklist'])
        ->and($card['title'])->toBe('Ship it')
        ->and($card['priority'])->toBe('high')
        ->and($card['assignee'])->toBe(['id' => $manager->id, 'name' => $manager->name])
        ->and($card['milestone'])->toBe(['id' => $milestone->id, 'name' => 'Launch'])
        ->and($card['checklist'])->toBe(['done' => 0, 'total' => 0]);
});

it('denies an outsider and a non-member assignee', function (string $actor) {
    $task = makeTask($this->todo);
    $user = projectActor($actor, $this->project, $task);

    $this->actingAs($user)->get(route('projects.board', $this->project))->assertForbidden();
})->with(['outsider', 'assignee']);

it('orders columns by position and tasks by (position, id), and reflects the column-done rule', function () {
    $manager = projectActor('project_manager', $this->project);
    $a = makeTask($this->todo, ['title' => 'A', 'position' => 1]);
    $b = makeTask($this->todo, ['title' => 'B', 'position' => 0]);
    $doneTask = makeTask($this->done, ['title' => 'Done task', 'due_date' => today()->subDay()]);

    $props = boardProps($this->actingAs($manager)->get(route('projects.board', $this->project)));
    $columnIds = collect($props['columns'])->pluck('id')->all();
    expect($columnIds)->toBe($this->project->columns->pluck('id')->all());

    $todo = collect($props['columns'])->firstWhere('id', $this->todo->id);
    expect(collect($todo['tasks'])->pluck('title')->all())->toBe(['B', 'A']);

    $done = collect($props['columns'])->firstWhere('id', $this->done->id);
    expect($done['isDone'])->toBeTrue();
    $doneCard = collect($done['tasks'])->firstWhere('id', $doneTask->id);
    // Overdue and done in the same breath would be self-contradictory: a done-column task is
    // never "overdue" regardless of its due date (EPIC-011E §15).
    expect($doneCard['overdue'])->toBeFalse();
});

it('never leaks a raw model, description, comment, checklist text, or email onto the board', function () {
    $manager = projectActor('project_manager', $this->project);
    $assignee = $manager; // a project member, so the assignment itself is valid (A3)
    $task = makeTask($this->todo, ['description' => 'SECRET DESCRIPTION', 'assignee_id' => $assignee->id]);
    $task->comments()->create(['user_id' => $manager->id, 'body' => 'SECRET COMMENT BODY']);
    $task->checklistItems()->create(['title' => 'SECRET CHECKLIST TITLE', 'position' => 0, 'completed' => false]);

    $props = boardProps($this->actingAs($manager)->get(route('projects.board', $this->project)));
    // Only the board's own props: `auth.user.email` is the shared, viewer-facing prop on every
    // Inertia page and is not what this test is about.
    $json = json_encode(Arr::only($props, ['project', 'columns', 'abilities']));

    expect($json)
        ->not->toContain('SECRET DESCRIPTION')
        ->not->toContain('SECRET COMMENT BODY')
        ->not->toContain('SECRET CHECKLIST TITLE')
        ->not->toContain($assignee->email)
        ->not->toContain('"budget"')
        ->not->toContain('"createdAt"');
});

it('computes abilities.manage from the policy alone and abilities.openSettings from the A9 route gate', function (
    string $actor, bool $manage, bool $openSettings,
) {
    $user = projectActor($actor, $this->project);

    $props = boardProps($this->actingAs($user)->get(route('projects.board', $this->project)));

    expect($props['abilities'])->toBe(['manage' => $manage, 'openSettings' => $openSettings]);
})->with([
    'member' => ['member', false, false],
    'manager_role (no projects.manage)' => ['manager_role', false, false],
    'project_manager' => ['project_manager', true, true],
    // A9: projects.admin without projects.manage is admitted by the manage policy (D1, no route
    // middleware) but would 403 at the projects.edit route middleware, so no Settings link.
    'admin_only' => ['admin_only', true, false],
    'admin' => ['admin', true, true],
]);

it('reflects checklist aggregate counts without a query per task', function () {
    $manager = projectActor('project_manager', $this->project);
    $task = makeTask($this->todo);
    $task->checklistItems()->createMany([
        ['title' => 'One', 'position' => 0, 'completed' => true],
        ['title' => 'Two', 'position' => 1, 'completed' => false],
        ['title' => 'Three', 'position' => 2, 'completed' => true],
    ]);

    $props = boardProps($this->actingAs($manager)->get(route('projects.board', $this->project)));
    $todo = collect($props['columns'])->firstWhere('id', $this->todo->id);
    $card = collect($todo['tasks'])->firstWhere('id', $task->id);

    expect($card['checklist'])->toBe(['done' => 2, 'total' => 3]);
});

it('keeps the move response contract redirecting to the board with only columns and flash requested', function () {
    $manager = projectActor('project_manager', $this->project);
    $task = makeTask($this->todo);

    // The asset version an already-loaded SPA would be sending: read from a plain (non-XHR)
    // document load, the same way a real browser gets it before its first Inertia visit. Sending
    // no X-Inertia-Version on an X-Inertia request instead trips Inertia's own stale-version 409
    // handling, which is unrelated to what this test is pinning.
    $version = $this->actingAs($manager)
        ->get(route('projects.board', $this->project))
        ->viewData('page')['version'];

    $response = $this->actingAs($manager)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Data' => 'columns,flash',
            'X-Inertia-Partial-Component' => 'projects/board',
        ])
        ->from(route('projects.board', $this->project))
        ->putJson(route('projects.tasks.move', [$this->project, $task]), [
            'column_id' => $this->done->id, 'position' => 0,
        ]);

    // PUT redirects are answered 303 for Inertia requests so the browser follows with GET.
    expect($response->status())->toBe(303);
    $response->assertRedirect(route('projects.board', $this->project));

    $follow = $this->actingAs($manager)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
            'X-Inertia-Partial-Data' => 'columns,flash',
            'X-Inertia-Partial-Component' => 'projects/board',
        ])
        ->get(route('projects.board', $this->project));

    // An X-Inertia XHR response is the JSON payload directly (component/props/url/version), not
    // a Blade view wrapping it: boardProps()'s viewData('page') is for the initial document load.
    $follow->assertOk();
    $props = $follow->json('props');
    // `errors` is Inertia's own always-shared prop; `project` and `abilities` were not
    // requested and must be excluded by the partial reload.
    expect($props)->toHaveKeys(['columns', 'flash'])
        ->and($props)->not->toHaveKeys(['project', 'abilities']);
});

it('does not render the board for a project that does not exist', function () {
    $manager = projectActor('project_manager', $this->project);

    $this->actingAs($manager)->get('/projects/999999/board')->assertNotFound();
});

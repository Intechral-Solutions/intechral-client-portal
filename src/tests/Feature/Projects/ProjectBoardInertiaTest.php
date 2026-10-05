<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
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
    // FLIPPED IN EPIC-015 WP5 (S1): a card gains `done` (column-authoritative) and its TaskPolicy
    // complete/reopen `abilities`, for the card's Complete/Reopen control. Was: the first eight keys.
    expect(array_keys($card))->toBe(['id', 'title', 'priority', 'dueDate', 'overdue', 'assignee', 'milestone', 'checklist', 'done', 'abilities'])
        ->and($card['done'])->toBeFalse()
        ->and($card['abilities'])->toBe(['complete' => true, 'reopen' => true])
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

// ── EPIC-015 WP5 (S1): card Complete/Reopen abilities ────────────────────────

/** Every card on the board, flattened, keyed by task id. */
function boardCards(array $props): array
{
    return collect($props['columns'])->flatMap(fn (array $column) => $column['tasks'])->keyBy('id')->all();
}

it('gives every card exactly TaskPolicy\'s complete/reopen answers, for every actor shape', function (string $kind) {
    // `member` is the customer shape (the `user` role); `role_less_member` holds no role or
    // permission at all, so membership is its only standing.
    $actor = $kind === 'role_less_member'
        ? tap(User::factory()->create(), fn (User $user) => $this->project->members()->attach($user->id, ['role' => 'member']))
        : projectActor($kind, $this->project);
    $other = projectActor('member', $this->project);

    $tasks = [
        'mine open' => makeTask($this->todo, ['title' => 'Mine open', 'assignee_id' => $actor->id, 'position' => 0]),
        'mine done' => makeTask($this->done, ['title' => 'Mine done', 'assignee_id' => $actor->id, 'position' => 0]),
        'theirs open' => makeTask($this->todo, ['title' => 'Theirs open', 'assignee_id' => $other->id, 'position' => 1]),
        'unassigned done' => makeTask($this->done, ['title' => 'Unassigned done', 'position' => 1]),
    ];

    $cards = boardCards(boardProps($this->actingAs($actor)->get(route('projects.board', $this->project))->assertOk()));

    foreach ($tasks as $label => $task) {
        $fresh = $task->fresh();

        expect($cards[$task->id]['abilities'])->toBe([
            'complete' => Gate::forUser($actor)->allows('complete', $fresh),
            'reopen' => Gate::forUser($actor)->allows('reopen', $fresh),
        ], "{$kind}: {$label}")
            ->and($cards[$task->id]['done'])->toBe($fresh->isDone(), "{$kind}: {$label} done");
    }

    // The interesting answers, stated, so a parity bug in both places cannot pass silently.
    $manages = in_array($kind, ['project_manager', 'admin', 'admin_only'], true);
    expect($cards[$tasks['theirs open']->id]['abilities']['complete'])->toBe($manages, "{$kind} on another's task")
        ->and($cards[$tasks['mine open']->id]['abilities']['complete'])->toBeTrue("{$kind} on own task")
        ->and($cards[$tasks['unassigned done']->id]['abilities']['reopen'])->toBe($manages, "{$kind} reopen unassigned");
})->with(['member', 'role_less_member', 'manager_role', 'project_manager', 'admin', 'admin_only']);

it('gives a viewer with no completion ability no card control at all', function () {
    $viewer = projectActor('member', $this->project);
    makeTask($this->todo, ['title' => 'Someone else\'s']);
    makeTask($this->done, ['title' => 'Done already']);

    foreach (boardCards(boardProps($this->actingAs($viewer)->get(route('projects.board', $this->project)))) as $card) {
        expect($card['abilities'])->toBe(['complete' => false, 'reopen' => false]);
    }
});

it('reads done from the column, never from tasks.status (INV-P1)', function () {
    $inDone = makeTask($this->done, ['title' => 'Column done', 'status' => 'todo']);
    $inTodo = makeTask($this->todo, ['title' => 'Status done', 'status' => 'done']);

    $cards = boardCards(boardProps($this->actingAs($this->admin)->get(route('projects.board', $this->project))));

    expect($cards[$inDone->id]['done'])->toBeTrue()
        ->and($cards[$inTodo->id]['done'])->toBeFalse();
});

it('completes and reopens through the shared tasks.* endpoints, landing back on the board', function () {
    $assignee = projectActor('member', $this->project);
    $task = makeTask($this->todo, ['title' => 'From the card', 'assignee_id' => $assignee->id]);
    $board = route('projects.board', $this->project);

    $this->actingAs($assignee)->from($board)->put(route('tasks.complete', $task))->assertRedirect($board);
    expect($task->fresh()->column_id)->toBe($this->done->id);
    $card = boardCards(boardProps($this->actingAs($assignee)->get($board)))[$task->id];
    expect($card['done'])->toBeTrue()->and($card['abilities']['reopen'])->toBeTrue();

    $this->actingAs($assignee)->from($board)->put(route('tasks.reopen', $task))->assertRedirect($board);
    expect($task->fresh()->column->is_done_column)->toBeFalse();

    // A viewer without the ability is refused by the route itself, whatever a card shows.
    $this->actingAs(projectActor('member', $this->project))->from($board)->put(route('tasks.complete', $task))->assertForbidden();
});

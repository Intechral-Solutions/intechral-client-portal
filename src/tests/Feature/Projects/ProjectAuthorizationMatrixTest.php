<?php

use App\Models\TaskChecklistItem;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E §16/§23: the actor-by-route authorization matrix. Every route x every actor.
 * Expected outcomes are the TARGET contract after WP1:
 *   - D1: structural task routes need ProjectPolicy::manage (policy only, no route middleware)
 *   - D7-B: member sync is projects.admin only, and is not coupled to the projects.manage gate
 *   - A9: project management routes keep their projects.manage route middleware (pinned)
 */

const ALLOW = 'allow';
const DENY = 403;
const LOGIN = 'login';
const MISSING = 404;

/** ProjectPolicy::view routes: member, either manager kind, admin; not outsiders or assignees. */
const MATRIX_VIEW = [
    'guest' => LOGIN, 'outsider' => DENY, 'member' => ALLOW, 'manager_role' => ALLOW,
    'project_manager' => ALLOW, 'admin' => ALLOW, 'admin_only' => ALLOW, 'assignee' => DENY,
];

/** projects.manage middleware AND manage policy (A9: admin-only is blocked at the route). */
const MATRIX_MANAGE_ROUTE = [
    'guest' => LOGIN, 'outsider' => DENY, 'member' => DENY, 'manager_role' => DENY,
    'project_manager' => ALLOW, 'admin' => ALLOW, 'admin_only' => DENY, 'assignee' => DENY,
];

/** Policy-only manage (D1): no route middleware, so projects.admin alone is admitted. */
const MATRIX_MANAGE_POLICY = [
    'guest' => LOGIN, 'outsider' => DENY, 'member' => DENY, 'manager_role' => DENY,
    'project_manager' => ALLOW, 'admin' => ALLOW, 'admin_only' => ALLOW, 'assignee' => DENY,
];

/** D7-B: membership is projects.admin only, whatever else the actor holds. */
const MATRIX_MEMBERS = [
    'guest' => LOGIN, 'outsider' => DENY, 'member' => DENY, 'manager_role' => DENY,
    'project_manager' => DENY, 'admin' => ALLOW, 'admin_only' => ALLOW, 'assignee' => DENY,
];

/** Authenticated-only routes. */
const MATRIX_AUTH = [
    'guest' => LOGIN, 'outsider' => ALLOW, 'member' => ALLOW, 'manager_role' => ALLOW,
    'project_manager' => ALLOW, 'admin' => ALLOW, 'admin_only' => ALLOW, 'assignee' => ALLOW,
];

/**
 * EPIC-014 §13.1: tasks.update / tasks.destroy are standalone-only. On a board task TaskPolicy
 * answers first (403 for anyone without manage), then an authorized actor gets 404: board edits
 * and deletes stay on projects.tasks.*, and nobody unauthorized learns the task's kind.
 */
const MATRIX_BOARD_KIND_MISMATCH = [
    'guest' => LOGIN, 'outsider' => DENY, 'member' => DENY, 'manager_role' => DENY,
    'project_manager' => MISSING, 'admin' => MISSING, 'admin_only' => MISSING, 'assignee' => DENY,
];

/** Create needs projects.manage (route middleware and policy), not a project membership. */
const MATRIX_CREATE = [
    'guest' => LOGIN, 'outsider' => DENY, 'member' => DENY, 'manager_role' => DENY,
    'project_manager' => ALLOW, 'admin' => ALLOW, 'admin_only' => DENY, 'assignee' => DENY,
];

function projectMatrixExpectations(): array
{
    return [
        'projects.index' => MATRIX_AUTH,
        'projects.create' => MATRIX_CREATE,
        'projects.store' => MATRIX_CREATE,
        'projects.show' => MATRIX_VIEW,
        'projects.board' => MATRIX_VIEW,
        'projects.edit' => MATRIX_MANAGE_ROUTE,
        'projects.update' => MATRIX_MANAGE_ROUTE,
        'projects.destroy' => MATRIX_MANAGE_ROUTE,
        'projects.members.sync' => MATRIX_MEMBERS,
        'projects.companies.sync' => MATRIX_MANAGE_ROUTE,
        'projects.milestones.index' => MATRIX_VIEW,
        'projects.milestones.store' => MATRIX_MANAGE_ROUTE,
        'projects.milestones.update' => MATRIX_MANAGE_ROUTE,
        'projects.milestones.destroy' => MATRIX_MANAGE_ROUTE,
        // EPIC-015 WP1 PR B (§9.2): Complete/Reopen share the milestone mutation gate (A9).
        'projects.milestones.complete' => MATRIX_MANAGE_ROUTE,
        'projects.milestones.reopen' => MATRIX_MANAGE_ROUTE,
        'projects.tasks.show' => MATRIX_VIEW,
        // EPIC-015 WP3 (§7, §11.2): the project Tasks tab is ProjectPolicy::view, like the Board.
        'projects.tasks.index' => MATRIX_VIEW,
        'projects.tasks.store' => MATRIX_MANAGE_POLICY,
        'projects.tasks.update' => MATRIX_MANAGE_POLICY,
        'projects.tasks.destroy' => MATRIX_MANAGE_POLICY,
        'projects.tasks.move' => MATRIX_MANAGE_POLICY,
        'projects.tasks.checklist.store' => MATRIX_MANAGE_POLICY,
        'projects.tasks.checklist.destroy' => MATRIX_MANAGE_POLICY,
        'projects.tasks.comments.store' => MATRIX_VIEW,
        'projects.tasks.checklist.toggle' => MATRIX_VIEW,
        'tasks.index' => MATRIX_AUTH,
        'tasks.store' => MATRIX_AUTH,
        // EPIC-014 WP2 (§13.1, Q1): the generic task routes, exercised on the board task. The
        // matrix's `assignee` is a non-member, so Q1's member-assignee arm does not apply to it
        // (TaskCompletionTest covers the member-assignee). Bulk authorizes per row, not per route.
        'tasks.complete' => MATRIX_MANAGE_POLICY,
        'tasks.reopen' => MATRIX_MANAGE_POLICY,
        'tasks.assignee.update' => MATRIX_MANAGE_POLICY,
        'tasks.update' => MATRIX_BOARD_KIND_MISMATCH,
        'tasks.destroy' => MATRIX_BOARD_KIND_MISMATCH,
        'tasks.bulk' => MATRIX_AUTH,
        // EPIC-014 WP5 (§13.1, P6): on a board task tasks.show authorizes `view` (ProjectPolicy) and
        // then redirects to the canonical projects.tasks.show; the standalone page is TaskShowTest.
        'tasks.show' => MATRIX_VIEW,
    ];
}

function projectMatrixCases(): Generator
{
    foreach (projectMatrixExpectations() as $route => $byActor) {
        foreach (PROJECT_ACTORS as $actor) {
            yield "{$route} as {$actor}" => [$route, $actor, $byActor[$actor]];
        }
    }
}

/** Issue the request for one route. Payloads are valid, so an allowed actor really succeeds. */
function matrixRequest($test, string $route, ?User $user, object $ctx): TestResponse
{
    $test = $user ? $test->actingAs($user) : $test;
    $p = $ctx->project;

    return match ($route) {
        'projects.index' => $test->get(route('projects.index')),
        'projects.create' => $test->get(route('projects.create')),
        'projects.store' => $test->post(route('projects.store'), ['name' => 'Matrix New', 'status' => 'active']),
        'projects.show' => $test->get(route('projects.show', $p)),
        'projects.board' => $test->get(route('projects.board', $p)),
        'projects.edit' => $test->get(route('projects.edit', $p)),
        'projects.update' => $test->put(route('projects.update', $p), ['name' => 'Renamed', 'status' => 'active']),
        'projects.destroy' => $test->delete(route('projects.destroy', $p)),
        'projects.members.sync' => $test->put(route('projects.members.sync', $p), [
            'members' => [['user_id' => $p->created_by, 'role' => 'manager']],
        ]),
        'projects.companies.sync' => $test->put(route('projects.companies.sync', $p), ['companies' => []]),
        'projects.milestones.index' => $test->get(route('projects.milestones.index', $p)),
        'projects.milestones.store' => $test->post(route('projects.milestones.store', $p), ['name' => 'MS', 'due_date' => '2030-01-01']),
        'projects.milestones.update' => $test->put(route('projects.milestones.update', [$p, $ctx->milestone]), ['name' => 'MS2', 'due_date' => '2030-02-01']),
        'projects.milestones.destroy' => $test->delete(route('projects.milestones.destroy', [$p, $ctx->milestone])),
        'projects.milestones.complete' => $test->put(route('projects.milestones.complete', [$p, $ctx->milestone])),
        'projects.milestones.reopen' => $test->put(route('projects.milestones.reopen', [$p, $ctx->milestone])),
        'projects.tasks.show' => $test->get(route('projects.tasks.show', [$p, $ctx->task])),
        'projects.tasks.index' => $test->get(route('projects.tasks.index', $p)),
        'projects.tasks.store' => $test->post(route('projects.tasks.store', $p), [
            'column_id' => $ctx->todo->id, 'title' => 'Matrix task', 'priority' => 'low',
        ]),
        'projects.tasks.update' => $test->put(route('projects.tasks.update', [$p, $ctx->task]), ['title' => 'Renamed', 'priority' => 'high']),
        'projects.tasks.destroy' => $test->delete(route('projects.tasks.destroy', [$p, $ctx->task])),
        'projects.tasks.move' => $test->putJson(route('projects.tasks.move', [$p, $ctx->task]), [
            'column_id' => $ctx->done->id, 'position' => 0,
        ]),
        'projects.tasks.checklist.store' => $test->post(route('projects.tasks.checklist.store', [$p, $ctx->task]), ['title' => 'Step']),
        'projects.tasks.checklist.destroy' => $test->delete(route('projects.tasks.checklist.destroy', [$p, $ctx->task, $ctx->item->id])),
        'projects.tasks.comments.store' => $test->post(route('projects.tasks.comments.store', [$p, $ctx->task]), ['body' => 'Hello']),
        'projects.tasks.checklist.toggle' => $test->putJson(route('projects.tasks.checklist.toggle', [$p, $ctx->task, $ctx->item->id])),
        'tasks.index' => $test->get(route('tasks.index')),
        'tasks.store' => $test->post(route('tasks.store'), ['title' => 'Mine', 'priority' => 'low', 'status' => 'todo']),
        'tasks.complete' => $test->put(route('tasks.complete', $ctx->task)),
        'tasks.reopen' => $test->put(route('tasks.reopen', $ctx->task)),
        'tasks.assignee.update' => $test->put(route('tasks.assignee.update', $ctx->task), ['assignee_id' => null]),
        'tasks.update' => $test->put(route('tasks.update', $ctx->task), ['title' => 'Renamed', 'priority' => 'high', 'status' => 'todo']),
        'tasks.destroy' => $test->delete(route('tasks.destroy', $ctx->task)),
        'tasks.bulk' => $test->post(route('tasks.bulk'), ['action' => 'complete', 'ids' => [$ctx->task->id]]),
        'tasks.show' => $test->get(route('tasks.show', $ctx->task)),
    };
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

it('enforces the actor-by-route matrix', function (string $route, string $actor, string|int $expected) {
    $project = makeProject();
    $ctx = (object) [
        'project' => $project,
        'todo' => $project->columns[1],
        'done' => $project->columns[4],
    ];
    $ctx->task = makeTask($ctx->todo);
    $ctx->item = TaskChecklistItem::factory()->create(['task_id' => $ctx->task->id]);
    $ctx->milestone = $project->milestones()->create(['name' => 'Seed', 'due_date' => '2030-01-01']);
    $user = projectActor($actor, $project, $ctx->task);

    $response = matrixRequest($this, $route, $user, $ctx);

    if ($expected === LOGIN) {
        expect(in_array($response->status(), [302, 401], true))->toBeTrue("{$route} as guest gave {$response->status()}");
        if ($response->status() === 302) {
            $response->assertRedirect('/login');
        }

        return;
    }

    if ($expected === DENY) {
        $response->assertForbidden();

        return;
    }

    if ($expected === MISSING) {
        $response->assertNotFound();

        return;
    }

    expect(in_array($response->status(), [200, 302], true))
        ->toBeTrue("{$route} as {$actor} expected success, got {$response->status()}");
    if ($response->status() === 302) {
        expect($response->headers->get('Location'))->not->toEndWith('/login');
    }
})->with(fn () => iterator_to_array(projectMatrixCases()));

it('checks authorization before the child 404 so an outsider learns nothing about ids', function () {
    $project = makeProject();
    $other = makeProject(null, 'Other');
    $foreignTask = makeTask($other->columns[0]);
    $foreignItem = TaskChecklistItem::factory()->create(['task_id' => $foreignTask->id]);
    $outsider = projectActor('outsider', $project);
    $manager = projectActor('project_manager', $project);

    $routes = [
        fn ($t) => $t->put(route('projects.tasks.update', [$project, $foreignTask]), ['title' => 'x', 'priority' => 'low']),
        fn ($t) => $t->delete(route('projects.tasks.destroy', [$project, $foreignTask])),
        fn ($t) => $t->putJson(route('projects.tasks.move', [$project, $foreignTask]), ['column_id' => $project->columns[0]->id, 'position' => 0]),
        fn ($t) => $t->post(route('projects.tasks.checklist.store', [$project, $foreignTask]), ['title' => 'x']),
        fn ($t) => $t->delete(route('projects.tasks.checklist.destroy', [$project, $foreignTask, $foreignItem->id])),
    ];

    foreach ($routes as $call) {
        $call($this->actingAs($outsider))->assertForbidden();
        $call($this->actingAs($manager))->assertNotFound();
    }
});

it('checks authorization before the time-history deletion guard', function () {
    $project = makeProject();
    $task = makeTask($project->columns[0]);
    TimeEntry::factory()->create(['user_id' => makeUser()->id, 'task_id' => $task->id]);
    $outsider = projectActor('outsider', $project);
    $member = projectActor('member', $project);

    foreach ([$outsider, $member] as $actor) {
        $this->actingAs($actor)->delete(route('projects.tasks.destroy', [$project, $task]))->assertForbidden();
        $this->actingAs($actor)->delete(route('projects.destroy', $project))->assertForbidden();
    }
});

it('lets a manager of one project do nothing structural on another', function () {
    $mine = makeProject();
    $theirs = makeProject(null, 'Theirs');
    $manager = projectActor('project_manager', $mine);
    $task = makeTask($theirs->columns[0]);

    $this->actingAs($manager)->post(route('projects.tasks.store', $theirs), [
        'column_id' => $theirs->columns[0]->id, 'title' => 'x', 'priority' => 'low',
    ])->assertForbidden();
    $this->actingAs($manager)->putJson(route('projects.tasks.move', [$theirs, $task]), [
        'column_id' => $theirs->columns[1]->id, 'position' => 0,
    ])->assertForbidden();
    $this->actingAs($manager)->delete(route('projects.destroy', $theirs))->assertForbidden();
});

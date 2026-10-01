<?php

use App\Models\CrmCompany;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-011E P1 to P3 (§25): the query count of the list-shaped pages must not depend on the
 * number of rows. Each test measures a warm request with a few rows, adds many more, and
 * measures again.
 */

const BUDGET_TOLERANCE = 1;

function countQueries(callable $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/** Warm once (permission and route caches), then measure. */
function warmQueries(callable $request): int
{
    $request();

    return countQueries($request);
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

it('does not run per-project queries on the projects index', function () {
    $member = makeUser('user');
    $owner = makeUser('operator');
    $grow = function (int $n) use ($member, $owner) {
        for ($i = 0; $i < $n; $i++) {
            $project = makeProject($owner, "Index {$i}");
            $project->members()->attach($member->id, ['role' => 'member']);
            $project->members()->attach(makeUser()->id, ['role' => 'member']);
            foreach ([1, 4, 2] as $c => $col) {
                makeTask($project->columns[$col], ['due_date' => today()->subDays($c), 'position' => $c]);
            }
        }
    };

    $grow(3);
    $small = warmQueries(fn () => $this->actingAs($member)->get(route('projects.index'))->assertOk());
    $grow(27);
    $large = warmQueries(fn () => $this->actingAs($member)->get(route('projects.index'))->assertOk());

    expect($large)->toBeLessThanOrEqual($small + BUDGET_TOLERANCE, "index queries: 3 projects={$small}, 30 projects={$large}");
});

it('does not run per-task queries on the board', function () {
    $admin = makeUser('operator');
    $project = makeProject($admin);
    $milestone = $project->milestones()->create(['name' => 'MS', 'due_date' => today()->addWeek()]);
    $grow = function (int $n) use ($project, $admin, $milestone) {
        for ($i = 0; $i < $n; $i++) {
            $task = makeTask($project->columns[$i % 5], [
                'position' => 1000 + $i, 'assignee_id' => $admin->id, 'milestone_id' => $milestone->id, 'due_date' => today()->subDay(),
            ]);
            TaskChecklistItem::factory()->count(3)->create(['task_id' => $task->id]);
        }
    };

    $grow(3);
    $small = warmQueries(fn () => $this->actingAs($admin)->get(route('projects.board', $project))->assertOk());
    $grow(27);
    $large = warmQueries(fn () => $this->actingAs($admin)->get(route('projects.board', $project))->assertOk());

    expect($large)->toBeLessThanOrEqual($small + BUDGET_TOLERANCE, "board queries: 3 tasks={$small}, 30 tasks={$large}");
});

it('does not run per-comment or per-checklist-item queries on the task page (WP7)', function () {
    $admin = makeUser('operator');
    $project = makeProject($admin);
    $task = makeTask($project->columns[1]);
    $grow = function (int $n) use ($task, $admin) {
        for ($i = 0; $i < $n; $i++) {
            $task->comments()->create(['user_id' => $admin->id, 'body' => "Comment {$i}"]);
            TaskChecklistItem::factory()->create(['task_id' => $task->id, 'position' => $i]);
        }
    };

    $grow(3);
    $small = warmQueries(fn () => $this->actingAs($admin)->get(route('projects.tasks.show', [$project, $task]))->assertOk());
    $grow(27);
    $large = warmQueries(fn () => $this->actingAs($admin)->get(route('projects.tasks.show', [$project, $task]))->assertOk());

    expect($large)->toBeLessThanOrEqual($small + BUDGET_TOLERANCE, "task page queries: 3 rows={$small}, 30 rows={$large}");
});

it('does not run per-milestone queries on the milestones page', function () {
    $admin = makeUser('operator');
    $project = makeProject($admin);
    $grow = function (int $n) use ($project) {
        for ($i = 0; $i < $n; $i++) {
            $milestone = $project->milestones()->create(['name' => "MS {$i}", 'due_date' => today()->addDays($i)]);
            makeTask($project->columns[1], ['milestone_id' => $milestone->id, 'position' => 500 + $i]);
            makeTask($project->columns[4], ['milestone_id' => $milestone->id, 'position' => 500 + $i]);
        }
    };

    $grow(3);
    $small = warmQueries(fn () => $this->actingAs($admin)->get(route('projects.milestones.index', $project))->assertOk());
    $grow(27);
    $large = warmQueries(fn () => $this->actingAs($admin)->get(route('projects.milestones.index', $project))->assertOk());

    expect($large)->toBeLessThanOrEqual($small + BUDGET_TOLERANCE, "milestone queries: 3={$small}, 30={$large}");
});

it('does not run per-row queries on the tasks list', function () {
    $owner = makeUser('operator');
    $user = makeUser('user');
    $project = makeProject($owner);
    $project->members()->attach($user->id, ['role' => 'member']);
    $grow = function (int $n) use ($user, $project) {
        for ($i = 0; $i < $n; $i++) {
            makeTask($project->columns[$i % 5], ['assignee_id' => $user->id, 'position' => 2000 + $i, 'due_date' => today()->subDay()]);
            Task::factory()->standalone()->create(['assignee_id' => $user->id]);
            Task::factory()->standalone()->create([
                'assignee_id' => $user->id, 'ticket_id' => Ticket::factory()->create(['user_id' => $i % 2 ? $user->id : User::factory()->create()->id])->id,
            ]);
        }
    };

    $grow(3);
    $small = warmQueries(fn () => $this->actingAs($user)->get(route('tasks.index'))->assertOk());
    $grow(7);
    $large = warmQueries(fn () => $this->actingAs($user)->get(route('tasks.index'))->assertOk());

    expect($large)->toBeLessThanOrEqual($small + BUDGET_TOLERANCE, "tasks queries: 9 rows={$small}, 30 rows={$large}");
});

/*
 * EPIC-014 WP3 (§9.7): the Tasks workspace query, its filter options and the batched row
 * abilities cost a constant number of queries per request shape. Each case grows rows, projects,
 * assignees and companies together, so a per-row, per-project or per-option query would show.
 */

/** Grow a Tasks-workspace world around $member: one project, one company, one assignee per step. */
function growTasksWorld(User $member, User $owner, int $n): void
{
    static $step = 0;

    for ($i = 0; $i < $n; $i++, $step++) {
        $project = makeProject($owner, "Budget {$step}");
        $project->members()->attach($member->id, ['role' => $step % 2 ? 'member' : 'manager']);
        $other = makeUser();
        $project->members()->attach($other->id, ['role' => 'member']);
        $project->milestones()->create(['name' => "MS {$step}", 'due_date' => today()]);
        $project->companies()->attach(CrmCompany::factory()->create(['created_by' => $owner->id])->id);
        makeTask($project->columns[$step % 5], ['assignee_id' => $member->id, 'priority' => 'high', 'title' => "Budget task {$step}", 'due_date' => today()->subDay()]);
        makeTask($project->columns[1], ['assignee_id' => $other->id, 'priority' => 'high', 'title' => "Budget other {$step}", 'position' => 1]);
        Task::factory()->standalone()->create(['created_by' => $member->id, 'assignee_id' => null, 'title' => "Budget loose {$step}", 'priority' => 'high']);
        Task::factory()->standalone()->create([
            'assignee_id' => $member->id, 'title' => "Budget ticket {$step}", 'ticket_id' => Ticket::factory()->create(['user_id' => $member->id])->id,
        ]);
    }
}

it('keeps My Tasks, All Tasks, filtered, searched and option-heavy requests constant as rows grow (WP3)', function (string $who, array $query) {
    $owner = makeUser('operator');
    $member = makeUser('user');
    $member->givePermissionTo(['projects.manage', 'tasks.view_all']);
    $actor = $who === 'operator' ? $owner : $member;
    if (isset($query['project'])) {
        $query['project'] = makeProject($owner, 'Selected')->id;
        Project::find($query['project'])->members()->attach($member->id, ['role' => 'manager']);
    }
    $request = fn () => $this->actingAs($actor)->get(route('tasks.index', $query))->assertOk();

    growTasksWorld($member, $owner, 3);
    if (isset($query['project'])) {
        makeTask(Project::find($query['project'])->columns[1], ['assignee_id' => $member->id, 'title' => 'Budget selected']);
    }
    $small = warmQueries($request);
    growTasksWorld($member, $owner, 12);
    $large = warmQueries($request);

    expect($large)->toBeLessThanOrEqual($small + BUDGET_TOLERANCE, "{$who} ".json_encode($query).": small={$small}, large={$large}");
})->with([
    'member, My Tasks' => ['member', []],
    'member, All Tasks' => ['member', ['view' => 'all']],
    'operator, All Tasks' => ['operator', ['view' => 'all', 'completion' => 'any']],
    'member, All Tasks, completion/priority/due/kind/search/sort' => ['member', [
        'view' => 'all', 'completion' => 'any', 'priority' => ['high'], 'due' => 'overdue', 'kind' => 'project', 'q' => 'Budget', 'sort' => 'priority',
    ]],
    'member, one project selected (milestones offered)' => ['member', ['view' => 'all', 'project' => true]],
]);

it('keeps milestone completion counts equal to the per-milestone method', function () {
    $admin = makeUser('operator');
    $project = makeProject($admin);
    foreach ([[1, 4], [0, 0], [3, 1]] as $i => [$open, $done]) {
        $milestone = $project->milestones()->create(['name' => "Parity {$i}", 'due_date' => today()->addDays($i)]);
        for ($k = 0; $k < $open; $k++) {
            makeTask($project->columns[1], ['milestone_id' => $milestone->id, 'position' => $k]);
        }
        for ($k = 0; $k < $done; $k++) {
            makeTask($project->columns[4], ['milestone_id' => $milestone->id, 'position' => $k]);
        }
    }

    $response = $this->actingAs($admin)->get(route('projects.milestones.index', $project))->assertOk();
    $pageCompletionById = collect($response->viewData('page')['props']['milestones'])
        ->pluck('completion', 'id');

    foreach ($project->milestones()->withTaskCounts()->get() as $milestone) {
        expect($pageCompletionById[$milestone->id])->toBe($milestone->completionFromCounts());
        expect($milestone->completionFromCounts())->toBe($milestone->completionPercentage());
    }
});

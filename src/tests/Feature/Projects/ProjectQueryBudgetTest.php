<?php

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

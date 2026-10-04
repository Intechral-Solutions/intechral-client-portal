<?php

use App\Http\Presenters\ProjectOverviewPresenter;
use App\Models\CrmCompany;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;
use App\Queries\ProjectHealth;
use App\Services\ProjectMilestoneService;
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

/*
 * EPIC-015 WP1 PR B (§17): the Overview DTO, the milestones page with completion provenance, and
 * index-shaped health all cost a constant number of queries as the project grows. Every
 * measurement uses FRESH Project and User instances, as a real request would, so no relation or
 * permission loaded by an earlier call can make the budget vacuous.
 */

/** Grow one project: tasks (open, done, overdue, malformed), milestones (open, overdue, completed by different users), members, time. */
function growOverviewWorld(Project $project, User $owner, int $n): void
{
    static $step = 0;

    for ($i = 0; $i < $n; $i++, $step++) {
        $member = makeUser();
        $project->members()->attach($member->id, ['role' => $step % 3 ? 'member' : 'manager']);
        $milestone = $project->milestones()->create(['name' => "Grow {$step}", 'due_date' => today()->addDays($step % 2 ? 5 : -5)]);
        if ($step % 3 === 0) {
            app(ProjectMilestoneService::class)->complete($milestone, $member);
        }
        $open = makeTask($project->columns[1], ['milestone_id' => $milestone->id, 'assignee_id' => $member->id, 'due_date' => today()->subDay(), 'position' => 3000 + $step]);
        makeTask($project->columns[4], ['milestone_id' => $milestone->id, 'position' => 3000 + $step]);
        $dual = makeTask($project->columns[2], ['position' => 3000 + $step]);
        DB::table('tasks')->where('id', $dual->id)->update(['ticket_id' => Ticket::factory()->create()->id]);
        TimeEntry::factory()->create(['user_id' => $member->id, 'task_id' => $open->id, 'duration_minutes' => 15, 'timer_started_at' => null]);
        TimeEntry::factory()->create(['user_id' => $member->id, 'project_id' => $project->id, 'duration_minutes' => 30, 'timer_started_at' => null]);
        TimeEntry::factory()->create(['user_id' => $owner->id, 'project_id' => $project->id, 'duration_minutes' => 45, 'timer_started_at' => null]);
    }
}

it('keeps the Overview DTO constant as tasks, milestones, members and time grow (PR B)', function (string $who) {
    $owner = makeUser('operator');
    $project = makeProject($owner, 'Overview budget');
    $project->update(['budget' => 1000, 'target_date' => today()->subDay()]);
    $viewer = match ($who) {
        // Settings access (budget + roster) and all-user time.
        'operator' => $owner,
        // Settings access through projects.manage + manager role, own time only.
        'project manager' => projectActor('project_manager', $project),
        // Customer member: own time, no gated fields.
        'customer member' => projectActor('member', $project),
        // Plain member with time.view_all: all-user time, no Settings fields.
        'staff member' => tap(projectActor('member', $project))->givePermissionTo('time.view_all'),
    };
    $request = function () use ($project, $viewer) {
        $dto = ProjectOverviewPresenter::overview(Project::findOrFail($project->id), User::findOrFail($viewer->id));
        expect($dto)->toHaveKey('milestones');

        return $dto;
    };

    growOverviewWorld($project, $owner, 3);
    $small = warmQueries($request);
    $smallDto = $request();
    growOverviewWorld($project, $owner, 27);
    $large = warmQueries($request);
    $largeDto = $request();

    // The world really grew, so the comparison is not vacuous.
    expect($largeDto['milestones']['total'])->toBe($smallDto['milestones']['total'] + 27)
        ->and($largeDto['tasks']['total'])->toBe($smallDto['tasks']['total'] + 54);
    if ($largeDto['time']['scope'] === 'all') {
        expect($largeDto['time']['totalMinutes'])->toBe($smallDto['time']['totalMinutes'] + 27 * 90);
    }
    if (array_key_exists('members', $largeDto)) {
        expect(count($largeDto['members']))->toBe(count($smallDto['members']) + 27);
    }
    expect($large)->toBeLessThanOrEqual($small + BUDGET_TOLERANCE, "overview queries as {$who}: small={$small}, large={$large}");
})->with(['operator', 'project manager', 'customer member', 'staff member']);

it('keeps the projects.show Overview PAGE constant as the project grows (WP2)', function (string $who) {
    // EPIC-015 WP2 (§17, §39 of the WP2 brief): the real route, not just the presenter. The page adds
    // the request's own fixed cost (session, auth, shared shell props) on top of the presenter's
    // constant budget; neither may grow with tasks, milestones, members or time.
    $owner = makeUser('operator');
    $project = makeProject($owner, 'Overview page budget');
    $project->update(['budget' => 1000, 'target_date' => today()->subDay()]);
    $viewer = match ($who) {
        'operator' => $owner,
        'customer member' => projectActor('member', $project),
    };
    $request = fn () => $this->actingAs(User::findOrFail($viewer->id))
        ->get(route('projects.show', $project))
        ->assertOk()
        ->viewData('page')['props'];

    growOverviewWorld($project, $owner, 3);
    $small = warmQueries($request);
    $smallProps = $request();
    growOverviewWorld($project, $owner, 27);
    $large = warmQueries($request);
    $largeProps = $request();

    expect($largeProps['milestones']['total'])->toBe($smallProps['milestones']['total'] + 27)
        ->and($largeProps['tasks']['total'])->toBe($smallProps['tasks']['total'] + 54)
        ->and($large)->toBeLessThanOrEqual($small + BUDGET_TOLERANCE, "overview page queries as {$who}: small={$small}, large={$large}");
})->with(['operator', 'customer member']);

it('loads milestone completion provenance without a per-milestone query on the milestones page (PR B)', function () {
    $admin = makeUser('operator');
    $project = makeProject($admin);
    $grow = function (int $n) use ($project) {
        for ($i = 0; $i < $n; $i++) {
            $milestone = $project->milestones()->create(['name' => "Done {$i}", 'due_date' => today()->subDays($i)]);
            app(ProjectMilestoneService::class)->complete($milestone, makeUser('operator'));
        }
    };

    $grow(3);
    $small = warmQueries(fn () => $this->actingAs($admin)->get(route('projects.milestones.index', $project))->assertOk());
    $grow(27);
    $large = warmQueries(fn () => $this->actingAs($admin)->get(route('projects.milestones.index', $project))->assertOk());

    expect($large)->toBeLessThanOrEqual($small + BUDGET_TOLERANCE, "milestone completer queries: 3={$small}, 30={$large}");
});

it('derives index-shaped health for a page of projects without a query per project (WP4 readiness, PR B)', function () {
    $member = makeUser('user');
    $owner = makeUser('operator');
    $grow = function (int $n) use ($member, $owner) {
        for ($i = 0; $i < $n; $i++) {
            $project = makeProject($owner, "Health index {$i}");
            $project->members()->attach($member->id, ['role' => 'member']);
            $project->milestones()->create(['name' => 'Late', 'due_date' => today()->subDays($i + 1)]);
            makeTask($project->columns[1], ['due_date' => today()->subDay()]);
        }
    };
    $page = function () use ($member) {
        return ProjectHealth::withFacts(Project::visibleTo(User::findOrFail($member->id)))
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (Project $project) => ProjectHealth::forIndex($project));
    };

    $grow(3);
    $small = warmQueries($page);
    $grow(27);
    $large = warmQueries($page);

    expect($page()->count())->toBe(20)
        ->and(collect($page()->items())->pluck('state')->unique()->all())->toBe(['off_track'])
        ->and(collect($page()->items())->pluck('reasons.0.earliest')->unique()->all())->toBe([null])
        ->and($large)->toBeLessThanOrEqual($small + BUDGET_TOLERANCE, "index health queries: 3={$small}, 30={$large}");
});

/*
 * EPIC-015 WP3 (§17): the project Tasks tab costs a constant number of queries per request shape as
 * the project grows. Each step adds a member who is assigned work, a milestone, open/overdue/done
 * tasks on it, an unassigned task, a malformed project+ticket row and a task in another project, so a
 * per-row milestone, assignee, ability or option query would show.
 */
function growProjectTasksWorld(Project $project, Project $elsewhere, int $n): void
{
    static $step = 0;

    for ($i = 0; $i < $n; $i++, $step++) {
        $person = makeUser('user', ['name' => "Assignee {$step}"]);
        $project->members()->attach($person->id, ['role' => 'member']);
        $milestone = $project->milestones()->create(['name' => "Milestone {$step}", 'due_date' => today()->addDays($step % 9)]);
        makeTask($project->columns[$step % 4], ['title' => "Budget open {$step}", 'assignee_id' => $person->id, 'milestone_id' => $milestone->id, 'priority' => 'high', 'position' => 100 + $step]);
        makeTask($project->columns[1], ['title' => "Budget overdue {$step}", 'assignee_id' => $person->id, 'milestone_id' => $milestone->id, 'due_date' => today()->subDay(), 'position' => 200 + $step]);
        makeTask($project->columns[4], ['title' => "Budget done {$step}", 'assignee_id' => $person->id, 'milestone_id' => $milestone->id, 'position' => 300 + $step]);
        makeTask($project->columns[0], ['title' => "Budget loose {$step}", 'position' => 400 + $step]);
        makeTask($project->columns[1], ['title' => "Budget malformed {$step}", 'assignee_id' => $person->id, 'ticket_id' => Ticket::factory()->create()->id, 'position' => 500 + $step]);
        makeTask($elsewhere->columns[1], ['title' => "Budget elsewhere {$step}", 'assignee_id' => $person->id]);
    }
}

it('keeps the project Tasks tab constant as tasks, milestones and assignees grow (WP3)', function (string $who, array $query) {
    $owner = makeUser('operator');
    $project = makeProject($owner, 'Tasks tab budget');
    $elsewhere = makeProject($owner, 'Elsewhere');
    $firstMilestone = $project->milestones()->create(['name' => 'First', 'due_date' => today()]);
    $assignee = makeUser('user', ['name' => 'Fixed assignee']);
    $project->members()->attach($assignee->id, ['role' => 'member']);
    makeTask($project->columns[1], ['title' => 'Budget seed', 'assignee_id' => $assignee->id, 'milestone_id' => $firstMilestone->id]);
    $viewer = match ($who) {
        'operator' => $owner,
        'project manager' => projectActor('project_manager', $project),
        'customer member' => projectActor('member', $project),
    };
    $query = array_map(fn ($value) => match ($value) {
        '@milestone' => $firstMilestone->id,
        '@assignee' => $assignee->id,
        default => $value,
    }, $query);
    $request = fn () => $this->actingAs(User::findOrFail($viewer->id))
        ->get(route('projects.tasks.index', $project).'?'.http_build_query($query))
        ->assertOk()
        ->viewData('page')['props'];

    growProjectTasksWorld($project, $elsewhere, 3);
    $small = warmQueries($request);
    $smallProps = $request();
    growProjectTasksWorld($project, $elsewhere, 27);
    $large = warmQueries($request);
    $largeProps = $request();

    // The world really grew, so the comparison is not vacuous.
    expect(count($largeProps['filterOptions']['milestones']))->toBe(count($smallProps['filterOptions']['milestones']) + 27)
        ->and(count($largeProps['filterOptions']['assignees']))->toBe(count($smallProps['filterOptions']['assignees']) + 27)
        ->and($large)->toBeLessThanOrEqual($small + BUDGET_TOLERANCE, "project tasks {$who} ".json_encode($query).": small={$small}, large={$large}");
})->with([
    'customer member, default' => ['customer member', []],
    'project manager, default (assignment candidates)' => ['project manager', []],
    'operator, every completion' => ['operator', ['completion' => 'any']],
    'customer member, milestone' => ['customer member', ['milestone' => '@milestone', 'completion' => 'any']],
    'customer member, assignee' => ['customer member', ['assignee' => '@assignee']],
    'customer member, unassigned' => ['customer member', ['assignee' => 'none']],
    'project manager, priority/due' => ['project manager', ['priority' => ['high'], 'due' => 'overdue']],
    'customer member, search + board sort' => ['customer member', ['q' => 'Budget', 'sort' => 'board', 'dir' => 'desc']],
    'customer member, no match' => ['customer member', ['q' => 'zzz-nothing']],
]);

it('keeps the global Tasks list free of milestone data: project scope alone loads it (WP3, P4)', function () {
    $owner = makeUser('operator');
    $member = makeUser('user');
    $member->givePermissionTo('tasks.view_all');
    $project = makeProject($owner, 'Global budget');
    $project->members()->attach($member->id, ['role' => 'member']);
    $milestone = $project->milestones()->create(['name' => 'Global milestone', 'due_date' => today()]);
    foreach (range(1, 5) as $i) {
        makeTask($project->columns[1], ['title' => "Global {$i}", 'assignee_id' => $member->id, 'milestone_id' => $milestone->id, 'position' => $i]);
    }

    foreach ([[], ['view' => 'all'], ['view' => 'all', 'completion' => 'any', 'q' => 'Global']] as $query) {
        $request = fn () => $this->actingAs($member)->get(route('tasks.index', $query))->assertOk();
        $request();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $props = $request()->viewData('page')['props'];
        $sql = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        expect($sql)->not->toContain('project_milestones')
            ->and(array_key_exists('milestone', $props['tasks']['data'][0]))->toBeFalse(json_encode($query));
    }
});

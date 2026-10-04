<?php

use App\Http\Presenters\ProjectOverviewPresenter;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;
use App\Queries\ProjectHealth;
use App\Services\ProjectMilestoneService;
use App\Services\TimeEntryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-015 WP1 PR B (§12.3, INV-P8, INV-P15, INV-P16): the Overview DTO, tested directly. No page
 * renders it yet (WP2). NEW CONTRACT throughout.
 *
 * Every gated field is asserted by KEY PRESENCE, not value: an authorized actor's `budget` key
 * exists even when the value is null; an unauthorized actor's DTO has no such key at all.
 *
 * Time fixture on project A (each duration a distinct number, so a total names its members):
 *
 *   direct           worker, no task, project_id = A                         10   A
 *   boardTask        worker, task in A                                       20   A
 *   staleStandalone  worker, standalone task, project_id = A (malformed)     40   none
 *   staleTicket      worker, ticket task, project_id = A (malformed)         80   none
 *   dualLinked       worker, dual-linked task (A + ticket), project_id = A  160   none
 *   boardTaskInB     worker, task in B, project_id = A (malformed)          320   B
 *   running          worker, task in A, timer still running                 999   excluded (not settled)
 *   viewerOwn        the customer member, no task, project_id = A          2000   A
 *
 *   A, all users = 10 + 20 + 2000 = 2030; the customer member's own = 2000.
 */

const OVERVIEW_ALL_MINUTES = 2030;
const OVERVIEW_OWN_MINUTES = 2000;

/** Actor => the `time.scope` it receives, or null for no `time` key. */
const OVERVIEW_TIME_SCOPE = [
    'customer_member' => 'own',
    'no_permission_member' => null,
    'staff_member' => 'all',
    'manage_permission_member' => 'own',
    'manager_role' => 'own',
    'project_manager' => 'own',
    'admin' => 'all',
    'admin_only' => 'own',
    'admin_only_manager' => 'own',
];

function overviewOf(Project $project, User $viewer): array
{
    return ProjectOverviewPresenter::overview($project->fresh(), $viewer->fresh());
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Carbon::setTestNow('2026-06-15 12:00:00');
    $this->owner = makeUser('operator', ['name' => 'Owner Olive', 'email' => 'olive@example.test']);
    $this->project = makeProject($this->owner, 'Alpha');
    $this->project->update(['description' => 'The Alpha project.', 'start_date' => '2026-06-01', 'target_date' => '2026-07-31']);
    [, $this->todo, , , $this->done] = $this->project->columns->all();
});

afterEach(function () {
    Carbon::setTestNow();
});

// ── Shape ────────────────────────────────────────────────────────────────────

it('builds the full Overview for an actor with every permission, with exactly the planned keys', function () {
    $this->project->update(['budget' => 1500]);
    $dto = overviewOf($this->project, $this->owner);

    expect(array_keys($dto))->toBe(['project', 'health', 'tasks', 'milestones', 'abilities', 'budget', 'members', 'time'])
        ->and($dto['project'])->toBe([
            'id' => $this->project->id, 'name' => 'Alpha', 'description' => 'The Alpha project.',
            'status' => 'active', 'startDate' => '2026-06-01', 'targetDate' => '2026-07-31',
        ])
        ->and($dto['health'])->toBe(['state' => 'insufficient_data', 'label' => 'Not enough data', 'reasons' => [['code' => 'no_tracked_work']]])
        ->and($dto['tasks'])->toBe(['total' => 0, 'done' => 0, 'open' => 0, 'overdue' => 0, 'completion' => 0])
        ->and($dto['milestones'])->toBe(['total' => 0, 'completed' => 0, 'overdue' => 0, 'currentId' => null, 'nextId' => null, 'items' => []])
        ->and($dto['abilities'])->toBe(['openSettings' => true])
        ->and($dto['budget'])->toBe('1500.00')
        ->and($dto['members'])->toBe([['id' => $this->owner->id, 'name' => 'Owner Olive', 'role' => 'manager', 'isOwner' => true]])
        ->and($dto['time'])->toBe(['scope' => 'all', 'totalMinutes' => 0]);
});

it('sends the names-and-roles roster owner first, with project roles and no email', function () {
    $zed = makeUser('user', ['name' => 'Aaron Member', 'email' => 'aaron@example.test']);
    $this->project->members()->attach($zed->id, ['role' => 'member']);

    $dto = overviewOf($this->project, $this->owner);

    expect($dto['members'])->toBe([
        ['id' => $this->owner->id, 'name' => 'Owner Olive', 'role' => 'manager', 'isOwner' => true],
        ['id' => $zed->id, 'name' => 'Aaron Member', 'role' => 'member', 'isOwner' => false],
    ])->and(json_encode($dto))->not->toContain('@example.test')->not->toContain('email');
});

it('gives no health for an on-hold project and a reasoned health for an active one', function () {
    makeTask($this->todo, ['due_date' => '2026-06-01']);
    expect(overviewOf($this->project, $this->owner)['health'])->toBe([
        'state' => 'at_risk', 'label' => 'At risk', 'reasons' => [['code' => 'tasks_overdue', 'count' => 1]],
    ]);

    $this->project->update(['status' => 'on_hold']);
    $dto = overviewOf($this->project, $this->owner);
    expect(array_key_exists('health', $dto))->toBeTrue()->and($dto['health'])->toBeNull();
});

it('names the earliest overdue milestone in the Overview health, from the same rule as ProjectHealth', function () {
    $late = $this->project->milestones()->create(['name' => 'Missed', 'due_date' => '2026-06-10']);
    $this->project->milestones()->create(['name' => 'Missed later', 'due_date' => '2026-06-12']);

    $dto = overviewOf($this->project, $this->owner);

    expect($dto['health']['reasons'])->toBe([[
        'code' => 'milestones_overdue', 'count' => 2, 'earliest' => ['id' => $late->id, 'name' => 'Missed', 'dueDate' => '2026-06-10'],
    ]])->and($dto['health'])->toBe(ProjectHealth::forOverview(ProjectHealth::withFacts(Project::query())->findOrFail($this->project->id)));
});

// ── Customer-safe minimality (INV-P8, INV-P16) ───────────────────────────────

it('omits budget, roster and the Settings ability as KEYS unless the actor has Settings access, and gates time by permission', function (string $kind) {
    $actor = settingsAccessActor($kind, $this->project);
    $settings = SETTINGS_ACCESS_ACTORS[$kind];
    $timeScope = OVERVIEW_TIME_SCOPE[$kind];

    $dto = overviewOf($this->project, $actor);

    expect(array_key_exists('budget', $dto))->toBe($settings, "budget key for {$kind}")
        ->and(array_key_exists('members', $dto))->toBe($settings, "members key for {$kind}")
        ->and(array_key_exists('openSettings', $dto['abilities']))->toBe($settings, "openSettings key for {$kind}")
        ->and(array_key_exists('time', $dto))->toBe($timeScope !== null, "time key for {$kind}");
    if ($settings) {
        expect($dto['budget'])->toBeNull()->and($dto['abilities'])->toBe(['openSettings' => true]);
    } else {
        expect($dto['abilities'])->toBe([]);
    }
    if ($timeScope !== null) {
        expect($dto['time']['scope'])->toBe($timeScope);
    }
    // Always present for any viewer.
    expect($dto)->toHaveKeys(['project', 'health', 'tasks', 'milestones', 'abilities']);
})->with(array_keys(OVERVIEW_TIME_SCOPE));

it('gives a customer member no budget, no roster, no other users\' time, and no member name except milestone provenance', function () {
    $this->project->update(['budget' => '98765.43']);
    $customer = settingsAccessActor('customer_member', $this->project);
    $colleague = makeUser('user', ['name' => 'Colleague Cara', 'email' => 'cara@example.test']);
    $this->project->members()->attach($colleague->id, ['role' => 'member']);
    $completer = makeUser('operator', ['name' => 'Provenance Pat', 'email' => 'pat@example.test']);
    $milestone = $this->project->milestones()->create(['name' => 'Launch', 'due_date' => '2026-06-20']);
    app(ProjectMilestoneService::class)->complete($milestone, $completer);
    makeTask($this->todo, ['assignee_id' => $colleague->id]);
    TimeEntry::factory()->create(['user_id' => $colleague->id, 'project_id' => $this->project->id, 'task_id' => null, 'duration_minutes' => 300, 'timer_started_at' => null]);
    TimeEntry::factory()->create(['user_id' => $customer->id, 'project_id' => $this->project->id, 'task_id' => null, 'duration_minutes' => 45, 'timer_started_at' => null]);

    $dto = overviewOf($this->project, $customer);
    $json = json_encode($dto);

    expect($dto)->not->toHaveKey('budget')
        ->and($dto)->not->toHaveKey('members')
        ->and($dto['abilities'])->toBe([])
        ->and($dto['time'])->toBe(['scope' => 'own', 'totalMinutes' => 45])
        ->and($dto['milestones']['items'][0]['completedBy'])->toBe(['id' => $completer->id, 'name' => 'Provenance Pat'])
        ->and($json)->not->toContain('98765.43')
        ->and($json)->not->toContain('Colleague Cara')
        ->and($json)->not->toContain('Owner Olive')
        ->and($json)->not->toContain('@example.test');
});

it('never puts a raw model or internal column on the Overview', function () {
    $milestone = $this->project->milestones()->create(['name' => 'MS', 'due_date' => '2026-06-20']);
    app(ProjectMilestoneService::class)->complete($milestone, $this->owner);
    makeTask($this->todo, ['milestone_id' => $milestone->id]);

    $json = json_encode(overviewOf($this->project, $this->owner));

    foreach (['created_at', 'updated_at', 'project_id', 'created_by', 'completed_by', 'tasks_count', 'done_tasks_count', 'milestones_count', 'client_id', 'pivot', 'email'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }
});

// ── Project time (PT, PR-A canonical attribution) ────────────────────────────

describe('project time', function () {
    beforeEach(function () {
        $this->worker = makeUser();
        $this->customer = settingsAccessActor('customer_member', $this->project);
        $this->projectB = makeProject($this->owner, 'Beta');
        $taskA = makeTask($this->todo, ['title' => 'Task in A']);
        $taskB = makeTask($this->projectB->columns[0], ['title' => 'Task in B']);
        $standalone = Task::factory()->standalone()->create();
        $ticketTask = Task::factory()->standalone()->create(['ticket_id' => Ticket::factory()->create()->id]);
        $dual = makeTask($this->todo, ['title' => 'Dual linked']);
        DB::table('tasks')->where('id', $dual->id)->update(['ticket_id' => Ticket::factory()->create()->id]);

        $entry = fn (array $attributes) => TimeEntry::factory()->create([
            'user_id' => $this->worker->id, 'date' => today()->toDateString(), 'timer_started_at' => null, ...$attributes,
        ]);
        $entry(['project_id' => $this->project->id, 'duration_minutes' => 10]);
        $entry(['task_id' => $taskA->id, 'duration_minutes' => 20]);
        $entry(['task_id' => $standalone->id, 'project_id' => $this->project->id, 'duration_minutes' => 40]);
        $entry(['task_id' => $ticketTask->id, 'project_id' => $this->project->id, 'duration_minutes' => 80]);
        $entry(['task_id' => $dual->id, 'project_id' => $this->project->id, 'duration_minutes' => 160]);
        $entry(['task_id' => $taskB->id, 'project_id' => $this->project->id, 'duration_minutes' => 320]);
        $entry(['task_id' => $taskA->id, 'duration_minutes' => 999, 'timer_started_at' => now()->subHour(), 'stopped_at' => null]);
        $entry(['user_id' => $this->customer->id, 'project_id' => $this->project->id, 'duration_minutes' => 2000]);
    });

    it('totals all users\' settled time on the project by the canonical attribution, excluding stale, malformed and running rows', function () {
        expect(overviewOf($this->project, $this->owner)['time'])->toBe(['scope' => 'all', 'totalMinutes' => OVERVIEW_ALL_MINUTES]);
    });

    it('equals the report total, the by-project group and the canonical scope (PT parity)', function () {
        $service = app(TimeEntryService::class);
        $grouped = $service->summaryByProject()->firstWhere('project_id', $this->project->id);
        $scoped = (int) TimeEntry::attributedToProject($this->project->id)->whereNull('timer_started_at')->sum('duration_minutes');

        expect($service->totalMinutes(['project_id' => $this->project->id]))->toBe(OVERVIEW_ALL_MINUTES)
            ->and((int) $grouped->total_minutes)->toBe(OVERVIEW_ALL_MINUTES)
            ->and($scoped)->toBe(OVERVIEW_ALL_MINUTES)
            ->and(overviewOf($this->project, $this->owner)['time']['totalMinutes'])->toBe(OVERVIEW_ALL_MINUTES)
            // The malformed row naming A but carrying B's task counts under B only.
            ->and(overviewOf($this->projectB, $this->owner)['time']['totalMinutes'])->toBe(320);
    });

    it('gives a time.log viewer only their own time on the project, and the /time filter agrees', function () {
        expect(overviewOf($this->project, $this->customer)['time'])->toBe(['scope' => 'own', 'totalMinutes' => OVERVIEW_OWN_MINUTES])
            ->and(app(TimeEntryService::class)->totalMinutes(['project_id' => $this->project->id, 'user_id' => $this->customer->id]))->toBe(OVERVIEW_OWN_MINUTES);

        // The worker (`user` role) on A: direct + board task; their stale, malformed and running rows are excluded.
        expect(overviewOf($this->project, $this->worker)['time'])->toBe(['scope' => 'own', 'totalMinutes' => 30]);
    });

    it('gives a time.view_all viewer without Settings access the all-users total but still no budget or roster', function () {
        $staff = settingsAccessActor('staff_member', $this->project);
        $dto = overviewOf($this->project, $staff);

        expect($dto['time'])->toBe(['scope' => 'all', 'totalMinutes' => OVERVIEW_ALL_MINUTES])
            ->and($dto)->not->toHaveKey('budget')
            ->and($dto)->not->toHaveKey('members');
    });

    it('sends no time key at all to a member with neither time permission', function () {
        $dto = overviewOf($this->project, settingsAccessActor('no_permission_member', $this->project));

        expect($dto)->not->toHaveKey('time')->and(json_encode($dto))->not->toContain('totalMinutes');
    });

    it('changes no billing state and writes no time entry', function () {
        $before = DB::table('time_entries')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        overviewOf($this->project, $this->owner);
        overviewOf($this->project, $this->customer);

        expect(DB::table('time_entries')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all())->toBe($before);
    });
});

// ── Progress parity (INV-P1, §8.4) ───────────────────────────────────────────

it('counts task progress by the board column over valid kinds, in parity with the project helpers and aggregates', function () {
    makeTask($this->done, ['status' => 'todo', 'position' => 0]);                       // done (column)
    makeTask($this->done, ['status' => 'todo', 'position' => 1, 'due_date' => '2026-06-01']); // done, never overdue
    makeTask($this->todo, ['status' => 'done', 'position' => 0, 'due_date' => '2026-06-01']); // open and overdue (status ignored)
    makeTask($this->todo, ['position' => 1, 'due_date' => '2026-06-15']);                // open, due today: not overdue
    $dual = makeTask($this->todo, ['position' => 2, 'due_date' => '2026-06-01']);         // malformed: excluded
    DB::table('tasks')->where('id', $dual->id)->update(['ticket_id' => Ticket::factory()->create()->id]);
    $dualDone = makeTask($this->done, ['position' => 2]);                                  // malformed: excluded
    DB::table('tasks')->where('id', $dualDone->id)->update(['ticket_id' => Ticket::factory()->create()->id]);
    makeTask(makeProject(null, 'Elsewhere')->columns[1], ['due_date' => '2026-06-01']);   // another project

    $tasks = overviewOf($this->project, $this->owner)['tasks'];
    $stats = Project::withTaskStats()->findOrFail($this->project->id);

    expect($tasks)->toBe(['total' => 4, 'done' => 2, 'open' => 2, 'overdue' => 1, 'completion' => 50])
        ->and($tasks['completion'])->toBe($this->project->fresh()->completionPercentage())
        ->and($tasks['completion'])->toBe($stats->completionFromCounts())
        ->and($tasks['overdue'])->toBe($this->project->fresh()->overdueTasks())
        ->and($tasks['total'])->toBe((int) $stats->tasks_count);
});

// ── Milestone summary (§9, §14.2 data) ───────────────────────────────────────

it('orders milestones by due date then id and marks current (first incomplete) and next (first incomplete, not overdue)', function () {
    $ms = fn (string $name, string $due) => $this->project->milestones()->create(['name' => $name, 'due_date' => $due]);
    $kickoff = $ms('Kickoff', '2026-05-01');
    $designB = $ms('Design B', '2026-06-10');
    $designA = $ms('Design A', '2026-06-10');
    $beta = $ms('Beta', '2026-07-01');
    $launch = $ms('Launch', '2026-08-01');
    $service = app(ProjectMilestoneService::class);
    $service->complete($kickoff, $this->owner);
    $service->complete($beta, $this->owner); // out-of-order completion is shown as it is

    $milestones = overviewOf($this->project, $this->owner)['milestones'];

    expect(collect($milestones['items'])->pluck('id')->all())->toBe([$kickoff->id, $designB->id, $designA->id, $beta->id, $launch->id])
        ->and(collect($milestones['items'])->pluck('overdue')->all())->toBe([false, true, true, false, false])
        ->and($milestones['currentId'])->toBe($designB->id)   // overdue current keeps "current"
        ->and($milestones['nextId'])->toBe($launch->id)        // first incomplete that is not overdue
        ->and($milestones)->toMatchArray(['total' => 5, 'completed' => 2, 'overdue' => 2]);

    foreach ([$designB, $designA, $launch] as $milestone) {
        $service->complete($milestone, $this->owner);
    }
    expect(overviewOf($this->project, $this->owner)['milestones'])->toMatchArray([
        'total' => 5, 'completed' => 5, 'overdue' => 0, 'currentId' => null, 'nextId' => null,
    ]);
});

it('uses the shared milestone item, with task progress kept separate from completion', function () {
    $milestone = $this->project->milestones()->create(['name' => 'Only', 'due_date' => '2026-06-30']);
    makeTask($this->done, ['milestone_id' => $milestone->id]);

    $item = overviewOf($this->project, $this->owner)['milestones']['items'][0];

    expect($item)->toMatchArray(['taskCount' => 1, 'doneCount' => 1, 'openTaskCount' => 0, 'completion' => 100, 'completedAt' => null, 'completedBy' => null])
        ->and(overviewOf($this->project, $this->owner)['milestones']['currentId'])->toBe($milestone->id);
});

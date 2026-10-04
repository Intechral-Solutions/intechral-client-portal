<?php

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\Ticket;
use App\Queries\ProjectHealth;
use App\Queries\ProjectHealthFacts;
use App\Services\ProjectMilestoneService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-015 WP1 PR B (§8, Q1, INV-P13): derived project health. NEW CONTRACT throughout.
 *
 * Part 1 pins the pure derivation (`ProjectHealth::derive`) as a truth table over the §8.1 facts.
 * Part 2 pins that real data produces those facts from aggregates (`ProjectHealth::withFacts`):
 * the column-authoritative done rule, the explicit milestone completion rule, the date edges, the
 * malformed dual-linked task exclusion, and the index (count-only) versus Overview (named earliest)
 * forms.
 */

/** Facts with sensible "active, tracked, nothing wrong" defaults, overridden per case. */
function healthFacts(array $overrides = []): ProjectHealthFacts
{
    return new ProjectHealthFacts(...[
        'status' => 'active',
        'startDate' => null,
        'startsInFuture' => false,
        'targetPassed' => false,
        'taskCount' => 3,
        'openTaskCount' => 1,
        'overdueTaskCount' => 0,
        'milestoneCount' => 1,
        'overdueMilestoneCount' => 0,
        'earliestOverdueMilestone' => null,
        ...$overrides,
    ]);
}

/** A project loaded with the health aggregates. */
function healthOf(Project $project, bool $overview = false): ?array
{
    $loaded = ProjectHealth::withFacts(Project::query())->findOrFail($project->id);

    return $overview ? ProjectHealth::forOverview($loaded) : ProjectHealth::forIndex($loaded);
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Carbon::setTestNow('2026-06-15 12:00:00');
    $this->project = makeProject(null, 'Health');
    [$this->backlog, $this->todo, , , $this->done] = $this->project->columns->all();
});

afterEach(function () {
    Carbon::setTestNow();
});

// ── Part 1: the truth table ──────────────────────────────────────────────────

it('derives health in the §8.2 order with structured reasons', function (array $facts, ?string $state, ?array $reasons) {
    $health = ProjectHealth::derive(healthFacts($facts));

    if ($state === null) {
        expect($health)->toBeNull();

        return;
    }

    expect($health)->toBe([
        'state' => $state,
        'label' => ProjectHealth::LABELS[$state],
        'reasons' => $reasons,
    ]);
})->with([
    // Lifecycle precedence.
    'completed with overdue work, past target and overdue milestones' => [
        ['status' => 'completed', 'targetPassed' => true, 'overdueTaskCount' => 2, 'overdueMilestoneCount' => 1], 'complete', [],
    ],
    'completed with nothing tracked' => [['status' => 'completed', 'taskCount' => 0, 'openTaskCount' => 0, 'milestoneCount' => 0], 'complete', []],
    'on_hold with overdue work' => [['status' => 'on_hold', 'overdueTaskCount' => 2, 'overdueMilestoneCount' => 1, 'targetPassed' => true], null, null],
    'archived with overdue work' => [['status' => 'archived', 'overdueTaskCount' => 2, 'overdueMilestoneCount' => 1], null, null],
    'archived with nothing tracked' => [['status' => 'archived', 'taskCount' => 0, 'milestoneCount' => 0], null, null],
    'future start beats overdue work' => [
        ['startsInFuture' => true, 'startDate' => '2026-09-01', 'overdueTaskCount' => 1, 'overdueMilestoneCount' => 1, 'targetPassed' => true],
        'not_started', [['code' => 'starts_in_future', 'date' => '2026-09-01']],
    ],
    'future start with nothing tracked' => [
        ['startsInFuture' => true, 'startDate' => '2026-09-01', 'taskCount' => 0, 'openTaskCount' => 0, 'milestoneCount' => 0],
        'not_started', [['code' => 'starts_in_future', 'date' => '2026-09-01']],
    ],
    // Not enough data.
    'no dates and no work' => [['taskCount' => 0, 'openTaskCount' => 0, 'milestoneCount' => 0], 'insufficient_data', [['code' => 'no_tracked_work']]],
    'no work but a past target' => [
        ['taskCount' => 0, 'openTaskCount' => 0, 'milestoneCount' => 0, 'targetPassed' => true], 'insufficient_data', [['code' => 'no_tracked_work']],
    ],
    // Tracked work.
    'no tasks but one open, on-time milestone' => [['taskCount' => 0, 'openTaskCount' => 0, 'milestoneCount' => 1], 'on_track', []],
    'overdue milestone with zero tasks' => [
        ['taskCount' => 0, 'openTaskCount' => 0, 'milestoneCount' => 1, 'overdueMilestoneCount' => 1],
        'off_track', [['code' => 'milestones_overdue', 'count' => 1, 'earliest' => null]],
    ],
    'open overdue task' => [['overdueTaskCount' => 1], 'at_risk', [['code' => 'tasks_overdue', 'count' => 1]]],
    'past target with an open task' => [
        ['targetPassed' => true, 'openTaskCount' => 2], 'off_track', [['code' => 'target_passed', 'openTaskCount' => 2]],
    ],
    'past target with no open work' => [['targetPassed' => true, 'openTaskCount' => 0], 'on_track', []],
    'past target, no open tasks, but an overdue milestone' => [
        ['targetPassed' => true, 'openTaskCount' => 0, 'overdueMilestoneCount' => 1],
        'off_track', [['code' => 'milestones_overdue', 'count' => 1, 'earliest' => null]],
    ],
    'overdue milestone and overdue task' => [
        ['overdueMilestoneCount' => 2, 'overdueTaskCount' => 3],
        'off_track', [['code' => 'milestones_overdue', 'count' => 2, 'earliest' => null], ['code' => 'tasks_overdue', 'count' => 3]],
    ],
    'every signal, in the fixed order, one milestone item' => [
        ['targetPassed' => true, 'openTaskCount' => 4, 'overdueMilestoneCount' => 3, 'overdueTaskCount' => 2,
            'earliestOverdueMilestone' => ['id' => 7, 'name' => 'Beta', 'dueDate' => '2026-05-01']],
        'off_track', [
            ['code' => 'target_passed', 'openTaskCount' => 4],
            ['code' => 'milestones_overdue', 'count' => 3, 'earliest' => ['id' => 7, 'name' => 'Beta', 'dueDate' => '2026-05-01']],
            ['code' => 'tasks_overdue', 'count' => 2],
        ],
    ],
    'past target and overdue tasks' => [
        ['targetPassed' => true, 'openTaskCount' => 2, 'overdueTaskCount' => 1],
        'off_track', [['code' => 'target_passed', 'openTaskCount' => 2], ['code' => 'tasks_overdue', 'count' => 1]],
    ],
    'nothing wrong' => [[], 'on_track', []],
    'every task done' => [['openTaskCount' => 0], 'on_track', []],
]);

it('keeps reasons bounded: one milestone item however many milestones are overdue', function () {
    $health = ProjectHealth::derive(healthFacts(['overdueMilestoneCount' => 40, 'overdueTaskCount' => 90, 'targetPassed' => true]));

    expect($health['reasons'])->toHaveCount(3)
        ->and(collect($health['reasons'])->pluck('code')->all())->toBe(['target_passed', 'milestones_overdue', 'tasks_overdue']);
});

it('returns no health for any non-active, non-completed status', function () {
    expect(ProjectHealth::derive(healthFacts(['status' => 'on_hold'])))->toBeNull()
        ->and(ProjectHealth::derive(healthFacts(['status' => 'archived'])))->toBeNull();
});

// ── Part 2: facts from real data (today is 2026-06-15) ───────────────────────

it('reads Not enough data for an empty active project, and Complete / no health from the lifecycle alone', function () {
    expect(healthOf($this->project)['state'])->toBe('insufficient_data');

    $this->project->update(['status' => 'completed']);
    expect(healthOf($this->project)['state'])->toBe('complete');

    $this->project->update(['status' => 'on_hold']);
    expect(healthOf($this->project))->toBeNull();

    $this->project->update(['status' => 'archived']);
    expect(healthOf($this->project))->toBeNull();
});

it('reads the date edges in the application timezone: due or target today is not past, start today has started', function () {
    makeTask($this->todo, ['due_date' => '2026-06-15']);
    $this->project->update(['target_date' => '2026-06-15', 'start_date' => '2026-06-15']);
    expect(healthOf($this->project))->toMatchArray(['state' => 'on_track', 'reasons' => []]);

    $this->project->update(['start_date' => '2026-06-16']);
    expect(healthOf($this->project))->toMatchArray([
        'state' => 'not_started', 'reasons' => [['code' => 'starts_in_future', 'date' => '2026-06-16']],
    ]);

    $this->project->update(['start_date' => null, 'target_date' => '2026-06-14']);
    expect(healthOf($this->project))->toMatchArray([
        'state' => 'off_track', 'reasons' => [['code' => 'target_passed', 'openTaskCount' => 1]],
    ]);
});

it('uses the board column, not tasks.status, for done; a done-column task is never overdue', function () {
    makeTask($this->done, ['due_date' => '2026-06-01', 'status' => 'todo']);
    expect(healthOf($this->project))->toMatchArray(['state' => 'on_track', 'reasons' => []]);

    // Raw status says done, but the column is not the Done column: it is open and overdue.
    makeTask($this->todo, ['due_date' => '2026-06-01', 'status' => 'done']);
    expect(healthOf($this->project))->toMatchArray(['state' => 'at_risk', 'reasons' => [['code' => 'tasks_overdue', 'count' => 1]]]);

    $this->project->update(['target_date' => '2026-06-01']);
    expect(healthOf($this->project)['reasons'])->toBe([
        ['code' => 'target_passed', 'openTaskCount' => 1],
        ['code' => 'tasks_overdue', 'count' => 1],
    ]);
});

it('uses explicit milestone completion: an overdue zero-task milestone is Off track until completed', function () {
    $kickoff = $this->project->milestones()->create(['name' => 'Kickoff', 'due_date' => '2026-06-01']);
    expect(healthOf($this->project))->toMatchArray([
        'state' => 'off_track', 'reasons' => [['code' => 'milestones_overdue', 'count' => 1, 'earliest' => null]],
    ]);

    app(ProjectMilestoneService::class)->complete($kickoff, makeUser('operator'));
    expect(healthOf($this->project))->toMatchArray(['state' => 'on_track', 'reasons' => []]);
});

it('does not let finished tasks complete an overdue milestone for health purposes', function () {
    $milestone = $this->project->milestones()->create(['name' => 'Beta', 'due_date' => '2026-06-01']);
    makeTask($this->done, ['milestone_id' => $milestone->id]);

    expect(healthOf($this->project)['state'])->toBe('off_track');
});

it('names the earliest overdue milestone by due date then id on the Overview, and only counts on the index', function () {
    $later = $this->project->milestones()->create(['name' => 'Later', 'due_date' => '2026-06-10']);
    $tieB = $this->project->milestones()->create(['name' => 'Tie B', 'due_date' => '2026-06-05']);
    $tieA = $this->project->milestones()->create(['name' => 'Tie A', 'due_date' => '2026-06-05']);
    $completed = $this->project->milestones()->create(['name' => 'Completed earliest', 'due_date' => '2026-05-01']);
    app(ProjectMilestoneService::class)->complete($completed, makeUser('operator'));
    $this->project->milestones()->create(['name' => 'Upcoming', 'due_date' => '2026-07-01']);

    expect(healthOf($this->project, overview: true)['reasons'])->toBe([[
        'code' => 'milestones_overdue',
        'count' => 3,
        'earliest' => ['id' => $tieB->id, 'name' => 'Tie B', 'dueDate' => '2026-06-05'],
    ]])->and($tieB->id)->toBeLessThan($tieA->id)->and($later->id)->toBeGreaterThan(0);

    expect(healthOf($this->project)['reasons'])->toBe([['code' => 'milestones_overdue', 'count' => 3, 'earliest' => null]]);
});

it('excludes a malformed dual-linked task from every health input (PR-A ruling)', function () {
    $dual = makeTask($this->todo, ['due_date' => '2026-06-01']);
    DB::table('tasks')->where('id', $dual->id)->update(['ticket_id' => Ticket::factory()->create()->id]);

    // Its only task is malformed, so there is no tracked work at all.
    expect(healthOf($this->project))->toMatchArray(['state' => 'insufficient_data']);

    // Even with a past target it is not an open task.
    $this->project->update(['target_date' => '2026-06-01']);
    makeTask($this->done, ['due_date' => '2026-06-01']);
    expect(healthOf($this->project))->toMatchArray(['state' => 'on_track', 'reasons' => []]);

    // Linked to a milestone, it adds nothing to that milestone either.
    $milestone = $this->project->milestones()->create(['name' => 'M', 'due_date' => '2026-07-01']);
    DB::table('tasks')->where('id', $dual->id)->update(['milestone_id' => $milestone->id]);
    expect(healthOf($this->project, overview: true))->toMatchArray(['state' => 'on_track', 'reasons' => []]);
});

it('counts only this project\'s tasks and milestones', function () {
    $other = makeProject(null, 'Other');
    makeTask($other->columns[1], ['due_date' => '2026-06-01']);
    $other->milestones()->create(['name' => 'Other late', 'due_date' => '2026-06-01']);
    makeTask($this->todo);

    expect(healthOf($this->project))->toMatchArray(['state' => 'on_track', 'reasons' => []])
        ->and(healthOf($other))->toMatchArray(['state' => 'off_track']);
});

it('gives the same answer for a page of projects from one query as for each project alone', function () {
    $late = makeProject(null, 'Late');
    makeTask($late->columns[1], ['due_date' => '2026-06-01']);
    $offTrack = makeProject(null, 'Off');
    $offTrack->milestones()->create(['name' => 'Missed', 'due_date' => '2026-06-01']);
    $onHold = makeProject(null, 'Paused');
    $onHold->update(['status' => 'on_hold']);
    $ids = [$this->project->id, $late->id, $offTrack->id, $onHold->id];

    DB::flushQueryLog();
    DB::enableQueryLog();
    $page = ProjectHealth::withFacts(Project::query())->whereIn('id', $ids)->orderBy('id')->get()
        ->mapWithKeys(fn (Project $project) => [$project->id => ProjectHealth::forIndex($project)]);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(1);
    foreach ($ids as $id) {
        expect($page[$id])->toBe(healthOf(Project::find($id)));
    }
    expect($page->map(fn ($health) => $health['state'] ?? null)->all())->toBe([
        $this->project->id => 'insufficient_data', $late->id => 'at_risk', $offTrack->id => 'off_track', $onHold->id => null,
    ]);
});

it('keeps the milestone overdue aggregate equal to the per-row rule', function () {
    foreach (['2026-06-01', '2026-06-14', '2026-06-15', '2026-07-01'] as $i => $due) {
        $milestone = $this->project->milestones()->create(['name' => "M{$i}", 'due_date' => $due]);
        if ($i === 0) {
            app(ProjectMilestoneService::class)->complete($milestone, makeUser('operator'));
        }
    }

    $stats = Project::withMilestoneStats()->findOrFail($this->project->id);
    $rows = ProjectMilestone::where('project_id', $this->project->id)->get();

    expect((int) $stats->milestones_count)->toBe(4)
        ->and((int) $stats->completed_milestones_count)->toBe($rows->filter->isCompleted()->count())
        ->and((int) $stats->overdue_milestones_count)->toBe($rows->filter->isOverdue()->count())
        ->and((int) $stats->overdue_milestones_count)->toBe(1);
});

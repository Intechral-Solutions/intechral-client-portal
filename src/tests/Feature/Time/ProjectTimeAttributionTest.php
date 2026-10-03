<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Services\ProjectService;
use App\Services\TimeEntryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../Projects/ProjectTestHelpers.php';

/*
 * EPIC-015 WP1 PR A (§10, INV-P4): the one definition of "time on a project".
 *
 * Written first against the code as it stood at `58c58f1`, BEFORE any attribution seam existed.
 * Every consumer used `time_entries.project_id` alone, and a task-attributed entry has
 * `project_id = NULL` (TimeEntryController::contextRules makes the three context keys exclusive),
 * so task time was invisible to every project filter and by-project row. Those characterization
 * tests were then FLIPPED IN WP1 to the committed rule; each keeps, in its comment, what it
 * asserted before. Tests are one of:
 *
 *   FLIPPED IN WP1 — was KNOWN DEFECT; now asserts the committed rule.
 *   PRESERVED      — behaviour EPIC-015 keeps.
 *
 * The attribution rule: an entry WITH a task belongs to that task's project (none, if the task is
 * standalone or ticket-linked); an entry WITHOUT one belongs to its own project_id. One scope
 * (TimeEntry::scopeAttributedToProject), one grouping (attributedProjectIdSql) and one per-entry
 * answer (attributedProject) serve every consumer. The Overview consumer arrives in PR B and will
 * join the parity test below.
 *
 * Fixture ("the world"): one entry per shape, each with a distinct duration (a power of two) so a
 * total identifies exactly which entries it holds.
 *
 *   direct               project_id = A                               10
 *   boardTask            task in A, project_id NULL                   20
 *   standaloneTask       standalone task, project_id NULL             40
 *   ticketTask           ticket task, project_id NULL                 80
 *   malformedBoard       task in B, project_id = A   (invalid)       160
 *   malformedStandalone  standalone task, project_id = A (invalid)   320
 *   malformedTicket      ticket task, project_id = A (invalid)       640
 *   dualMatching         DUAL-LINKED task (project B + ticket),
 *                        project_id = B (invalid)                   1280
 *   dualConflicting      DUAL-LINKED task (project B + ticket),
 *                        project_id = A (invalid)                   2560
 *   unscoped             no task, no project                        5120
 *
 * A dual-linked task (project_id AND ticket_id) has no valid product kind (owner ruling, §8.4), so
 * time recorded against it is attributed to NO project, whatever its stored project_id says.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = makeUser('operator');
    $this->worker = makeUser();
    $this->projectA = makeProject(null, 'Project A');
    $this->projectB = makeProject(null, 'Project B');
    $this->taskA = makeTask($this->projectA->columns[0], ['title' => 'Task in A']);
    $this->taskB = makeTask($this->projectB->columns[0], ['title' => 'Task in B']);
    $this->standalone = Task::factory()->standalone()->create(['title' => 'Standalone']);
    $this->ticketTask = Task::factory()->standalone()->create([
        'title' => 'Ticket task', 'ticket_id' => Ticket::factory()->create()->id,
    ]);

    $entry = fn (array $attributes) => TimeEntry::factory()->create([
        'user_id' => $this->worker->id,
        'date' => today()->toDateString(),
        'billable' => true,
        'timer_started_at' => null,
        ...$attributes,
    ]);

    $this->dualTask = makeTask($this->projectB->columns[0], [
        'title' => 'Dual linked', 'ticket_id' => Ticket::factory()->create()->id,
    ]);

    $this->entries = [
        'direct' => $entry(['project_id' => $this->projectA->id, 'duration_minutes' => 10]),
        'boardTask' => $entry(['task_id' => $this->taskA->id, 'duration_minutes' => 20]),
        'standaloneTask' => $entry(['task_id' => $this->standalone->id, 'duration_minutes' => 40]),
        'ticketTask' => $entry(['task_id' => $this->ticketTask->id, 'duration_minutes' => 80]),
        'malformedBoard' => $entry(['task_id' => $this->taskB->id, 'project_id' => $this->projectA->id, 'duration_minutes' => 160]),
        'malformedStandalone' => $entry(['task_id' => $this->standalone->id, 'project_id' => $this->projectA->id, 'duration_minutes' => 320]),
        'malformedTicket' => $entry(['task_id' => $this->ticketTask->id, 'project_id' => $this->projectA->id, 'duration_minutes' => 640]),
        'dualMatching' => $entry(['task_id' => $this->dualTask->id, 'project_id' => $this->projectB->id, 'duration_minutes' => 1280]),
        'dualConflicting' => $entry(['task_id' => $this->dualTask->id, 'project_id' => $this->projectA->id, 'duration_minutes' => 2560]),
        'unscoped' => $entry(['duration_minutes' => 5120]),
    ];

    // Everything attributed to no project: standalone, ticket and dual-linked task time, and the
    // entry with no context at all.
    $this->noProjectMinutes = 40 + 80 + 320 + 640 + 1280 + 2560 + 5120;

    $this->service = app(TimeEntryService::class);
});

/** Group rows as [project id (or 'none') => minutes]. */
function ptaGroups($rows): array
{
    return collect($rows)
        ->mapWithKeys(fn ($row) => [($row->project_id ?? 'none') => (int) $row->total_minutes])
        ->all();
}

// ── The rule, at the service ──────────────────────────────────────────────────

it('FLIPPED IN WP1: the project filter counts direct and board-task time, and a malformed row counts only under its TASK\'s project', function () {
    // Before: A = 10 + 160 + 320 (project_id alone, so the board-task entry was invisible and both
    // malformed rows were counted under A); B = 0.
    expect($this->service->totalMinutes(['project_id' => $this->projectA->id]))->toBe(10 + 20)
        ->and($this->service->totalMinutes(['project_id' => $this->projectB->id]))->toBe(160);
});

it('FLIPPED IN WP1: standalone-, ticket- and dual-linked-task rows with a stored project_id count under NO project, in the filtered totals', function () {
    $inA = TimeEntry::attributedToProject($this->projectA->id)->whereNull('timer_started_at')->pluck('time_entries.id')->all();
    $inB = TimeEntry::attributedToProject($this->projectB->id)->whereNull('timer_started_at')->pluck('time_entries.id')->all();

    foreach (['standaloneTask', 'ticketTask', 'malformedStandalone', 'malformedTicket', 'dualMatching', 'dualConflicting', 'unscoped'] as $name) {
        $id = $this->entries[$name]->id;
        expect(in_array($id, $inA, true))->toBeFalse("{$name} must not be in A")
            ->and(in_array($id, $inB, true))->toBeFalse("{$name} must not be in B");
    }

    expect($inA)->toEqualCanonicalizing([$this->entries['direct']->id, $this->entries['boardTask']->id])
        ->and($inB)->toEqualCanonicalizing([$this->entries['malformedBoard']->id]);
});

it('FLIPPED IN WP1: the by-project grouping uses the same attribution, so project B has its row and the malformed standalone row falls in the null group', function () {
    // Before: [A => 490, none => 140] (grouped on project_id alone).
    expect(ptaGroups($this->service->summaryByProject()))->toEqual([
        $this->projectA->id => 10 + 20,
        $this->projectB->id => 160,
        'none' => $this->noProjectMinutes,
    ]);
});

it('every entry is counted in AT MOST ONE project: per-entry scope membership, and the groups partition the settled total', function () {
    $projects = [$this->projectA->id, $this->projectB->id];

    foreach ($this->entries as $name => $entry) {
        $homes = collect($projects)->filter(
            fn ($id) => TimeEntry::attributedToProject($id)->whereKey($entry->id)->exists(),
        );
        expect($homes->count())->toBeLessThanOrEqual(1, "{$name} is counted under {$homes->count()} projects");
    }

    // The two malformed rows each land in exactly the place the rule gives them.
    expect(TimeEntry::attributedToProject($this->projectB->id)->whereKey($this->entries['malformedBoard']->id)->exists())->toBeTrue()
        ->and(TimeEntry::attributedToProject($this->projectA->id)->whereKey($this->entries['malformedBoard']->id)->exists())->toBeFalse();

    expect(array_sum(ptaGroups($this->service->summaryByProject())))->toBe(collect($this->entries)->sum('duration_minutes'));
});

it('PRESERVED: the attribution is not a COALESCE — standalone, ticket and dual-linked rows with a project_id group under NO project, in the UNFILTERED grouping', function () {
    $groups = ptaGroups($this->service->summaryByProject());

    // Under COALESCE(tasks.project_id, time_entries.project_id) these rows would fall back to their
    // stored project_id and inflate A (and B), so the null group would shrink.
    expect($groups['none'])->toBe($this->noProjectMinutes)
        ->and($groups[$this->projectA->id])->toBe(30)
        ->and($groups[$this->projectB->id])->toBe(160);

    // The stored project_id on those rows is untouched: this is attribution, not a rewrite.
    foreach (['malformedStandalone', 'malformedTicket', 'dualConflicting'] as $name) {
        expect(DB::table('time_entries')->where('id', $this->entries[$name]->id)->value('project_id'))->toBe($this->projectA->id);
    }
});

it('PARITY: for every project the filtered total, the grouped total and the sum of entries whose attributedProject() is that project all agree, and so does the unattributed group', function () {
    $groups = ptaGroups($this->service->summaryByProject());
    $settled = TimeEntry::with(['project', 'task.project'])->whereNull('timer_started_at')->get();

    foreach ([$this->projectA->id, $this->projectB->id] as $id) {
        $filtered = $this->service->totalMinutes(['project_id' => $id]);
        $scoped = (int) TimeEntry::attributedToProject($id)->whereNull('timer_started_at')->sum('duration_minutes');
        $perEntry = (int) $settled->filter(fn (TimeEntry $e) => $e->attributedProject()?->id === $id)->sum('duration_minutes');

        expect($filtered)->toBe($groups[$id] ?? 0)->and($scoped)->toBe($filtered)->and($perEntry)->toBe($filtered);
    }

    // The unattributed group: no group id in SQL, no project on the entry.
    $unattributed = (int) $settled->filter(fn (TimeEntry $e) => $e->attributedProject() === null)->sum('duration_minutes');
    expect($groups['none'])->toBe($unattributed)->and($unattributed)->toBe($this->noProjectMinutes);

    // Nothing is counted twice or dropped: the groups partition every settled entry.
    expect(array_sum($groups))->toBe((int) $settled->sum('duration_minutes'));
});

it('PRESERVED: running (unsettled) timers stay out of every project total, as they do today', function () {
    // A NON-ZERO stored duration, so including the running row in a total would change it.
    TimeEntry::factory()->running()->create(['user_id' => $this->worker->id, 'task_id' => $this->taskA->id, 'duration_minutes' => 999]);
    TimeEntry::factory()->running()->create(['user_id' => $this->worker->id, 'project_id' => $this->projectA->id, 'duration_minutes' => 777]);

    expect($this->service->totalMinutes(['project_id' => $this->projectA->id]))->toBe(30)
        ->and(ptaGroups($this->service->summaryByProject())[$this->projectA->id])->toBe(30)
        ->and((int) TimeEntry::attributedToProject($this->projectA->id)->whereNull('timer_started_at')->sum('duration_minutes'))->toBe(30)
        ->and($this->service->summaryByProject(['project_id' => $this->projectA->id])->sum('total_minutes'))->toBe(30);
});

it('the per-user summary and the other filters compose with the attributed project filter', function () {
    $other = makeUser();
    TimeEntry::factory()->create(['user_id' => $other->id, 'task_id' => $this->taskA->id, 'duration_minutes' => 7, 'date' => today()->toDateString(), 'timer_started_at' => null, 'billable' => false]);

    $byUser = collect($this->service->summaryByUser(['project_id' => $this->projectA->id]))->mapWithKeys(fn ($r) => [$r->user_id => (int) $r->total_minutes]);

    expect($byUser->all())->toEqual([$this->worker->id => 30, $other->id => 7])
        ->and($this->service->totalMinutes(['project_id' => $this->projectA->id, 'billable' => false]))->toBe(7)
        ->and($this->service->totalMinutes(['project_id' => $this->projectA->id, 'user_id' => $this->worker->id]))->toBe(30);
});

// ── The rule, at the pages ────────────────────────────────────────────────────

it('FLIPPED IN WP1: the operator report filters, totals and names rows by the attributed project', function () {
    // Before: A total 490 with 3 rows; the board-task row had a null project name.
    $this->actingAs($this->operator)
        ->get(route('operator.time.index', ['project_id' => $this->projectA->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totalMinutes', 30)
            ->has('entries.data', 2)
            ->where('byProject', fn ($rows) => collect($rows)->map(fn ($r) => [$r['id'], $r['name'], $r['totalMinutes']])->all() === [[$this->projectA->id, 'Project A', 30]])
            ->where('entries.data', fn ($rows) => collect($rows)->pluck('projectName')->unique()->values()->all() === ['Project A']));

    $this->actingAs($this->operator)
        ->get(route('operator.time.index', ['project_id' => $this->projectB->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('totalMinutes', 160)
            ->has('entries.data', 1)
            ->where('entries.data.0.projectName', 'Project B'));
});

it('FLIPPED IN WP1: the unfiltered operator report shows each attributed project once, plus a "No project" row', function () {
    $this->actingAs($this->operator)
        ->get(route('operator.time.index'))
        ->assertInertia(fn (Assert $page) => $page->where('byProject', function ($rows) {
            $byName = collect($rows)->mapWithKeys(fn ($r) => [$r['name'] => $r['totalMinutes']])->all();

            return $byName == ['Project A' => 30, 'Project B' => 160, 'No project' => $this->noProjectMinutes];
        }));
});

it('FLIPPED IN WP1: the /time page filter and total follow the attributed project, and stay user-scoped', function () {
    $this->actingAs($this->worker)
        ->get(route('time.index', ['project_id' => $this->projectA->id]))
        ->assertInertia(fn (Assert $page) => $page->where('totalMinutes', 30)->has('entries.data', 2));

    // Another user's time on the same project is never visible here.
    $stranger = makeUser();
    $this->actingAs($stranger)
        ->get(route('time.index', ['project_id' => $this->projectA->id]))
        ->assertInertia(fn (Assert $page) => $page->where('totalMinutes', 0)->has('entries.data', 0));
});

it('PARITY: the operator report total, the /time total and the service agree for the same project and actor scope', function () {
    $service = $this->service->totalMinutes(['project_id' => $this->projectA->id, 'user_id' => $this->worker->id]);

    $report = null;
    $this->actingAs($this->operator)->get(route('operator.time.index', ['project_id' => $this->projectA->id, 'user_id' => $this->worker->id]))
        ->assertInertia(function (Assert $page) use (&$report) {
            $report = $page->toArray()['props']['totalMinutes'];

            return $page;
        });

    $mine = null;
    $this->actingAs($this->worker)->get(route('time.index', ['project_id' => $this->projectA->id]))
        ->assertInertia(function (Assert $page) use (&$mine) {
            $mine = $page->toArray()['props']['totalMinutes'];

            return $page;
        });

    expect($report)->toBe($service)->and($mine)->toBe($service)->and($service)->toBe(30);
});

it('FLIPPED IN WP1: the CSV names the attributed project, and a standalone- or ticket-task row has none even with a stored project_id', function () {
    // Before: the board-task rows had an empty project and the malformed board row said "Project A".
    $rows = collect(explode("\n", trim($this->service->exportCsv([]))))->skip(1)
        ->map(fn ($line) => str_getcsv($line, escape: ''))
        ->map(fn ($r) => [$r[3], $r[2]])   // [task, project]
        ->sort()->values()->all();

    expect($rows)->toEqual(collect([
        ['', 'Project A'],              // direct project entry (no task)
        ['Task in A', 'Project A'],
        ['Task in B', 'Project B'],     // malformed row: project_id = A, task in B
        ['Standalone', ''],
        ['Standalone', ''],             // malformed row: project_id = A, standalone task
        ['Ticket task', ''],
        ['Ticket task', ''],            // malformed row: project_id = A, ticket task
        ['Dual linked', ''],            // dual-linked task, project_id = B
        ['Dual linked', ''],            // dual-linked task, project_id = A
        ['', ''],                       // no context at all
    ])->sort()->values()->all());

    // Filtering the export by project uses the same scope.
    $filtered = collect(explode("\n", trim($this->service->exportCsv(['project_id' => $this->projectB->id]))))->skip(1)->count();
    expect($filtered)->toBe(1);
});

// ── Delete safety is a different question and stays conservative ──────────────

it('PRESERVED (INV-P3): RecordedTimeGuard blocks deleting EITHER project a malformed row references, though reporting counts it under one', function () {
    $c = makeProject(null, 'Project C');       // no tasks; only the malformed row's project_id points here
    $d = makeProject(null, 'Project D');
    $taskD = makeTask($d->columns[0]);
    TimeEntry::factory()->create([
        'user_id' => $this->worker->id, 'task_id' => $taskD->id, 'project_id' => $c->id,
        'duration_minutes' => 5, 'date' => today()->toDateString(), 'timer_started_at' => null,
    ]);

    // Reporting: D only.
    expect($this->service->totalMinutes(['project_id' => $c->id]))->toBe(0)
        ->and($this->service->totalMinutes(['project_id' => $d->id]))->toBe(5);

    // Delete safety: any reference blocks, so C is protected as well.
    foreach ([$c, $d] as $project) {
        expect(fn () => app(ProjectService::class)->deleteProject($project))->toThrow(ValidationException::class)
            ->and(Project::whereKey($project->id)->exists())->toBeTrue();
    }
});

// ── Query budget ──────────────────────────────────────────────────────────────

it('QUERY BUDGET: the by-project summary, the operator report and the /time page cost the same number of queries as the data grows', function () {
    $count = function (callable $run): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $measure = fn () => [
        $count(fn () => $this->service->summaryByProject(['project_id' => $this->projectA->id])->count()),
        $count(fn () => $this->actingAs($this->operator)->get(route('operator.time.index', ['project_id' => $this->projectA->id]))->assertOk()),
        $count(fn () => $this->actingAs($this->worker)->get(route('time.index', ['project_id' => $this->projectA->id]))->assertOk()),
        $count(fn () => $this->service->exportCsv(['project_id' => $this->projectA->id])),
    ];

    $measure();            // warm-up: the first request also loads permissions and the session
    $small = $measure();

    // Grow: more projects, tasks and entries, all of them on project A or its tasks.
    for ($i = 0; $i < 12; $i++) {
        $task = makeTask($this->projectA->columns[0], ['title' => "Grow {$i}"]);
        TimeEntry::factory()->count(3)->create(['user_id' => $this->worker->id, 'task_id' => $task->id, 'date' => today()->toDateString(), 'timer_started_at' => null]);
        TimeEntry::factory()->create(['user_id' => $this->worker->id, 'project_id' => $this->projectA->id, 'date' => today()->toDateString(), 'timer_started_at' => null]);
    }

    expect($measure())->toBe($small);
});

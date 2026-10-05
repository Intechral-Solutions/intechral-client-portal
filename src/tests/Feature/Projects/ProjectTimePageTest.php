<?php

use App\Http\Presenters\ProjectOverviewPresenter;
use App\Http\Presenters\ProjectTimePresenter;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimeEntryService;

require_once __DIR__.'/ProjectTestHelpers.php';

/*
 * EPIC-015 WP5 (S3, §7, §10, INV-P4, INV-P8, INV-P14, INV-P15): the project Time tab,
 * `GET /projects/{project}/time` (`projects.time.index`), through real HTTP requests.
 *
 * Authorization is `ProjectPolicy::view` and then `ProjectTimeAccess` (time.view_all → all, time.log
 * alone → own, neither → 403). Attribution is the WP1 canonical scope, never `project_id` alone, over
 * settled entries; the total is the Overview's own figure. Rows are minimal: date, duration,
 * context, and a display name only in `all` scope.
 *
 * Fixture minutes are distinct powers of two so a total names exactly which entries it holds:
 *
 *   direct A (worker)                 1     A
 *   board task in A (worker)          2     A
 *   board task in A (colleague)       4     A
 *   direct A (customer)               8     A
 *   board task in A (customer)       16     A
 *   direct B (worker)                32     B
 *   board task in B, project_id = A  64     B   (malformed: the task wins)
 *   standalone task, project_id = A 128     none
 *   ticket task, project_id = A     256     none
 *   dual-linked task in A           512     none (owner ruling, A1.1.5)
 *   running timer on A, 999 stored  999     excluded: not settled
 */

const PROJECT_TIME_SHARED_PROPS = ['app', 'auth', 'shell', 'navigation', 'flash', 'errors'];

function projectTimeProps($response): array
{
    $props = array_diff_key($response->viewData('page')['props'], array_flip(PROJECT_TIME_SHARED_PROPS));

    return json_decode(json_encode($props), true);
}

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->owner = makeUser('operator', ['name' => 'Owner Olive', 'email' => 'olive@example.test']);
    $this->projectA = makeProject($this->owner, 'Apollo');
    $this->projectB = makeProject($this->owner, 'Borealis');

    $this->worker = makeUser('user', ['name' => 'Worker Wren', 'email' => 'wren@example.test']);
    $this->colleague = makeUser('user', ['name' => 'Colleague Carl', 'email' => 'carl@example.test']);
    $this->customer = makeUser('user', ['name' => 'Customer Cleo', 'email' => 'cleo@example.test']);
    foreach ([$this->worker, $this->colleague, $this->customer] as $person) {
        $this->projectA->members()->attach($person->id, ['role' => 'member']);
    }

    $this->taskA = makeTask($this->projectA->columns[0], ['title' => 'Design review']);
    $this->taskB = makeTask($this->projectB->columns[0], ['title' => 'Borealis task']);
    $this->standalone = Task::factory()->standalone()->create(['title' => 'Standalone']);
    $this->ticketTask = Task::factory()->standalone()->create(['title' => 'Ticket task', 'ticket_id' => Ticket::factory()->create()->id]);
    $this->dualTask = makeTask($this->projectA->columns[0], ['title' => 'Dual linked', 'ticket_id' => Ticket::factory()->create()->id]);

    $entry = fn (User $user, array $attributes, string $date = '2026-09-01') => TimeEntry::factory()->create([
        'user_id' => $user->id, 'date' => $date, 'timer_started_at' => null, ...$attributes,
    ]);

    $this->entries = [
        'directA' => $entry($this->worker, ['project_id' => $this->projectA->id, 'duration_minutes' => 1], '2026-09-01'),
        'taskA' => $entry($this->worker, ['task_id' => $this->taskA->id, 'duration_minutes' => 2], '2026-09-03'),
        'colleagueTaskA' => $entry($this->colleague, ['task_id' => $this->taskA->id, 'duration_minutes' => 4], '2026-09-02'),
        'customerDirectA' => $entry($this->customer, ['project_id' => $this->projectA->id, 'duration_minutes' => 8], '2026-09-04'),
        'customerTaskA' => $entry($this->customer, ['task_id' => $this->taskA->id, 'duration_minutes' => 16], '2026-09-04'),
        'directB' => $entry($this->worker, ['project_id' => $this->projectB->id, 'duration_minutes' => 32]),
        'malformedBoard' => $entry($this->worker, ['task_id' => $this->taskB->id, 'project_id' => $this->projectA->id, 'duration_minutes' => 64]),
        'malformedStandalone' => $entry($this->worker, ['task_id' => $this->standalone->id, 'project_id' => $this->projectA->id, 'duration_minutes' => 128]),
        'malformedTicket' => $entry($this->worker, ['task_id' => $this->ticketTask->id, 'project_id' => $this->projectA->id, 'duration_minutes' => 256]),
        'dual' => $entry($this->worker, ['task_id' => $this->dualTask->id, 'project_id' => $this->projectA->id, 'duration_minutes' => 512]),
    ];
    $this->running = TimeEntry::factory()->create([
        'user_id' => $this->worker->id, 'project_id' => $this->projectA->id, 'date' => '2026-09-05',
        'duration_minutes' => 999, 'timer_started_at' => now()->subMinutes(5),
    ]);
});

function projectTimeIds(array $props): array
{
    return array_column($props['entries']['data'], 'id');
}

// ── Authorization (§7, INV-P14) ──────────────────────────────────────────────

it('sends a guest to the login page', function () {
    $this->get(route('projects.time.index', $this->projectA))->assertRedirect(route('login'));
});

it('authorizes project visibility first, then a time scope, over every actor shape', function (string $kind) {
    $actor = settingsAccessActor($kind, $this->projectA);
    $status = $this->actingAs($actor)->get(route('projects.time.index', $this->projectA))->status();

    $expected = match ($kind) {
        // Not a viewer of the project: ProjectPolicy::view refuses first.
        'outsider' => 403,
        // A viewer with neither time.view_all nor time.log has no project time anywhere.
        'no_permission_member' => 403,
        default => 200,
    };

    expect($status)->toBe($expected, "{$kind}");
})->with(array_keys(SETTINGS_ACCESS_ACTORS));

it('refuses a stale assignee who is no longer a member, whatever their time permission', function () {
    $this->taskA->update(['assignee_id' => $this->worker->id]);
    $this->projectA->members()->detach($this->worker->id);

    $this->actingAs($this->worker)->get(route('projects.time.index', $this->projectA))->assertForbidden();
});

it('offers the Time tab on every workspace page exactly when the Time route admits the actor', function (string $kind) {
    $actor = settingsAccessActor($kind, $this->projectA);
    $admitted = $this->actingAs($actor)->get(route('projects.time.index', $this->projectA))->status() === 200;

    foreach (['projects.show', 'projects.board', 'projects.tasks.index', 'projects.milestones.index', 'projects.time.index'] as $route) {
        $response = $this->actingAs($actor)->get(route($route, $this->projectA));

        if ($route === 'projects.time.index' && ! $admitted) {
            continue;
        }

        expect(projectTimeProps($response->assertOk())['tabs'])->toBe(['time' => $admitted], "{$kind} on {$route}");
    }
})->with(array_keys(array_filter(SETTINGS_ACCESS_ACTORS, fn ($allowed, $kind) => $kind !== 'outsider', ARRAY_FILTER_USE_BOTH)));

it('is read only: no write route answers at the Time URI', function () {
    foreach (['post', 'put', 'patch', 'delete'] as $method) {
        $this->actingAs($this->owner)->{$method}(route('projects.time.index', $this->projectA))->assertStatus(405);
    }
});

// ── Props contract and DTO minimality (INV-P8, INV-P15) ──────────────────────

it('carries exactly the Time tab contract for an all-team viewer', function () {
    $props = projectTimeProps($this->actingAs($this->owner)->get(route('projects.time.index', $this->projectA))->assertOk());

    expect(array_keys($props))->toBe(['project', 'summary', 'entries', 'tabs', 'abilities'])
        ->and($props['project'])->toBe(['id' => $this->projectA->id, 'name' => 'Apollo', 'status' => 'active'])
        ->and($props['summary'])->toBe(['scope' => 'all', 'totalMinutes' => 1 + 2 + 4 + 8 + 16 + 0])
        ->and($props['tabs'])->toBe(['time' => true])
        ->and($props['abilities'])->toBe(['openSettings' => true])
        ->and($props['entries']['per_page'])->toBe(ProjectTimePresenter::PER_PAGE);

    $taskRow = collect($props['entries']['data'])->firstWhere('id', $this->entries['taskA']->id);
    $directRow = collect($props['entries']['data'])->firstWhere('id', $this->entries['directA']->id);

    expect(array_keys($taskRow))->toBe(['id', 'date', 'durationMinutes', 'context', 'userName'])
        ->and($taskRow)->toBe([
            'id' => $this->entries['taskA']->id,
            'date' => '2026-09-03',
            'durationMinutes' => 2,
            'context' => [
                'kind' => 'task',
                'id' => $this->taskA->id,
                'title' => 'Design review',
                'url' => route('projects.tasks.show', [$this->projectA->id, $this->taskA->id]),
            ],
            'userName' => 'Worker Wren',
        ])
        ->and($directRow['context'])->toBe(['kind' => 'project']);
});

it('sends an own-scope viewer their own entries only, with no other person in the props', function () {
    $props = projectTimeProps($this->actingAs($this->customer)->get(route('projects.time.index', $this->projectA))->assertOk());

    expect($props['summary'])->toBe(['scope' => 'own', 'totalMinutes' => 8 + 16])
        ->and(projectTimeIds($props))->toBe([$this->entries['customerTaskA']->id, $this->entries['customerDirectA']->id])
        ->and($props['entries']['total'])->toBe(2)
        ->and($props['abilities'])->toBe([])
        ->and($props['tabs'])->toBe(['time' => true]);

    foreach ($props['entries']['data'] as $row) {
        // Not even their own name: an own row needs no person, and the key's absence is the rule.
        expect(array_keys($row))->toBe(['id', 'date', 'durationMinutes', 'context']);
    }

    $json = json_encode($props);
    foreach (['Worker Wren', 'Colleague Carl', 'Owner Olive', 'Customer Cleo', '@example.test', 'email', 'userName'] as $absent) {
        expect($json)->not->toContain($absent);
    }
});

it('never serializes description, billing, invoice, lock, user id or email', function (string $kind) {
    $actor = $kind === 'all' ? $this->owner : $this->customer;
    $props = projectTimeProps($this->actingAs($actor)->get(route('projects.time.index', $this->projectA))->assertOk());

    $keys = collect($props['entries']['data'])->flatMap(fn (array $row) => array_keys($row))->unique()->all();

    foreach (['description', 'billable', 'billed', 'invoiceLinked', 'invoice_id', 'locked', 'running', 'user_id', 'userId', 'user', 'email', 'hours', 'project_id'] as $absent) {
        expect($keys)->not->toContain($absent);
    }

    expect(json_encode($props))->not->toContain($this->entries['taskA']->description);
})->with(['all', 'own']);

it('gives a staff member with time.view_all all project time but no Settings datum', function () {
    $staff = settingsAccessActor('staff_member', $this->projectA);
    $props = projectTimeProps($this->actingAs($staff)->get(route('projects.time.index', $this->projectA))->assertOk());

    expect($props['summary'])->toBe(['scope' => 'all', 'totalMinutes' => 31])
        ->and($props['abilities'])->toBe([])
        ->and(collect($props['entries']['data'])->pluck('userName')->unique()->sort()->values()->all())
        ->toBe(['Colleague Carl', 'Customer Cleo', 'Worker Wren']);
});

it('gives a project manager without time.view_all their own time only', function () {
    $manager = settingsAccessActor('project_manager', $this->projectA);
    TimeEntry::factory()->create(['user_id' => $manager->id, 'task_id' => $this->taskA->id, 'duration_minutes' => 7, 'timer_started_at' => null]);

    $props = projectTimeProps($this->actingAs($manager)->get(route('projects.time.index', $this->projectA))->assertOk());

    expect($props['summary'])->toBe(['scope' => 'own', 'totalMinutes' => 7])
        ->and($props['abilities'])->toBe(['openSettings' => true])
        ->and($props['entries']['total'])->toBe(1)
        ->and(json_encode($props))->not->toContain('Worker Wren');
});

it('links every task context to a task page the viewer can open', function () {
    $props = projectTimeProps($this->actingAs($this->customer)->get(route('projects.time.index', $this->projectA))->assertOk());

    foreach ($props['entries']['data'] as $row) {
        if ($row['context']['kind'] === 'task') {
            $this->actingAs($this->customer)->get($row['context']['url'])->assertOk();
        }
    }
});

// ── Canonical attribution (§10, INV-P4) ──────────────────────────────────────

it('lists exactly the canonical project time: direct plus valid task time, settled only', function () {
    $propsA = projectTimeProps($this->actingAs($this->owner)->get(route('projects.time.index', $this->projectA)));
    $propsB = projectTimeProps($this->actingAs($this->owner)->get(route('projects.time.index', $this->projectB)));

    $idsOf = fn (array $names) => collect($names)->map(fn ($name) => $this->entries[$name]->id)->sort()->values()->all();

    expect(collect(projectTimeIds($propsA))->sort()->values()->all())
        ->toBe($idsOf(['directA', 'taskA', 'colleagueTaskA', 'customerDirectA', 'customerTaskA']))
        // A malformed board-task row counts under its TASK's project only.
        ->and(collect(projectTimeIds($propsB))->sort()->values()->all())->toBe($idsOf(['directB', 'malformedBoard']))
        ->and($propsA['summary']['totalMinutes'])->toBe(31)
        ->and($propsB['summary']['totalMinutes'])->toBe(32 + 64)
        // The running timer, standalone, ticket and dual-linked rows are in neither.
        ->and(projectTimeIds($propsA))->not->toContain($this->running->id)
        ->and(array_merge(projectTimeIds($propsA), projectTimeIds($propsB)))->not->toContain(
            $this->entries['malformedStandalone']->id,
            $this->entries['malformedTicket']->id,
            $this->entries['dual']->id,
        );
});

it('agrees with the Overview, the report service and its own rows, per scope', function (string $kind) {
    $actor = $kind === 'all' ? $this->owner : $this->worker;
    $props = projectTimeProps($this->actingAs($actor)->get(route('projects.time.index', $this->projectA)));
    $overview = ProjectOverviewPresenter::overview($this->projectA->fresh(), $actor->fresh());
    $service = app(TimeEntryService::class)->totalMinutes([
        'project_id' => $this->projectA->id,
        'user_id' => $kind === 'own' ? $actor->id : null,
    ]);

    expect($props['summary']['totalMinutes'])->toBe($overview['time']['totalMinutes'])
        ->and($props['summary']['totalMinutes'])->toBe($service)
        ->and($props['summary']['scope'])->toBe($overview['time']['scope'])
        // One page holds every row here, so the rows sum to the total: rows and total are one set.
        ->and(array_sum(array_column($props['entries']['data'], 'durationMinutes')))->toBe($service)
        ->and($props['summary']['totalMinutes'])->toBe($kind === 'all' ? 31 : 1 + 2);
})->with(['all', 'own']);

it('orders entries by date then id, newest first', function () {
    $props = projectTimeProps($this->actingAs($this->owner)->get(route('projects.time.index', $this->projectA)));

    $rows = collect($props['entries']['data'])->map(fn (array $row) => [$row['date'], $row['id']])->all();
    $sorted = collect($rows)->sort(fn ($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]])->values()->all();

    expect($rows)->toBe($sorted)
        ->and($rows[0][0])->toBe('2026-09-04');
});

it('paginates at 25 with canonical links and a constant total', function () {
    TimeEntry::factory()->count(30)->create([
        'user_id' => $this->worker->id, 'project_id' => $this->projectA->id, 'date' => '2026-08-01',
        'duration_minutes' => 1024, 'timer_started_at' => null,
    ]);

    $first = projectTimeProps($this->actingAs($this->owner)->get(route('projects.time.index', $this->projectA).'?junk=1'));
    $second = projectTimeProps($this->actingAs($this->owner)->get(route('projects.time.index', [$this->projectA, 'page' => 2])));

    expect(count($first['entries']['data']))->toBe(25)
        ->and($first['entries']['total'])->toBe(35)
        ->and($first['entries']['last_page'])->toBe(2)
        ->and($first['entries']['next_page_url'])->not->toContain('junk')
        ->and(count($second['entries']['data']))->toBe(10)
        ->and($first['summary'])->toBe($second['summary'])
        ->and($first['summary']['totalMinutes'])->toBe(31 + 30 * 1024)
        ->and(array_intersect(projectTimeIds($first), projectTimeIds($second)))->toBe([]);
});

it('answers a page past the last with no rows but the real total, never a zero', function () {
    // The Time page decides "no time yet" from this total, so a stale `?page=99` must not look empty.
    $beyond = projectTimeProps($this->actingAs($this->owner)->get(route('projects.time.index', [$this->projectA, 'page' => 99]))->assertOk());
    $first = projectTimeProps($this->actingAs($this->owner)->get(route('projects.time.index', $this->projectA)));

    expect($beyond['entries']['data'])->toBe([])
        ->and($beyond['entries']['current_page'])->toBe(99)
        ->and($beyond['entries']['total'])->toBeGreaterThan(0)
        ->and($beyond['entries']['total'])->toBe($first['entries']['total'])
        ->and($beyond['summary'])->toBe($first['summary'])
        ->and($beyond['summary']['totalMinutes'])->toBeGreaterThan(0);
});

it('shows an empty project as an empty list with a zero total', function () {
    $empty = makeProject($this->owner, 'Empty');
    $props = projectTimeProps($this->actingAs($this->owner)->get(route('projects.time.index', $empty))->assertOk());

    expect($props['summary'])->toBe(['scope' => 'all', 'totalMinutes' => 0])
        ->and($props['entries']['data'])->toBe([])
        ->and($props['entries']['total'])->toBe(0);
});

it('writes nothing', function () {
    $before = TimeEntry::query()->orderBy('id')->get()->toArray();

    $this->actingAs($this->owner)->get(route('projects.time.index', $this->projectA))->assertOk();
    $this->actingAs($this->customer)->get(route('projects.time.index', $this->projectA))->assertOk();

    expect(TimeEntry::query()->orderBy('id')->get()->toArray())->toBe($before);
});

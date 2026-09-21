<?php

use App\Models\Project;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\TimeEntryBlock;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

it('renders the personal time page with filtered minimal entry data and a full filtered total', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $firstProject = app(ProjectService::class)->create($user, ['name' => 'First project']);
    $secondProject = app(ProjectService::class)->create($user, ['name' => 'Second project']);
    TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $firstProject->id,
        'duration_minutes' => 60,
        'description' => 'Included',
    ]);
    TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $secondProject->id,
        'duration_minutes' => 120,
        'description' => 'Excluded',
    ]);

    $this->actingAs($user)
        ->get(route('time.index', ['project_id' => $firstProject->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('time/index')
            ->where('filters.project_id', (string) $firstProject->id)
            ->where('totalMinutes', 60)
            ->has('entries.data', 1)
            ->where('entries.data.0.description', 'Included')
            ->where('entries.data.0.locked', false)
            ->missing('entries.data.0.user_id')
            ->missing('entries.data.0.invoice_id')
            ->has('projects', 2));
});

it('keeps personal totals independent of pagination', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    TimeEntry::factory()->count(26)->create([
        'user_id' => $user->id,
        'duration_minutes' => 30,
        'timer_started_at' => null,
    ]);

    $this->actingAs($user)
        ->get(route('time.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 25)
            ->where('totalMinutes', 780));
});

it('renders the allocation page with explicit user-scoped block data', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $other = User::factory()->create();
    $entry = TimeEntry::factory()->create([
        'user_id' => $user->id,
        'description' => 'Visible allocation',
    ]);
    $otherEntry = TimeEntry::factory()->create(['user_id' => $other->id]);
    TimeEntryBlock::create([
        'time_entry_id' => $entry->id,
        'user_id' => $user->id,
        'block_date' => today()->toDateString(),
        'block_number' => 40,
        'allocation_pct' => 100,
        'is_overridden' => false,
    ]);
    TimeEntryBlock::create([
        'time_entry_id' => $otherEntry->id,
        'user_id' => $other->id,
        'block_date' => today()->toDateString(),
        'block_number' => 40,
        'allocation_pct' => 100,
        'is_overridden' => false,
    ]);

    $this->actingAs($user)
        ->get(route('time.allocation'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('time/allocation')
            ->where('date', today()->toDateString())
            ->has('entries', 1)
            ->where('entries.0.description', 'Visible allocation')
            ->has('entries.0.blocks', 1)
            ->missing('entries.0.user_id'));
});

it('renders operator reports with normalized filters and totals across all pages', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    TimeEntry::factory()->count(51)->create([
        'user_id' => $operator->id,
        'duration_minutes' => 60,
        'billable' => true,
        'timer_started_at' => null,
    ]);
    TimeEntry::factory()->create([
        'user_id' => $operator->id,
        'duration_minutes' => 30,
        'billable' => false,
        'timer_started_at' => null,
    ]);

    $this->actingAs($operator)
        ->get(route('operator.time.index', ['billable' => '']))
        ->assertInertia(fn (Assert $page) => $page
            ->component('operator/time/index')
            ->where('filters.billable', null)
            ->has('entries.data', 50)
            ->where('totalMinutes', 3090));

    $this->actingAs($operator)
        ->get(route('operator.time.index', ['billable' => '0']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.billable', '0')
            ->has('entries.data', 1)
            ->where('totalMinutes', 30));
});

it('exports filtered RFC-compatible CSV and neutralizes spreadsheet formulas', function () {
    $operator = User::factory()->create(['name' => '=2+3']);
    $operator->assignRole('operator');
    $project = Project::factory()->create(['name' => '+SUM(A1:A2)']);
    TimeEntry::factory()->create([
        'user_id' => $operator->id,
        'project_id' => $project->id,
        'description' => "@command, with a comma\nand newline",
        'duration_minutes' => 60,
        'billable' => true,
        'timer_started_at' => null,
    ]);
    TimeEntry::factory()->create([
        'user_id' => $operator->id,
        'duration_minutes' => 30,
        'billable' => false,
        'timer_started_at' => null,
    ]);

    $response = $this->actingAs($operator)->get(route('operator.time.export', [
        'project_id' => $project->id,
        'billable' => '1',
    ]));

    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $csv = $response->getContent();

    expect($csv)->toContain("'=2+3")
        ->toContain("'+SUM(A1:A2)")
        ->toContain("'@command, with a comma\nand newline")
        ->and(substr_count($csv, "\n"))->toBeGreaterThanOrEqual(2);
});

it('orders operator report rows deterministically so pagination never repeats or skips rows', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $entries = TimeEntry::factory()->count(60)->create([
        'user_id' => $operator->id,
        'date' => today()->toDateString(),
        'duration_minutes' => 15,
        'timer_started_at' => null,
    ]);

    $firstPage = $this->actingAs($operator)
        ->get(route('operator.time.index'))
        ->viewData('page')['props']['entries']['data'];
    $secondPage = $this->actingAs($operator)
        ->get(route('operator.time.index', ['page' => 2]))
        ->viewData('page')['props']['entries']['data'];

    $ids = collect([...$firstPage, ...$secondPage])->pluck('id');

    expect($ids->all())->toBe($entries->pluck('id')->sortDesc()->values()->all());
});

it('always serializes every operator filter, including missing dates, as null', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)
        ->get(route('operator.time.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.user_id', null)
            ->where('filters.project_id', null)
            ->where('filters.from', null)
            ->where('filters.to', null)
            ->where('filters.billable', null));
});

it('round-trips the backend-only personal ticket filter through the applied filters', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $ticket = Ticket::factory()->open()->create(['user_id' => $user->id]);
    TimeEntry::factory()->create([
        'user_id' => $user->id,
        'ticket_id' => $ticket->id,
        'duration_minutes' => 45,
        'description' => 'On ticket',
    ]);
    TimeEntry::factory()->create([
        'user_id' => $user->id,
        'duration_minutes' => 90,
        'description' => 'Elsewhere',
    ]);

    $this->actingAs($user)
        ->get(route('time.index', ['ticket_id' => $ticket->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.ticket_id', (string) $ticket->id)
            ->where('totalMinutes', 45)
            ->has('entries.data', 1)
            ->where('entries.data.0.description', 'On ticket'));
});

it('excludes partially stopped legacy rows from totals until a stop normalizes them', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    TimeEntry::factory()->create(['user_id' => $user->id, 'duration_minutes' => 60]);
    $legacy = TimeEntry::factory()->running()->create([
        'user_id' => $user->id,
        'duration_minutes' => 20,
        'timer_started_at' => now()->subMinutes(30),
    ]);
    // Bypass model guardrails to reproduce the corrupt legacy shape.
    DB::table('time_entries')->where('id', $legacy->id)->update(['stopped_at' => now()->subMinutes(5)]);

    $this->actingAs($user)
        ->get(route('time.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 2)
            ->where('totalMinutes', 60));

    $this->actingAs($user)->postJson(route('time.timer.stop', $legacy))->assertOk();

    $this->actingAs($user)
        ->get(route('time.index'))
        ->assertInertia(fn (Assert $page) => $page->where('totalMinutes', 85));
});

it('offers only project options the caller is authorized to submit', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $member = app(ProjectService::class)->create($user, ['name' => 'Member project']);
    $abandoned = app(ProjectService::class)->create($user, ['name' => 'Abandoned project']);
    $abandoned->members()->detach($user->id);

    $labels = $this->actingAs($user)
        ->getJson(route('time.context.options', ['type' => 'project']))
        ->assertOk()
        ->json();

    expect(collect($labels)->pluck('label')->all())->toBe(['Member project']);

    $this->actingAs($user)->postJson(route('time.timer.start'), ['project_id' => $abandoned->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('project_id');
    $this->actingAs($user)->postJson(route('time.timer.start'), ['project_id' => $member->id])
        ->assertOk();
});

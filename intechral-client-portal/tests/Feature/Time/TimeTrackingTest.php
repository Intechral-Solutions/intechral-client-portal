<?php

use App\Models\TimeEntry;
use App\Models\TimeEntryBlock;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\TimeEntryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

afterEach(function () {
    Carbon::setTestNow();
});

// ── Guests ───────────────────────────────────────────────────────────────────

it('redirects guests from time index to login', function () {
    $this->get(route('time.index'))->assertRedirect('/login');
});

// ── Access control ────────────────────────────────────────────────────────────

it('allows users with time.log to access the time index', function () {
    $user = User::factory()->create();
    $user->assignRole('user'); // user role has time.log

    $this->actingAs($user)->get(route('time.index'))->assertOk();
});

it('returns 403 for users without time.log', function () {
    $user = User::factory()->create();
    // No role assigned, no permissions

    $this->actingAs($user)->get(route('time.index'))->assertForbidden();
});

it('returns 403 for users without time.view_all on operator report', function () {
    $user = User::factory()->create();
    $user->assignRole('user'); // has time.log and time.view_own, not time.view_all

    $this->actingAs($user)->get(route('operator.time.index'))->assertForbidden();
});

it('allows operators to view the time report', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)->get(route('operator.time.index'))->assertOk();
});

// ── Logging time ─────────────────────────────────────────────────────────────

it('logs a manual time entry', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->post(route('time.store'), [
        'date' => today()->format('Y-m-d'),
        'hours' => '2.5',
        'description' => 'Working on feature X',
        'billable' => '1',
    ])->assertRedirect();

    $entry = TimeEntry::where('user_id', $user->id)->first();
    expect($entry)->not->toBeNull();
    expect($entry->duration_minutes)->toBe(150);
    expect($entry->billable)->toBeTrue();
    expect($entry->billed)->toBeFalse();
});

it('converts decimal hours to minutes correctly', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->post(route('time.store'), [
        'date' => today()->format('Y-m-d'),
        'hours' => '1.25',
    ])->assertRedirect();

    expect(TimeEntry::where('user_id', $user->id)->first()->duration_minutes)->toBe(75);
});

it('validates date cannot be in the future', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->post(route('time.store'), [
        'date' => today()->addDay()->format('Y-m-d'),
        'hours' => '1',
    ])->assertSessionHasErrors('date');
});

it('validates hours is required and positive', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->post(route('time.store'), [
        'date' => today()->format('Y-m-d'),
        'hours' => '-1',
    ])->assertSessionHasErrors('hours');
});

it('links a time entry to a project', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $project = app(ProjectService::class)->create($operator, ['name' => 'Time Test Project']);

    $user = User::factory()->create();
    $user->assignRole('user');
    $project->members()->attach($user->id, ['role' => 'member']);

    $this->actingAs($user)->post(route('time.store'), [
        'date' => today()->format('Y-m-d'),
        'hours' => '3',
        'project_id' => $project->id,
    ])->assertRedirect();

    expect(TimeEntry::where('user_id', $user->id)->where('project_id', $project->id)->exists())->toBeTrue();
});

// ── Delete ────────────────────────────────────────────────────────────────────

it('allows a user to delete their own unbilled entry', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $entry = TimeEntry::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->delete(route('time.destroy', $entry))->assertRedirect();

    expect(TimeEntry::find($entry->id))->toBeNull();
});

it('returns 403 when deleting another user\'s entry', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $other = User::factory()->create();
    $other->assignRole('user');

    $entry = TimeEntry::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user)->delete(route('time.destroy', $entry))->assertForbidden();
});

it('returns 403 deleting a billed entry', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $entry = TimeEntry::factory()->billed()->create(['user_id' => $user->id]);

    $this->actingAs($user)->delete(route('time.destroy', $entry))->assertForbidden();
});

// ── Timer ─────────────────────────────────────────────────────────────────────

it('starts a timer and returns started_at', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)
        ->postJson(route('time.timer.start'), [])
        ->assertOk()
        ->assertJsonStructure(['id', 'started_at']);

    expect(TimeEntry::where('user_id', $user->id)->whereNotNull('timer_started_at')->exists())->toBeTrue();
});

it('allows multiple concurrent timers per user', function () {
    // startTimer() was updated (2026-03-27) to support the multi-timer block system.
    // Starting a second timer no longer stops the first — both run concurrently.
    $user = User::factory()->create();
    $user->assignRole('user');

    app(TimeEntryService::class)->startTimer($user, ['description' => 'First']);
    app(TimeEntryService::class)->startTimer($user, ['description' => 'Second']);

    $running = TimeEntry::where('user_id', $user->id)->whereNotNull('timer_started_at')->get();
    expect($running)->toHaveCount(2);
    expect($running->pluck('description')->all())->toContain('First', 'Second');
});

it('stops a timer and records elapsed minutes', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $entry = TimeEntry::factory()->running()->create([
        'user_id' => $user->id,
        'duration_minutes' => 0,
        'timer_started_at' => now()->subMinutes(30),
    ]);

    $this->actingAs($user)
        ->postJson(route('time.timer.stop', $entry))
        ->assertOk()
        ->assertJsonPath('duration_minutes', 30);

    expect($entry->fresh()->timer_started_at)->toBeNull();
    expect($entry->fresh()->duration_minutes)->toBe(30);
});

it('permanently stops a legacy timer whose elapsed duration exceeds smallint storage', function () {
    Carbon::setTestNow('2026-06-30 20:22:27');

    $user = User::factory()->create();
    $user->assignRole('user');

    $entry = TimeEntry::factory()->running()->create([
        'user_id' => $user->id,
        'date' => now()->subDays(95)->toDateString(),
        'duration_minutes' => 0,
        'timer_started_at' => now()->subDays(95),
        'stopped_at' => null,
    ]);

    $this->actingAs($user)
        ->getJson(route('time.timers.active'))
        ->assertOk()
        ->assertJsonFragment(['id' => $entry->id]);

    $this->actingAs($user)
        ->postJson(route('time.timer.stop', $entry))
        ->assertOk()
        ->assertJsonPath('duration_minutes', 136800);

    $entry->refresh();

    expect($entry->timer_started_at)->toBeNull()
        ->and($entry->stopped_at->equalTo(now()))->toBeTrue()
        ->and($entry->duration_minutes)->toBe(136800)
        ->and($entry->blocks()->count())->toBeGreaterThan(9000);

    $this->actingAs($user)
        ->getJson(route('time.timers.active'))
        ->assertOk()
        ->assertExactJson([]);
});

it('hides and safely normalizes a partially stopped legacy timer', function () {
    Carbon::setTestNow('2026-06-30 20:22:27');

    $user = User::factory()->create();
    $user->assignRole('user');

    $entry = TimeEntry::factory()->running()->create([
        'user_id' => $user->id,
        'duration_minutes' => 0,
        'timer_started_at' => now()->subMinutes(30),
    ]);
    $originalStop = now()->subMinutes(5);

    // Bypass model guardrails to reproduce a legacy/corrupt database row.
    DB::table('time_entries')->where('id', $entry->id)->update([
        'stopped_at' => $originalStop,
    ]);
    $entry->refresh();

    expect($entry->isRunning())->toBeFalse();

    $this->actingAs($user)
        ->getJson(route('time.timers.active'))
        ->assertOk()
        ->assertExactJson([]);

    $this->actingAs($user)
        ->postJson(route('time.timer.stop', $entry))
        ->assertOk()
        ->assertJsonPath('duration_minutes', 25);

    $entry->refresh();

    expect($entry->timer_started_at)->toBeNull()
        ->and($entry->stopped_at->equalTo($originalStop))->toBeTrue()
        ->and($entry->duration_minutes)->toBe(25);

    // A retry is a successful no-op and cannot add the duration twice.
    $this->actingAs($user)
        ->postJson(route('time.timer.stop', $entry))
        ->assertOk()
        ->assertJsonPath('duration_minutes', 25);
});

it('rejects impossible timer states through normal model writes', function () {
    expect(fn () => TimeEntry::factory()->create([
        'timer_started_at' => now()->subMinutes(30),
        'stopped_at' => now(),
    ]))->toThrow(LogicException::class)
        ->and(fn () => TimeEntry::factory()->create([
            'billed' => true,
            'timer_started_at' => now()->subMinutes(30),
            'stopped_at' => null,
        ]))->toThrow(LogicException::class);
});

it('rebalances finalized blocks for overlapping concurrent timers', function () {
    Carbon::setTestNow('2026-06-30 20:30:00');

    $user = User::factory()->create();

    $first = TimeEntry::factory()->running()->create([
        'user_id' => $user->id,
        'timer_started_at' => now()->subMinutes(30),
    ]);
    $second = TimeEntry::factory()->running()->create([
        'user_id' => $user->id,
        'timer_started_at' => now()->subMinutes(15),
    ]);

    $service = app(TimeEntryService::class);
    $service->stopTimer($first);
    $service->stopTimer($second);

    $blockNumber = (int) (now()->startOfDay()->diffInMinutes(now()->subMinutes(15)) / 15);
    $allocations = TimeEntryBlock::where('block_date', now()->toDateString())
        ->where('block_number', $blockNumber)
        ->whereIn('time_entry_id', [$first->id, $second->id])
        ->pluck('allocation_pct', 'time_entry_id');

    expect($allocations)->toHaveCount(2)
        ->and((float) $allocations[$first->id])->toBe(50.0)
        ->and((float) $allocations[$second->id])->toBe(50.0);
});

it('returns 403 stopping another user\'s timer', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $other = User::factory()->create();
    $other->assignRole('user');

    $entry = TimeEntry::factory()->running()->create(['user_id' => $other->id]);

    $this->actingAs($user)->postJson(route('time.timer.stop', $entry))->assertForbidden();
});

// ── Service helpers ───────────────────────────────────────────────────────────

it('calculates effective duration including live elapsed time', function () {
    $entry = TimeEntry::factory()->make([
        'duration_minutes' => 10,
        'timer_started_at' => now()->subMinutes(20),
    ]);

    // 10 stored + 20 live = 30 effective
    expect($entry->effectiveDurationMinutes())->toBeGreaterThanOrEqual(30);
});

it('durationForHumans returns correct format', function () {
    $entry = TimeEntry::factory()->make(['duration_minutes' => 90, 'timer_started_at' => null]);
    expect($entry->durationForHumans())->toBe('1h 30m');

    $entry2 = TimeEntry::factory()->make(['duration_minutes' => 60, 'timer_started_at' => null]);
    expect($entry2->durationForHumans())->toBe('1h');

    $entry3 = TimeEntry::factory()->make(['duration_minutes' => 45, 'timer_started_at' => null]);
    expect($entry3->durationForHumans())->toBe('45m');
});

// ── Operator report ───────────────────────────────────────────────────────────

it('shows all entries on the operator time report', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    TimeEntry::factory()->count(3)->create(['user_id' => $operator->id]);

    $this->actingAs($operator)
        ->get(route('operator.time.index'))
        ->assertOk();
});

it('exports time entries as CSV', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    TimeEntry::factory()->count(2)->create(['user_id' => $operator->id]);

    $response = $this->actingAs($operator)->get(route('operator.time.export'));
    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});

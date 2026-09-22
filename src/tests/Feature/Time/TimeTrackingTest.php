<?php

use App\Models\Task;
use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\TimeEntryBlock;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\TimeEntryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

it('rejects inaccessible and multiple time contexts', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $other = User::factory()->create();
    $other->assignRole('user');
    $project = app(ProjectService::class)->create($other, ['name' => 'Private project']);
    $ticket = Ticket::factory()->open()->create(['user_id' => $other->id]);

    $this->actingAs($user)->post(route('time.store'), [
        'date' => today()->toDateString(),
        'hours' => 1,
        'project_id' => $project->id,
    ])->assertSessionHasErrors('project_id');

    $this->actingAs($user)->postJson(route('time.timer.start'), [
        'project_id' => $project->id,
        'ticket_id' => $ticket->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['project_id', 'ticket_id']);

    expect(TimeEntry::where('user_id', $user->id)->exists())->toBeFalse();
});

it('allows authorized embedded task and ticket timer contexts', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $other = User::factory()->create();
    $project = app(ProjectService::class)->create($other, ['name' => 'Shared project']);
    $project->members()->attach($user->id, ['role' => 'member']);
    $task = Task::create([
        'project_id' => $project->id,
        'column_id' => $project->columns()->firstOrFail()->id,
        'assignee_id' => $other->id,
        'created_by' => $other->id,
        'title' => 'Shared task',
        'priority' => 'medium',
        'position' => 0,
        'status' => 'todo',
    ]);
    $ticket = Ticket::factory()->open()->create([
        'user_id' => $user->id,
        'assignee_id' => null,
    ]);

    $this->actingAs($user)
        ->postJson(route('time.timer.start'), ['task_id' => $task->id])
        ->assertOk()
        ->assertJsonPath('context.type', 'Task');

    $this->actingAs($user)
        ->postJson(route('time.timer.start'), ['ticket_id' => $ticket->id])
        ->assertOk()
        ->assertJsonPath('context.type', 'Ticket');
});

it('identifies the context record by id on every timer context DTO', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $other = User::factory()->create();
    $project = app(ProjectService::class)->create($other, ['name' => 'Shared project']);
    $project->members()->attach($user->id, ['role' => 'member']);
    $task = Task::create([
        'project_id' => $project->id,
        'column_id' => $project->columns()->firstOrFail()->id,
        'assignee_id' => $other->id,
        'created_by' => $other->id,
        'title' => 'Shared task',
        'priority' => 'medium',
        'position' => 0,
        'status' => 'todo',
    ]);
    $ticket = Ticket::factory()->open()->create(['user_id' => $user->id, 'assignee_id' => null]);

    $this->actingAs($user)
        ->postJson(route('time.timer.start'), ['project_id' => $project->id])
        ->assertOk()
        ->assertJsonPath('context.type', 'Project')
        ->assertJsonPath('context.id', $project->id);
    $this->actingAs($user)
        ->postJson(route('time.timer.start'), ['task_id' => $task->id])
        ->assertOk()
        ->assertJsonPath('context.type', 'Task')
        ->assertJsonPath('context.id', $task->id);
    $this->actingAs($user)
        ->postJson(route('time.timer.start'), ['ticket_id' => $ticket->id])
        ->assertOk()
        ->assertJsonPath('context.type', 'Ticket')
        ->assertJsonPath('context.id', $ticket->id);

    $contexts = collect($this->actingAs($user)->getJson(route('time.timers.active'))->assertOk()->json())
        ->pluck('context')
        ->mapWithKeys(fn (array $context) => [$context['type'] => $context['id']]);

    expect($contexts->all())->toBe([
        'Project' => $project->id,
        'Task' => $task->id,
        'Ticket' => $ticket->id,
    ]);
});

it('identifies the context record by id on allocation entries and leaves context-free entries null', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $project = app(ProjectService::class)->create($user, ['name' => 'Own project']);
    $withContext = TimeEntry::factory()->create(['user_id' => $user->id, 'project_id' => $project->id]);
    $withoutContext = TimeEntry::factory()->create(['user_id' => $user->id, 'project_id' => null]);

    foreach ([[$withContext, 40], [$withoutContext, 41]] as [$entry, $number]) {
        TimeEntryBlock::create([
            'time_entry_id' => $entry->id,
            'user_id' => $user->id,
            'block_date' => today()->toDateString(),
            'block_number' => $number,
            'allocation_pct' => 100,
            'is_overridden' => false,
        ]);
    }

    $this->actingAs($user)
        ->get(route('time.allocation'))
        ->assertInertia(fn ($page) => $page
            ->where('entries.0.context.type', 'Project')
            ->where('entries.0.context.id', $project->id)
            ->where('entries.1.context', null));
});

it('clears nullable fields and prior context when updating an entry', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $project = app(ProjectService::class)->create($user, ['name' => 'Clearable project']);
    $entry = TimeEntry::factory()->create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'description' => 'Remove me',
    ]);

    $this->actingAs($user)->put(route('time.update', $entry), [
        'date' => today()->toDateString(),
        'hours' => 1,
        'project_id' => null,
        'task_id' => null,
        'ticket_id' => null,
        'description' => null,
        'billable' => false,
    ])->assertRedirect();

    $entry->refresh();

    expect($entry->project_id)->toBeNull()
        ->and($entry->task_id)->toBeNull()
        ->and($entry->ticket_id)->toBeNull()
        ->and($entry->description)->toBeNull()
        ->and($entry->billable)->toBeFalse();
});

// ── Create vs edit duration contract ─────────────────────────────────────────

it('keeps the 15-minute minimum for new manual entries', function (string $hours) {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->post(route('time.store'), [
        'date' => today()->toDateString(),
        'hours' => $hours,
    ])->assertSessionHasErrors('hours');

    expect(TimeEntry::where('user_id', $user->id)->exists())->toBeFalse();
})->with(['seven minutes' => '0.12', 'just under quarter hour' => '0.24', 'zero' => '0', 'negative' => '-0.5']);

it('still accepts normal quarter-hour manual entries', function (string $hours, int $minutes) {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->post(route('time.store'), [
        'date' => today()->toDateString(),
        'hours' => $hours,
    ])->assertSessionHasNoErrors();

    expect(TimeEntry::where('user_id', $user->id)->value('duration_minutes'))->toBe($minutes);
})->with([['0.25', 15], ['1.5', 90], ['24', 1440]]);

it('edits a legitimate short timer-derived entry without rounding it up to 15 minutes', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $entry = TimeEntry::factory()->create([
        'user_id' => $user->id,
        'duration_minutes' => 7,
        'description' => 'Short timer',
        'timer_started_at' => null,
        'stopped_at' => now(),
    ]);

    // The edit form seeds hours as round(7 / 60, 2) = 0.12.
    $this->actingAs($user)->put(route('time.update', $entry), [
        'date' => today()->toDateString(),
        'hours' => 0.12,
        'description' => 'Renamed short timer',
        'billable' => true,
    ])->assertSessionHasNoErrors();

    expect($entry->fresh()->duration_minutes)->toBe(7)
        ->and($entry->fresh()->description)->toBe('Renamed short timer');
});

it('round-trips every whole-minute duration through the two-decimal hours the page serializes', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $entry = TimeEntry::factory()->create(['user_id' => $user->id, 'duration_minutes' => 30]);

    foreach ([1, 2, 7, 14, 15, 50, 59, 61, 133, 599, 1440] as $minutes) {
        $entry->forceFill(['duration_minutes' => $minutes])->save();
        $hours = round($minutes / 60, 2);

        $this->actingAs($user)->put(route('time.update', $entry), [
            'date' => today()->toDateString(),
            'hours' => $hours,
        ])->assertSessionHasNoErrors();

        expect($entry->fresh()->duration_minutes)->toBe($minutes);
    }
});

it('never lets an accepted edit round to zero minutes and rejects zero, negative, and oversized hours', function (mixed $hours, bool $accepted) {
    $user = User::factory()->create();
    $user->assignRole('user');
    $entry = TimeEntry::factory()->create(['user_id' => $user->id, 'duration_minutes' => 30]);

    $response = $this->actingAs($user)->put(route('time.update', $entry), [
        'date' => today()->toDateString(),
        'hours' => $hours,
    ]);

    if ($accepted) {
        $response->assertSessionHasNoErrors();
        expect($entry->fresh()->duration_minutes)->toBeGreaterThanOrEqual(1);
    } else {
        $response->assertSessionHasErrors('hours');
        expect($entry->fresh()->duration_minutes)->toBe(30);
    }
})->with([
    'zero' => [0, false],
    'negative' => [-1, false],
    'rounds to zero minutes' => [0.005, false],
    'above a day' => [24.01, false],
    'smallest accepted hundredth' => [0.01, true],
    'one minute' => [0.02, true],
    'full day' => [24, true],
]);

it('rejects a sub-minute duration at the service boundary too', function () {
    $user = User::factory()->create();
    $entry = TimeEntry::factory()->create(['user_id' => $user->id, 'duration_minutes' => 30]);

    expect(fn () => app(TimeEntryService::class)->update($entry, ['hours' => 0.004]))
        ->toThrow(ValidationException::class)
        ->and($entry->fresh()->duration_minutes)->toBe(30);
});

it('keeps billing locks in force for short entries regardless of duration', function (string $state) {
    $user = User::factory()->create();
    $user->assignRole('user');
    $entry = TimeEntry::factory()->{$state}()->create([
        'user_id' => $user->id,
        'duration_minutes' => 7,
        'description' => 'Locked short entry',
    ]);

    $this->actingAs($user)->put(route('time.update', $entry), [
        'date' => today()->toDateString(),
        'hours' => 0.12,
        'description' => 'Forbidden',
    ])->assertSessionHasErrors(['time_entry' => TimeEntry::BILLING_LOCK_MESSAGE]);

    expect($entry->fresh()->description)->toBe('Locked short entry')
        ->and($entry->fresh()->duration_minutes)->toBe(7);
})->with(['billed', 'invoiced']);

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

it('returns the billing lock message when deleting a billed entry', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $entry = TimeEntry::factory()->billed()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete(route('time.destroy', $entry))
        ->assertSessionHasErrors([
            'time_entry' => TimeEntry::BILLING_LOCK_MESSAGE,
        ]);
});

// ── Timer ─────────────────────────────────────────────────────────────────────

it('starts a timer and returns started_at', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)
        ->postJson(route('time.timer.start'), [])
        ->assertOk()
        ->assertJsonStructure(['id', 'started_at', 'server_now', 'description', 'context'])
        ->assertJsonMissingPath('user');

    expect(TimeEntry::where('user_id', $user->id)->whereNotNull('timer_started_at')->exists())->toBeTrue();
});

it('returns minimal ordered active timer DTOs with server time', function () {
    Carbon::setTestNow('2026-09-20 12:00:00');
    $user = User::factory()->create();
    $user->assignRole('user');
    $later = TimeEntry::factory()->running()->create([
        'user_id' => $user->id,
        'timer_started_at' => now()->subMinutes(5),
    ]);
    $earlier = TimeEntry::factory()->running()->create([
        'user_id' => $user->id,
        'timer_started_at' => now()->subMinutes(10),
    ]);

    $this->actingAs($user)
        ->getJson(route('time.timers.active'))
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.id', $earlier->id)
        ->assertJsonPath('1.id', $later->id)
        ->assertJsonPath('0.server_now', now()->toISOString())
        ->assertJsonStructure([
            '*' => ['id', 'started_at', 'server_now', 'description', 'context'],
        ])
        ->assertJsonMissingPath('0.user_id')
        ->assertJsonMissingPath('0.billable');
});

it('returns the canonical description after updating a running timer', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $entry = TimeEntry::factory()->running()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patchJson(route('time.timer.description', $entry), ['description' => 'Updated timer'])
        ->assertOk()
        ->assertExactJson([
            'id' => $entry->id,
            'description' => 'Updated timer',
        ]);
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
    Carbon::setTestNow('2026-06-30 20:22:27');

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

it('rounds any partial minute up to the next full minute when stopping a timer', function () {
    Carbon::setTestNow('2026-06-30 20:22:27');

    $user = User::factory()->create();
    $user->assignRole('user');

    $oneSecondEntry = TimeEntry::factory()->running()->create([
        'user_id' => $user->id,
        'duration_minutes' => 0,
        'timer_started_at' => now()->subSecond(),
    ]);

    $this->actingAs($user)
        ->postJson(route('time.timer.stop', $oneSecondEntry))
        ->assertOk()
        ->assertJsonPath('duration_minutes', 1);

    $overOneMinuteEntry = TimeEntry::factory()->running()->create([
        'user_id' => $user->id,
        'duration_minutes' => 0,
        'timer_started_at' => now()->subMinute()->subSecond(),
    ]);

    $this->actingAs($user)
        ->postJson(route('time.timer.stop', $overOneMinuteEntry))
        ->assertOk()
        ->assertJsonPath('duration_minutes', 2);

    $exactlyOneMinuteEntry = TimeEntry::factory()->running()->create([
        'user_id' => $user->id,
        'duration_minutes' => 0,
        'timer_started_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)
        ->postJson(route('time.timer.stop', $exactlyOneMinuteEntry))
        ->assertOk()
        ->assertJsonPath('duration_minutes', 1);
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

it('returns the authoritative whole slot after one allocation adjustment', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $first = TimeEntry::factory()->create(['user_id' => $user->id]);
    $second = TimeEntry::factory()->create(['user_id' => $user->id]);
    $firstBlock = TimeEntryBlock::create([
        'time_entry_id' => $first->id,
        'user_id' => $user->id,
        'block_date' => today()->toDateString(),
        'block_number' => 20,
        'allocation_pct' => 50,
        'is_overridden' => false,
    ]);
    TimeEntryBlock::create([
        'time_entry_id' => $second->id,
        'user_id' => $user->id,
        'block_date' => today()->toDateString(),
        'block_number' => 20,
        'allocation_pct' => 50,
        'is_overridden' => false,
    ]);

    $response = $this->actingAs($user)
        ->patchJson(route('time.blocks.allocation', $firstBlock), ['allocation_pct' => 75])
        ->assertOk()
        ->assertJsonPath('slot.block_number', 20)
        ->assertJsonCount(2, 'slot.blocks');

    expect((float) collect($response->json('slot.blocks'))->sum('allocation_pct'))->toBe(100.0)
        ->and((float) $firstBlock->fresh()->allocation_pct)->toBe(75.0)
        ->and((float) TimeEntryBlock::where('time_entry_id', $second->id)->value('allocation_pct'))->toBe(25.0);
});

it('rejects a non-100 allocation for a single-entry slot', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $entry = TimeEntry::factory()->create(['user_id' => $user->id]);
    $block = TimeEntryBlock::create([
        'time_entry_id' => $entry->id,
        'user_id' => $user->id,
        'block_date' => today()->toDateString(),
        'block_number' => 21,
        'allocation_pct' => 100,
        'is_overridden' => false,
    ]);

    $this->actingAs($user)
        ->patchJson(route('time.blocks.allocation', $block), ['allocation_pct' => 50])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('allocation_pct');

    expect((float) $block->fresh()->allocation_pct)->toBe(100.0);
});

it('returns 403 stopping another user\'s timer', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $other = User::factory()->create();
    $other->assignRole('user');

    $entry = TimeEntry::factory()->running()->create(['user_id' => $other->id]);

    $this->actingAs($user)->postJson(route('time.timer.stop', $entry))->assertForbidden();
});

// ── Ownership boundaries (routes lacking React-independent coverage) ─────────

it('rejects updating another user\'s entry without mutating it', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $other = User::factory()->create();
    $other->assignRole('user');
    $entry = TimeEntry::factory()->create([
        'user_id' => $other->id,
        'duration_minutes' => 60,
        'description' => 'Untouched',
        'billable' => true,
    ]);
    $before = $entry->fresh()->getAttributes();

    $this->actingAs($user)->put(route('time.update', $entry), [
        'date' => today()->toDateString(),
        'hours' => 5,
        'description' => 'Hijacked',
        'billable' => false,
    ])->assertForbidden();

    expect($entry->fresh()->getAttributes())->toBe($before);
});

it('rejects editing another user\'s timer description without mutating it', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $other = User::factory()->create();
    $other->assignRole('user');
    $entry = TimeEntry::factory()->running()->create([
        'user_id' => $other->id,
        'description' => 'Untouched',
    ]);

    $this->actingAs($user)
        ->patchJson(route('time.timer.description', $entry), ['description' => 'Hijacked'])
        ->assertForbidden();

    expect($entry->fresh()->description)->toBe('Untouched')
        ->and($entry->fresh()->isRunning())->toBeTrue();
});

it('rejects adjusting another user\'s allocation block without mutating the slot', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $other = User::factory()->create();
    $other->assignRole('user');
    $first = TimeEntry::factory()->create(['user_id' => $other->id]);
    $second = TimeEntry::factory()->create(['user_id' => $other->id]);
    $blocks = collect([$first, $second])->map(fn (TimeEntry $entry) => TimeEntryBlock::create([
        'time_entry_id' => $entry->id,
        'user_id' => $other->id,
        'block_date' => today()->toDateString(),
        'block_number' => 30,
        'allocation_pct' => 50,
        'is_overridden' => false,
    ]));

    $this->actingAs($user)
        ->patchJson(route('time.blocks.allocation', $blocks[0]), ['allocation_pct' => 90])
        ->assertForbidden();

    expect($blocks->map(fn (TimeEntryBlock $block) => (float) $block->fresh()->allocation_pct)->all())
        ->toBe([50.0, 50.0])
        ->and($blocks->every(fn (TimeEntryBlock $block) => ! $block->fresh()->is_overridden))->toBeTrue();
});

it('rejects an allocation block owned by the caller when its time entry belongs to someone else', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $other = User::factory()->create();
    $other->assignRole('user');
    $foreignEntry = TimeEntry::factory()->create(['user_id' => $other->id]);
    $ownEntry = TimeEntry::factory()->create(['user_id' => $user->id]);
    $mismatched = TimeEntryBlock::create([
        'time_entry_id' => $foreignEntry->id,
        'user_id' => $user->id,
        'block_date' => today()->toDateString(),
        'block_number' => 31,
        'allocation_pct' => 50,
        'is_overridden' => false,
    ]);
    $sibling = TimeEntryBlock::create([
        'time_entry_id' => $ownEntry->id,
        'user_id' => $user->id,
        'block_date' => today()->toDateString(),
        'block_number' => 31,
        'allocation_pct' => 50,
        'is_overridden' => false,
    ]);

    $this->actingAs($user)
        ->patchJson(route('time.blocks.allocation', $mismatched), ['allocation_pct' => 90])
        ->assertForbidden();

    expect((float) $mismatched->fresh()->allocation_pct)->toBe(50.0)
        ->and((float) $sibling->fresh()->allocation_pct)->toBe(50.0);
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

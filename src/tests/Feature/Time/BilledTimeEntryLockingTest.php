<?php

use App\Models\TimeEntry;
use App\Models\TimeEntryBlock;
use App\Models\User;
use App\Services\TimeEntryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

it('centralizes billing lock state for billed and invoiced entries', function () {
    $unlocked = TimeEntry::factory()->create();
    $billed = TimeEntry::factory()->billed()->create();
    $invoiced = TimeEntry::factory()->invoiced()->create();

    expect($unlocked->isLockedForBilling())->toBeFalse()
        ->and($billed->isLockedForBilling())->toBeTrue()
        ->and($invoiced->isLockedForBilling())->toBeTrue();
});

it('preserves update and delete behavior for unlocked entries', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $entry = TimeEntry::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->put(route('time.update', $entry), [
        'date' => today()->toDateString(),
        'hours' => 2,
        'description' => 'Updated unlocked entry',
        'billable' => true,
    ])->assertRedirect();

    expect($entry->fresh()->duration_minutes)->toBe(120)
        ->and($entry->fresh()->description)->toBe('Updated unlocked entry');

    $this->actingAs($user)
        ->delete(route('time.destroy', $entry))
        ->assertRedirect();

    expect(TimeEntry::find($entry->id))->toBeNull();
});

it('rejects updates to billed entries', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $entry = TimeEntry::factory()->billed()->create([
        'user_id' => $user->id,
        'description' => 'Original billed entry',
    ]);

    $this->actingAs($user)->put(route('time.update', $entry), [
        'date' => today()->toDateString(),
        'hours' => 2,
        'description' => 'Forbidden update',
    ])->assertSessionHasErrors([
        'time_entry' => TimeEntry::BILLING_LOCK_MESSAGE,
    ]);

    expect($entry->fresh()->description)->toBe('Original billed entry');
});

it('rejects updates to invoice-linked entries even when billed is false', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $entry = TimeEntry::factory()->invoiced()->create([
        'user_id' => $user->id,
        'description' => 'Original invoiced entry',
    ]);

    $this->actingAs($user)->put(route('time.update', $entry), [
        'date' => today()->toDateString(),
        'hours' => 2,
        'description' => 'Forbidden update',
    ])->assertSessionHasErrors([
        'time_entry' => TimeEntry::BILLING_LOCK_MESSAGE,
    ]);

    expect($entry->fresh()->description)->toBe('Original invoiced entry')
        ->and($entry->fresh()->billed)->toBeFalse();
});

it('rejects deletion of billed and invoice-linked entries', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $billed = TimeEntry::factory()->billed()->create(['user_id' => $user->id]);
    $invoiced = TimeEntry::factory()->invoiced()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete(route('time.destroy', $billed))
        ->assertSessionHasErrors('time_entry');

    $this->actingAs($user)
        ->delete(route('time.destroy', $invoiced))
        ->assertSessionHasErrors('time_entry');

    expect(TimeEntry::find($billed->id))->not->toBeNull()
        ->and(TimeEntry::find($invoiced->id))->not->toBeNull();
});

it('keeps locked entries visible to their owner and in operator reports', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    TimeEntry::factory()->invoiced()->create([
        'user_id' => $user->id,
        'description' => 'Visible locked entry',
    ]);

    $this->actingAs($user)
        ->get(route('time.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.data.0.description', 'Visible locked entry')
            ->where('entries.data.0.locked', true));

    $this->actingAs($operator)
        ->get(route('operator.time.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('entries.data.0.description', 'Visible locked entry')
            ->where('entries.data.0.locked', true));
});

it('rejects description and stop mutations for an inconsistent locked running timer', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $entry = TimeEntry::factory()->running()->create([
        'user_id' => $user->id,
        'description' => 'Locked running entry',
    ]);

    // Bypass model guards to represent inconsistent legacy data.
    DB::table('time_entries')->where('id', $entry->id)->update(['billed' => true]);

    $this->actingAs($user)
        ->patchJson(route('time.timer.description', $entry), [
            'description' => 'Forbidden description',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('time_entry');

    $this->actingAs($user)
        ->postJson(route('time.timer.stop', $entry))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('time_entry');

    $entry->refresh();

    expect($entry->description)->toBe('Locked running entry')
        ->and($entry->timer_started_at)->not->toBeNull();
});

it('rejects allocation changes when the target or any affected sibling is locked', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $lockedEntry = TimeEntry::factory()->billed()->create(['user_id' => $user->id]);
    $unlockedEntry = TimeEntry::factory()->create(['user_id' => $user->id]);

    $lockedBlock = TimeEntryBlock::create([
        'time_entry_id' => $lockedEntry->id,
        'user_id' => $user->id,
        'block_date' => today()->toDateString(),
        'block_number' => 40,
        'allocation_pct' => 50,
        'is_overridden' => false,
    ]);
    $unlockedBlock = TimeEntryBlock::create([
        'time_entry_id' => $unlockedEntry->id,
        'user_id' => $user->id,
        'block_date' => today()->toDateString(),
        'block_number' => 40,
        'allocation_pct' => 50,
        'is_overridden' => false,
    ]);

    $this->actingAs($user)
        ->patchJson(route('time.blocks.allocation', $lockedBlock), ['allocation_pct' => 75])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('time_entry');

    $this->actingAs($user)
        ->patchJson(route('time.blocks.allocation', $unlockedBlock), ['allocation_pct' => 75])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('time_entry');

    expect((float) $lockedBlock->fresh()->allocation_pct)->toBe(50.0)
        ->and((float) $unlockedBlock->fresh()->allocation_pct)->toBe(50.0);
});

it('does not let operators bypass billing locks through ordinary mutation routes', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $entry = TimeEntry::factory()->billed()->create([
        'user_id' => $operator->id,
        'description' => 'Operator locked entry',
    ]);

    $this->actingAs($operator)->put(route('time.update', $entry), [
        'date' => today()->toDateString(),
        'hours' => 2,
        'description' => 'Operator bypass attempt',
    ])->assertSessionHasErrors('time_entry');

    $this->actingAs($operator)
        ->delete(route('time.destroy', $entry))
        ->assertSessionHasErrors('time_entry');

    expect($entry->fresh()->description)->toBe('Operator locked entry');
});

it('rechecks persisted lock state instead of trusting a stale model', function () {
    $entry = TimeEntry::factory()->create(['description' => 'Persisted lock']);

    DB::table('time_entries')->where('id', $entry->id)->update(['billed' => true]);

    expect(fn () => app(TimeEntryService::class)->update($entry, [
        'description' => 'Stale model bypass attempt',
    ]))->toThrow(ValidationException::class)
        ->and($entry->fresh()->description)->toBe('Persisted lock');
});

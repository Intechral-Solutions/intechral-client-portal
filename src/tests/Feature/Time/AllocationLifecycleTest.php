<?php

use App\Models\Invoice;
use App\Models\TimeEntry;
use App\Models\TimeEntryBlock;
use App\Models\User;
use App\Services\TimeEntryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/AllocationHelpers.php';

/*
 * Lifecycle rules for a slot's allocation:
 *
 *  - Deleting an entry rebalances every slot it occupied.
 *  - is_overridden records a user's explicit choice for the CURRENT set of entries in the
 *    slot. It is sticky while membership is unchanged, and any structural change (an entry
 *    joining or leaving) clears it and recomputes the mutable blocks by seconds in the slot.
 *  - Blocks of billed or invoice-linked entries are immutable history: never moved, and the
 *    mutable entries share only what remains (possibly nothing).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

afterEach(function () {
    Carbon::setTestNow();
});

function deleteEntry(TimeEntry $entry): void
{
    app(TimeEntryService::class)->delete($entry);
}

function adjust(TimeEntry $entry, float $pct, int $slot = SLOT_NINE): void
{
    app(TimeEntryService::class)->updateBlockAllocation(blockOf($entry, $slot), $pct);
}

// ── Deletion rebalances ──────────────────────────────────────────────────────

it('gives the survivor the whole slot when one of two entries is deleted', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $first = finalizedTimer($user, '09:01:00', '09:02:00');
    $second = finalizedTimer($user, '09:05:00', '09:06:00');
    expect(slotAllocations($user))->toBe([$first->id => 50.0, $second->id => 50.0]);

    $this->actingAs($user)->delete(route('time.destroy', $first))->assertRedirect();

    expect(slotAllocations($user))->toBe([$second->id => 100.0])
        ->and(TimeEntry::find($first->id))->toBeNull();
});

it('redistributes the survivors of a three-entry slot to exactly 100', function () {
    $user = User::factory()->create();
    $first = finalizedTimer($user, '09:01:00', '09:02:00');
    $second = finalizedTimer($user, '09:05:00', '09:06:00');
    $third = finalizedTimer($user, '09:10:00', '09:11:00');
    expect(slotAllocations($user))->toBe([$first->id => 33.33, $second->id => 33.33, $third->id => 33.34]);

    deleteEntry($second);

    expect(slotAllocations($user))->toBe([$first->id => 50.0, $third->id => 50.0])
        ->and(slotTotal($user))->toBe(100.0);
});

it('keeps deterministic seconds weighting for the survivors', function () {
    $user = User::factory()->create();
    $long = finalizedTimer($user, '09:00:00', '09:03:00');
    $short = finalizedTimer($user, '09:05:00', '09:06:00');
    $other = finalizedTimer($user, '09:10:00', '09:11:00');
    expect(slotAllocations($user))->toBe([$long->id => 60.0, $short->id => 20.0, $other->id => 20.0]);

    deleteEntry($other);

    expect(slotAllocations($user))->toBe([$long->id => 75.0, $short->id => 25.0]);
});

it('rebalances every slot the deleted entry occupied', function () {
    $user = User::factory()->create();
    $shareSlot36 = finalizedTimer($user, '09:05:00', '09:06:00');
    $shareSlot37 = finalizedTimer($user, '09:16:00', '09:17:00');
    $long = finalizedTimer($user, '08:50:00', '09:20:00');
    expect(slotAllocations($user, 35))->toBe([$long->id => 100.0])
        ->and(slotTotal($user, SLOT_NINE))->toBe(100.0)
        ->and(slotTotal($user, 37))->toBe(100.0);

    deleteEntry($long);

    expect(slotAllocations($user, 35))->toBe([])
        ->and(slotAllocations($user, SLOT_NINE))->toBe([$shareSlot36->id => 100.0])
        ->and(slotAllocations($user, 37))->toBe([$shareSlot37->id => 100.0]);
});

it('deletes the only entry of a slot without error and leaves no slot behind', function () {
    $user = User::factory()->create();
    $only = finalizedTimer($user, '09:01:00', '09:02:00');

    deleteEntry($only);

    expect(TimeEntry::find($only->id))->toBeNull()
        ->and(TimeEntryBlock::where('user_id', $user->id)->count())->toBe(0);
});

it('still deletes entries that have no allocation blocks', function () {
    $user = User::factory()->create();
    $manual = app(TimeEntryService::class)->log($user, ['date' => SLOT_DATE, 'hours' => 1]);
    $timer = finalizedTimer($user, '09:01:00', '09:02:00');

    deleteEntry($manual);

    expect(TimeEntry::find($manual->id))->toBeNull()
        ->and(slotAllocations($user))->toBe([$timer->id => 100.0]);
});

it('never changes another user\'s blocks when an entry is deleted', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $theirEntry = finalizedTimer($theirs, '09:01:00', '09:02:00');
    $theirOther = finalizedTimer($theirs, '09:05:00', '09:06:00');
    $before = TimeEntryBlock::where('user_id', $theirs->id)->orderBy('id')->get(['id', 'allocation_pct', 'is_overridden', 'updated_at'])->toArray();
    $first = finalizedTimer($mine, '09:01:00', '09:02:00');
    finalizedTimer($mine, '09:05:00', '09:06:00');

    deleteEntry($first);

    expect(TimeEntryBlock::where('user_id', $theirs->id)->orderBy('id')->get(['id', 'allocation_pct', 'is_overridden', 'updated_at'])->toArray())->toBe($before)
        ->and(slotAllocations($theirs))->toBe([$theirEntry->id => 50.0, $theirOther->id => 50.0])
        ->and(slotTotal($mine))->toBe(100.0);
});

it('blocks deleting a billed or invoice-linked entry and leaves its slot untouched', function (string $lock) {
    $user = User::factory()->create();
    $user->assignRole('user');
    $target = finalizedTimer($user, '09:01:00', '09:02:00');
    $sibling = finalizedTimer($user, '09:05:00', '09:06:00');
    $target->forceFill($lock === 'billed' ? ['billed' => true] : ['invoice_id' => Invoice::factory()->create()->id])->save();

    $this->actingAs($user)->delete(route('time.destroy', $target))
        ->assertSessionHasErrors(['time_entry' => TimeEntry::BILLING_LOCK_MESSAGE]);
    expect(fn () => deleteEntry($target))->toThrow(ValidationException::class);

    expect(TimeEntry::find($target->id))->not->toBeNull()
        ->and(slotAllocations($user))->toBe([$target->id => 50.0, $sibling->id => 50.0]);
})->with(['billed', 'invoice-linked']);

it('never moves a surviving billed or invoice-linked block when a sibling is deleted', function (string $lock) {
    $user = User::factory()->create();
    $locked = finalizedTimer($user, '09:01:00', '09:02:00');
    $open = finalizedTimer($user, '09:05:00', '09:06:00');
    $leaving = finalizedTimer($user, '09:10:00', '09:11:00');
    TimeEntryBlock::where('time_entry_id', $locked->id)->update(['allocation_pct' => 60]);
    TimeEntryBlock::where('time_entry_id', $open->id)->update(['allocation_pct' => 20]);
    TimeEntryBlock::where('time_entry_id', $leaving->id)->update(['allocation_pct' => 20]);
    $locked->forceFill($lock === 'billed' ? ['billed' => true] : ['invoice_id' => Invoice::factory()->create()->id])->save();
    $snapshot = blockOf($locked)->only(['allocation_pct', 'is_overridden', 'updated_at']);

    Carbon::setTestNow('2026-06-30 12:00:00');
    deleteEntry($leaving);

    expect(blockOf($locked)->only(['allocation_pct', 'is_overridden', 'updated_at']))->toEqual($snapshot)
        ->and(slotAllocations($user))->toBe([$locked->id => 60.0, $open->id => 40.0]);
})->with(['billed', 'invoice-linked']);

it('leaves mutable survivors at zero beside an immutable 100% block', function () {
    $user = User::factory()->create();
    $locked = finalizedTimer($user, '09:01:00', '09:02:00');
    lockBilled($locked);
    $first = finalizedTimer($user, '09:05:00', '09:06:00');
    $second = finalizedTimer($user, '09:10:00', '09:11:00');
    expect(slotAllocations($user))->toBe([$locked->id => 100.0, $first->id => 0.0, $second->id => 0.0]);

    deleteEntry($second);

    expect(slotAllocations($user))->toBe([$locked->id => 100.0, $first->id => 0.0]);
});

it('rolls the whole deletion back when redistribution fails', function () {
    $user = User::factory()->create();
    $first = finalizedTimer($user, '09:01:00', '09:02:00');
    $second = finalizedTimer($user, '09:05:00', '09:06:00');
    $before = TimeEntryBlock::where('user_id', $user->id)->orderBy('id')->get(['id', 'time_entry_id', 'allocation_pct', 'is_overridden'])->toArray();

    DB::listen(function ($query) {
        if (str_contains(strtolower($query->sql), 'on duplicate key update')) {
            throw new RuntimeException('redistribution failed');
        }
    });

    expect(fn () => deleteEntry($first))->toThrow(RuntimeException::class);

    expect(TimeEntry::find($first->id))->not->toBeNull()
        ->and(TimeEntryBlock::where('user_id', $user->id)->orderBy('id')->get(['id', 'time_entry_id', 'allocation_pct', 'is_overridden'])->toArray())->toBe($before)
        ->and(slotAllocations($user))->toBe([$first->id => 50.0, $second->id => 50.0]);
});

it('takes the per-user lock before entry or block locks when deleting', function () {
    $user = User::factory()->create();
    $entry = finalizedTimer($user, '09:01:00', '09:02:00');
    finalizedTimer($user, '09:05:00', '09:06:00');

    DB::enableQueryLog();
    deleteEntry($entry);
    $locking = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $sql) => str_contains(strtolower($sql), 'for update'))
        ->values();
    DB::disableQueryLog();

    expect($locking->first())->toContain('`users`');
});

// ── Manual override lifecycle ────────────────────────────────────────────────

it('keeps a manual override while slot membership does not change', function () {
    $user = User::factory()->create();
    $first = finalizedTimer($user, '09:01:00', '09:02:00');
    $second = finalizedTimer($user, '09:05:00', '09:06:00');
    adjust($first, 70);
    expect(slotAllocations($user))->toBe([$first->id => 70.0, $second->id => 30.0])
        ->and(slotOverrides($user))->toBe([$first->id => true, $second->id => true]);

    // Non-structural activity: an edit, a description change, a retried stop, and another
    // entry finalizing in a different slot.
    app(TimeEntryService::class)->update($first, ['date' => SLOT_DATE, 'hours' => 1, 'description' => 'Renamed']);
    app(TimeEntryService::class)->stopTimer($first);
    finalizedTimer($user, '10:01:00', '10:02:00');

    expect(slotAllocations($user))->toBe([$first->id => 70.0, $second->id => 30.0])
        ->and(slotOverrides($user))->toBe([$first->id => true, $second->id => true]);
});

it('invalidates mutable overrides and reweights by seconds when a new entry joins', function () {
    $user = User::factory()->create();
    $first = finalizedTimer($user, '09:01:00', '09:02:00');
    $second = finalizedTimer($user, '09:05:00', '09:06:00');
    adjust($first, 70);

    $joiner = finalizedTimer($user, '09:10:00', '09:12:00');

    // Weights 60s / 60s / 120s -> 25 / 25 / 50; the old 70/30 choice no longer applies.
    expect(slotAllocations($user))->toBe([$first->id => 25.0, $second->id => 25.0, $joiner->id => 50.0])
        ->and(slotOverrides($user))->toBe([$first->id => false, $second->id => false, $joiner->id => false])
        ->and(slotTotal($user))->toBe(100.0);
});

it('does not let a 100% manual override force a newly joining sibling to zero', function () {
    $user = User::factory()->create();
    $first = finalizedTimer($user, '09:01:00', '09:02:00');
    $second = finalizedTimer($user, '09:05:00', '09:06:00');
    adjust($first, 100);
    expect(slotAllocations($user))->toBe([$first->id => 100.0, $second->id => 0.0]);

    $third = finalizedTimer($user, '09:10:00', '09:11:00');

    expect(slotAllocations($user))->toBe([$first->id => 33.33, $second->id => 33.33, $third->id => 33.34]);
});

it('invalidates mutable overrides when a member leaves the slot', function () {
    $user = User::factory()->create();
    $first = finalizedTimer($user, '09:01:00', '09:02:00');
    $second = finalizedTimer($user, '09:05:00', '09:06:00');
    $third = finalizedTimer($user, '09:10:00', '09:11:00');
    adjust($first, 60);
    expect(slotAllocations($user))->toBe([$first->id => 60.0, $second->id => 20.0, $third->id => 20.0])
        ->and(slotOverrides($user))->toBe([$first->id => true, $second->id => true, $third->id => true]);

    deleteEntry($third);

    expect(slotAllocations($user))->toBe([$first->id => 50.0, $second->id => 50.0])
        ->and(slotOverrides($user))->toBe([$first->id => false, $second->id => false]);
});

it('only invalidates overrides in slots whose membership actually changed', function () {
    $user = User::factory()->create();
    $shared = finalizedTimer($user, '09:14:00', '09:16:00');   // slots 36 and 37
    $other = finalizedTimer($user, '09:17:00', '09:18:00');    // slot 37
    adjust($shared, 40, 37);
    expect(slotOverrides($user, 37))->toBe([$shared->id => true, $other->id => true]);

    finalizedTimer($user, '09:05:00', '09:06:00');             // joins slot 36 only

    expect(slotOverrides($user, 37))->toBe([$shared->id => true, $other->id => true])
        ->and(slotAllocations($user, 37))->toBe([$shared->id => 40.0, $other->id => 60.0]);
});

it('keeps billed and invoice-linked blocks frozen through both a join and a leave', function (string $lock) {
    $user = User::factory()->create();
    $locked = finalizedTimer($user, '09:01:00', '09:02:00');
    $open = finalizedTimer($user, '09:05:00', '09:06:00');
    TimeEntryBlock::where('time_entry_id', $locked->id)->update(['allocation_pct' => 60]);
    TimeEntryBlock::where('time_entry_id', $open->id)->update(['allocation_pct' => 40, 'is_overridden' => true]);
    $locked->forceFill($lock === 'billed' ? ['billed' => true] : ['invoice_id' => Invoice::factory()->create()->id])->save();
    $frozen = blockOf($locked)->only(['allocation_pct', 'is_overridden', 'updated_at']);

    $joiner = finalizedTimer($user, '09:10:00', '09:11:00');

    expect(blockOf($locked)->only(['allocation_pct', 'is_overridden', 'updated_at']))->toEqual($frozen)
        ->and(slotAllocations($user))->toBe([$locked->id => 60.0, $open->id => 20.0, $joiner->id => 20.0])
        ->and(slotOverrides($user))->toBe([$locked->id => false, $open->id => false, $joiner->id => false]);

    deleteEntry($joiner);

    expect(blockOf($locked)->only(['allocation_pct', 'is_overridden', 'updated_at']))->toEqual($frozen)
        ->and(slotAllocations($user))->toBe([$locked->id => 60.0, $open->id => 40.0]);
})->with(['billed', 'invoice-linked']);

it('gives a joiner 0% beside an immutable 100% block instead of touching it', function () {
    $user = User::factory()->create();
    $locked = finalizedTimer($user, '09:01:00', '09:02:00');
    lockBilled($locked);
    $frozen = blockOf($locked)->only(['allocation_pct', 'updated_at']);

    $joiner = finalizedTimer($user, '09:05:00', '09:06:00');

    expect(slotAllocations($user))->toBe([$locked->id => 100.0, $joiner->id => 0.0])
        ->and(blockOf($locked)->only(['allocation_pct', 'updated_at']))->toEqual($frozen);
});

it('is independent of stop order even when the slot holds manual overrides', function () {
    $results = [];

    foreach ([[0, 1], [1, 0]] as $order) {
        $user = User::factory()->create();
        $first = finalizedTimer($user, '09:01:00', '09:02:00');
        $second = finalizedTimer($user, '09:05:00', '09:06:00');
        adjust($first, 70);
        $joiners = [
            runningEntry($user, '09:10:00', '09:11:00'),
            runningEntry($user, '09:12:00', '09:14:00'),
        ];
        $stops = ['09:11:00', '09:14:00'];

        foreach ($order as $index) {
            finalizeAt($joiners[$index], $stops[$index]);
            expect(slotTotal($user))->toBe(100.0);
        }

        $results[] = [array_values(slotAllocations($user)), array_values(slotOverrides($user))];
    }

    expect(array_unique($results, SORT_REGULAR))->toHaveCount(1)
        ->and($results[0][1])->toBe([false, false, false, false]);
});

it('lets the editor override the recalculated slot again, and that choice is sticky', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $first = finalizedTimer($user, '09:01:00', '09:02:00');
    $second = finalizedTimer($user, '09:05:00', '09:06:00');
    adjust($first, 70);
    $third = finalizedTimer($user, '09:10:00', '09:11:00');
    expect(slotOverrides($user))->toBe([$first->id => false, $second->id => false, $third->id => false]);

    $this->actingAs($user)
        ->patchJson(route('time.blocks.allocation', blockOf($second)), ['allocation_pct' => 50])
        ->assertOk();

    expect(slotTotal($user))->toBe(100.0)
        ->and((float) blockOf($second)->allocation_pct)->toBe(50.0)
        ->and(slotOverrides($user))->toBe([$first->id => true, $second->id => true, $third->id => true]);

    finalizedTimer($user, '10:01:00', '10:02:00');   // unrelated slot: the new choice stays

    expect((float) blockOf($second)->allocation_pct)->toBe(50.0)
        ->and(slotOverrides($user)[$second->id])->toBeTrue();
});

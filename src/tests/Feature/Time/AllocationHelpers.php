<?php

use App\Models\TimeEntry;
use App\Models\TimeEntryBlock;
use App\Models\User;
use App\Services\TimeEntryService;
use Carbon\Carbon;

/*
 * Shared fixtures for the allocation tests. Loaded with require_once from each test file;
 * the file name deliberately does not end in "Test" so Pest does not treat it as a test.
 */

const SLOT_DATE = '2026-06-30';
const SLOT_NINE = 36; // 09:00-09:15

function runningEntry(User $user, string $start, string $stop, ?string $description = null): TimeEntry
{
    return TimeEntry::factory()->running()->create([
        'user_id' => $user->id,
        'date' => SLOT_DATE,
        'description' => $description,
        'duration_minutes' => 0,
        'timer_started_at' => Carbon::parse("2026-06-30 {$start}"),
        'stopped_at' => null,
    ]);
}

function finalizeAt(TimeEntry $entry, string $stop): TimeEntry
{
    Carbon::setTestNow("2026-06-30 {$stop}");
    app(TimeEntryService::class)->stopTimer($entry);

    return $entry->fresh();
}

/** Finalize a timer that ran start..stop in one step. */
function finalizedTimer(User $user, string $start, string $stop): TimeEntry
{
    return finalizeAt(runningEntry($user, $start, $stop), $stop);
}

/** @return array<int, float> entry id => allocation_pct for one slot */
function slotAllocations(User $user, int $slot = SLOT_NINE): array
{
    return TimeEntryBlock::where('user_id', $user->id)
        ->where('block_date', SLOT_DATE)
        ->where('block_number', $slot)
        ->orderBy('time_entry_id')
        ->pluck('allocation_pct', 'time_entry_id')
        ->map(fn ($pct) => (float) $pct)
        ->all();
}

function slotTotal(User $user, int $slot = SLOT_NINE): float
{
    return round(array_sum(slotAllocations($user, $slot)), 2);
}

/** @return array<int, bool> entry id => is_overridden for one slot */
function slotOverrides(User $user, int $slot = SLOT_NINE): array
{
    return TimeEntryBlock::where('user_id', $user->id)
        ->where('block_date', SLOT_DATE)
        ->where('block_number', $slot)
        ->orderBy('time_entry_id')
        ->pluck('is_overridden', 'time_entry_id')
        ->map(fn ($flag) => (bool) $flag)
        ->all();
}

function blockOf(TimeEntry $entry, int $slot = SLOT_NINE): TimeEntryBlock
{
    return TimeEntryBlock::where('time_entry_id', $entry->id)
        ->where('block_number', $slot)
        ->firstOrFail();
}

function lockBilled(TimeEntry $entry): void
{
    $entry->forceFill(['billed' => true])->save();
}

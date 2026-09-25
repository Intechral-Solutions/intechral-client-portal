<?php

use App\Models\Invoice;
use App\Models\TimeEntryBlock;
use App\Models\User;
use App\Services\TimeEntryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/AllocationHelpers.php';

/*
 * Invariant under test: for one user, date, and 15-minute slot, the allocation_pct of every
 * block sums to exactly 100. Blocks of a billing-locked entry are immutable; the remainder is
 * shared by the rest of the slot, weighted by the seconds each entry spent in it. How manual
 * overrides behave when slot membership changes is covered in AllocationLifecycleTest.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('gives a lone finalized entry the whole slot', function () {
    $user = User::factory()->create();
    $entry = finalizedTimer($user, '09:01:00', '09:02:00');

    expect(slotAllocations($user))->toBe([$entry->id => 100.0]);
});

it('keeps a slot at exactly 100% as sequential, non-overlapping timers join it', function () {
    $user = User::factory()->create();

    $first = finalizedTimer($user, '09:01:00', '09:02:00');
    expect(slotTotal($user))->toBe(100.0);

    $second = finalizedTimer($user, '09:05:00', '09:06:00');
    expect(slotAllocations($user))->toBe([$first->id => 50.0, $second->id => 50.0])
        ->and(slotTotal($user))->toBe(100.0);

    $third = finalizedTimer($user, '09:10:00', '09:11:00');
    // Equal weights; the odd cent goes to the highest entry id, deterministically.
    expect(slotAllocations($user))->toBe([$first->id => 33.33, $second->id => 33.33, $third->id => 33.34])
        ->and(slotTotal($user))->toBe(100.0);
});

it('weights a shared slot by the seconds each entry spent in it', function () {
    $user = User::factory()->create();

    $long = finalizedTimer($user, '09:00:00', '09:03:00');
    $short = finalizedTimer($user, '09:05:00', '09:06:00');

    expect(slotAllocations($user))->toBe([$long->id => 75.0, $short->id => 25.0]);
});

it('still splits genuinely overlapping timers by their shared seconds', function () {
    $user = User::factory()->create();

    $outer = finalizedTimer($user, '09:00:00', '09:15:00');
    $inner = finalizedTimer($user, '09:05:00', '09:10:00');

    expect(slotAllocations($user))->toBe([$outer->id => 75.0, $inner->id => 25.0]);
});

it('produces the same allocation whatever order simultaneous timers are finalized in', function (array $order) {
    $user = User::factory()->create();
    $entries = [
        runningEntry($user, '09:01:00', '09:02:00'),
        runningEntry($user, '09:05:00', '09:06:00'),
        runningEntry($user, '09:10:00', '09:11:00'),
    ];
    $stops = ['09:02:00', '09:06:00', '09:11:00'];

    foreach ($order as $index) {
        finalizeAt($entries[$index], $stops[$index]);
        expect(slotTotal($user))->toBe(100.0);
    }

    expect(array_values(slotAllocations($user)))->toBe([33.33, 33.33, 33.34]);
})->with([
    'in start order' => [[0, 1, 2]],
    'reverse' => [[2, 1, 0]],
    'middle first' => [[1, 0, 2]],
    'last then first' => [[2, 0, 1]],
]);

it('is order-independent even when durations are not whole minutes', function () {
    // 20s, 45s, and 90s timers. Their persisted intervals are whole minutes, so every entry
    // must be weighted from that same persisted interval regardless of who finalizes last.
    $timers = [['09:01:00', '09:01:20'], ['09:05:00', '09:05:45'], ['09:10:00', '09:11:30']];
    $results = [];

    foreach ([[0, 1, 2], [2, 1, 0], [0, 2, 1], [1, 0, 2], [2, 0, 1]] as $order) {
        $user = User::factory()->create();
        $entries = array_map(fn (array $t) => runningEntry($user, $t[0], $t[1]), $timers);

        foreach ($order as $index) {
            finalizeAt($entries[$index], $timers[$index][1]);
            expect(slotTotal($user))->toBe(100.0);
        }

        $results[] = array_values(slotAllocations($user));
    }

    expect(array_unique($results, SORT_REGULAR))->toHaveCount(1);
});

it('takes the per-user serialization lock before any entry or block lock', function () {
    $user = User::factory()->create();
    $entry = runningEntry($user, '09:01:00', '09:02:00');
    Carbon::setTestNow('2026-06-30 09:02:00');

    DB::enableQueryLog();
    app(TimeEntryService::class)->stopTimer($entry);
    $locking = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql) => str_contains(strtolower($sql), 'for update'))
        ->values();
    DB::disableQueryLog();

    expect($locking->first())->toContain('`users`');
});

it('never rewrites a billed sibling and leaves the newcomer the unclaimed remainder', function () {
    $user = User::factory()->create();
    $billed = finalizedTimer($user, '09:01:00', '09:02:00');
    $billed->forceFill(['billed' => true])->save();
    $lockedBlock = TimeEntryBlock::where('time_entry_id', $billed->id)->firstOrFail();
    $stamp = $lockedBlock->updated_at;

    $newcomer = finalizedTimer($user, '09:05:00', '09:06:00');

    expect(slotAllocations($user))->toBe([$billed->id => 100.0, $newcomer->id => 0.0])
        ->and($lockedBlock->fresh()->updated_at->equalTo($stamp))->toBeTrue()
        ->and((bool) $lockedBlock->fresh()->is_overridden)->toBeFalse();
});

it('shares only the unfrozen remainder when an invoice-linked sibling holds part of the slot', function () {
    $user = User::factory()->create();
    $locked = finalizedTimer($user, '09:01:00', '09:02:00');
    $open = finalizedTimer($user, '09:05:00', '09:06:00');
    // The earlier finalizations split the slot 50/50; pin a 60/40 split as if adjusted then billed.
    TimeEntryBlock::where('time_entry_id', $locked->id)->update(['allocation_pct' => 60]);
    TimeEntryBlock::where('time_entry_id', $open->id)->update(['allocation_pct' => 40]);
    $locked->forceFill(['invoice_id' => Invoice::factory()->create()->id])->save();

    $newcomer = finalizedTimer($user, '09:10:00', '09:11:00');

    expect(slotAllocations($user))->toBe([$locked->id => 60.0, $open->id => 20.0, $newcomer->id => 20.0])
        ->and(slotTotal($user))->toBe(100.0);
});

it('normalizes a legacy over-allocated slot the next time an entry finalizes into it', function () {
    $user = User::factory()->create();
    $first = finalizedTimer($user, '09:01:00', '09:02:00');
    $second = finalizedTimer($user, '09:05:00', '09:06:00');
    // Reproduce the historical state where sequential timers each kept 100%.
    TimeEntryBlock::whereIn('time_entry_id', [$first->id, $second->id])->update(['allocation_pct' => 100]);
    expect(slotTotal($user))->toBe(200.0);

    finalizedTimer($user, '09:10:00', '09:11:00');

    expect(slotTotal($user))->toBe(100.0);
});

it('never touches another user\'s blocks', function () {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    $theirEntry = finalizedTimer($theirs, '09:01:00', '09:02:00');
    $theirStamp = TimeEntryBlock::where('time_entry_id', $theirEntry->id)->value('updated_at');

    finalizedTimer($mine, '09:01:00', '09:02:00');
    finalizedTimer($mine, '09:05:00', '09:06:00');

    expect(slotAllocations($theirs))->toBe([$theirEntry->id => 100.0])
        ->and(slotTotal($mine))->toBe(100.0)
        ->and(TimeEntryBlock::where('time_entry_id', $theirEntry->id)->value('updated_at'))->toEqual($theirStamp);
});

it('keeps every slot of a multi-slot timer at 100% when it shares only one of them', function () {
    $user = User::factory()->create();
    $shared = finalizedTimer($user, '09:05:00', '09:10:00');
    $long = finalizedTimer($user, '08:50:00', '09:20:00');

    expect(slotAllocations($user, 35))->toBe([$long->id => 100.0])
        ->and(slotAllocations($user, SLOT_NINE))->toBe([$shared->id => 25.0, $long->id => 75.0])
        ->and(slotAllocations($user, 37))->toBe([$long->id => 100.0]);
});

it('does not create or alter blocks for manual entries', function () {
    $user = User::factory()->create();
    $timer = finalizedTimer($user, '09:01:00', '09:02:00');

    app(TimeEntryService::class)->log($user, ['date' => SLOT_DATE, 'hours' => 1]);

    expect(slotAllocations($user))->toBe([$timer->id => 100.0])
        ->and(TimeEntryBlock::where('user_id', $user->id)->count())->toBe(1);
});

it('lets the allocation editor adjust a slot the finalizer produced and keeps it at 100%', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $first = finalizedTimer($user, '09:01:00', '09:02:00');
    finalizedTimer($user, '09:05:00', '09:06:00');
    finalizedTimer($user, '09:10:00', '09:11:00');
    $block = TimeEntryBlock::where('time_entry_id', $first->id)->firstOrFail();

    $response = $this->actingAs($user)
        ->patchJson(route('time.blocks.allocation', $block), ['allocation_pct' => 50])
        ->assertOk();

    expect((float) $response->json('slot.allocation_pct'))->toBe(100.0)
        ->and(slotTotal($user))->toBe(100.0)
        ->and((float) $block->fresh()->allocation_pct)->toBe(50.0);
});

it('does not change an entry\'s allocation when a stop is retried', function () {
    $user = User::factory()->create();
    $first = finalizedTimer($user, '09:01:00', '09:02:00');
    finalizedTimer($user, '09:05:00', '09:06:00');
    $before = slotAllocations($user);

    app(TimeEntryService::class)->stopTimer($first);

    expect(slotAllocations($user))->toBe($before);
});

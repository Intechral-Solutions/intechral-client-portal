<?php

namespace App\Services;

use App\Models\TimeEntry;
use App\Models\TimeEntryBlock;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TimeEntryService
{
    /**
     * Manually log a time entry.
     *
     * $data keys: date, duration_minutes OR hours (decimal),
     *             project_id?, task_id?, ticket_id?, description?, billable?
     */
    public function log(User $user, array $data): TimeEntry
    {
        $minutes = isset($data['hours'])
            ? $this->minutesFromHours($data['hours'])
            : (int) $data['duration_minutes'];

        return TimeEntry::create([
            'user_id' => $user->id,
            'project_id' => $data['project_id'] ?? null,
            'task_id' => $data['task_id'] ?? null,
            'ticket_id' => $data['ticket_id'] ?? null,
            'date' => $data['date'],
            'duration_minutes' => $minutes,
            'description' => $data['description'] ?? null,
            'billable' => $data['billable'] ?? true,
            'billed' => false,
            'invoice_id' => null,
            'timer_started_at' => null,
            'stopped_at' => null,
        ]);
    }

    /**
     * Update an existing entry only while it is not billing-locked.
     */
    public function update(TimeEntry $entry, array $data): TimeEntry
    {
        return DB::transaction(function () use ($entry, $data): TimeEntry {
            $current = TimeEntry::query()->lockForUpdate()->findOrFail($entry->getKey());
            $this->ensureMutable($current);

            $minutes = isset($data['hours'])
                ? $this->minutesFromHours($data['hours'])
                : ($data['duration_minutes'] ?? $current->duration_minutes);

            $current->update([
                'project_id' => array_key_exists('project_id', $data) ? $data['project_id'] : $current->project_id,
                'task_id' => array_key_exists('task_id', $data) ? $data['task_id'] : $current->task_id,
                'ticket_id' => array_key_exists('ticket_id', $data) ? $data['ticket_id'] : $current->ticket_id,
                'date' => $data['date'] ?? $current->date,
                'duration_minutes' => $minutes,
                'description' => array_key_exists('description', $data) ? $data['description'] : $current->description,
                'billable' => $data['billable'] ?? $current->billable,
            ]);

            return $current->fresh();
        });
    }

    public function delete(TimeEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $current = TimeEntry::query()->lockForUpdate()->findOrFail($entry->getKey());
            $this->ensureMutable($current);
            $current->delete();
        });
    }

    /**
     * Start a live timer for the user.
     * Multiple timers can run concurrently — no existing timers are stopped.
     */
    public function startTimer(User $user, array $data = []): TimeEntry
    {
        return TimeEntry::create([
            'user_id' => $user->id,
            'project_id' => $data['project_id'] ?? null,
            'task_id' => $data['task_id'] ?? null,
            'ticket_id' => $data['ticket_id'] ?? null,
            'date' => today()->toDateString(),
            'duration_minutes' => 0,
            'description' => $data['description'] ?? null,
            'billable' => $data['billable'] ?? true,
            'billed' => false,
            'invoice_id' => null,
            'timer_started_at' => now(),
            'stopped_at' => null,
        ]);
    }

    /**
     * Stop a running timer, persist elapsed duration, and finalize 15-min blocks.
     * The exact timer_started_at (second-precise) is captured before being nulled so
     * block calculations use the real start timestamp, not a reconstructed approximation.
     */
    public function stopTimer(TimeEntry $entry): TimeEntry
    {
        return DB::transaction(function () use ($entry): TimeEntry {
            $this->lockAllocationScope($entry->user_id);

            $current = TimeEntry::query()
                ->lockForUpdate()
                ->findOrFail($entry->getKey());

            $this->ensureMutable($current);

            // Idempotent retry: a prior request already normalized this entry.
            if ($current->timer_started_at === null) {
                return $current;
            }

            $startedAt = $current->timer_started_at->copy();
            $wasPartiallyStopped = $current->stopped_at !== null;
            $stoppedAt = $current->stopped_at?->copy() ?? now();

            // A malformed legacy end before its start cannot define an interval.
            if ($stoppedAt->lt($startedAt)) {
                $stoppedAt = now();
                $wasPartiallyStopped = false;
            }

            // Any partial minute counts as a full minute (e.g. 61s -> 2 min), never truncated.
            $elapsedSeconds = $startedAt->diffInSeconds($stoppedAt);
            $elapsed = $elapsedSeconds > 0 ? (int) ceil($elapsedSeconds / 60) : 0;
            $duration = $wasPartiallyStopped
                ? max($current->duration_minutes, $elapsed)
                : $current->duration_minutes + $elapsed;

            $current->update([
                'duration_minutes' => $duration,
                'stopped_at' => $stoppedAt,
                'timer_started_at' => null,
            ]);

            $current->refresh();
            $this->finalizeBlocks($current, $startedAt, $stoppedAt);

            return $current->fresh();
        });
    }

    public function updateTimerDescription(TimeEntry $entry, ?string $description): TimeEntry
    {
        return DB::transaction(function () use ($entry, $description): TimeEntry {
            $current = TimeEntry::query()->lockForUpdate()->findOrFail($entry->getKey());
            $this->ensureMutable($current);

            if (! $current->isRunning()) {
                throw ValidationException::withMessages(['timer' => 'Timer is not running.']);
            }

            $current->update(['description' => $description]);

            return $current->fresh();
        });
    }

    public function updateBlockAllocation(TimeEntryBlock $block, float $newPercentage): Collection
    {
        return DB::transaction(function () use ($block, $newPercentage): Collection {
            $this->lockAllocationScope($block->user_id);

            $slotBlocks = TimeEntryBlock::where('user_id', $block->user_id)
                ->where('block_date', $block->block_date)
                ->where('block_number', $block->block_number)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $current = $slotBlocks->firstWhere('id', $block->id);
            if (! $current) {
                abort(404);
            }

            $timeEntries = TimeEntry::whereIn('id', $slotBlocks->pluck('time_entry_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($slotBlocks as $slotBlock) {
                $timeEntry = $timeEntries->get($slotBlock->time_entry_id);
                if (! $timeEntry) {
                    abort(404);
                }

                $this->ensureMutable($timeEntry);
            }

            $siblings = $slotBlocks->where('id', '!=', $current->id);
            $newPercentage = round($newPercentage, 2);

            if ($siblings->isEmpty() && $newPercentage !== 100.0) {
                throw ValidationException::withMessages([
                    'allocation_pct' => 'A single-entry time block must remain allocated at 100%.',
                ]);
            }

            $shares = $this->distribute(
                round(100.0 - $newPercentage, 2),
                $siblings->mapWithKeys(fn (TimeEntryBlock $sibling) => [
                    $sibling->id => (float) $sibling->allocation_pct,
                ])->all(),
            );

            foreach ($siblings as $sibling) {
                $sibling->update(['allocation_pct' => $shares[$sibling->id], 'is_overridden' => true]);
            }

            $current->update([
                'allocation_pct' => $newPercentage,
                'is_overridden' => true,
            ]);

            return TimeEntryBlock::where('user_id', $block->user_id)
                ->where('block_date', $block->block_date)
                ->where('block_number', $block->block_number)
                ->with('timeEntry')
                ->orderBy('id')
                ->get();
        });
    }

    /**
     * Return all active timers for a user (multiple can run simultaneously).
     */
    public function activeTimers(User $user): Collection
    {
        return TimeEntry::where('user_id', $user->id)
            ->running()
            ->with(['project', 'task', 'ticket'])
            ->orderBy('timer_started_at')
            ->get();
    }

    /**
     * Return the single active timer for a user, or null.
     * Kept for backward compatibility.
     */
    public function activeTimer(User $user): ?TimeEntry
    {
        return $this->activeTimers($user)->first();
    }

    /**
     * Summarise total minutes per project for a date range.
     */
    public function summaryByProject(array $filters = []): \Illuminate\Support\Collection
    {
        $query = TimeEntry::query()
            ->selectRaw('project_id, SUM(duration_minutes) as total_minutes, SUM(CASE WHEN billable = 1 THEN duration_minutes ELSE 0 END) as billable_minutes')
            ->with('project:id,name')
            ->whereNull('timer_started_at');

        $this->applyFilters($query, $filters);

        return $query->groupBy('project_id')->get();
    }

    /**
     * Summarise total minutes per user for a date range.
     */
    public function summaryByUser(array $filters = []): \Illuminate\Support\Collection
    {
        $query = TimeEntry::query()
            ->selectRaw('user_id, SUM(duration_minutes) as total_minutes, SUM(CASE WHEN billable = 1 THEN duration_minutes ELSE 0 END) as billable_minutes')
            ->with('user:id,name')
            ->whereNull('timer_started_at');

        $this->applyFilters($query, $filters);

        return $query->groupBy('user_id')->get();
    }

    public function totalMinutes(array $filters = []): int
    {
        $query = TimeEntry::query()->whereNull('timer_started_at');
        $this->applyFilters($query, $filters);

        return (int) $query->sum('duration_minutes');
    }

    /**
     * Build a CSV export of time entries matching the given filters.
     */
    public function exportCsv(array $filters = []): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv(
            $stream,
            ['Date', 'User', 'Project', 'Task', 'Ticket', 'Description', 'Hours', 'Billable', 'Billed'],
            escape: '',
        );

        $query = TimeEntry::with(['user:id,name', 'project:id,name', 'task:id,title', 'ticket:id,ticket_number'])
            ->whereNull('timer_started_at')
            ->orderBy('date')
            ->orderBy('user_id');

        $this->applyFilters($query, $filters);

        foreach ($query->get() as $entry) {
            fputcsv($stream, [
                $entry->date->format('Y-m-d'),
                $this->safeCsvText($entry->user?->name),
                $this->safeCsvText($entry->project?->name),
                $this->safeCsvText($entry->task?->title),
                $this->safeCsvText($entry->ticket?->ticket_number),
                $this->safeCsvText($entry->description),
                number_format($entry->durationDecimal(), 2),
                $entry->billable ? 'Yes' : 'No',
                $entry->billed ? 'Yes' : 'No',
            ], escape: '');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    // ── Private ──────────────────────────────────────────────

    /**
     * Compute and store 15-minute block allocations for a freshly stopped timer.
     *
     * Invariant: for one user, date, and slot, block allocations sum to exactly 100%
     * (the same invariant updateBlockAllocation() enforces). Every slot this entry
     * touches is therefore recomputed from all entries that hold a block in it, not only
     * from ones that overlapped this timer in wall-clock time; otherwise sequential
     * timers inside one slot would each keep 100%.
     *
     * Frozen blocks keep their value: those of a billing-locked entry (never mutated)
     * and those the user manually overrode. The unfrozen entries share what remains,
     * weighted by the seconds each spent in the slot. Every entry, including the one
     * being finalized, is weighted from the same persisted interval
     * (stopped_at - duration_minutes .. stopped_at, at least one second), so the result
     * depends only on the set of entries and never on which one finalizes last. If
     * frozen blocks already claim 100% or more, the unfrozen entries receive 0% rather
     * than a locked or overridden block being touched.
     *
     * The caller holds the per-user allocation lock, so concurrent finalizations of one
     * user's timers run one after another and each sees the previous result; because the
     * whole slot is recomputed, the outcome does not depend on the order they finish in.
     */
    private function finalizeBlocks(TimeEntry $entry, Carbon $startedAt, Carbon $stoppedAt): void
    {
        // Snap to 15-minute block boundaries.
        $blockStart = $startedAt->copy()->floorUnit('minute', 15);
        $blockEnd = $stoppedAt->copy()->floorUnit('minute', 15);

        $slots = [];
        $current = $blockStart->copy();

        while ($current->lte($blockEnd)) {
            $slotEnd = $current->copy()->addMinutes(15);
            $seconds = $this->secondsInWindow($startedAt, $stoppedAt, $current, $slotEnd);

            if ($seconds > 0) {
                $blockDate = $current->toDateString();
                $blockNumber = (int) ($current->copy()->startOfDay()->diffInMinutes($current) / 15);
                $slots[$this->slotKey($blockDate, $blockNumber)] = [
                    'date' => $blockDate,
                    'number' => $blockNumber,
                    'start' => $current->copy(),
                    'end' => $slotEnd,
                ];
            }

            $current->addMinutes(15);
        }

        if ($slots === []) {
            return;
        }

        $existing = TimeEntryBlock::where('user_id', $entry->user_id)
            ->whereBetween('block_date', [$blockStart->toDateString(), $blockEnd->toDateString()])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->filter(fn (TimeEntryBlock $block) => isset($slots[$this->slotKey($block->block_date->toDateString(), $block->block_number)]))
            ->groupBy(fn (TimeEntryBlock $block) => $this->slotKey($block->block_date->toDateString(), $block->block_number));

        $siblings = TimeEntry::whereIn('id', $existing->flatten()->pluck('time_entry_id')->unique()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $rows = [];
        $timestamp = now();

        foreach ($slots as $key => $slot) {
            $participants = [
                $entry->id => ['block' => null, 'frozen' => false, 'weight' => $this->slotWeight($entry, $slot)],
            ];

            foreach ($existing->get($key, []) as $block) {
                $entryId = $block->time_entry_id;

                if ($entryId === $entry->id) {
                    $participants[$entryId]['block'] = $block;
                    $participants[$entryId]['frozen'] = (bool) $block->is_overridden;

                    continue;
                }

                $sibling = $siblings->get($entryId);
                $participants[$entryId] = [
                    'block' => $block,
                    'frozen' => $block->is_overridden || ($sibling?->isLockedForBilling() ?? false),
                    'weight' => $sibling ? $this->slotWeight($sibling, $slot) : 0.0,
                ];
            }

            ksort($participants);

            $frozenTotal = 0.0;
            $weights = [];

            foreach ($participants as $entryId => $participant) {
                if ($participant['frozen']) {
                    $frozenTotal += (float) $participant['block']->allocation_pct;
                } else {
                    $weights[$entryId] = max(1.0, $participant['weight']);
                }
            }

            $shares = $this->distribute(max(0.0, round(100.0 - $frozenTotal, 2)), $weights);

            foreach ($shares as $entryId => $percentage) {
                $block = $participants[$entryId]['block'];

                if ($block !== null && abs((float) $block->allocation_pct - $percentage) < 0.005) {
                    continue;
                }

                $rows[] = [
                    'time_entry_id' => $entryId,
                    'user_id' => $entry->user_id,
                    'block_date' => $slot['date'],
                    'block_number' => $slot['number'],
                    'allocation_pct' => $percentage,
                    'is_overridden' => false,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            TimeEntryBlock::upsert(
                $chunk,
                ['time_entry_id', 'block_date', 'block_number'],
                ['user_id', 'allocation_pct', 'is_overridden', 'updated_at'],
            );
        }
    }

    /**
     * Split $total percentage points across the weighted keys, in key order.
     *
     * The single redistribution rule shared by manual adjustment and timer finalization:
     * each share is proportional to its weight rounded to two decimals, the last key
     * takes the rounding remainder so the shares sum to exactly $total, and equal
     * shares are used when every weight is zero. A rounding overshoot on a tiny total
     * is paid back from the earlier shares so no share is ever negative.
     *
     * @param  array<int|string, float>  $weights
     * @return array<int|string, float>
     */
    private function distribute(float $total, array $weights): array
    {
        $keys = array_keys($weights);
        $count = count($keys);

        if ($count === 0) {
            return [];
        }

        $weightSum = array_sum($weights);
        $shares = [];
        $assigned = 0.0;

        foreach ($keys as $index => $key) {
            $share = $index === $count - 1
                ? round($total - $assigned, 2)
                : ($weightSum > 0
                    ? round($weights[$key] / $weightSum * $total, 2)
                    : round($total / $count, 2));

            $shares[$key] = $share;
            $assigned += $share;
        }

        $lastKey = $keys[$count - 1];

        if ($shares[$lastKey] < 0) {
            $deficit = -$shares[$lastKey];
            $shares[$lastKey] = 0.0;

            foreach (array_reverse(array_slice($keys, 0, -1)) as $key) {
                $take = min($shares[$key], $deficit);
                $shares[$key] = round($shares[$key] - $take, 2);
                $deficit = round($deficit - $take, 2);
            }
        }

        return $shares;
    }

    /**
     * Serialize every allocation write for one user. It is always the first lock a
     * transaction takes, before entry and block rows, so concurrent stops and manual
     * adjustments queue behind each other instead of deadlocking on lock order.
     */
    private function lockAllocationScope(int $userId): void
    {
        User::query()->whereKey($userId)->lockForUpdate()->first();
    }

    private function slotKey(string $blockDate, int $blockNumber): string
    {
        return "{$blockDate}:{$blockNumber}";
    }

    /**
     * Seconds an entry spent in a slot, measured from its persisted interval. Stopped
     * timers keep only whole minutes, so the start is stopped_at - duration_minutes.
     *
     * @param  array{start: Carbon, end: Carbon}  $slot
     */
    private function slotWeight(TimeEntry $entry, array $slot): float
    {
        if ($entry->stopped_at === null) {
            return 1.0;
        }

        return max(1.0, (float) $this->secondsInWindow(
            $entry->stopped_at->copy()->subMinutes($entry->duration_minutes),
            $entry->stopped_at,
            $slot['start'],
            $slot['end'],
        ));
    }

    /**
     * Overlap in seconds between [entryStart, entryEnd) and [windowStart, windowEnd).
     */
    private function secondsInWindow(Carbon $entryStart, Carbon $entryEnd, Carbon $windowStart, Carbon $windowEnd): int
    {
        $overlapStart = $entryStart->max($windowStart);
        $overlapEnd = $entryEnd->min($windowEnd);

        return max(0, (int) $overlapStart->diffInSeconds($overlapEnd, false));
    }

    /**
     * Convert decimal hours to stored whole minutes. A positive duration must never
     * round to zero minutes, so a sub-minute value is rejected instead of stored as 0.
     */
    private function minutesFromHours(mixed $hours): int
    {
        $minutes = (int) round((float) $hours * 60);

        if ($minutes < 1) {
            throw ValidationException::withMessages([
                'hours' => 'The duration must be at least one minute.',
            ]);
        }

        return $minutes;
    }

    private function ensureMutable(TimeEntry $entry): void
    {
        if ($entry->isLockedForBilling()) {
            throw ValidationException::withMessages([
                'time_entry' => TimeEntry::BILLING_LOCK_MESSAGE,
            ]);
        }
    }

    private function applyFilters($query, array $filters): void
    {
        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (! empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }
        if (! empty($filters['from'])) {
            $query->where('date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('date', '<=', $filters['to']);
        }
        if (isset($filters['billable'])) {
            $query->where('billable', $filters['billable']);
        }
        if (isset($filters['billed'])) {
            $query->where('billed', $filters['billed']);
        }
    }

    private function safeCsvText(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\\s]*[=+\-@]/u', $value) === 1
            ? "'{$value}"
            : $value;
    }
}

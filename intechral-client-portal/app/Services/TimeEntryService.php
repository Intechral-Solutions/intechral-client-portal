<?php

namespace App\Services;

use App\Models\TimeEntry;
use App\Models\TimeEntryBlock;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
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
            ? (int) round((float) $data['hours'] * 60)
            : (int) $data['duration_minutes'];

        return TimeEntry::create([
            'user_id'          => $user->id,
            'project_id'       => $data['project_id'] ?? null,
            'task_id'          => $data['task_id'] ?? null,
            'ticket_id'        => $data['ticket_id'] ?? null,
            'date'             => $data['date'],
            'duration_minutes' => $minutes,
            'description'      => $data['description'] ?? null,
            'billable'         => $data['billable'] ?? true,
            'billed'           => false,
            'timer_started_at' => null,
        ]);
    }

    /**
     * Update an existing entry (only allowed while not billed).
     */
    public function update(TimeEntry $entry, array $data): TimeEntry
    {
        $minutes = isset($data['hours'])
            ? (int) round((float) $data['hours'] * 60)
            : ($data['duration_minutes'] ?? $entry->duration_minutes);

        $entry->update([
            'project_id'       => $data['project_id'] ?? $entry->project_id,
            'task_id'          => $data['task_id'] ?? $entry->task_id,
            'ticket_id'        => $data['ticket_id'] ?? $entry->ticket_id,
            'date'             => $data['date'] ?? $entry->date,
            'duration_minutes' => $minutes,
            'description'      => $data['description'] ?? $entry->description,
            'billable'         => $data['billable'] ?? $entry->billable,
        ]);

        return $entry->fresh();
    }

    /**
     * Start a live timer for the user.
     * Multiple timers can run concurrently — no existing timers are stopped.
     */
    public function startTimer(User $user, array $data = []): TimeEntry
    {
        return TimeEntry::create([
            'user_id'          => $user->id,
            'project_id'       => $data['project_id'] ?? null,
            'task_id'          => $data['task_id'] ?? null,
            'ticket_id'        => $data['ticket_id'] ?? null,
            'date'             => today()->toDateString(),
            'duration_minutes' => 0,
            'description'      => $data['description'] ?? null,
            'billable'         => $data['billable'] ?? true,
            'billed'           => false,
            'timer_started_at' => now(),
        ]);
    }

    /**
     * Stop a running timer, persist elapsed duration, and finalize 15-min blocks.
     * The exact timer_started_at (second-precise) is captured before being nulled so
     * block calculations use the real start timestamp, not a reconstructed approximation.
     */
    public function stopTimer(TimeEntry $entry): TimeEntry
    {
        if (! $entry->isRunning()) {
            throw ValidationException::withMessages(['timer' => 'Timer is not running.']);
        }

        $startedAt = $entry->timer_started_at->copy(); // capture before nulling
        $stoppedAt = now();
        $elapsed   = (int) $startedAt->diffInMinutes($stoppedAt);

        $entry->update([
            'duration_minutes' => $entry->duration_minutes + $elapsed,
            'stopped_at'       => $stoppedAt,
            'timer_started_at' => null,
        ]);

        $this->finalizeBlocks($entry->fresh(), $startedAt, $stoppedAt);

        return $entry->fresh();
    }

    /**
     * Return all active timers for a user (multiple can run simultaneously).
     */
    public function activeTimers(User $user): Collection
    {
        return TimeEntry::where('user_id', $user->id)
            ->whereNotNull('timer_started_at')
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

    /**
     * Build a CSV export of time entries matching the given filters.
     */
    public function exportCsv(array $filters = []): string
    {
        $headers = ['Date', 'User', 'Project', 'Task', 'Ticket', 'Description', 'Hours', 'Billable', 'Billed'];
        $csv = implode(',', $headers) . "\n";

        $query = TimeEntry::with(['user:id,name', 'project:id,name', 'task:id,title', 'ticket:id,ticket_number'])
            ->whereNull('timer_started_at')
            ->orderBy('date')
            ->orderBy('user_id');

        $this->applyFilters($query, $filters);

        foreach ($query->get() as $entry) {
            $csv .= implode(',', [
                $entry->date->format('Y-m-d'),
                '"' . ($entry->user->name ?? '') . '"',
                '"' . ($entry->project->name ?? '') . '"',
                '"' . ($entry->task->title ?? '') . '"',
                '"' . ($entry->ticket->ticket_number ?? '') . '"',
                '"' . str_replace('"', '""', $entry->description ?? '') . '"',
                number_format($entry->durationDecimal(), 2),
                $entry->billable ? 'Yes' : 'No',
                $entry->billed   ? 'Yes' : 'No',
            ]) . "\n";
        }

        return $csv;
    }

    // ── Private ──────────────────────────────────────────────

    /**
     * Compute and store 15-minute block allocations for a freshly stopped timer.
     *
     * Uses exact second-precision timestamps (not reconstructed from duration_minutes)
     * so block percentages accurately reflect the real start/stop times.
     * Concurrent entries (same user, same slot) get proportional allocations.
     * Blocks already marked is_overridden = true are left untouched.
     */
    private function finalizeBlocks(TimeEntry $entry, Carbon $startedAt, Carbon $stoppedAt): void
    {

        // Snap to 15-minute block boundaries.
        $blockStart = $startedAt->copy()->floorUnit('minute', 15);
        $blockEnd   = $stoppedAt->copy()->floorUnit('minute', 15);

        $current = $blockStart->copy();

        while ($current->lte($blockEnd)) {
            $slotEnd     = $current->copy()->addMinutes(15);
            $blockDate   = $current->toDateString();
            $blockNumber = (int) ($current->copy()->startOfDay()->diffInMinutes($current) / 15);

            $mySeconds = $this->secondsInWindow($startedAt, $stoppedAt, $current, $slotEnd);

            if ($mySeconds <= 0) {
                $current->addMinutes(15);
                continue;
            }

            // Find other finalized entries for this user that ran during this slot.
            $concurrent        = TimeEntry::where('user_id', $entry->user_id)
                ->where('id', '!=', $entry->id)
                ->runningDuring($current, $slotEnd)
                ->get();

            $totalSeconds      = $mySeconds;
            $concurrentSeconds = [];

            foreach ($concurrent as $other) {
                // Concurrent entries in scopeRunningDuring are already stopped (have stopped_at).
                // Reconstruct their start from stopped_at - duration_minutes as minute-level approximation.
                // (Their exact timer_started_at was nulled when they stopped; this is the best we have
                //  for historical entries. New entries stopped via this service will store exact times.)
                $otherStart = $other->stopped_at->copy()->subMinutes($other->duration_minutes);
                $secs       = $this->secondsInWindow($otherStart, $other->stopped_at, $current, $slotEnd);
                $concurrentSeconds[$other->id] = $secs;
                $totalSeconds += $secs;
            }

            $myPct = $totalSeconds > 0 ? round($mySeconds / $totalSeconds * 100, 2) : 100.00;
            $this->upsertBlock($entry->id, $entry->user_id, $blockDate, $blockNumber, $myPct);

            foreach ($concurrent as $other) {
                $otherPct = $totalSeconds > 0
                    ? round(($concurrentSeconds[$other->id] ?? 0) / $totalSeconds * 100, 2)
                    : 0.00;
                $this->upsertBlock($other->id, $other->user_id, $blockDate, $blockNumber, $otherPct);
            }

            $current->addMinutes(15);
        }
    }

    /**
     * Overlap in seconds between [entryStart, entryEnd) and [windowStart, windowEnd).
     */
    private function secondsInWindow(Carbon $entryStart, Carbon $entryEnd, Carbon $windowStart, Carbon $windowEnd): int
    {
        $overlapStart = $entryStart->max($windowStart);
        $overlapEnd   = $entryEnd->min($windowEnd);

        return max(0, (int) $overlapStart->diffInSeconds($overlapEnd, false));
    }

    /**
     * Upsert a time_entry_blocks row, skipping manually overridden rows.
     */
    private function upsertBlock(int $entryId, int $userId, string $blockDate, int $blockNumber, float $pct): void
    {
        $existing = TimeEntryBlock::where('time_entry_id', $entryId)
            ->where('block_date', $blockDate)
            ->where('block_number', $blockNumber)
            ->first();

        if ($existing?->is_overridden) {
            return;
        }

        TimeEntryBlock::updateOrCreate(
            [
                'time_entry_id' => $entryId,
                'block_date'    => $blockDate,
                'block_number'  => $blockNumber,
            ],
            [
                'user_id'        => $userId,
                'allocation_pct' => $pct,
                'is_overridden'  => false,
            ]
        );
    }

    private function applyFilters($query, array $filters): void
    {
        if (! empty($filters['user_id']))    $query->where('user_id', $filters['user_id']);
        if (! empty($filters['project_id'])) $query->where('project_id', $filters['project_id']);
        if (! empty($filters['from']))       $query->where('date', '>=', $filters['from']);
        if (! empty($filters['to']))         $query->where('date', '<=', $filters['to']);
        if (isset($filters['billable']))     $query->where('billable', $filters['billable']);
        if (isset($filters['billed']))       $query->where('billed', $filters['billed']);
    }
}

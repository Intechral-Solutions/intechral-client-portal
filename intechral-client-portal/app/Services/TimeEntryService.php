<?php

namespace App\Services;

use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class TimeEntryService
{
    /**
     * Manually log a time entry.
     *
     * $data keys: date, duration_minutes OR hours (decimal), project_id?, task_id?, description?, billable?
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
            'date'             => $data['date'] ?? $entry->date,
            'duration_minutes' => $minutes,
            'description'      => $data['description'] ?? $entry->description,
            'billable'         => $data['billable'] ?? $entry->billable,
        ]);

        return $entry->fresh();
    }

    /**
     * Start a live timer for the user.
     * Only one running timer per user is allowed — stops any existing one first.
     */
    public function startTimer(User $user, array $data = []): TimeEntry
    {
        // Stop any currently running timer
        TimeEntry::where('user_id', $user->id)
            ->whereNotNull('timer_started_at')
            ->each(fn ($e) => $this->stopTimer($e));

        return TimeEntry::create([
            'user_id'          => $user->id,
            'project_id'       => $data['project_id'] ?? null,
            'task_id'          => $data['task_id'] ?? null,
            'date'             => today()->toDateString(),
            'duration_minutes' => 0,
            'description'      => $data['description'] ?? null,
            'billable'         => $data['billable'] ?? true,
            'billed'           => false,
            'timer_started_at' => now(),
        ]);
    }

    /**
     * Stop a running timer and persist the elapsed duration.
     */
    public function stopTimer(TimeEntry $entry): TimeEntry
    {
        if (! $entry->isRunning()) {
            throw ValidationException::withMessages(['timer' => 'Timer is not running.']);
        }

        $elapsed = (int) $entry->timer_started_at->diffInMinutes(now());

        $entry->update([
            'duration_minutes' => $entry->duration_minutes + $elapsed,
            'timer_started_at' => null,
        ]);

        return $entry->fresh();
    }

    /**
     * Return the active timer for a user, or null.
     */
    public function activeTimer(User $user): ?TimeEntry
    {
        return TimeEntry::where('user_id', $user->id)
            ->whereNotNull('timer_started_at')
            ->with(['project', 'task'])
            ->first();
    }

    /**
     * Summarise total minutes per project for a date range.
     *
     * Returns a collection of [ project_id, project_name, total_minutes, billable_minutes ]
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
        $headers = ['Date', 'User', 'Project', 'Task', 'Description', 'Hours', 'Billable', 'Billed'];
        $csv = implode(',', $headers) . "\n";

        $query = TimeEntry::with(['user:id,name', 'project:id,name', 'task:id,title'])
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
                '"' . str_replace('"', '""', $entry->description ?? '') . '"',
                number_format($entry->durationDecimal(), 2),
                $entry->billable ? 'Yes' : 'No',
                $entry->billed   ? 'Yes' : 'No',
            ]) . "\n";
        }

        return $csv;
    }

    // ── Private ──────────────────────────────────────────────

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

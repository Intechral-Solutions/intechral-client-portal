<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class TimeEntry extends Model
{
    use HasFactory;

    public const BILLING_LOCK_MESSAGE = 'This time entry has already been billed and can no longer be modified.';

    protected $fillable = [
        'user_id',
        'project_id',
        'task_id',
        'ticket_id',
        'invoice_id',
        'date',
        'duration_minutes',
        'description',
        'billable',
        'billed',
        'timer_started_at',
        'stopped_at',
    ];

    protected $casts = [
        'date' => 'date',
        'billable' => 'boolean',
        'billed' => 'boolean',
        'timer_started_at' => 'datetime',
        'stopped_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (TimeEntry $entry): void {
            if ($entry->timer_started_at !== null && $entry->stopped_at !== null) {
                throw new LogicException('A time entry cannot be both running and stopped.');
            }

            if ($entry->timer_started_at !== null && $entry->isLockedForBilling()) {
                throw new LogicException('A billed or invoiced time entry cannot be running.');
            }
        });
    }

    // ── Relationships ────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(TimeEntryBlock::class);
    }

    // ── Helpers ──────────────────────────────────────────────

    public function isRunning(): bool
    {
        return $this->timer_started_at !== null && $this->stopped_at === null;
    }

    public function isLockedForBilling(): bool
    {
        return $this->billed || $this->invoice_id !== null;
    }

    /** Effective duration including live elapsed time if timer is running */
    public function effectiveDurationMinutes(): int
    {
        if ($this->isRunning()) {
            return $this->duration_minutes + (int) $this->timer_started_at->diffInMinutes(now());
        }

        return $this->duration_minutes;
    }

    public function durationForHumans(): string
    {
        $minutes = $this->effectiveDurationMinutes();
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        if ($h === 0) {
            return "{$m}m";
        }
        if ($m === 0) {
            return "{$h}h";
        }

        return "{$h}h {$m}m";
    }

    public function durationDecimal(): float
    {
        return round($this->effectiveDurationMinutes() / 60, 2);
    }

    // ── Scopes ───────────────────────────────────────────────

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * THE definition of "time on project P" for reporting, filtering and the project Overview
     * (EPIC-015 §10, INV-P4). Not the delete guard: see `RecordedTimeGuard::deleteProject`, which
     * deliberately stays conservative (any direct or task reference blocks a delete).
     *
     * An entry is attributed to exactly ONE project, or to none:
     *   - no task: its own `project_id`;
     *   - a task with a VALID BOARD kind (`tasks.project_id` set, no ticket): the TASK's project.
     *     The entry's own `project_id` is ignored, so a malformed row carrying both counts only
     *     under its task's project;
     *   - any other task (standalone, ticket-linked, or MALFORMED: linked to both a project and a
     *     ticket, which has no valid product kind, owner ruling §8.4): NO project, whatever the
     *     entry's own `project_id` or the task's `project_id` says.
     *
     * Every project consumer must go through this scope, `attributedProjectIdSql()` (the matching
     * grouping) or `attributedProject()` (the matching per-entry answer), so the three cannot
     * disagree. A plain `project_id = P OR task_id IN tasks(P)` is NOT equivalent: a malformed
     * row would match two projects.
     */
    public function scopeAttributedToProject($query, int $projectId)
    {
        return $query->where(fn ($attributed) => $attributed
            ->where(fn ($direct) => $direct
                ->whereNull('time_entries.task_id')
                ->where('time_entries.project_id', $projectId))
            ->orWhereIn('time_entries.task_id', Task::query()
                ->where('tasks.project_id', $projectId)
                ->whereNull('tasks.ticket_id')
                ->select('tasks.id')));
    }

    /**
     * Joins each entry to its task, so `attributedProjectIdSql()` can group on plain columns. A LEFT
     * join: entries without a task stay, and the join cannot multiply rows (`tasks.id` is unique).
     * Use it only with `selectRaw`, where every column is qualified: `tasks` shares column names
     * with other tables, so `select *` would collide.
     */
    public function scopeJoinedToTask($query)
    {
        return $query->leftJoin('tasks', 'tasks.id', '=', 'time_entries.task_id');
    }

    /**
     * The grouping expression equal to `scopeAttributedToProject`. Requires `scopeJoinedToTask`.
     * Plain columns, not a correlated subquery, because MariaDB's ONLY_FULL_GROUP_BY accepts a
     * select expression only when it is textually the GROUP BY one.
     *
     *   no task                        -> the entry's own project (NULL for "no project")
     *   task, valid board kind         -> the task's project
     *   task, anything else            -> NULL (standalone, ticket, malformed dual-linked)
     *
     * Deliberately a CASE and not `COALESCE(tasks.project_id, time_entries.project_id)`: for a task
     * with no project COALESCE would fall back to the entry's stale `project_id`, and for a
     * malformed dual-linked task it would leak `tasks.project_id`; both attribute the entry to a
     * project the scope excludes.
     */
    public static function attributedProjectIdSql(): string
    {
        // CAST keeps the id an integer: a CASE with an ELSE NULL branch comes back from the driver as
        // a string, which would change the JSON type of every by-project row id.
        return 'CAST(CASE '
            .'WHEN time_entries.task_id IS NULL THEN time_entries.project_id '
            .'WHEN tasks.ticket_id IS NULL THEN tasks.project_id '
            .'ELSE NULL END AS UNSIGNED)';
    }

    /**
     * The one project this entry is attributed to, or null, matching `scopeAttributedToProject`.
     * Eager load `project` and `task:id,project_id,ticket_id` with `task.project` (the malformed
     * check reads `ticket_id`) so a list of entries costs no query per row.
     */
    public function attributedProject(): ?Project
    {
        if ($this->task_id === null) {
            return $this->project;
        }

        $task = $this->task;

        // A missing task (foreign keys normally forbid it) or a malformed one has no project.
        if ($task === null || $task->isMalformedKind()) {
            return null;
        }

        return $task->project;
    }

    public function scopeForTicket($query, int $ticketId)
    {
        return $query->where('ticket_id', $ticketId);
    }

    public function scopeBetweenDates($query, string $from, string $to)
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    public function scopeBillable($query)
    {
        return $query->where('billable', true)->where('billed', false);
    }

    /** Canonical active timer state. */
    public function scopeRunning($query)
    {
        return $query
            ->whereNotNull('timer_started_at')
            ->whereNull('stopped_at');
    }
}

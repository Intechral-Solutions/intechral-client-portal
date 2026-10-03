<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    use HasFactory;

    protected $table = 'tasks';

    protected $fillable = [
        'project_id',
        'ticket_id',
        'column_id',
        'milestone_id',
        'assignee_id',
        'created_by',
        'title',
        'description',
        'due_date',
        'priority',
        'position',
        'status',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    public const STATUSES = ['todo', 'in_progress', 'done'];

    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    /** The three shapes that share the table (EPIC-011E §15). */
    public const KIND_BOARD = 'board';

    public const KIND_TICKET = 'ticket';

    public const KIND_STANDALONE = 'standalone';

    // ── Relationships ────────────────────────────────────────

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(ProjectColumn::class, 'column_id');
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class, 'milestone_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class, 'task_id')->orderBy('position');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'task_id')->orderBy('created_at');
    }

    /** Tasks this task depends on (must complete before this one can start). */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            Task::class,
            'task_dependencies',
            'task_id',
            'depends_on_task_id'
        );
    }

    /** Tasks that depend on this task. */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            Task::class,
            'task_dependencies',
            'depends_on_task_id',
            'task_id'
        );
    }

    // ── Scopes ───────────────────────────────────────────────

    /**
     * SQL equivalent of isDone(): a board column decides when the task has one, the raw
     * status decides otherwise. Every query that needs "done" goes through here so the rule
     * cannot be re-invented (EPIC-011E §15).
     */
    public function scopeDone(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereHas('column', fn (Builder $column) => $column->where('is_done_column', true))
                ->orWhere(fn (Builder $q) => $q->whereNull('column_id')->where('status', 'done'));
        });
    }

    /**
     * Excludes the one MALFORMED shape: a task linked to both a project and a ticket. That row has
     * NO valid product kind (EPIC-015 §8.4, owner ruling): it is neither a project task nor a
     * ticket task, so no project aggregate, board collection, project task list or time
     * attribution may count it. Everything else (board, standalone, ticket) is valid. The row is
     * never deleted or migrated; the integrity audit keeps flagging it.
     *
     * The single predicate; see `isMalformedKind()` for the per-instance twin. TaskPolicy and
     * TaskQuery already refuse this row and keep their own (equivalent) checks.
     */
    public function scopeOfValidKind(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('tasks.project_id')
            ->orWhereNull('tasks.ticket_id'));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNot(fn (Builder $q) => $q->done());
    }

    /** Due before today (application timezone) and not done. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('due_date')
            ->where('due_date', '<', today())
            ->open();
    }

    // ── Helpers ──────────────────────────────────────────────

    /**
     * True for the invalid legacy row linked to BOTH a project and a ticket (EPIC-014 INV-13,
     * EPIC-015 §8.4). It has no valid kind; `kind()` still answers "board" for it and must not be
     * used to decide whether the row is acceptable. No application path writes it.
     */
    public function isMalformedKind(): bool
    {
        return $this->project_id !== null && $this->ticket_id !== null;
    }

    /** Which of the three task shapes this row is. */
    public function kind(): string
    {
        if ($this->project_id !== null) {
            return self::KIND_BOARD;
        }

        return $this->ticket_id !== null ? self::KIND_TICKET : self::KIND_STANDALONE;
    }

    public function isDone(): bool
    {
        if ($this->column_id !== null) {
            return $this->column?->is_done_column ?? false;
        }

        return $this->status === 'done';
    }

    public function isOverdue(): bool
    {
        return $this->due_date !== null
            && $this->due_date->lt(today())
            && ! $this->isDone();
    }

    /** Human-readable status label, whether board-based or standalone. */
    public function effectiveStatus(): string
    {
        if ($this->column_id !== null && $this->column) {
            return $this->column->name;
        }

        return match ($this->status) {
            'in_progress' => 'In Progress',
            'done' => 'Done',
            default => 'To Do',
        };
    }
}

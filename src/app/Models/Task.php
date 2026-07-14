<?php

namespace App\Models;

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

    // ── Helpers ──────────────────────────────────────────────

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
            && $this->due_date->isPast()
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

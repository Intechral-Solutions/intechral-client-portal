<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectMilestone extends Model
{
    use HasFactory;

    // `completed_at`/`completed_by` are deliberately NOT fillable: only ProjectMilestoneService
    // writes them (EPIC-015 §9.2), so the create/update routes can never touch completion.
    protected $fillable = ['project_id', 'name', 'due_date', 'description'];

    protected $casts = ['due_date' => 'date', 'completed_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'milestone_id');
    }

    /** Who completed it (provenance only, EPIC-015 §9.4). Null while open or once that user is deleted. */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /** Aggregates for the milestones page: every milestone in one query, not two each. */
    public function scopeWithTaskCounts(Builder $query): Builder
    {
        // Valid kinds only: the malformed project+ticket row is in no count (EPIC-015 §8.4).
        return $query->withCount([
            'tasks' => fn (Builder $tasks) => $tasks->ofValidKind(),
            'tasks as done_tasks_count' => fn (Builder $tasks) => $tasks->ofValidKind()->done(),
        ]);
    }

    /**
     * SQL twin of isOverdue() (EPIC-015 Q2): due before today and not explicitly completed. Task
     * progress plays no part, so a zero-task milestone is overdue only until someone completes it.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNull('project_milestones.completed_at')
            ->where('project_milestones.due_date', '<', today());
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('project_milestones.completed_at');
    }

    public function completionPercentage(): int
    {
        return Project::percentage($this->tasks()->ofValidKind()->done()->count(), $this->tasks()->ofValidKind()->count());
    }

    /** Same figure from the aggregates added by scopeWithTaskCounts(). Task progress, not completion. */
    public function completionFromCounts(): int
    {
        return Project::percentage((int) $this->done_tasks_count, (int) $this->tasks_count);
    }

    /** Explicit completion (EPIC-015 Q2): the source of truth, independent of task progress. */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /** Due before today (a milestone due today is not overdue) and not completed. */
    public function isOverdue(): bool
    {
        return $this->due_date->lt(today()) && ! $this->isCompleted();
    }
}

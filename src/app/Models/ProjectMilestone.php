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

    protected $fillable = ['project_id', 'name', 'due_date', 'description'];

    protected $casts = ['due_date' => 'date'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'milestone_id');
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

    public function completionPercentage(): int
    {
        return Project::percentage($this->tasks()->ofValidKind()->done()->count(), $this->tasks()->ofValidKind()->count());
    }

    /** Same figure from the aggregates added by scopeWithTaskCounts(). */
    public function completionFromCounts(): int
    {
        return Project::percentage((int) $this->done_tasks_count, (int) $this->tasks_count);
    }

    /** Due before today and not finished; a milestone due today is not overdue. */
    public function isOverdueAt(int $completion): bool
    {
        return $this->due_date->lt(today()) && $completion < 100;
    }
}

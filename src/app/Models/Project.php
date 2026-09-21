<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'created_by',
        'client_id',
        'start_date',
        'target_date',
        'status',
        'budget',
    ];

    protected $casts = [
        'start_date' => 'date',
        'target_date' => 'date',
        'budget' => 'decimal:2',
    ];

    public const STATUSES = ['active', 'on_hold', 'completed', 'archived'];

    // ── Relationships ────────────────────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function columns(): HasMany
    {
        return $this->hasMany(ProjectColumn::class)->orderBy('position');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(CrmCompany::class, 'project_company')
            ->withTimestamps();
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('due_date');
    }

    // ── Scopes ───────────────────────────────────────────────

    /**
     * The projects ProjectPolicy::view allows: administrators see every project, everyone else
     * only the ones they are a member of. A company link is metadata and never widens this
     * (EPIC-011E D2). Keep it identical to the policy; a test compares them.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('projects.admin')) {
            return $query;
        }

        return $query->whereHas('members', fn (Builder $members) => $members->where('users.id', $user->id));
    }

    /** Aggregate counts the index needs, so rendering a card never queries per project. */
    public function scopeWithTaskStats(Builder $query): Builder
    {
        return $query->withCount([
            'tasks',
            'tasks as done_tasks_count' => fn (Builder $tasks) => $tasks->done(),
            'tasks as overdue_tasks_count' => fn (Builder $tasks) => $tasks->overdue(),
            'members',
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────

    public function hasMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    public function completionPercentage(): int
    {
        return self::percentage($this->tasks()->done()->count(), $this->tasks()->count());
    }

    /** Same figure from the aggregates added by scopeWithTaskStats(). */
    public function completionFromCounts(): int
    {
        return self::percentage((int) $this->done_tasks_count, (int) $this->tasks_count);
    }

    public function overdueTasks(): int
    {
        return $this->tasks()->overdue()->count();
    }

    public static function percentage(int $done, int $total): int
    {
        return $total === 0 ? 0 : (int) round(($done / $total) * 100);
    }
}

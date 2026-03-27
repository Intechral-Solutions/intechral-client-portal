<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\CrmCompany;

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
        'start_date'  => 'date',
        'target_date' => 'date',
        'budget'      => 'decimal:2',
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

    // ── Helpers ──────────────────────────────────────────────

    public function hasMember(User $user): bool
    {
        return $this->members()->where('user_id', $user->id)->exists();
    }

    public function completionPercentage(): int
    {
        $total = $this->tasks()->count();
        if ($total === 0) return 0;

        $done = $this->tasks()
            ->whereHas('column', fn ($q) => $q->where('is_done_column', true))
            ->count();

        return (int) round(($done / $total) * 100);
    }

    public function overdueTasks(): int
    {
        return $this->tasks()
            ->whereNotNull('due_date')
            ->where('due_date', '<', today())
            ->whereHas('column', fn ($q) => $q->where('is_done_column', false))
            ->count();
    }
}

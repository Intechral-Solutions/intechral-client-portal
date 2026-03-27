<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'project_id',
        'task_id',
        'invoice_id',
        'date',
        'duration_minutes',
        'description',
        'billable',
        'billed',
        'timer_started_at',
    ];

    protected $casts = [
        'date'             => 'date',
        'billable'         => 'boolean',
        'billed'           => 'boolean',
        'timer_started_at' => 'datetime',
    ];

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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    // ── Helpers ──────────────────────────────────────────────

    public function isRunning(): bool
    {
        return $this->timer_started_at !== null;
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

        if ($h === 0) return "{$m}m";
        if ($m === 0) return "{$h}h";
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

    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    public function scopeBetweenDates($query, string $from, string $to)
    {
        return $query->whereBetween('date', [$from, $to]);
    }

    public function scopeBillable($query)
    {
        return $query->where('billable', true)->where('billed', false);
    }
}

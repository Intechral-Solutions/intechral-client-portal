<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TimeEntry extends Model
{
    use HasFactory;

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
        'date'             => 'date',
        'billable'         => 'boolean',
        'billed'           => 'boolean',
        'timer_started_at' => 'datetime',
        'stopped_at'       => 'datetime',
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

    /**
     * Entries that were running at any point during the given window.
     * Only matches finalized (stopped) entries; running timers are handled separately.
     */
    public function scopeRunningDuring($query, \Carbon\Carbon $from, \Carbon\Carbon $to)
    {
        return $query
            ->whereNotNull('stopped_at')
            ->where('timer_started_at', '<=', $to)
            ->where('stopped_at', '>=', $from);
    }
}

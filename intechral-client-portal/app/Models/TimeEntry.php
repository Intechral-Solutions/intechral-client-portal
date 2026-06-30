<?php

namespace App\Models;

use Carbon\Carbon;
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

    /** Canonical active timer state. */
    public function scopeRunning($query)
    {
        return $query
            ->whereNotNull('timer_started_at')
            ->whereNull('stopped_at');
    }

    /**
     * Finalized entries that overlapped the given window.
     *
     * Stopped timers intentionally clear timer_started_at, so their approximate
     * start is reconstructed from stopped_at - duration_minutes.
     */
    public function scopeRunningDuring($query, Carbon $from, Carbon $to)
    {
        return $query
            ->whereNull('timer_started_at')
            ->whereNotNull('stopped_at')
            ->where('stopped_at', '>=', $from)
            ->whereRaw(
                'DATE_SUB(stopped_at, INTERVAL duration_minutes MINUTE) <= ?',
                [$to],
            );
    }
}

<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntryBlock extends Model
{
    protected $fillable = [
        'time_entry_id',
        'user_id',
        'block_date',
        'block_number',
        'allocation_pct',
        'is_overridden',
    ];

    protected $casts = [
        'block_date' => 'date',
        'allocation_pct' => 'decimal:2',
        'is_overridden' => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────

    public function timeEntry(): BelongsTo
    {
        return $this->belongsTo(TimeEntry::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Helpers ──────────────────────────────────────────────

    /** Returns the Carbon datetime when this 15-minute block starts. */
    public function startsAt(): Carbon
    {
        return $this->block_date->copy()->addMinutes($this->block_number * 15);
    }

    /** Returns the Carbon datetime when this 15-minute block ends. */
    public function endsAt(): Carbon
    {
        return $this->startsAt()->addMinutes(15);
    }

    /** Minutes allocated to this entry for this block (allocation_pct of 15). */
    public function minutesAllocated(): float
    {
        return round((float) $this->allocation_pct * 15 / 100, 2);
    }
}

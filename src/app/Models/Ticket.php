<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_number',
        'user_id',
        'company_id',
        'assignee_id',
        'title',
        'description',
        'category',
        'priority',
        'status',
        'sla_due_at',
        'resolved_at',
        'closed_at',
    ];

    protected $casts = [
        'sla_due_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(CrmCompany::class, 'company_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'ticket_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class)->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class)->whereNull('reply_id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(TicketStatusHistory::class)->orderBy('created_at');
    }

    // ── Helpers ──────────────────────────────────────────────

    public function isOverdue(): bool
    {
        return $this->sla_due_at !== null
            && $this->sla_due_at->isPast()
            && ! in_array($this->status, ['resolved', 'closed']);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['resolved', 'closed']);
    }

    // ── Scopes ───────────────────────────────────────────────

    public function scopeForUser($query, User $user)
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', now())
            ->whereNotIn('status', ['resolved', 'closed']);
    }

    /**
     * Internal notes never influence matches unless the caller opts in with $includeInternal,
     * which it derives from TicketPolicy::viewInternal (EPIC-010D H3).
     */
    public function scopeSearch($query, string $term, bool $includeInternal = false)
    {
        return $query->where(function ($q) use ($term, $includeInternal) {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")
                ->orWhere('ticket_number', 'like', "%{$term}%")
                ->orWhereHas('replies', fn ($r) => $r->where('body', 'like', "%{$term}%")
                    ->when(! $includeInternal, fn ($r) => $r->where('is_internal', false)));
        });
    }

    // ── Status valid transitions ─────────────────────────────

    public const TRANSITIONS = [
        'open' => ['in_progress', 'pending_user', 'resolved', 'closed'],
        'in_progress' => ['pending_user', 'resolved', 'closed', 'open'],
        'pending_user' => ['in_progress', 'resolved', 'closed', 'open'],
        'resolved' => ['closed', 'open'],
        'closed' => ['open'],
    ];

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::TRANSITIONS[$this->status] ?? []);
    }

    // ── SLA hours by priority ────────────────────────────────

    public const SLA_HOURS = [
        'critical' => 4,
        'high' => 8,
        'medium' => 24,
        'low' => 72,
    ];
}

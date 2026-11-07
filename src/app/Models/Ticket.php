<?php

namespace App\Models;

use App\Support\Helpdesk\TicketReference;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use LogicException;

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

    // `due_override_at` is deliberately NOT fillable: it changes only through TicketService::addReply(), with its history row.
    protected $casts = [
        'sla_due_at' => 'datetime',
        'due_override_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /** True only inside {@see convertingLegacyReferences()}: the one window in which a stored reference may change. */
    private static bool $convertingLegacyReferences = false;

    protected static function booted(): void
    {
        // C6-R: only a canonical reference is ever issued, and once issued it never changes (ADR-008). The single exception is the
        // guarded release-0.9 conversion of a legacy value (`tickets:rereference`): a non-canonical value becoming a canonical one,
        // inside its scope. Anything else, a canonical value included, is a bug and throws.
        static::creating(function (Ticket $ticket) {
            if (! TicketReference::isCanonical($ticket->ticket_number)) {
                throw new InvalidArgumentException('A ticket is created with a canonical TK-XXXXX-XXXXX reference.');
            }
        });

        static::updating(function (Ticket $ticket) {
            // Release 1.0: the original SLA deadline is fixed at creation. An operator's working due date is `due_override_at`.
            if ($ticket->isDirty('sla_due_at')) {
                throw new LogicException('The SLA deadline is immutable once set; adjust the due date override instead.');
            }

            if (! $ticket->isDirty('ticket_number')) {
                return;
            }

            if (self::$convertingLegacyReferences
                && ! TicketReference::isCanonical($ticket->getOriginal('ticket_number'))
                && TicketReference::isCanonical($ticket->ticket_number)) {
                return;
            }

            throw new LogicException('A ticket reference is immutable once issued.');
        });
    }

    /** Opens the one window in which a legacy reference may be replaced by a canonical one. Used by `tickets:rereference` and by DevSeeder, which takes over a legacy development fixture. */
    public static function convertingLegacyReferences(callable $callback): mixed
    {
        self::$convertingLegacyReferences = true;

        try {
            return $callback();
        } finally {
            self::$convertingLegacyReferences = false;
        }
    }

    // ── Routing ──────────────────────────────────────────────

    /** The public identity in every URL is the reference, never the numeric key (C6-R). */
    public function getRouteKeyName(): string
    {
        return 'ticket_number';
    }

    /**
     * Strict: only a canonical reference resolves, and a value that is not one is refused before the database is touched. A numeric id,
     * a legacy `TKT-` number or any other spelling is a 404, so a stale link after recovery cannot reach a different ticket.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        $reference = $field === null || $field === 'ticket_number' ? TicketReference::canonicalize($value) : null;

        return $reference === null ? null : $this->where('ticket_number', $reference)->first();
    }

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

    public function dueDateChanges(): HasMany
    {
        return $this->hasMany(TicketDueDateChange::class)->orderBy('created_at')->orderBy('id');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(TicketStatusHistory::class)->orderBy('created_at');
    }

    // ── Helpers ──────────────────────────────────────────────

    /** The operational due date: the operator's override when there is one, otherwise the original SLA deadline. */
    public function effectiveDueAt(): ?CarbonInterface
    {
        return $this->due_override_at ?? $this->sla_due_at;
    }

    public function hasDueOverride(): bool
    {
        return $this->due_override_at !== null;
    }

    /** Operationally overdue: past the EFFECTIVE due date while still open. */
    public function isOverdue(): bool
    {
        $due = $this->effectiveDueAt();

        return $due !== null
            && $due->isPast()
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

    /** SQL twin of {@see self::isOverdue()}. */
    public function scopeOverdue($query)
    {
        return $query->whereRaw('COALESCE(due_override_at, sla_due_at) < ?', [now()])
            ->whereNotIn('status', ['resolved', 'closed']);
    }

    /** Soonest effective due date first; a ticket with neither date sorts first, as it did when only `sla_due_at` existed. */
    public function scopeOrderByEffectiveDue($query)
    {
        return $query->orderByRaw('COALESCE(due_override_at, sla_due_at)');
    }

    /**
     * Internal notes never influence matches unless the caller opts in with $includeInternal,
     * which it derives from TicketPolicy::viewInternal (EPIC-010D H3).
     */
    public function scopeSearch($query, string $term, bool $includeInternal = false)
    {
        // The term is literal text (EPIC-010D F-4): `%`, `_` and `\` are escaped, so they match
        // themselves rather than acting as LIKE wildcards. The backslash is escaped first.
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        return $query->where(function ($q) use ($like, $term, $includeInternal) {
            $q->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('ticket_number', 'like', $like)
                ->when(TicketReference::fromSearch($term), fn ($q, $reference) => $q->orWhere('ticket_number', $reference))
                ->orWhereHas('replies', fn ($r) => $r->where('body', 'like', $like)
                    ->when(! $includeInternal, fn ($r) => $r->where('is_internal', false)));
        });
    }

    // ── Status valid transitions ─────────────────────────────

    /** Every status value, in lifecycle order (the DB enum). */
    public const STATUSES = ['open', 'in_progress', 'pending_user', 'resolved', 'closed'];

    public const TRANSITIONS = [
        'open' => ['in_progress', 'pending_user', 'resolved', 'closed'],
        'in_progress' => ['pending_user', 'resolved', 'closed', 'open'],
        'pending_user' => ['in_progress', 'resolved', 'closed', 'open'],
        'resolved' => ['closed', 'open'],
        'closed' => ['open'],
    ];

    /** Operator-facing status vocabulary (persisted values are unchanged). The customer wording lives in RequesterTicketPresenter. */
    public const OPERATOR_STATUS_LABELS = [
        'open' => 'Open',
        'in_progress' => 'In Progress',
        'pending_user' => 'Waiting on customer',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    public static function operatorStatusLabel(string $status): string
    {
        return self::OPERATOR_STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

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

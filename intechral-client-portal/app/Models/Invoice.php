<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number',
        'client_id',
        'created_by',
        'project_id',
        'issued_at',
        'due_at',
        'paid_at',
        'sent_at',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'total',
        'currency',
        'status',
        'stripe_payment_intent_id',
        'notes',
    ];

    protected $casts = [
        'issued_at'  => 'date',
        'due_at'     => 'date',
        'paid_at'    => 'datetime',
        'sent_at'    => 'datetime',
        'subtotal'   => 'decimal:2',
        'tax_rate'   => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total'      => 'decimal:2',
    ];

    public const STATUSES = ['draft', 'sent', 'paid', 'overdue', 'cancelled'];

    // ── Relationships ────────────────────────────────────────

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('paid_at');
    }

    // ── Helpers ──────────────────────────────────────────────

    public function isOverdue(): bool
    {
        return in_array($this->status, ['sent']) && $this->due_at->isPast();
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isPayable(): bool
    {
        return in_array($this->status, ['sent', 'overdue']);
    }

    public function amountDue(): string
    {
        return number_format((float) $this->total, 2);
    }

    /** Total amount paid so far across all payment records */
    public function amountPaidSoFar(): float
    {
        return (float) $this->payments()->sum('amount');
    }
}

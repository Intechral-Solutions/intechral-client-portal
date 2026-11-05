<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One adjustment of a ticket's operator due date (Release 1.0). Operator-only history: never serialized to a requester.
 * `reply_id` is NOT NULL and UNIQUE, and (`ticket_id`, `reply_id`) is a composite foreign key to the reply, so a change
 * always has exactly one explaining reply or note and that reply belongs to the same ticket.
 * A null `previous_override_at` means the SLA deadline applied before; a null `new_override_at` means it was restored.
 */
class TicketDueDateChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'reply_id',
        'user_id',
        'previous_override_at',
        'new_override_at',
        'created_at',
    ];

    protected $casts = [
        'previous_override_at' => 'datetime',
        'new_override_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(TicketReply::class, 'reply_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

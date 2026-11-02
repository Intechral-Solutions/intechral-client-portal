<?php

namespace App\Rules;

use App\Models\Ticket;
use App\Support\Helpdesk\TicketReference;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Binds a SUBMITTED numeric `ticket_id` to the ticket's public reference (C6-R, ADR-008). Time attribution still carries the internal key,
 * but a page, a running client-side timer or a saved form that was built before a recovery names a ticket by a number that may now belong
 * to a different ticket. The companion `ticket_reference` it was built with must be that ticket's reference, so a stale id is refused
 * instead of silently attaching time to someone else's ticket. A missing, malformed or different reference is the same refusal.
 */
class TicketReferenceMatchesId implements ValidationRule
{
    public function __construct(private readonly mixed $reference) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $reference = TicketReference::canonicalize($this->reference);
        $ticket = is_numeric($value) ? Ticket::query()->select(['id', 'ticket_number'])->find($value) : null;

        if ($reference === null || $ticket === null || $ticket->ticket_number !== $reference) {
            $fail('The selected :attribute is invalid.');
        }
    }
}

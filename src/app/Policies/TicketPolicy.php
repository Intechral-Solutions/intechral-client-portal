<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        // Ticket owner always sees their own tickets
        if ($ticket->user_id === $user->id) {
            return true;
        }

        // Operators with tickets.view can see all tickets
        return $user->can('tickets.view') && $user->hasAnyRole(['operator']);
    }
}

<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;

/**
 * Ticket authorization (EPIC-010D §9). Operator-side Ticket work is identified by the
 * `tickets.assign` capability, never by a role name: the built-in `user` role holds
 * `tickets.view`, so that permission cannot mean "all Tickets". Legacy company membership grants
 * no Ticket visibility (D1).
 */
class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        return $ticket->user_id === $user->id || $this->worksTickets($user);
    }

    public function reply(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    /**
     * Internal notes and their attachments. Without a Ticket this answers the class-level
     * question ("may this actor see internal content of the Tickets they can view?"), which is
     * what a search over an already visibility-scoped query needs.
     */
    public function viewInternal(User $user, ?Ticket $ticket = null): bool
    {
        if (! $this->worksTickets($user)) {
            return false;
        }

        return $ticket === null || $this->view($user, $ticket);
    }

    /**
     * Ticket → reply → attachment: the attachment must belong to the Ticket, the actor must see
     * the Ticket, and an attachment on an internal note needs internal visibility.
     */
    public function downloadAttachment(User $user, Ticket $ticket, TicketAttachment $attachment): bool
    {
        if ($attachment->ticket_id !== $ticket->id || ! $this->view($user, $ticket)) {
            return false;
        }

        if ($attachment->reply_id === null) {
            return true;
        }

        $reply = $attachment->reply;

        if ($reply === null || $reply->ticket_id !== $ticket->id) {
            return false;
        }

        return ! $reply->is_internal || $this->viewInternal($user, $ticket);
    }

    private function worksTickets(User $user): bool
    {
        return $user->can('tickets.assign');
    }
}

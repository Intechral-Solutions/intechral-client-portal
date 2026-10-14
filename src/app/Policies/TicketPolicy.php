<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Shared\Pilot\CustomerPilotGate;

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

    /**
     * Whether the actor works tickets as an operator: holds `tickets.assign` AND is not a restricted customer.
     *
     * R0.9-CS1: an account without `portal.operator` is a customer to the boundary in closed and pilot mode, and
     * `tickets.assign` must not widen what a customer may see or do on the Helpdesk routes they are allowed. Without
     * this clause such an account would read every ticket, download internal-note attachments, post internal notes and be
     * redirected into the operator application. Operators (eligible accounts) and legacy mode are unchanged.
     */
    private function worksTickets(User $user): bool
    {
        return $user->can('tickets.assign') && ! CustomerPilotGate::restricts($user);
    }
}

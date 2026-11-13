<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Services\PartnerSupportAccess;
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
        return $this->viewAsRequesterOrOperator($user, $ticket);
    }

    /**
     * The two established ways to see a Ticket: being its requester, or working Tickets as an operator. Today `view` is
     * exactly this. Authorization that REUSES Ticket visibility for something else (ticket-linked task visibility, time
     * attribution to a ticket task: TaskPolicy, AccessibleTimeContext) asks this ability instead of `view`, so a later
     * widening of `view` (partner support personnel, Release 1.0) can never also grant task visibility or time logging.
     * A widening of `view` must NOT change this method; a new viewer class gets its own named ability.
     */
    public function viewAsRequesterOrOperator(User $user, Ticket $ticket): bool
    {
        return $ticket->user_id === $user->id || $this->worksTickets($user);
    }

    public function reply(User $user, Ticket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    /**
     * Release 1.0 (R1-4): the PARTNER SUPPORT viewer class, its own named ability and deliberately not part of `view`. True only for effective
     * partner support authority over this Ticket's organization, read from the tables on every call ({@see PartnerSupportAccess}). It is asked only
     * by the `support.*` routes; no existing route, relation or presenter asks it, so a requester's and an operator's visibility are unchanged.
     */
    public function viewAsPartnerSupport(User $user, Ticket $ticket): bool
    {
        return PartnerSupportAccess::allows($user, $ticket);
    }

    /**
     * Release 1.0 (R1-5): whether the actor may use the partner support CREATION page and route at all: they hold an effective support grant right now
     * ({@see PartnerSupportAccess::holdsGrant()}). Which customer and which contact is decided inside the creation transaction
     * (`ServiceRequestAccess::attributionForPartnerSupport`); this is the door, not the room.
     */
    public function createAsPartnerSupport(User $user): bool
    {
        return PartnerSupportAccess::holdsGrant($user);
    }

    /**
     * A partner support user may add a PUBLIC reply to a supported request that is still being worked. It never carries an internal flag, a due date,
     * a status change or an assignment: it authorizes exactly one thing, and TicketService::addPartnerSupportReply re-checks it under lock.
     */
    public function replyAsPartnerSupport(User $user, Ticket $ticket): bool
    {
        return $this->viewAsPartnerSupport($user, $ticket) && $ticket->isOpen();
    }

    /** A support user's attachment: on the opening message or on a PUBLIC reply of a ticket they support, never on an internal note. */
    public function downloadAttachmentAsPartnerSupport(User $user, Ticket $ticket, TicketAttachment $attachment): bool
    {
        if ($attachment->ticket_id !== $ticket->id || ! $this->viewAsPartnerSupport($user, $ticket)) {
            return false;
        }

        if ($attachment->reply_id === null) {
            return true;
        }

        $reply = $attachment->reply;

        return $reply !== null && $reply->ticket_id === $ticket->id && ! $reply->is_internal;
    }

    /**
     * Release 1.0: change the operational due date, as part of a reply or note. Operators only (`worksTickets`), on a ticket they
     * can view. Never a requester: being able to reply is not being able to move the deadline.
     */
    public function adjustDueDate(User $user, Ticket $ticket): bool
    {
        return $this->worksTickets($user) && $this->view($user, $ticket);
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

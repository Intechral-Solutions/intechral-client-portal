<?php

namespace App\Support\Helpdesk;

use App\Services\ServiceRequestAccess;

/**
 * Who a new Ticket is for, who it belongs to and who submitted it: the three values of Release 1.0 (R1-2, R1-5).
 *
 *  - `requesterId`    -> `tickets.user_id`: the customer contact who needs the support;
 *  - `organizationId` -> `tickets.organization_id`: the customer organization that owns the request;
 *  - `submittedById`  -> `tickets.submitted_by_id`: the authenticated actor who created the row.
 *
 * Produced only by {@see ServiceRequestAccess} ({@see ServiceRequestAccess::attributionFor()} for a requester's own request,
 * {@see ServiceRequestAccess::attributionForPartnerSupport()} for one raised on a managed customer's behalf), which decides all three from current
 * database state inside the creation transaction, and consumed only by `TicketService::createWithReference()`, which is private. Nothing builds one
 * from request input. The values are provenance, not authorization: they are never read to decide who may see a Ticket (visibility is
 * requester-or-operator, or partner support through its own ability).
 */
final readonly class TicketAttribution
{
    public function __construct(
        public int $organizationId,
        public int $submittedById,
        public int $requesterId,
    ) {}
}

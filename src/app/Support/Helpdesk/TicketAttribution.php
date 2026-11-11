<?php

namespace App\Support\Helpdesk;

use App\Services\ServiceRequestAccess;

/**
 * Who a new Ticket belongs to and who submitted it: the two durable provenance values of Release 1.0 (R1-2).
 *
 * Produced only by {@see ServiceRequestAccess::attributionFor()}, which decides it from current database state inside the
 * creation transaction, and consumed only by `TicketService::createWithReference()`, which is private. Nothing builds one from request
 * input. The values are provenance, not authorization: they are never read to decide who may see a Ticket.
 */
final readonly class TicketAttribution
{
    public function __construct(
        public int $organizationId,
        public int $submittedById,
    ) {}
}

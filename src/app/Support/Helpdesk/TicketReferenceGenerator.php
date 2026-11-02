<?php

namespace App\Support\Helpdesk;

/**
 * Where TicketService gets each new reference. A seam, not a strategy: production always uses the random default, and tests bind a
 * stub to force a collision, an exhaustion or a race.
 */
class TicketReferenceGenerator
{
    public function next(): string
    {
        return TicketReference::generate();
    }
}

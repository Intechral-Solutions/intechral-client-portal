<?php

namespace App\Shared\Pilot;

/**
 * What a customer account may reach (R0.9-CS1). Resolved by {@see CustomerPilotGate::mode()}, never read from
 * configuration directly, so an unknown value can only ever resolve to {@see self::Closed}.
 */
enum CustomerSurfaceMode: string
{
    /** The default. Authentication and account essentials only, and a dashboard that says the portal is not open yet. */
    case Closed = 'closed';

    /** The approved early-access surface: the Helpdesk and the customer's own projects as a reduced overview. */
    case Pilot = 'pilot';

    /** The behaviour before the boundary existed. Local and testing environments only; elsewhere it resolves to Closed. */
    case Legacy = 'legacy';
}

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Customer surface mode (Release 0.9, R0.9-CS1)
    |--------------------------------------------------------------------------
    |
    | What a customer account (any signed-in user without the `portal.operator` capability) may reach:
    |
    |   closed  authentication, account management and sign-out only; the dashboard says the portal is
    |           not open yet. The DEFAULT, and what every missing, empty, null or unrecognised value means.
    |   pilot   the approved early-access surface: the Helpdesk and the customer's own projects.
    |   legacy  the behaviour before the boundary existed. Honoured ONLY when APP_ENV is local or testing;
    |           anywhere else it resolves to closed and logs a warning.
    |
    | There is no per-module switch: the surface each mode allows is a reviewed list in code
    | (App\Shared\Pilot\CustomerPilotGate), so no configuration value can turn on an unreviewed customer
    | module. Operators (holders of `portal.operator`) are never affected.
    | docs/architecture/customer-pilot-surface.md
    |
    */

    'customer_surface_mode' => env('PORTAL_CUSTOMER_SURFACE_MODE', 'closed'),

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Operator new-ticket alert destination (EPIC-017 O3, RE-H13)
    |--------------------------------------------------------------------------
    |
    | One mailbox that is told when a customer raises a request. It is NOT every operator and NOT every
    | `tickets.assign` holder. May be unset in local and test environments (the alert is skipped with a log
    | line). Production, which includes the staging installation (it runs APP_ENV=production), must set it:
    | `php artisan runtime:preflight` refuses a missing or invalid address, and the cron workers then refuse
    | to start. Configuration proves the address is well formed; that mail reaches it is proved by the first
    | real alert. App\Support\Helpdesk\HelpdeskNotifyAddress, docs/architecture/notification-delivery.md.
    |
    */

    'notify_address' => env('HELPDESK_NOTIFY_ADDRESS'),

    /*
    |--------------------------------------------------------------------------
    | Legacy ticket-reference conversion gate (Release 0.9 C6-R, ADR-008)
    |--------------------------------------------------------------------------
    |
    | `php artisan tickets:rereference --apply` replaces pre-C6-R ticket numbers (`TKT-0001` ...) with `TK-XXXXX-XXXXX` references. It
    | refuses to change anything unless this is true. Leave it unset (false) everywhere except the one staging conversion: set it for
    | that run, then remove it. A fresh production install has no legacy rows, proves it with `tickets:rereference --check`, and never
    | sets this. The command and this gate are Release 0.9 scaffolding.
    |
    */

    'rereference_allowed' => filter_var(env('TICKET_REREFERENCE_ALLOWED', false), FILTER_VALIDATE_BOOL),

];

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

];

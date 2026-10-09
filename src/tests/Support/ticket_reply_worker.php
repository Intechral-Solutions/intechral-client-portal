<?php

/*
 * Child process for TicketReplyRaceTest (EPIC-017 WP2, O2). Bootstraps the application against the
 * testing database (TestDatabaseSafety refuses anything else), waits for a shared start time so
 * the workers really overlap, then performs ONE action and prints its outcome as JSON ("ok:..." or
 * "error: <class>: <message>"):
 *
 *  - default mode: a customer reply through TicketService::addReply ("ok:<reply id>");
 *  - mode `transition:<status>`: TicketService::transition to that status ("ok:transitioned").
 *
 * Usage: php ticket_reply_worker.php <startAtMicrotime> <ticketId> <userId> <label> [mode]
 */

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$startAt = (float) ($argv[1] ?? 0);
$ticket = Ticket::findOrFail((int) ($argv[2] ?? 0));
$user = User::findOrFail((int) ($argv[3] ?? 0));
$label = (string) ($argv[4] ?? 'worker');
$mode = (string) ($argv[5] ?? 'reply');

while (microtime(true) < $startAt) {
    usleep(100);
}

try {
    if (str_starts_with($mode, 'transition:')) {
        $app->make(TicketService::class)->transition($ticket, $user, substr($mode, strlen('transition:')));
        echo json_encode('ok:transitioned');
    } else {
        $reply = $app->make(TicketService::class)->addReply($ticket, $user, "Race reply from {$label}", false);
        echo json_encode('ok:'.$reply->id);
    }
} catch (Throwable $e) {
    echo json_encode('error: '.get_class($e).': '.$e->getMessage());
}

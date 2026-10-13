<?php

/*
 * EPIC-017 WP2 step 3 — the O2 reply lifecycle under REAL concurrency (MariaDB, separate processes).
 *
 * Every worker holds a ticket the same instant it was `pending_user` and posts a customer reply.
 * TicketService::addReply locks the ticket row and reads its committed status, so exactly ONE
 * reply moves it to `in_progress` and writes exactly one history row; the others see
 * `in_progress` and leave it alone. Without the lock each worker would read `pending_user` and
 * record its own transition.
 *
 * Like the H8 probe (TicketNumberCharacterizationTest), it must end RefreshDatabase's transaction
 * so the workers can see its rows, and it deletes them in a finally block. It seeds no roles, so
 * nothing but its own user and ticket is ever committed; a user with no permission is a customer.
 * TICKET_REPLY_RACE_WORKERS turns the dial up for a one-off soak.
 *
 * The second test races TicketService::transition on its own (competing operators). Its outcome is
 * fixed by the lock, not by timing: whichever worker wins, exactly one transition succeeds and the
 * others are refused, so it cannot fail on correct code however the workers interleave.
 */

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\TicketStatusHistory;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedUsers;

/**
 * Launch $workers processes that all start at the same instant and run $mode against the ticket
 * as the user; return each worker's outcome string ("ok:..." or "error: ...").
 *
 * @return Collection<int, string>
 */
function ticketRaceOutcomes(int $workers, int $ticketId, int $userId, string $mode = 'reply'): Collection
{
    $connection = config('database.connections.'.config('database.default'));
    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => $connection['host'],
        'DB_PORT' => $connection['port'],
        'DB_DATABASE' => $connection['database'],
        'DB_USERNAME' => $connection['username'],
        'DB_PASSWORD' => $connection['password'],
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
    ];
    $startAt = microtime(true) + 2.5;

    $processes = [];
    for ($w = 0; $w < $workers; $w++) {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/ticket_reply_worker.php'), (string) $startAt, (string) $ticketId, (string) $userId, "w{$w}", $mode],
            base_path(),
            $env,
        );
        $process->setTimeout(120);
        $process->start();
        $processes[] = $process;
    }

    return collect($processes)->map(function (Process $process) {
        $process->wait();
        $decoded = json_decode(trim($process->getOutput()), true);

        return is_string($decoded) ? $decoded : 'error: worker failed: '.$process->getErrorOutput().$process->getOutput();
    });
}

it('moves a waiting ticket to in_progress exactly once when customer replies race', function () {
    DB::commit();
    $customer = User::factory()->create();
    $ticket = Ticket::factory()->create(['user_id' => $customer->id, 'status' => 'pending_user', 'assignee_id' => null]);

    $workers = (int) (getenv('TICKET_REPLY_RACE_WORKERS') ?: 6);

    try {
        $outcomes = ticketRaceOutcomes($workers, $ticket->id, $customer->id);

        $errors = $outcomes->reject(fn (string $o) => str_starts_with($o, 'ok:'));
        $history = TicketStatusHistory::where('ticket_id', $ticket->id)->get(['user_id', 'old_status', 'new_status']);

        expect($errors->values()->all())->toBe([])
            ->and(TicketReply::where('ticket_id', $ticket->id)->count())->toBe($workers)
            ->and($ticket->fresh()->status)->toBe('in_progress')
            ->and($history->map(fn ($h) => [$h->user_id, $h->old_status, $h->new_status])->all())
            ->toBe([[$customer->id, 'pending_user', 'in_progress']]);
    } finally {
        CommittedUsers::delete($customer->id);
    }

    expect(Ticket::whereKey($ticket->id)->exists())->toBeFalse();
});

it('lets exactly one of several racing operators make the same transition, recording one history row', function () {
    DB::commit();
    $requester = User::factory()->create();
    $operator = User::factory()->create();
    $ticket = Ticket::factory()->create(['user_id' => $requester->id, 'status' => 'open', 'assignee_id' => null]);

    $workers = (int) (getenv('TICKET_REPLY_RACE_WORKERS') ?: 6);

    try {
        $outcomes = ticketRaceOutcomes($workers, $ticket->id, $operator->id, 'transition:resolved');

        // `open → resolved` is valid once; every other worker meets `resolved → resolved` under the lock.
        $refused = $outcomes->filter(fn (string $o) => str_starts_with($o, 'error: Illuminate\\Validation\\ValidationException:'));
        $history = TicketStatusHistory::where('ticket_id', $ticket->id)->get(['user_id', 'old_status', 'new_status']);

        expect($outcomes->filter(fn (string $o) => str_starts_with($o, 'ok:'))->count())->toBe(1)
            ->and($refused->count())->toBe($workers - 1)
            ->and($ticket->fresh()->status)->toBe('resolved')
            ->and($history->map(fn ($h) => [$h->user_id, $h->old_status, $h->new_status])->all())
            ->toBe([[$operator->id, 'open', 'resolved']]);
    } finally {
        CommittedUsers::delete([$requester->id, $operator->id]);
    }

    expect(Ticket::whereKey($ticket->id)->exists())->toBeFalse();
});

<?php

/*
 * EPIC-017 WP2 G3-A — the two queued ticket notifications and a ticket that is gone by the time the worker runs.
 *
 * `TicketCreatedNotification` and `TicketRepliedNotification` are queued. A ticket can be deleted before the job
 * runs (the E2E cleanup deletes the tickets a browser run created; a user delete cascades to its tickets). Without
 * `#[DeleteWhenMissingModels]` the worker's unserialize throws ModelNotFoundException and the job lands in
 * `failed_jobs`. With it (honoured for queued notifications by Laravel 13.17, which is what is installed) the job is
 * discarded successfully: there is nothing left to tell anyone about.
 *
 * These tests use the REAL database queue and a REAL `queue:work --once` worker, because `failed_jobs` rows are
 * written by the worker, not by the job: a sync job cannot prove "no new failed_jobs record". They cover only that
 * narrow lifecycle correction; every other failure must still fail, and an existing ticket must still send.
 */

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketRepliedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/** The same shape as TicketCreatedNotification, WITHOUT the attribute: the control that proves the harness can see a failure. */
class UnprotectedTicketNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Ticket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("[{$this->ticket->ticket_number}] unprotected");
    }
}

beforeEach(function () {
    config(['queue.default' => 'database', 'mail.default' => 'array']);
});

/** Run exactly one queued job, the way a worker does, and say what was left behind. */
function ticketWorkerRunsOnce(): array
{
    Artisan::call('queue:work', ['--once' => true, '--tries' => 1, '--sleep' => 0, '--queue' => 'default']);

    return [
        'jobs' => DB::table('jobs')->count(),
        'failed' => DB::table('failed_jobs')->count(),
    ];
}

function ticketPayload(): array
{
    return json_decode((string) DB::table('jobs')->value('payload'), true);
}

it('queues both ticket notifications with the missing-model flag set, and nothing else sets it', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user, 'user')->create();
    $reply = TicketReply::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $user->id]);

    $user->notify(new TicketCreatedNotification($ticket));
    $user->notify(new TicketRepliedNotification($ticket, $reply));
    $user->notify(new UnprotectedTicketNotification($ticket));

    $flags = DB::table('jobs')->orderBy('id')->pluck('payload')->map(fn ($payload) => json_decode($payload, true)['deleteWhenMissingModels'])->all();

    expect($flags)->toBe([true, true, false]);
});

it('discards a queued TicketCreatedNotification whose ticket was deleted: the job is consumed and no failed_jobs row is written', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user, 'user')->create();
    $user->notify(new TicketCreatedNotification($ticket));
    $sent = 0;
    Event::listen(MessageSent::class, function () use (&$sent) {
        $sent++;
    });

    $ticket->delete();

    expect(ticketWorkerRunsOnce())->toBe(['jobs' => 0, 'failed' => 0])
        ->and($sent)->toBe(0);
});

it('discards a queued TicketRepliedNotification whose ticket (and so its reply) was deleted', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user, 'user')->create();
    $reply = TicketReply::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $user->id]);
    $user->notify(new TicketRepliedNotification($ticket, $reply));

    $ticket->delete();

    expect(DB::table('ticket_replies')->where('id', $reply->id)->exists())->toBeFalse()
        ->and(ticketWorkerRunsOnce())->toBe(['jobs' => 0, 'failed' => 0]);
});

it('control: the same notification WITHOUT the attribute fails into failed_jobs when its ticket is gone', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user, 'user')->create();
    $user->notify(new UnprotectedTicketNotification($ticket));

    $ticket->delete();

    // Proves this harness would notice the original defect; the two tests above are not passing vacuously.
    expect(ticketWorkerRunsOnce())->toBe(['jobs' => 0, 'failed' => 1])
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('ModelNotFoundException');
});

it('still sends the mail for a ticket that exists', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user, 'user')->create();
    $user->notify(new TicketCreatedNotification($ticket));
    $sent = [];
    Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent) {
        $sent[] = $event->message->getSubject();
    });

    expect(ticketWorkerRunsOnce())->toBe(['jobs' => 0, 'failed' => 0])
        ->and($sent)->toBe(["[{$ticket->ticket_number}] Ticket received: {$ticket->title}"]);
});

it('still sends a reply mail for a ticket and reply that exist', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user, 'user')->create();
    $reply = TicketReply::factory()->create(['ticket_id' => $ticket->id, 'user_id' => $user->id]);
    $user->notify(new TicketRepliedNotification($ticket, $reply));
    $sent = 0;
    Event::listen(MessageSent::class, function () use (&$sent) {
        $sent++;
    });

    expect(ticketWorkerRunsOnce())->toBe(['jobs' => 0, 'failed' => 0])
        ->and($sent)->toBe(1);
});

it('does not suppress an unrelated failure: a transport error for an existing ticket still fails the job', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user, 'user')->create();
    $user->notify(new TicketCreatedNotification($ticket));
    Event::listen(NotificationSending::class, function () {
        throw new RuntimeException('mail transport down');
    });

    expect(ticketWorkerRunsOnce())->toBe(['jobs' => 0, 'failed' => 1])
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('mail transport down');
});

it('does not touch work it does not own: an unrelated queued job is left on the queue', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user, 'user')->create();
    $user->notify(new TicketCreatedNotification($ticket));
    DB::table('jobs')->insert([
        'queue' => 'someone-elses-queue', 'payload' => '{"unrelated":true}', 'attempts' => 0,
        'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
    ]);
    $ticket->delete();

    // The worker is pinned to `default`, discards this notification, and leaves the other queue's job untouched.
    expect(ticketWorkerRunsOnce())->toBe(['jobs' => 1, 'failed' => 0])
        ->and(DB::table('jobs')->value('queue'))->toBe('someone-elses-queue');
});

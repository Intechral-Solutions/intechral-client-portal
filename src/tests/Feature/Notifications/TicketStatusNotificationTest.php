<?php

use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use App\Models\User;
use App\Notifications\TicketStatusNotification;
use App\Services\TicketService;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

/*
 * EPIC-017 O3, option c: the requester is told when their request becomes "Waiting on you" (pending_user) or "Resolved".
 * Nobody else is, nothing else is, and a change that rolled back or was overtaken announces nothing.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->owner = ticketUser('user', ['name' => 'Dana Customer']);
    $this->operator = ticketUser('operator', ['name' => 'OPERATORNAMEMARKER']);
    $this->agent = ticketAgent(['name' => 'ASSIGNEENAMEMARKER']);
});

function tsnTransition(Ticket $ticket, User $actor, string $status): void
{
    app(TicketService::class)->transition($ticket, $actor, $status);
}

function tsnLatestHistoryId(Ticket $ticket): int
{
    return (int) TicketStatusHistory::query()->where('ticket_id', $ticket->id)->max('id');
}

describe('who is told, and when', function () {
    beforeEach(fn () => Notification::fake());

    it('tells the requester, and only the requester, when the status becomes Waiting on you or Resolved', function (string $status) {
        $ticket = ticketFor($this->owner, ['assignee_id' => $this->agent->id]);

        tsnTransition($ticket, $this->operator, $status);

        Notification::assertSentTo($this->owner, TicketStatusNotification::class, fn (TicketStatusNotification $n, array $channels) => $n->status === $status
            && $n->ticket->is($ticket)
            && $n->historyId === tsnLatestHistoryId($ticket)
            && $channels === ['mail']);
        Notification::assertCount(1);
        foreach ([$this->operator, $this->agent] as $other) {
            Notification::assertNotSentTo($other, TicketStatusNotification::class);
        }
    })->with(['pending_user', 'resolved']);

    it('tells nobody about any other status', function (string $from, string $to) {
        $ticket = ticketFor($this->owner, ['status' => $from, 'assignee_id' => $this->agent->id]);

        tsnTransition($ticket, $this->operator, $to);

        Notification::assertNothingSent();
    })->with([
        'open to in progress' => ['open', 'in_progress'],
        'open to closed' => ['open', 'closed'],
        'in progress to open' => ['in_progress', 'open'],
        'pending to in progress' => ['pending_user', 'in_progress'],
        'pending to closed' => ['pending_user', 'closed'],
        'resolved to closed' => ['resolved', 'closed'],
        'resolved to open' => ['resolved', 'open'],
    ]);

    it('does not tell a requester about a change they made themselves', function () {
        $ticket = ticketFor($this->agent);

        tsnTransition($ticket, $this->agent, 'pending_user');

        Notification::assertNothingSent();
    });

    it('does tell a requester who holds operator capabilities when somebody else makes the change', function () {
        $ticket = ticketFor($this->agent);

        tsnTransition($ticket, $this->operator, 'resolved');

        Notification::assertSentTo($this->agent, TicketStatusNotification::class);
    });

    it('does not tell a suspended requester', function () {
        $ticket = ticketFor($this->owner);
        User::query()->whereKey($this->owner->id)->update(['disabled_at' => now()]);

        tsnTransition($ticket, $this->operator, 'pending_user');

        Notification::assertNothingSent();
    });

    it('does not tell a requester who may no longer view the ticket', function () {
        $ticket = ticketFor($this->owner);
        Gate::before(fn (User $user, string $ability) => $ability === 'view' && $user->is($this->owner) ? false : null);

        tsnTransition($ticket, $this->operator, 'resolved');

        Notification::assertNothingSent();
    });

    it('sends nothing for the lifecycle changes a customer reply or auto-close cause', function () {
        $pending = ticketFor($this->owner, ['status' => 'pending_user']);
        $resolved = ticketFor($this->owner, ['status' => 'resolved', 'resolved_at' => now()->subHours(100)]);

        app(TicketService::class)->addReply($pending, $this->owner, 'Here is the information', false);
        app(TicketService::class)->addReply($resolved, $this->owner, 'Still broken', false);
        $closable = ticketFor($this->owner, ['status' => 'resolved', 'resolved_at' => now()->subHours(100)]);
        app(TicketService::class)->autoCloseResolved();

        expect($pending->fresh()->status)->toBe('in_progress')->and($resolved->fresh()->status)->toBe('open')->and($closable->fresh()->status)->toBe('closed');
        Notification::assertNotSentTo($this->owner, TicketStatusNotification::class);
    });

    it('sends nothing when the transition is refused', function () {
        $ticket = ticketFor($this->owner, ['status' => 'closed']);

        expect(fn () => tsnTransition($ticket, $this->operator, 'resolved'))->toThrow(ValidationException::class);

        Notification::assertNothingSent();
    });
});

describe('over HTTP', function () {
    beforeEach(fn () => Notification::fake());

    it('tells the requester when an operator changes the status on the ticket page', function () {
        $ticket = ticketFor($this->owner);

        $this->actingAs($this->operator)->put(route('operator.tickets.status', $ticket), ['status' => 'pending_user'])->assertRedirect();

        Notification::assertSentToTimes($this->owner, TicketStatusNotification::class, 1);
    });

    it('tells each requester once for a bulk resolve, and tells nobody about a bulk close', function () {
        $other = ticketUser();
        $a = ticketFor($this->owner);
        $b = ticketFor($other);
        $c = ticketFor($this->owner);

        $this->actingAs($this->operator)->post(route('operator.tickets.bulk'), ['ticket_ids' => [$a->id, $b->id], 'action' => 'resolve'])->assertRedirect();

        Notification::assertSentToTimes($this->owner, TicketStatusNotification::class, 1);
        Notification::assertSentToTimes($other, TicketStatusNotification::class, 1);

        Notification::fake();
        $this->actingAs($this->operator)->post(route('operator.tickets.bulk'), ['ticket_ids' => [$c->id], 'action' => 'close'])->assertRedirect();
        Notification::assertNothingSent();
    });

    it('skips a bulk row that cannot make the transition without telling anyone about it', function () {
        $closed = ticketFor($this->owner, ['status' => 'closed']);

        $this->actingAs($this->operator)->post(route('operator.tickets.bulk'), ['ticket_ids' => [$closed->id], 'action' => 'resolve'])->assertRedirect();

        expect($closed->fresh()->status)->toBe('closed');
        Notification::assertNothingSent();
    });
});

describe('after commit, never on rollback', function () {
    it('hands the mail to the dispatcher only after the transition has committed', function () {
        $fake = ticketRecordNotificationLevels();
        $ticket = ticketFor($this->owner);
        $baseline = ticketTransactionLevel();

        tsnTransition($ticket, $this->operator, 'pending_user');

        expect($fake->levels)->toBe([$baseline]);
    });

    it('holds the mail until the CALLER\'s transaction commits', function () {
        Notification::fake();
        $a = ticketFor($this->owner);
        $b = ticketFor(ticketUser());

        DB::transaction(function () use ($a, $b) {
            tsnTransition($a, $this->operator, 'resolved');
            tsnTransition($b, $this->operator, 'pending_user');
            Notification::assertNothingSent();
        });

        Notification::assertCount(2);
    });

    it('sends nothing when a batch of transitions rolls back as one', function () {
        Notification::fake();
        $a = ticketFor($this->owner);
        $b = ticketFor(ticketUser());
        $calls = 0;
        TicketStatusHistory::creating(function () use (&$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('simulated history failure on the second ticket');
            }
        });

        expect(fn () => DB::transaction(function () use ($a, $b) {
            tsnTransition($a, $this->operator, 'resolved');
            tsnTransition($b, $this->operator, 'resolved');
        }))->toThrow(RuntimeException::class, 'second ticket');

        TicketStatusHistory::flushEventListeners();
        expect($a->fresh()->status)->toBe('open')->and($b->fresh()->status)->toBe('open');
        Notification::assertNothingSent();
    });

    it('sends nothing when the bulk action fails part way, over HTTP', function () {
        Notification::fake();
        $a = ticketFor($this->owner);
        $b = ticketFor(ticketUser());
        $calls = 0;
        TicketStatusHistory::creating(function () use (&$calls) {
            if (++$calls === 2) {
                throw new RuntimeException('simulated history failure on the second ticket');
            }
        });
        $this->withoutExceptionHandling();

        expect(fn () => $this->actingAs($this->operator)->post(route('operator.tickets.bulk'), ['ticket_ids' => [$a->id, $b->id], 'action' => 'resolve']))
            ->toThrow(RuntimeException::class);

        TicketStatusHistory::flushEventListeners();
        expect(Ticket::whereIn('id', [$a->id, $b->id])->pluck('status')->unique()->all())->toBe(['open']);
        Notification::assertNothingSent();
    });
});

describe('a failed hand-over after commit (review M2)', function () {
    it('keeps the committed transition and reports the failure instead of answering 500', function (string $status) {
        Exceptions::fake();
        ticketFailHandOff(TicketStatusNotification::class);
        $ticket = ticketFor($this->owner);

        $this->actingAs($this->operator)->put(route('operator.tickets.status', $ticket), ['status' => $status])
            ->assertRedirect()->assertSessionHas('status')->assertSessionHasNoErrors();

        expect($ticket->fresh()->status)->toBe($status)
            ->and(TicketStatusHistory::where('ticket_id', $ticket->id)->where('new_status', $status)->count())->toBe(1);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'simulated queue insert failure for TicketStatusNotification');
        Exceptions::assertReportedCount(1);
    })->with(['pending_user', 'resolved']);

    it('keeps the committed transition when the service is called directly, and reports the failure', function () {
        Exceptions::fake();
        ticketFailHandOff(TicketStatusNotification::class);
        $ticket = ticketFor($this->owner);

        tsnTransition($ticket, $this->operator, 'resolved');

        expect($ticket->fresh()->status)->toBe('resolved')->and($ticket->status)->toBe('resolved');
        Exceptions::assertReportedCount(1);
    });

    it('does not let one failed hand-over stop the rest of a bulk update, and keeps every committed change', function () {
        Exceptions::fake();
        $other = ticketUser('user', ['name' => 'Second Customer']);
        $first = ticketFor($this->owner);
        $second = ticketFor($other);
        $third = ticketFor($this->owner);
        $fake = ticketFailHandOff(TicketStatusNotification::class, fn ($notifiable) => $notifiable->is($this->owner));

        $this->actingAs($this->operator)->post(route('operator.tickets.bulk'), ['ticket_ids' => [$first->id, $second->id, $third->id], 'action' => 'resolve'])
            ->assertRedirect()->assertSessionHas('status', 'Bulk action applied to 3 ticket(s).');

        expect(Ticket::whereIn('id', [$first->id, $second->id, $third->id])->pluck('status')->unique()->all())->toBe(['resolved']);
        // The two hand-overs for the first customer failed and were reported; the second customer's was still made.
        $fake->assertSentTo($other, TicketStatusNotification::class);
        $fake->assertNotSentTo($this->owner, TicketStatusNotification::class);
        Exceptions::assertReportedCount(2);
    });

    it('also survives a failure while deciding who to tell, not only while queueing', function () {
        Exceptions::fake();
        Notification::fake();
        $ticket = ticketFor($this->owner);
        Gate::before(function (User $user, string $ability) {
            if ($ability === 'view' && $user->is($this->owner)) {
                throw new RuntimeException('simulated policy failure');
            }
        });

        tsnTransition($ticket, $this->operator, 'resolved');

        expect($ticket->fresh()->status)->toBe('resolved');
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'simulated policy failure');
        Notification::assertNothingSent();
    });

    it('still sends nothing, and reports nothing, when the transaction rolls back', function () {
        Exceptions::fake();
        ticketFailHandOff(TicketStatusNotification::class);
        $ticket = ticketFor($this->owner);

        expect(fn () => DB::transaction(function () use ($ticket) {
            tsnTransition($ticket, $this->operator, 'resolved');
            throw new RuntimeException('caller failed after the transition');
        }))->toThrow(RuntimeException::class, 'caller failed');

        expect($ticket->fresh()->status)->toBe('open');
        Exceptions::assertNothingReported();
    });

    it('leaves worker-time failures to the queue: a database error in shouldSend still fails the attempt and is retried', function () {
        // Covered end to end by NotificationSendTimeEligibilityTest for the invitation and reply mails; here the status mail's own check.
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        $ticket = ticketFor($this->owner);
        tsnTransition($ticket, $this->operator, 'resolved');
        $failures = [];
        Event::listen(JobExceptionOccurred::class, function ($event) use (&$failures) {
            $failures[] = $event->exception->getMessage();
        });
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'ticket_status_histories') && str_contains($query->sql, 'max(')) {
                throw new RuntimeException('simulated outage inside shouldSend');
            }
        });

        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0, '--queue' => 'default']);

        expect($failures)->toBe(['simulated outage inside shouldSend'])
            ->and(DB::table('jobs')->count())->toBe(1)->and(DB::table('failed_jobs')->count())->toBe(0);
    });
});

describe('what the mail says', function () {
    it('uses the customer vocabulary, links to the customer page and carries nothing internal', function (string $status, string $label) {
        Storage::fake('local');
        $ticket = ticketFor($this->owner, ['title' => 'Printer is broken', 'description' => 'DESCRIPTIONMARKER', 'assignee_id' => $this->agent->id]);
        ticketReply($ticket, $this->operator, true, 'INTERNALNOTEMARKER');
        ticketReply($ticket, $this->operator, false, 'PUBLICREPLYMARKER');
        ticketAttachment($ticket, null, $this->owner, 'secret-plan.pdf', 'x');
        tsnTransition($ticket, $this->operator, $status);

        $message = (new TicketStatusNotification($ticket->fresh(), $status, tsnLatestHistoryId($ticket)))->toMail($this->owner);
        $html = (string) $message->render();

        expect($message->subject)->toBe("[{$ticket->ticket_number}] {$label}: Printer is broken")
            ->and($html)->toContain($ticket->ticket_number)->toContain($label)->toContain('Printer is broken')->toContain('Hello Dana Customer,')
            ->toContain('href="'.route('tickets.show', $ticket).'"')
            ->not->toContain('INTERNALNOTEMARKER')->not->toContain('PUBLICREPLYMARKER')->not->toContain('DESCRIPTIONMARKER')->not->toContain('secret-plan.pdf')
            ->not->toContain('OPERATORNAMEMARKER')->not->toContain('ASSIGNEENAMEMARKER')
            ->not->toContain('SLA')->not->toContain('sla_')->not->toContain('overdue')->not->toContain('operator/tickets');
    })->with([
        'pending_user' => ['pending_user', 'Waiting on you'],
        'resolved' => ['resolved', 'Resolved'],
    ]);

    it('tells a resolved requester that a reply reopens the request and that it closes after the auto-close period', function () {
        $ticket = ticketFor($this->owner);

        $html = (string) (new TicketStatusNotification($ticket, 'resolved', 1))->toMail($this->owner)->render();

        expect($html)->toContain('reply to the request and it will be reopened')->toContain(TicketService::AUTO_CLOSE_HOURS.' hours')
            ->and(TicketService::AUTO_CLOSE_HOURS)->toBe(72);
    });

    it('cannot be built for a status that does not notify', function (string $status) {
        expect(fn () => new TicketStatusNotification(ticketFor($this->owner), $status, 1))->toThrow(InvalidArgumentException::class);
    })->with(['open', 'in_progress', 'closed', 'bogus']);
});

describe('closed customer-surface mode (review L2, accepted for this release)', function () {
    it('still sends the status mail, and its link answers 404 to the customer until the pilot is opened', function () {
        config(['portal.customer_surface_mode' => 'closed']);
        Notification::fake();
        $ticket = ticketFor($this->owner);

        tsnTransition($ticket, $this->operator, 'resolved');

        // Documented limitation (docs/architecture/notification-delivery.md section 6): the mail is not suppressed by the customer-surface
        // mode, and in closed mode the customer's ticket routes are server-side 404s, so the link in it leads nowhere. Production opens the
        // pilot before the first invitation, so no customer is in this state; operators working old tickets in closed mode are.
        Notification::assertSentTo($this->owner, TicketStatusNotification::class);
        $this->actingAs($this->owner)->get(route('tickets.show', $ticket))->assertNotFound();
    });
});

describe('through the real queue', function () {
    beforeEach(function () {
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        $this->sent = [];
        Event::listen(MessageSent::class, function (MessageSent $event) {
            $this->sent[] = $event->message;
        });
    });

    function tsnWorkerRuns(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            Artisan::call('queue:work', ['--once' => true, '--sleep' => 0, '--queue' => 'default']);
        }
    }

    function tsnQueueIsEmpty(): bool
    {
        return DB::table('jobs')->count() === 0 && DB::table('failed_jobs')->count() === 0;
    }

    it('delivers one mail to the requester', function (string $status, string $label) {
        $ticket = ticketFor($this->owner, ['title' => 'Real queue']);
        tsnTransition($ticket, $this->operator, $status);

        tsnWorkerRuns(1);

        expect($this->sent)->toHaveCount(1)
            ->and($this->sent[0]->getTo()[0]->getAddress())->toBe($this->owner->email)
            ->and($this->sent[0]->getSubject())->toBe("[{$ticket->ticket_number}] {$label}: Real queue")
            ->and($this->sent[0]->getHtmlBody())->toContain(route('tickets.show', $ticket))
            ->and(tsnQueueIsEmpty())->toBeTrue();
    })->with([['pending_user', 'Waiting on you'], ['resolved', 'Resolved']]);

    it('drops the mail when the status has already moved on', function () {
        $ticket = ticketFor($this->owner);
        tsnTransition($ticket, $this->operator, 'pending_user');
        tsnTransition($ticket, $this->operator, 'in_progress');

        tsnWorkerRuns(1);

        expect($this->sent)->toBeEmpty()->and(tsnQueueIsEmpty())->toBeTrue();
    });

    it('drops a Resolved mail for a request that was reopened or closed before the worker ran', function (string $then) {
        $ticket = ticketFor($this->owner);
        tsnTransition($ticket, $this->operator, 'resolved');
        tsnTransition($ticket, $this->operator, $then);

        tsnWorkerRuns(1);

        expect($this->sent)->toBeEmpty()->and(tsnQueueIsEmpty())->toBeTrue();
    })->with(['open', 'closed']);

    it('sends one mail, not two, when an operator flips resolved, open, resolved inside the queue delay', function () {
        $ticket = ticketFor($this->owner);
        tsnTransition($ticket, $this->operator, 'resolved');
        tsnTransition($ticket, $this->operator, 'open');
        tsnTransition($ticket, $this->operator, 'resolved');

        tsnWorkerRuns(2);

        expect($this->sent)->toHaveCount(1)->and(tsnQueueIsEmpty())->toBeTrue();
    });

    it('sends one mail, not two, when an operator returns a request to Waiting on you', function () {
        $ticket = ticketFor($this->owner);
        tsnTransition($ticket, $this->operator, 'pending_user');
        tsnTransition($ticket, $this->operator, 'in_progress');
        tsnTransition($ticket, $this->operator, 'pending_user');

        tsnWorkerRuns(2);

        expect($this->sent)->toHaveCount(1)->and(tsnQueueIsEmpty())->toBeTrue();
    });

    it('drops the mail for a requester suspended after it was queued', function () {
        $ticket = ticketFor($this->owner);
        tsnTransition($ticket, $this->operator, 'resolved');
        User::query()->whereKey($this->owner->id)->update(['disabled_at' => now()]);

        tsnWorkerRuns(1);

        expect($this->sent)->toBeEmpty()->and(tsnQueueIsEmpty())->toBeTrue();
    });

    it('drops the mail for a requester who may no longer view the ticket when the worker runs', function () {
        $ticket = ticketFor($this->owner);
        tsnTransition($ticket, $this->operator, 'resolved');
        Gate::before(fn (User $user, string $ability) => $ability === 'view' && $user->is($this->owner) ? false : null);

        tsnWorkerRuns(1);

        expect($this->sent)->toBeEmpty()->and(tsnQueueIsEmpty())->toBeTrue();
    });

    it('never mails anybody but the ticket\'s requester, whoever the notification is handed to', function () {
        $ticket = ticketFor($this->owner);
        tsnTransition($ticket, $this->operator, 'resolved');
        DB::table('jobs')->delete();
        $historyId = tsnLatestHistoryId($ticket);

        $this->agent->notify(new TicketStatusNotification($ticket, 'resolved', $historyId));
        tsnWorkerRuns(1);

        expect($this->sent)->toBeEmpty()->and(tsnQueueIsEmpty())->toBeTrue();
    });

    it('discards, rather than fails, a mail for a ticket that no longer exists', function () {
        $ticket = ticketFor($this->owner);
        tsnTransition($ticket, $this->operator, 'resolved');
        DB::table('ticket_status_histories')->where('ticket_id', $ticket->id)->delete();
        Ticket::query()->whereKey($ticket->id)->delete();

        tsnWorkerRuns(1);

        expect($this->sent)->toBeEmpty()->and(tsnQueueIsEmpty())->toBeTrue();
    });
});

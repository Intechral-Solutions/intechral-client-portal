<?php

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Notifications\NewTicketAlertNotification;
use App\Notifications\TicketCreatedNotification;
use App\Services\TicketService;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\NotificationFake;

require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

/*
 * EPIC-017 O3, option c: the operator new-ticket alert. One configured destination (HELPDESK_NOTIFY_ADDRESS), queued, handed over only
 * after the ticket is committed, and never a reason for a customer's request to fail.
 */

const NTA_ADDRESS = 'helpdesk@desk.invalid';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Storage::fake('local');
    config(['helpdesk.notify_address' => NTA_ADDRESS]);
});

/** Everything a customer types on the create form. */
function ntaForm(array $override = []): array
{
    return $override + ['title' => 'Printer is broken', 'description' => 'It prints blank pages.', 'category' => 'Technical', 'priority' => 'high'];
}

function ntaAlerts(int $times): void
{
    Notification::assertSentOnDemandTimes(NewTicketAlertNotification::class, $times);
}

describe('who is told', function () {
    beforeEach(fn () => Notification::fake());

    it('sends exactly one alert, to the configured address and to nobody else', function () {
        $customer = ticketUser();
        $operator = ticketUser('operator');
        $agent = ticketAgent();

        $this->actingAs($customer)->post(route('tickets.store'), ntaForm())->assertRedirect();

        ntaAlerts(1);
        Notification::assertSentOnDemand(NewTicketAlertNotification::class, fn ($notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === NTA_ADDRESS
            && $channels === ['mail']
            && $notification->ticket->is(Ticket::firstOrFail()));
        // The requester still gets their own confirmation (unchanged), and no account is mailed the alert.
        Notification::assertSentTo($customer, TicketCreatedNotification::class);
        foreach ([$customer, $operator, $agent] as $user) {
            Notification::assertNotSentTo($user, NewTicketAlertNotification::class);
        }
    });

    it('sends nothing for a reply, an assignment or a status change', function () {
        $customer = ticketUser();
        $agent = ticketAgent();
        $ticket = ticketFor($customer);

        $this->actingAs($agent)->post(route('operator.tickets.replies.store', $ticket), ['body' => 'On it', 'is_internal' => '0'])->assertRedirect();
        $this->actingAs($agent)->put(route('operator.tickets.assign', $ticket), ['assignee_id' => $agent->id])->assertRedirect();
        $this->actingAs($agent)->put(route('operator.tickets.status', $ticket), ['status' => 'in_progress'])->assertRedirect();
        $this->actingAs($customer)->post(route('tickets.replies.store', $ticket), ['body' => 'Thanks'])->assertRedirect();

        ntaAlerts(0);
    });

    it('sends one alert per ticket created', function () {
        $customer = ticketUser();

        $this->actingAs($customer)->post(route('tickets.store'), ntaForm(['title' => 'One']))->assertRedirect();
        $this->actingAs($customer)->post(route('tickets.store'), ntaForm(['title' => 'Two']))->assertRedirect();

        ntaAlerts(2);
    });

    it('trims the configured address', function () {
        config(['helpdesk.notify_address' => '  '.NTA_ADDRESS."  \n"]);

        app(TicketService::class)->create(ticketUser(), ntaForm());

        Notification::assertSentOnDemand(NewTicketAlertNotification::class, fn ($n, $c, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === NTA_ADDRESS);
    });
});

describe('absent or unusable configuration', function () {
    beforeEach(fn () => Notification::fake());

    it('skips the alert without failing the request, with a warning that never contains the address', function (mixed $value) {
        config(['helpdesk.notify_address' => $value]);
        Log::spy();

        $this->actingAs($customer = ticketUser())->post(route('tickets.store'), ntaForm())->assertRedirect();

        ntaAlerts(0);
        Notification::assertSentTo($customer, TicketCreatedNotification::class);
        expect(Ticket::count())->toBe(1);
        Log::shouldHaveReceived('log')->once()->withArgs(fn (string $level, string $message, array $context) => $level === 'warning'
            && str_contains($message, 'HELPDESK_NOTIFY_ADDRESS')
            && ! str_contains($message.json_encode($context), 'attacker')
            && $context === ['ticket_id' => Ticket::firstOrFail()->id]);
    })->with([
        'unset' => [null],
        'empty' => [''],
        'blank' => ["  \n "],
        'not an address' => ['not-an-address'],
        'two addresses' => ['a@desk.invalid, attacker@evil.invalid'],
        'a header-injection attempt' => ["a@desk.invalid\nBcc: attacker@evil.invalid"],
        'a documentation domain' => ['helpdesk@example.com'],
        'not a string' => [['helpdesk@desk.invalid']],
    ]);

    it('logs at error level in production, where the preflight should have prevented this', function () {
        config(['helpdesk.notify_address' => null]);
        $this->app['env'] = 'production';
        Log::spy();

        app(TicketService::class)->create(ticketUser(), ntaForm());

        Log::shouldHaveReceived('log')->once()->withArgs(fn (string $level) => $level === 'error');
        ntaAlerts(0);
    });

    it('never fails the request when the alert cannot be handed to the queue, and reports why', function () {
        Exceptions::fake();
        Notification::swap(new class extends NotificationFake
        {
            public function send($notifiables, $notification)
            {
                if ($notification instanceof NewTicketAlertNotification) {
                    throw new RuntimeException('queue is down');
                }

                parent::send($notifiables, $notification);
            }
        });

        $customer = ticketUser();
        $this->actingAs($customer)->post(route('tickets.store'), ntaForm())->assertRedirect();

        expect(Ticket::count())->toBe(1);
        Notification::assertSentTo($customer, TicketCreatedNotification::class);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'queue is down');
    });
});

describe('after commit, never on rollback', function () {
    it('hands the alert over only after the ticket and its attachments are committed', function () {
        $fake = ticketRecordNotificationLevels();
        $baseline = ticketTransactionLevel();

        app(TicketService::class)->create(ticketUser(), ntaForm(), [ticketUpload('a.pdf')]);

        // Two hand-overs (the requester's confirmation, the operator alert), both at the caller's own level.
        expect($fake->levels)->toBe([$baseline, $baseline]);
        ntaAlerts(1);
    });

    it('hands over no alert at all when the ticket cannot be created', function () {
        Notification::fake();
        TicketAttachment::creating(function () {
            throw new RuntimeException('simulated attachment failure');
        });

        expect(fn () => app(TicketService::class)->create(ticketUser(), ntaForm(), [ticketUpload('a.pdf')]))->toThrow(RuntimeException::class, 'simulated attachment failure');

        TicketAttachment::flushEventListeners();
        expect(Ticket::count())->toBe(0);
        Notification::assertNothingSent();
        ntaAlerts(0);
    });

    it('holds the alert until the CALLER\'s transaction commits, and drops it if that rolls back', function () {
        Notification::fake();
        $service = app(TicketService::class);

        DB::transaction(function () use ($service) {
            $service->create(ticketUser(), ntaForm());
            ntaAlerts(0);
        });
        ntaAlerts(1);

        Notification::fake();
        expect(fn () => DB::transaction(function () use ($service) {
            $service->create(ticketUser(), ntaForm(['title' => 'Rolled back']));
            throw new RuntimeException('caller failed after creating');
        }))->toThrow(RuntimeException::class);

        ntaAlerts(0);
        expect(Ticket::where('title', 'Rolled back')->exists())->toBeFalse();
    });
});

describe('ticket creation notifications are independent and never make a committed creation look failed (review L3)', function () {
    it('answers normally, keeps the ticket, reports the failure and STILL sends the operator alert when the customer confirmation cannot be queued', function () {
        Exceptions::fake();
        $fake = ticketFailHandOff(TicketCreatedNotification::class);

        $this->actingAs($customer = ticketUser())->post(route('tickets.store'), ntaForm())->assertRedirect()->assertSessionHasNoErrors();

        expect(Ticket::count())->toBe(1);
        $fake->assertNotSentTo($customer, TicketCreatedNotification::class);
        Notification::assertSentOnDemandTimes(NewTicketAlertNotification::class, 1);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'simulated queue insert failure for TicketCreatedNotification');
        Exceptions::assertReportedCount(1);
    });

    it('still sends the customer confirmation when the operator alert cannot be queued', function () {
        Exceptions::fake();
        $fake = ticketFailHandOff(NewTicketAlertNotification::class);

        $this->actingAs($customer = ticketUser())->post(route('tickets.store'), ntaForm())->assertRedirect()->assertSessionHasNoErrors();

        expect(Ticket::count())->toBe(1);
        $fake->assertSentTo($customer, TicketCreatedNotification::class);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'simulated queue insert failure for NewTicketAlertNotification');
        Exceptions::assertReportedCount(1);
    });

    it('reports both failures, and still answers normally, when neither can be queued', function () {
        Exceptions::fake();
        $fake = new class extends NotificationFake
        {
            public function sendNow($notifiables, $notification, ?array $channels = null)
            {
                throw new RuntimeException('simulated queue insert failure for '.class_basename($notification));
            }
        };
        Notification::swap($fake);

        $this->actingAs(ticketUser())->post(route('tickets.store'), ntaForm())->assertRedirect()->assertSessionHasNoErrors();

        expect(Ticket::count())->toBe(1);
        Exceptions::assertReportedCount(2);
    });

    it('returns the committed ticket from the service even when both hand-overs fail', function () {
        Exceptions::fake();
        ticketFailHandOff(TicketCreatedNotification::class);

        $ticket = app(TicketService::class)->create(ticketUser(), ntaForm());

        expect($ticket->exists)->toBeTrue()->and(Ticket::find($ticket->id))->not->toBeNull();
    });

    it('sends neither notification, and reports nothing, when the creation rolls back inside the service', function () {
        Exceptions::fake();
        Notification::fake();
        TicketAttachment::creating(function () {
            throw new RuntimeException('simulated attachment failure');
        });

        expect(fn () => app(TicketService::class)->create(ticketUser(), ntaForm(), [ticketUpload('a.pdf')]))->toThrow(RuntimeException::class, 'simulated attachment failure');

        TicketAttachment::flushEventListeners();
        expect(Ticket::count())->toBe(0);
        Notification::assertNothingSent();
        Exceptions::assertNothingReported();
    });

    it('sends neither notification, and reports nothing, when the CALLER\'s transaction rolls back after the creation', function () {
        Exceptions::fake();
        $fake = ticketFailHandOff(TicketCreatedNotification::class);

        expect(fn () => DB::transaction(function () {
            app(TicketService::class)->create(ticketUser(), ntaForm(['title' => 'Rolled back']));
            throw new RuntimeException('caller failed after creating');
        }))->toThrow(RuntimeException::class, 'caller failed');

        expect(Ticket::where('title', 'Rolled back')->exists())->toBeFalse();
        $fake->assertNothingSent();
        Exceptions::assertNothingReported();
    });

    it('holds BOTH notifications until the caller\'s transaction commits, the confirmation first', function () {
        $fake = ticketRecordNotificationLevels();
        $baseline = ticketTransactionLevel();

        DB::transaction(function () use ($fake) {
            app(TicketService::class)->create(ticketUser(), ntaForm());
            expect($fake->levels)->toBe([]);
        });

        expect($fake->levels)->toBe([$baseline, $baseline]);
    });
});

describe('what the alert says', function () {
    function ntaAlertFor(array $form, array $owner = []): array
    {
        $user = ticketUser('user', $owner);
        $ticket = app(TicketService::class)->create($user, ntaForm($form));
        $message = (new NewTicketAlertNotification($ticket))->toMail(new AnonymousNotifiable);

        return [$ticket, $message, (string) $message->render(), $user];
    }

    it('carries the number, title, category, priority, requester name and an operator link', function () {
        Notification::fake();
        [$ticket, $message, $html] = ntaAlertFor(['title' => 'Printer is broken', 'category' => 'Technical', 'priority' => 'high'], ['name' => 'Dana Customer']);

        expect($message->subject)->toBe("[{$ticket->ticket_number}] New request: Printer is broken")
            ->and($html)->toContain($ticket->ticket_number)->toContain('Printer is broken')->toContain('Technical')->toContain('High')
            ->toContain('Dana Customer')->toContain('It prints blank pages.')
            ->and($html)->toContain('href="'.route('operator.tickets.show', $ticket).'"');
    });

    it('shows only a short preview of the description', function () {
        Notification::fake();
        [, , $html] = ntaAlertFor(['description' => str_repeat('a', 190).'MIDDLE'.str_repeat('b', 50).'TAILMARKER']);

        expect($html)->toContain(str_repeat('a', 190))->not->toContain('TAILMARKER')->not->toContain(str_repeat('b', 50));
    });

    it('leaves out the requester address, attachment names, replies and internal notes', function () {
        Notification::fake();
        $user = ticketUser('user', ['name' => 'Dana Customer', 'email' => 'dana.private@customer.invalid']);
        $ticket = app(TicketService::class)->create($user, ntaForm(), [ticketUpload('secret-plan.pdf')]);
        ticketReply($ticket, ticketUser('operator'), true, 'INTERNALNOTEMARKER');
        ticketReply($ticket, ticketUser('operator'), false, 'PUBLICREPLYMARKER');

        $message = (new NewTicketAlertNotification($ticket->fresh()))->toMail(new AnonymousNotifiable);
        $html = (string) $message->render();

        expect($html.$message->subject)->not->toContain('dana.private@customer.invalid')->not->toContain('secret-plan.pdf')
            ->not->toContain('INTERNALNOTEMARKER')->not->toContain('PUBLICREPLYMARKER');
    });

    it('is a single-line subject however the title was typed', function () {
        Notification::fake();
        [, $message] = ntaAlertFor(['title' => "Printer\r\nBcc: attacker@evil.invalid"]);

        expect($message->subject)->not->toContain("\n")->not->toContain("\r");
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

    function ntaWorkerRuns(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            Artisan::call('queue:work', ['--once' => true, '--sleep' => 0, '--queue' => 'default']);
        }
    }

    it('delivers one mail to the helpdesk address and one to the requester, each addressed to that one recipient', function () {
        $customer = ticketUser('user', ['email' => 'dana@customer.invalid']);

        app(TicketService::class)->create($customer, ntaForm(['title' => 'Real queue']));
        ntaWorkerRuns(2);

        $recipients = collect($this->sent)->map(fn ($m) => collect($m->getTo())->map->getAddress()->all())->sortBy(fn ($to) => $to[0])->values()->all();
        expect($this->sent)->toHaveCount(2)
            ->and($recipients)->toBe([['dana@customer.invalid'], [NTA_ADDRESS]])
            ->and(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(0);
        $alert = collect($this->sent)->first(fn ($m) => $m->getTo()[0]->getAddress() === NTA_ADDRESS);
        expect($alert->getSubject())->toContain('New request: Real queue')->and($alert->getHtmlBody())->toContain('Real queue');
    });

    it('discards, rather than fails, an alert for a ticket that no longer exists', function () {
        $ticket = app(TicketService::class)->create(ticketUser(), ntaForm());
        Ticket::query()->whereKey($ticket->id)->delete();

        ntaWorkerRuns(2);

        expect($this->sent)->toBeEmpty()->and(DB::table('jobs')->count())->toBe(0)->and(DB::table('failed_jobs')->count())->toBe(0);
    });

    it('is not blocked by the suspended-recipient safeguard, which concerns accounts and the alert is an address', function () {
        $customer = ticketUser();
        app(TicketService::class)->create($customer, ntaForm());
        User::query()->whereKey($customer->id)->update(['disabled_at' => now()]);

        ntaWorkerRuns(2);

        // The suspended requester's confirmation is dropped (H3); the operator alert still reaches the helpdesk address.
        expect($this->sent)->toHaveCount(1)->and($this->sent[0]->getTo()[0]->getAddress())->toBe(NTA_ADDRESS);
    });
});

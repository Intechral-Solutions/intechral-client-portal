<?php

/*
 * Release 1.0 (R1-6): who is told, and what they are told, when partner support raises a request and while it is talked about.
 *
 *  - creation: the requester's receipt names the submitter; the submitter has a confirmation of their own; the operator alert names the submitter;
 *  - conversation: a requester's or an operator's PUBLIC reply reaches the partner support participants (the submitter, and staff who replied),
 *    never the author, never twice, never for an internal note;
 *  - unassigned: a partner reply nobody at Intechral would be told about alerts the helpdesk address, once, and never alongside a mailed assignee;
 *  - eligibility: partner support authority is decided at dispatch AND again when the worker runs the job (real database queue, real worker).
 *
 * Pilot mode (the mode customers run in); the suite default is legacy.
 */

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Notifications\NewTicketAlertNotification;
use App\Notifications\PartnerReplyAlertNotification;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketRepliedNotification;
use App\Services\OrganizationRelationships;
use App\Services\PartnerSupportGrants;
use App\Services\TicketService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

require_once __DIR__.'/PartnerSupportTestHelpers.php';

const R16_ADDRESS = 'helpdesk@desk.invalid';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Storage::fake('local');
    pilotMode('pilot');
    config(['helpdesk.notify_address' => R16_ADDRESS]);
});

afterEach(function () {
    ticketCleanFakeStorage();
});

/** A request the staff member raised for the contact, through the real service (so `submitted_by_id` is the staff member). */
function r16Raise(array $w, array $data = []): Ticket
{
    return app(TicketService::class)->createForCustomerContact(
        $w['staff'], $w['customer']->id, $w['requester']->id,
        $data + ['title' => 'Printer offline', 'description' => 'It stopped printing.', 'category' => 'General', 'priority' => 'low'],
    );
}

/** A second designated staff member of the same partner. */
function r16Colleague(array $w, string $name = 'Colin Colleague'): User
{
    $user = pilotCustomer(['name' => $name]);
    psJoin($user, $w['partner']);
    app(PartnerSupportGrants::class)->grant($w['partner'], $user, $w['operator']);

    return $user;
}

/** A partner-raised request with an operator assignee. */
function r16Assigned(array $w, ?Ticket $ticket = null): array
{
    $assignee = ticketAgent(['name' => 'Handler Hana', 'email' => 'assignee-r16@example.test']);
    $ticket ??= r16Raise($w);
    $ticket->forceFill(['assignee_id' => $assignee->id])->save();

    return [$ticket->refresh(), $assignee];
}

dataset('r16Ends', [
    'designation revoked' => fn (array $w) => app(PartnerSupportGrants::class)->revoke($w['partner'], $w['staff'], $w['operator']),
    'account suspended' => fn (array $w) => DB::table('users')->where('id', $w['staff']->id)->update(['disabled_at' => now()]),
    'customer unlinked from the partner' => fn (array $w) => DB::table('organizations')->where('id', $w['customer']->id)->update(['managed_by_organization_id' => null]),
    'customer organization disabled' => fn (array $w) => DB::table('organizations')->where('id', $w['customer']->id)->update(['disabled_at' => now()]),
    'partner organization disabled' => fn (array $w) => DB::table('organizations')->where('id', $w['partner']->id)->update(['disabled_at' => now()]),
    'partner designation removed' => fn (array $w) => DB::table('organizations')->where('id', $w['partner']->id)->update(['can_manage_customers' => false]),
    'account became an operator' => fn (array $w) => $w['staff']->assignRole('operator'),
    'membership of the partner removed' => fn (array $w) => DB::table('organization_members')->where('organization_id', $w['partner']->id)->where('user_id', $w['staff']->id)->delete(),
]);

// ── creation ────────────────────────────────────────────

describe('a request raised by partner support', function () {
    beforeEach(fn () => Notification::fake());

    it('sends the requester the receipt, the submitter their own confirmation, and the helpdesk its alert, once each', function () {
        $w = psWorld();
        $ticket = r16Raise($w);

        Notification::assertSentTo($w['requester'], TicketCreatedNotification::class, fn ($n) => $n->audience === TicketCreatedNotification::FOR_REQUESTER && $n->ticket->is($ticket));
        Notification::assertSentTo($w['staff'], TicketCreatedNotification::class, fn ($n) => $n->audience === TicketCreatedNotification::FOR_SUBMITTER && $n->ticket->is($ticket));
        Notification::assertSentTimes(TicketCreatedNotification::class, 2);
        Notification::assertSentOnDemandTimes(NewTicketAlertNotification::class, 1);
        // Nobody else: not a colleague of the submitter, not the operator.
        $colleague = r16Colleague($w);
        Notification::assertNotSentTo([$colleague, $w['operator']], TicketCreatedNotification::class);
    });

    it('says in the requester receipt who raised the request and for which organization, with the requester link', function () {
        $w = psWorld();
        $ticket = r16Raise($w);
        $mail = (new TicketCreatedNotification($ticket))->toMail($w['requester']);
        $html = (string) $mail->render();

        expect($html)->toContain('Pat Partner')->toContain('raised this request for you at')->toContain('Charter')->toContain('Printer offline')
            ->toContain('href="'.route('tickets.show', $ticket).'"')->not->toContain(route('support.show', $ticket))
            ->and($mail->subject)->toBe("[{$ticket->ticket_number}] Request received: Printer offline");
    });

    it('gives the submitter a confirmation that names the contact and organization and links to the SUPPORT page, never the requester page', function () {
        $w = psWorld();
        $ticket = r16Raise($w);
        $mail = (new TicketCreatedNotification($ticket, TicketCreatedNotification::FOR_SUBMITTER))->toMail($w['staff']);
        $html = (string) $mail->render();

        expect($mail->subject)->toBe("[{$ticket->ticket_number}] Request raised: Printer offline")
            ->and($html)->toContain('Hello Pat Partner')->toContain('You raised request')->toContain('Rae Requester')->toContain('Charter')
            ->toContain('href="'.route('support.show', $ticket).'"')->not->toContain(route('tickets.show', $ticket))
            ->not->toContain($w['requester']->email);
    });

    it('names the submitter AND the requester in the operator alert, without either address', function () {
        $w = psWorld();
        $ticket = r16Raise($w);
        $html = (string) (new NewTicketAlertNotification($ticket))->toMail(new AnonymousNotifiable)->render();

        expect($html)->toContain('was submitted by')->toContain('Pat Partner')->toContain('(partner support) on behalf of')->toContain('Rae Requester')
            ->toContain('Charter')->not->toContain($w['staff']->email)->not->toContain($w['requester']->email);
    });

    it('escapes the submitter name as user-authored text in all three mails', function () {
        $w = psWorld();
        $w['staff']->forceFill(['name' => 'Eve [click](https://evil.invalid) *bold*'])->save();
        $ticket = r16Raise($w);

        foreach ([
            (new TicketCreatedNotification($ticket))->toMail($w['requester']),
            (new NewTicketAlertNotification($ticket))->toMail(new AnonymousNotifiable),
        ] as $mail) {
            $html = (string) $mail->render();
            expect($html)->toContain('Eve')->not->toContain('href="https://evil.invalid"')->not->toContain('<strong>bold</strong>');
        }
    });

    it('leaves a request a requester raised themselves exactly as it was: one receipt, no submitter notice, the original alert wording', function () {
        $requester = pilotCustomer(['name' => 'Dana Direct']);
        $customer = psPaired('Direct Org');
        psJoin($requester, $customer);

        $ticket = app(TicketService::class)->create($requester, ['title' => 'Mine', 'description' => 'Mine.', 'category' => 'General', 'priority' => 'low']);

        Notification::assertSentTo($requester, TicketCreatedNotification::class, fn ($n) => $n->audience === TicketCreatedNotification::FOR_REQUESTER);
        Notification::assertSentTimes(TicketCreatedNotification::class, 1);
        $receipt = (string) (new TicketCreatedNotification($ticket))->toMail($requester)->render();
        $alert = (string) (new NewTicketAlertNotification($ticket))->toMail(new AnonymousNotifiable)->render();
        expect($receipt)->not->toContain('raised this request for you')
            ->and($alert)->toContain('was raised by')->toContain('Dana Direct')->not->toContain('was submitted by');
    });

    it('sends one receipt and no submitter notice when the staff member is also the contact', function () {
        $w = psWorld();
        psJoin($w['staff'], $w['customer']);
        $ticket = app(TicketService::class)->createForCustomerContact($w['staff'], $w['customer']->id, $w['staff']->id, ['title' => 'Own', 'description' => 'Own.', 'category' => 'General', 'priority' => 'low']);

        expect($ticket->user_id)->toBe($w['staff']->id)->and($ticket->submitted_by_id)->toBe($w['staff']->id);
        Notification::assertSentTimes(TicketCreatedNotification::class, 1);
        Notification::assertSentTo($w['staff'], TicketCreatedNotification::class, fn ($n) => $n->audience === TicketCreatedNotification::FOR_REQUESTER);
    });

    it('does not hand a confirmation to a submitter who is suspended, or whose authority ended, before the hand-over', function (Closure $end) {
        $w = psWorld();

        DB::transaction(function () use ($w, $end) {
            $ticket = r16Raise($w);
            $end($w);
        });

        Notification::assertNotSentTo($w['staff'], TicketCreatedNotification::class);
        Notification::assertSentTo($w['requester'], TicketCreatedNotification::class);
        Notification::assertSentOnDemandTimes(NewTicketAlertNotification::class, 1);
    })->with([
        'suspended' => fn (array $w) => DB::table('users')->where('id', $w['staff']->id)->update(['disabled_at' => now()]),
        'designation revoked' => fn (array $w) => app(PartnerSupportGrants::class)->revoke($w['partner'], $w['staff'], $w['operator']),
    ]);

    it('keeps one failed hand-over from stopping the others and from failing the creation', function () {
        Exceptions::fake();
        $w = psWorld();
        ticketFailHandOff(TicketCreatedNotification::class, fn ($n) => $n instanceof User && $n->is($w['staff']));

        $ticket = r16Raise($w);

        expect(Ticket::query()->whereKey($ticket->id)->exists())->toBeTrue();
        Notification::assertSentTo($w['requester'], TicketCreatedNotification::class);
        Notification::assertSentOnDemandTimes(NewTicketAlertNotification::class, 1);
        Exceptions::assertReported(RuntimeException::class);
    });
});

// ── the conversation ────────────────────────────────────

describe('a public reply by the requester or an operator', function () {
    beforeEach(fn () => Notification::fake());

    it('reaches the submitter, in the partner audience, and the assignee, but not the requester who wrote it', function () {
        $w = psWorld();
        [$ticket, $assignee] = r16Assigned($w);

        app(TicketService::class)->addReply($ticket, $w['requester'], 'It is still broken.', false);

        Notification::assertSentTo($w['staff'], TicketRepliedNotification::class, fn ($n) => $n->audience === TicketRepliedNotification::FOR_PARTNER_SUPPORT);
        Notification::assertSentTo($assignee, TicketRepliedNotification::class, fn ($n) => $n->audience === TicketRepliedNotification::FOR_PARTICIPANT);
        Notification::assertNotSentTo($w['requester'], TicketRepliedNotification::class);
        Notification::assertSentTimes(TicketRepliedNotification::class, 2);
        Notification::assertSentOnDemandTimes(PartnerReplyAlertNotification::class, 0);
    });

    it('reaches the requester and the submitter, but not the operator who wrote it', function () {
        $w = psWorld();
        [$ticket, $assignee] = r16Assigned($w);

        app(TicketService::class)->addReply($ticket, $assignee, 'We have a fix.', false);

        Notification::assertSentTo($w['requester'], TicketRepliedNotification::class, fn ($n) => $n->audience === TicketRepliedNotification::FOR_PARTICIPANT);
        Notification::assertSentTo($w['staff'], TicketRepliedNotification::class, fn ($n) => $n->audience === TicketRepliedNotification::FOR_PARTNER_SUPPORT);
        Notification::assertNotSentTo($assignee, TicketRepliedNotification::class);
        Notification::assertSentTimes(TicketRepliedNotification::class, 2);
    });

    it('reaches staff who replied on a request they did not submit, and nobody else of the partner', function () {
        $w = psWorld();
        $ticket = $w['ticket']; // raised by the requester themselves
        $helper = r16Colleague($w, 'Hetty Helper');
        $bystander = r16Colleague($w, 'Bea Bystander');
        app(TicketService::class)->addPartnerSupportReply($ticket, $helper, 'Try restarting it.');
        Notification::fake();

        app(TicketService::class)->addReply($ticket->refresh(), $w['requester'], 'Restarted, no change.', false);

        Notification::assertSentTo($helper, TicketRepliedNotification::class, fn ($n) => $n->audience === TicketRepliedNotification::FOR_PARTNER_SUPPORT);
        Notification::assertNotSentTo([$bystander, $w['staff']], TicketRepliedNotification::class);
        Notification::assertSentTimes(TicketRepliedNotification::class, 1);
    });

    it('tells a staff member who both submitted and replied once, not twice', function () {
        $w = psWorld();
        $ticket = r16Raise($w);
        app(TicketService::class)->addPartnerSupportReply($ticket, $w['staff'], 'Looking.');
        app(TicketService::class)->addPartnerSupportReply($ticket->refresh(), $w['staff'], 'Still looking.');
        Notification::fake();

        app(TicketService::class)->addReply($ticket->refresh(), $w['requester'], 'Thanks.', false);

        Notification::assertSentTo($w['staff'], TicketRepliedNotification::class);
        Notification::assertSentTimes(TicketRepliedNotification::class, 1);
    });

    it('never mails a partner participant for an internal note, and the note never appears in anything sent', function () {
        $w = psWorld();
        [$ticket, $assignee] = r16Assigned($w);
        Notification::fake(); // creation mail is not under test

        app(TicketService::class)->addReply($ticket, $assignee, 'INTERNAL-NOTE-SENTINEL', true);

        Notification::assertNothingSent();
    });

    it('does not tell other partner staff when a colleague replies (only a requester or an operator reply does)', function () {
        $w = psWorld();
        [$ticket, $assignee] = r16Assigned($w);
        $colleague = r16Colleague($w);

        app(TicketService::class)->addPartnerSupportReply($ticket, $colleague, 'On it.');

        Notification::assertNotSentTo([$w['staff'], $colleague], TicketRepliedNotification::class);
        Notification::assertSentTo([$w['requester'], $assignee], TicketRepliedNotification::class);
        Notification::assertSentTimes(TicketRepliedNotification::class, 2);
    });

    it('skips every account that is not effective partner support right now', function (Closure $change) {
        $w = psWorld();
        $ticket = r16Raise($w);
        $change($w);

        app(TicketService::class)->addReply($ticket, $w['requester'], 'Hello?', false);

        Notification::assertNotSentTo($w['staff'], TicketRepliedNotification::class);
    })->with('r16Ends');

    it('does not reach a participant of ANOTHER partner, a member of the customer, or an operator, whatever they once wrote', function () {
        $w = psWorld();
        $ticket = r16Raise($w);

        $other = psPaired('Other Partner');
        app(OrganizationRelationships::class)->designatePartner($other, $w['operator']);
        $foreign = pilotCustomer(['name' => 'Fiona Foreign']);
        psJoin($foreign, $other);
        app(PartnerSupportGrants::class)->grant($other, $foreign, $w['operator']);
        $coMember = pilotCustomer(['name' => 'Carl CoMember']);
        psJoin($coMember, $w['customer'], 'admin');
        $ungranted = pilotCustomer(['name' => 'Una Ungranted']);
        psJoin($ungranted, $w['partner']);

        // They are all authors of public replies, so are candidates; none passes the partner support rule.
        foreach ([$foreign, $coMember, $ungranted] as $author) {
            psReply($ticket, $author, false, 'a public reply');
        }
        psReply($ticket, $w['operator'], false, 'operator public reply');
        Notification::fake();

        app(TicketService::class)->addReply($ticket, $w['requester'], 'Anyone?', false);

        Notification::assertNotSentTo([$foreign, $coMember, $ungranted, $w['operator']], TicketRepliedNotification::class);
        Notification::assertSentTo($w['staff'], TicketRepliedNotification::class);
        Notification::assertSentTimes(TicketRepliedNotification::class, 1);
    });

    it('puts no partner mail out for a request nobody from partner support submitted or answered', function () {
        $w = psWorld();
        $other = r16Colleague($w);
        [$ticket] = r16Assigned($w, $w['ticket']);

        app(TicketService::class)->addReply($ticket, $w['requester'], 'Hi', false);

        Notification::assertNotSentTo([$w['staff'], $other], TicketRepliedNotification::class);
    });

    it('keeps one failed partner hand-over from failing the reply or stopping the requester and assignee mail', function () {
        Exceptions::fake();
        $w = psWorld();
        [$ticket, $assignee] = r16Assigned($w);
        ticketFailHandOff(TicketRepliedNotification::class, fn ($n) => $n instanceof User && $n->is($w['staff']));

        app(TicketService::class)->addReply($ticket, $assignee, 'Fixed.', false);

        expect(TicketReply::query()->where('body', 'Fixed.')->count())->toBe(1);
        Notification::assertSentTo($w['requester'], TicketRepliedNotification::class);
        Exceptions::assertReported(RuntimeException::class);
    });

    it('is queued, with the same retry policy as the other ticket mail', function () {
        foreach ([TicketCreatedNotification::class, TicketRepliedNotification::class, PartnerReplyAlertNotification::class] as $class) {
            expect(is_subclass_of($class, ShouldQueue::class))->toBeTrue($class)
                ->and((new ReflectionClass($class))->getAttributes(Tries::class)[0]->getArguments())->toBe([3]);
        }
    });
});

// ── the unassigned alert ────────────────────────────────

describe('a partner support reply and the helpdesk alert', function () {
    beforeEach(fn () => Notification::fake());

    function r16Alerts(int $times): void
    {
        Notification::assertSentOnDemandTimes(PartnerReplyAlertNotification::class, $times);
    }

    it('alerts the helpdesk address once when nobody is assigned, and still mails the requester', function () {
        $w = psWorld();

        $this->actingAs($w['staff'])->post(route('support.replies.store', $w['ticket']), ['body' => 'Can you check the cable?'])->assertRedirect();

        r16Alerts(1);
        Notification::assertSentOnDemand(PartnerReplyAlertNotification::class, fn ($n, $channels, AnonymousNotifiable $to) => $to->routes['mail'] === R16_ADDRESS && $n->ticket->is($w['ticket']));
        Notification::assertSentTo($w['requester'], TicketRepliedNotification::class);
        Notification::assertNotSentTo($w['staff'], TicketRepliedNotification::class);
    });

    it('sends no alert when an assignee is mailed, so the operator side hears once', function () {
        $w = psWorld();
        [$ticket, $assignee] = r16Assigned($w, $w['ticket']);

        $this->actingAs($w['staff'])->post(route('support.replies.store', $ticket), ['body' => 'Any news?'])->assertRedirect();

        r16Alerts(0);
        Notification::assertSentTo($assignee, TicketRepliedNotification::class);
    });

    it('alerts when the assignee cannot be mailed: suspended, or no longer able to view the request', function (Closure $change) {
        $w = psWorld();
        [$ticket, $assignee] = r16Assigned($w, $w['ticket']);
        $change($assignee);

        $this->actingAs($w['staff'])->post(route('support.replies.store', $ticket), ['body' => 'Any news?'])->assertRedirect();

        r16Alerts(1);
        Notification::assertNotSentTo($assignee, TicketRepliedNotification::class);
    })->with([
        'suspended' => fn (User $a) => DB::table('users')->where('id', $a->id)->update(['disabled_at' => now()]),
        'lost the ticket capability' => function (User $a) {
            $a->syncRoles([]);
            $a->syncPermissions([]);
            DB::table('model_has_roles')->where('model_id', $a->id)->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        },
    ]);

    it('does not alert for a requester reply or an operator reply on an unassigned request (unchanged behaviour)', function () {
        $w = psWorld();

        app(TicketService::class)->addReply($w['ticket'], $w['requester'], 'Hello', false);
        app(TicketService::class)->addReply($w['ticket']->refresh(), $w['operator'], 'Hi there', false);

        r16Alerts(0);
    });

    it('does not alert for an internal note, and never reaches the alert path from a refused reply', function () {
        $w = psWorld(['status' => 'resolved']);

        $this->actingAs($w['staff'])->post(route('support.replies.store', $w['ticket']), ['body' => 'Hello?'])->assertSessionHasErrors('body');

        r16Alerts(0);
        Notification::assertNothingSent();
    });

    it('writes a warning, and still completes the reply, when the helpdesk address is unusable', function () {
        config(['helpdesk.notify_address' => 'not-an-address']);
        Log::spy();
        $w = psWorld();

        $this->actingAs($w['staff'])->post(route('support.replies.store', $w['ticket']), ['body' => 'Hello'])->assertRedirect();

        r16Alerts(0);
        Notification::assertSentTo($w['requester'], TicketRepliedNotification::class);
        Log::shouldHaveReceived('log')->once()->withArgs(fn (string $level, string $message, array $context) => $level === 'warning'
            && str_contains($message, 'partner support reply alert') && ! str_contains($message.json_encode($context), 'not-an-address'));
    });

    it('carries the public reply only: a short escaped preview, the operator link, no attachment name, no address', function () {
        $w = psWorld();
        $w['staff']->forceFill(['name' => 'Eve *Bold* [x](https://evil.invalid)'])->save();
        $reply = app(TicketService::class)->addPartnerSupportReply($w['ticket'], $w['staff'], str_repeat('a', 190).'MIDDLE'.str_repeat('b', 60).'TAILMARKER', [ticketUpload('secret-plan.pdf')]);

        $html = (string) (new PartnerReplyAlertNotification($w['ticket'], $reply))->toMail(new AnonymousNotifiable)->render();

        expect($html)->toContain(str_repeat('a', 190))->not->toContain('TAILMARKER')->toContain('Charter')->toContain('Rae Requester')->toContain('Printer offline')
            ->toContain('href="'.route('operator.tickets.show', $w['ticket']).'"')->not->toContain(route('support.show', $w['ticket']))
            ->not->toContain('secret-plan.pdf')->not->toContain($w['staff']->email)->not->toContain($w['requester']->email)
            ->not->toContain('href="https://evil.invalid"')->not->toContain('<strong>Bold</strong>');
    });

    it('is not sent for a reply that is internal, gone or on another ticket', function () {
        $w = psWorld();
        $other = Ticket::factory()->open()->create(['organization_id' => $w['customer']->id]);
        $public = psReply($w['ticket'], $w['staff'], false, 'public');
        $note = psReply($w['ticket'], $w['operator'], true, 'note');
        $elsewhere = psReply($other, $w['staff'], false, 'elsewhere');
        $on = new AnonymousNotifiable;

        expect((new PartnerReplyAlertNotification($w['ticket'], $public))->shouldSend($on, 'mail'))->toBeTrue()
            ->and((new PartnerReplyAlertNotification($w['ticket'], $note))->shouldSend($on, 'mail'))->toBeFalse()
            ->and((new PartnerReplyAlertNotification($w['ticket'], $elsewhere))->shouldSend($on, 'mail'))->toBeFalse();
    });
});

// ── the real queue: eligibility is decided again when the worker runs ──

describe('through the real queue', function () {
    beforeEach(function () {
        config(['queue.default' => 'database', 'mail.default' => 'array']);
        $this->sent = [];
        Event::listen(MessageSent::class, function (MessageSent $event) {
            $this->sent[] = $event->message;
        });
    });

    function r16Drain(): void
    {
        for ($i = 0, $jobs = DB::table('jobs')->count(); $i < $jobs; $i++) {
            Artisan::call('queue:work', ['--once' => true, '--sleep' => 0, '--queue' => 'default']);
        }
    }

    function r16Clean(): bool
    {
        return DB::table('jobs')->count() === 0 && DB::table('failed_jobs')->count() === 0;
    }

    /** A request raised for the contact, with the creation jobs already worked off, then the requester's public reply left QUEUED. */
    function r16QueuedReply(array $w, array $files = []): array
    {
        $ticket = r16Raise($w);
        r16Drain();
        test()->sent = []; // the creation mail is not what these tests are about
        $reply = app(TicketService::class)->addReply($ticket, $w['requester'], 'It is still broken.', false, $files);

        return [$ticket, $reply];
    }

    function r16To(array $sent, User $user): array
    {
        return array_values(array_filter($sent, fn ($m) => $m->getTo()[0]->getAddress() === $user->email));
    }

    it('delivers the partner mail with the support link and the public text only', function () {
        $w = psWorld();
        $assignee = ticketAgent(['email' => 'assignee-r16@example.test']);
        $ticket = r16Raise($w);
        $ticket->forceFill(['assignee_id' => $assignee->id])->save();
        psReply($ticket, $assignee, true, 'INTERNAL-NOTE-SENTINEL credit hold');
        r16Drain();
        $this->sent = [];
        app(TicketService::class)->addReply($ticket->refresh(), $w['requester'], 'It is still broken.', false, [ticketUpload('secret-plan.pdf')]);

        r16Drain();

        $mail = r16To($this->sent, $w['staff']);
        expect($mail)->toHaveCount(1)->and(r16Clean())->toBeTrue();
        $html = (string) $mail[0]->getHtmlBody();
        $text = (string) $mail[0]->getTextBody();
        expect($mail[0]->getSubject())->toBe("[{$ticket->ticket_number}] New reply: Printer offline")
            ->and($html)->toContain('It is still broken.')->toContain('Rae Requester')->toContain('Charter')
            ->toContain(route('support.show', $ticket))->not->toContain(route('tickets.show', $ticket))
            ->not->toContain('INTERNAL-NOTE-SENTINEL')->not->toContain('secret-plan.pdf')->not->toContain('assignee-r16@example.test')
            ->and($text)->not->toContain('INTERNAL-NOTE-SENTINEL')->not->toContain('secret-plan.pdf');
        // The assignee got the ordinary mail with the ordinary link; the requester (the author) got nothing about their own reply.
        expect(r16To($this->sent, $assignee))->toHaveCount(1)->and(r16To($this->sent, $w['requester']))->toBeEmpty();
        expect((string) r16To($this->sent, $assignee)[0]->getHtmlBody())->toContain(route('tickets.show', $ticket));
    });

    it('sends nothing to a participant whose authority ended after the mail was queued', function (Closure $end) {
        $w = psWorld();
        [$ticket] = r16QueuedReply($w);
        expect(DB::table('jobs')->count())->toBe(1);
        $end($w);

        r16Drain();

        expect(r16To($this->sent, $w['staff']))->toBeEmpty()->and(r16Clean())->toBeTrue();
    })->with('r16Ends');

    it('does not even queue a partner mail for a participant who is not effective partner support at dispatch', function (Closure $end) {
        $w = psWorld();
        $ticket = r16Raise($w);
        r16Drain();
        $end($w);

        app(TicketService::class)->addReply($ticket, $w['requester'], 'Hello?', false);

        // The requester is the author and nobody is assigned: the only possible job was the participant's.
        expect(DB::table('jobs')->count())->toBe(0);
    })->with('r16Ends');

    it('queues nothing at all for an internal note (and the worker would refuse one anyway)', function () {
        $w = psWorld();
        $assignee = ticketAgent();
        $ticket = r16Raise($w);
        $ticket->forceFill(['assignee_id' => $assignee->id])->save();
        r16Drain();
        $this->sent = [];

        app(TicketService::class)->addReply($ticket->refresh(), $assignee, 'INTERNAL-NOTE-SENTINEL', true);

        expect(DB::table('jobs')->count())->toBe(0)->and($this->sent)->toBeEmpty();
    });

    it('sends the control mail when nothing changed', function () {
        $w = psWorld();
        r16QueuedReply($w);

        r16Drain();

        expect(r16To($this->sent, $w['staff']))->toHaveCount(1)->and(r16Clean())->toBeTrue();
    });

    it('drops the partner mail when the reply became an internal note', function () {
        $w = psWorld();
        [$ticket, $reply] = r16QueuedReply($w);
        DB::table('ticket_replies')->where('id', $reply->id)->update(['is_internal' => true]);

        r16Drain();

        expect($this->sent)->toBeEmpty()->and(r16Clean())->toBeTrue();
    });

    it('treats a job serialized before R1-6 (no audience in the payload) as the default audience, and an alert for an account as nothing', function () {
        $w = psWorld();
        $ticket = r16Raise($w);
        $reply = psReply($ticket, $w['operator'], false, 'Public');
        // The payload as the previous release wrote it: the same properties without `audience`.
        $legacy = function (object $n): object {
            $payload = $n->__serialize();
            unset($payload['audience']);
            $old = (new ReflectionClass($n))->newInstanceWithoutConstructor();
            $old->__unserialize($payload);

            return $old;
        };

        $created = $legacy(new TicketCreatedNotification($ticket));
        $replied = $legacy(new TicketRepliedNotification($ticket, $reply));

        expect(isset($created->audience))->toBeFalse()->and(isset($replied->audience))->toBeFalse()
            ->and($created->shouldSend($w['requester'], 'mail'))->toBeTrue()
            ->and((string) $created->toMail($w['requester'])->render())->toContain('has been received')
            ->and($replied->shouldSend($w['requester'], 'mail'))->toBeTrue()
            ->and($replied->shouldSend($w['staff'], 'mail'))->toBeFalse();
        expect((new PartnerReplyAlertNotification($ticket, $reply))->shouldSend($w['requester'], 'mail'))->toBeFalse();
    });

    it('does not mail the author, even when the job is built for them', function () {
        $w = psWorld();
        $ticket = r16Raise($w);
        $reply = psReply($ticket, $w['staff'], false, 'My own words');

        expect((new TicketRepliedNotification($ticket, $reply, TicketRepliedNotification::FOR_PARTNER_SUPPORT))->shouldSend($w['staff'], 'mail'))->toBeFalse()
            ->and((new TicketRepliedNotification($ticket, $reply))->shouldSend($w['staff'], 'mail'))->toBeFalse();
    });

    it('does not let the partner audience reach the requester, an operator or a member of the customer through the support rule', function () {
        $w = psWorld();
        $ticket = r16Raise($w);
        $reply = psReply($ticket, $w['operator'], false, 'Public');
        $note = new TicketRepliedNotification($ticket, $reply, TicketRepliedNotification::FOR_PARTNER_SUPPORT);

        expect($note->shouldSend($w['requester'], 'mail'))->toBeFalse()
            ->and($note->shouldSend($w['operator'], 'mail'))->toBeFalse()
            ->and($note->shouldSend($w['staff'], 'mail'))->toBeTrue();
    });

    it('sends the submitter confirmation, and drops it for a submitter whose authority ended in the queue', function () {
        $w = psWorld();
        $ticket = r16Raise($w);
        app(PartnerSupportGrants::class)->revoke($w['partner'], $w['staff'], $w['operator']);

        r16Drain();

        // The requester's receipt and the helpdesk alert are delivered; the submitter's confirmation is not.
        expect(r16To($this->sent, $w['requester']))->toHaveCount(1)->and(r16To($this->sent, $w['staff']))->toBeEmpty()->and(r16Clean())->toBeTrue();
    });

    it('delivers the submitter confirmation, with the support link, when authority still holds', function () {
        $w = psWorld();
        $ticket = r16Raise($w);

        r16Drain();

        $mine = r16To($this->sent, $w['staff']);
        expect($mine)->toHaveCount(1)->and((string) $mine[0]->getHtmlBody())->toContain(route('support.show', $ticket))->and(r16Clean())->toBeTrue();
        $theirs = r16To($this->sent, $w['requester']);
        expect($theirs)->toHaveCount(1)->and((string) $theirs[0]->getHtmlBody())->toContain('Pat Partner')->toContain(route('tickets.show', $ticket));
    });

    it('drops the receipt of a partner-raised request for a requester suspended in the queue, and never builds it for anyone but the requester', function () {
        $w = psWorld();
        $ticket = r16Raise($w);
        DB::table('users')->where('id', $w['requester']->id)->update(['disabled_at' => now()]);

        r16Drain();

        expect(r16To($this->sent, $w['requester']))->toBeEmpty()->and(r16Clean())->toBeTrue();
        // The receipt of a request someone ELSE authored is re-decided at send time: only the requester passes.
        $receipt = new TicketCreatedNotification($ticket);
        expect($receipt->shouldSend($w['staff'], 'mail'))->toBeFalse()->and($receipt->shouldSend($w['operator'], 'mail'))->toBeFalse()
            ->and($receipt->shouldSend(User::query()->find($w['requester']->id), 'mail'))->toBeTrue();
        // ... and the submitter confirmation is built only for the submitter.
        $confirmation = new TicketCreatedNotification($ticket, TicketCreatedNotification::FOR_SUBMITTER);
        expect($confirmation->shouldSend($w['requester'], 'mail'))->toBeFalse()->and($confirmation->shouldSend($w['staff'], 'mail'))->toBeTrue();
    });

    it('delivers the partner alert through the queue with the operator link only', function () {
        $w = psWorld();
        $this->actingAs($w['staff'])->post(route('support.replies.store', $w['ticket']), ['body' => 'Please check the cable.'])->assertRedirect();

        r16Drain();

        $alert = array_values(array_filter($this->sent, fn ($m) => $m->getTo()[0]->getAddress() === R16_ADDRESS));
        expect($alert)->toHaveCount(1)->and((string) $alert[0]->getHtmlBody())->toContain('Please check the cable.')->toContain(route('operator.tickets.show', $w['ticket']))
            ->and(r16To($this->sent, $w['requester']))->toHaveCount(1)->and(r16Clean())->toBeTrue();
    });
});

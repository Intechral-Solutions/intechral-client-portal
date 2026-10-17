<?php

/*
 * R0.9 WP1 — a queued notification is re-checked against persisted state when the worker runs it.
 *
 * Invitation and reply mail is queued, so minutes can pass between "decided to send" and "sent". In that window an
 * invitation can be re-issued or accepted, and a recipient can lose the right to see a ticket. Laravel evaluates
 * `shouldSend()` immediately before each channel delivers, inside the job, so that is where the rules live.
 *
 * These tests use the REAL database queue and a REAL `queue:work --once` worker wherever the outcome depends on what the
 * worker does with the job (consumed, released for retry, or failed): `failed_jobs` and `available_at` are written by
 * the worker, not by the job. The mail transport is the array mailer, so "sent" means a MessageSent event.
 */

use App\Models\Invitation;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketRepliedNotification;
use App\Services\InvitationService;
use App\Services\TicketService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    config(['queue.default' => 'database', 'mail.default' => 'array']);
    $this->sent = [];
    Event::listen(MessageSent::class, function (MessageSent $event) {
        $this->sent[] = $event->message;
    });
});

/** Run exactly one queued job, the way a worker does. Tries/backoff come from the job, as in production. */
function wp1WorkerRunsOnce(): void
{
    Artisan::call('queue:work', ['--once' => true, '--sleep' => 0, '--queue' => 'default']);
}

/** @return array{jobs: int, failed: int} */
function wp1QueueState(): array
{
    return ['jobs' => DB::table('jobs')->count(), 'failed' => DB::table('failed_jobs')->count()];
}

/** Make the next database statements that mention `$table` fail like a dropped connection, until the returned switch is cleared. */
function wp1FailQueriesOn(string $table): object
{
    $switch = (object) ['on' => true];
    DB::beforeExecuting(function (string $sql, array $bindings) use ($switch, $table) {
        if ($switch->on && str_contains($sql, "`{$table}`") && str_starts_with(ltrim($sql), 'select')) {
            throw new QueryException('mysql', $sql, $bindings, new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away'));
        }
    });

    return $switch;
}

function wp1Operator(): User
{
    return ticketUser('operator');
}

describe('invitations', function () {
    it('sends the live invitation (control)', function () {
        $invitation = app(InvitationService::class)->invite('new.person@example.com', wp1Operator());

        wp1WorkerRunsOnce();

        expect(wp1QueueState())->toBe(['jobs' => 0, 'failed' => 0])
            ->and($this->sent)->toHaveCount(1)
            ->and($this->sent[0]->getHtmlBody())->toContain($invitation->token);
    });

    it('does not send an obsolete invitation: only the re-issued link is mailed', function () {
        $service = app(InvitationService::class);
        $operator = wp1Operator();
        $first = $service->invite('new.person@example.com', $operator);
        $second = $service->invite('new.person@example.com', $operator);

        wp1WorkerRunsOnce();
        wp1WorkerRunsOnce();

        // Both jobs are consumed (the obsolete one by dropping its mail, not by failing); exactly one mail leaves.
        expect(wp1QueueState())->toBe(['jobs' => 0, 'failed' => 0])
            ->and($this->sent)->toHaveCount(1)
            ->and($this->sent[0]->getHtmlBody())->toContain($second->token)->not->toContain($first->token);
    });

    it('does not send an invitation that was accepted before the worker ran', function () {
        $service = app(InvitationService::class);
        $invitation = $service->invite('new.person@example.com', wp1Operator());
        $service->acceptWithPassword($invitation->token, 'New Person', 'a-Strong-Passw0rd!');

        wp1WorkerRunsOnce();

        expect(wp1QueueState())->toBe(['jobs' => 0, 'failed' => 0])->and($this->sent)->toBeEmpty();
    });

    it('does not send an invitation that expired by time before the worker ran', function () {
        app(InvitationService::class)->invite('new.person@example.com', wp1Operator());

        $this->travel(49)->hours();
        wp1WorkerRunsOnce();

        expect(wp1QueueState())->toBe(['jobs' => 0, 'failed' => 0])->and($this->sent)->toBeEmpty();
    });

    it('discards, rather than fails, an invitation that no longer exists', function () {
        $invitation = app(InvitationService::class)->invite('new.person@example.com', wp1Operator());
        $invitation->delete();

        wp1WorkerRunsOnce();

        expect(wp1QueueState())->toBe(['jobs' => 0, 'failed' => 0])->and($this->sent)->toBeEmpty();
    });

    it('does not mistake a transient database failure for ineligibility: the job is retried and then delivered', function () {
        app(InvitationService::class)->invite('new.person@example.com', wp1Operator());
        $outage = wp1FailQueriesOn('invitations');

        wp1WorkerRunsOnce();

        // Attempt 1 hit the outage: nothing was sent or dropped, the job is released 60 s out, not failed.
        $job = DB::table('jobs')->first();
        expect(wp1QueueState())->toBe(['jobs' => 1, 'failed' => 0])
            ->and($this->sent)->toBeEmpty()
            ->and($job->attempts)->toBe(1)
            ->and($job->available_at - now()->timestamp)->toBeBetween(58, 60);

        $outage->on = false;
        $this->travel(61)->seconds();
        wp1WorkerRunsOnce();

        expect(wp1QueueState())->toBe(['jobs' => 0, 'failed' => 0])->and($this->sent)->toHaveCount(1);
    });
});

describe('ticket replies', function () {
    beforeEach(function () {
        $this->owner = ticketUser();
        $this->agent = ticketAgent();
        $this->ticket = ticketFor($this->owner, ['assignee_id' => $this->agent->id]);
    });

    it('sends a public reply to the owner and to an assignee who can still view the ticket (control)', function () {
        $reply = ticketReply($this->ticket, wp1Operator(), false);
        $this->owner->notify(new TicketRepliedNotification($this->ticket, $reply));
        $this->agent->notify(new TicketRepliedNotification($this->ticket, $reply));

        wp1WorkerRunsOnce();
        wp1WorkerRunsOnce();

        expect(wp1QueueState())->toBe(['jobs' => 0, 'failed' => 0])->and($this->sent)->toHaveCount(2);
    });

    it('does not send the reply preview to an assignee who lost ticket access after it was queued', function () {
        $reply = ticketReply($this->ticket, wp1Operator(), false, 'Confidential preview text');
        $this->agent->notify(new TicketRepliedNotification($this->ticket, $reply));

        Role::findByName('support_agent')->revokePermissionTo('tickets.assign');
        wp1WorkerRunsOnce();

        expect(wp1QueueState())->toBe(['jobs' => 0, 'failed' => 0])->and($this->sent)->toBeEmpty();
    });

    it('uses the ticket policy, so an owner is never cut off by a lost operator capability', function () {
        $reply = ticketReply($this->ticket, wp1Operator(), false);
        $this->owner->notify(new TicketRepliedNotification($this->ticket, $reply));

        Role::findByName('user')->revokePermissionTo('tickets.view');
        wp1WorkerRunsOnce();

        // The policy lets an owner view their own ticket regardless of role permissions; the notification does not add a rule.
        expect($this->sent)->toHaveCount(1);
    });

    it('never mails an internal note, even if one is queued', function () {
        $note = ticketReply($this->ticket, wp1Operator(), true, 'Internal only');
        $this->agent->notify(new TicketRepliedNotification($this->ticket, $note));
        $this->owner->notify(new TicketRepliedNotification($this->ticket, $note));

        wp1WorkerRunsOnce();
        wp1WorkerRunsOnce();

        expect(wp1QueueState())->toBe(['jobs' => 0, 'failed' => 0])->and($this->sent)->toBeEmpty();
    });

    it('end to end: a reply queued by the service reaches the owner but not an assignee who lost access before delivery', function () {
        app(TicketService::class)->addReply($this->ticket, wp1Operator(), 'An update for you', false);
        expect(DB::table('jobs')->count())->toBe(2);

        Role::findByName('support_agent')->revokePermissionTo('tickets.assign');
        wp1WorkerRunsOnce();
        wp1WorkerRunsOnce();

        expect(wp1QueueState())->toBe(['jobs' => 0, 'failed' => 0])
            ->and($this->sent)->toHaveCount(1)
            ->and($this->sent[0]->getTo()[0]->getAddress())->toBe($this->owner->email);
    });

    it('still discards, rather than fails, a reply that was deleted (the existing missing-model safeguard)', function () {
        $reply = ticketReply($this->ticket, wp1Operator(), false);
        $this->owner->notify(new TicketRepliedNotification($this->ticket, $reply));
        $reply->delete();

        wp1WorkerRunsOnce();

        expect(wp1QueueState())->toBe(['jobs' => 0, 'failed' => 0])->and($this->sent)->toBeEmpty();
    });

    it('does not mistake a transient database failure for lost access: the job is retried and then delivered', function () {
        $reply = ticketReply($this->ticket, wp1Operator(), false);
        $this->owner->notify(new TicketRepliedNotification($this->ticket, $reply));
        $outage = wp1FailQueriesOn('ticket_replies');

        wp1WorkerRunsOnce();

        expect(wp1QueueState())->toBe(['jobs' => 1, 'failed' => 0])->and($this->sent)->toBeEmpty();

        $outage->on = false;
        $this->travel(61)->seconds();
        wp1WorkerRunsOnce();

        expect(wp1QueueState())->toBe(['jobs' => 0, 'failed' => 0])->and($this->sent)->toHaveCount(1);
    });

    it('keeps the existing suspension safeguard: a recipient suspended after queueing gets nothing', function () {
        $reply = ticketReply($this->ticket, wp1Operator(), false);
        $this->owner->notify(new TicketRepliedNotification($this->ticket, $reply));
        $this->owner->forceFill(['disabled_at' => now()])->save();

        wp1WorkerRunsOnce();

        expect(wp1QueueState())->toBe(['jobs' => 0, 'failed' => 0])->and($this->sent)->toBeEmpty();
    });

    it('keeps the existing missing-ticket safeguard on both ticket notifications', function () {
        $reply = ticketReply($this->ticket, wp1Operator(), false);
        $this->owner->notify(new TicketCreatedNotification($this->ticket));
        $this->owner->notify(new TicketRepliedNotification($this->ticket, $reply));
        $this->ticket->delete();

        wp1WorkerRunsOnce();
        wp1WorkerRunsOnce();

        expect(wp1QueueState())->toBe(['jobs' => 0, 'failed' => 0])->and($this->sent)->toBeEmpty();
    });
});

describe('retry contract', function () {
    it('gives every queued notification three attempts with a 60 s then 180 s backoff', function () {
        $owner = ticketUser();
        $ticket = ticketFor($owner);
        $reply = ticketReply($ticket, wp1Operator(), false);
        $invitation = Invitation::factory()->create();

        $jobs = [
            new SendQueuedNotifications($owner, new TicketCreatedNotification($ticket), ['mail']),
            new SendQueuedNotifications($owner, new TicketRepliedNotification($ticket, $reply), ['mail']),
            new SendQueuedNotifications($owner, new InvitationNotification($invitation), ['mail']),
        ];

        foreach ($jobs as $job) {
            expect($job->tries)->toBe(3, $job->displayName())
                ->and($job->backoff())->toBe([60, 180], $job->displayName());
        }
    });

    it('retries a failing send at 60 s then 180 s and fails it into failed_jobs on the third attempt', function () {
        $owner = ticketUser();
        $ticket = ticketFor($owner);
        $owner->notify(new TicketCreatedNotification($ticket));
        $attempts = 0;
        Event::listen(NotificationSending::class, function () use (&$attempts) {
            $attempts++;
            throw new RuntimeException('smtp 451 try again later');
        });

        wp1WorkerRunsOnce();
        expect(wp1QueueState())->toBe(['jobs' => 1, 'failed' => 0])
            ->and(DB::table('jobs')->value('available_at') - now()->timestamp)->toBeBetween(58, 60);

        $this->travel(61)->seconds();
        wp1WorkerRunsOnce();
        expect(wp1QueueState())->toBe(['jobs' => 1, 'failed' => 0])
            ->and(DB::table('jobs')->value('available_at') - now()->timestamp)->toBeBetween(178, 180);

        $this->travel(181)->seconds();
        wp1WorkerRunsOnce();
        expect(wp1QueueState())->toBe(['jobs' => 0, 'failed' => 1])
            ->and($attempts)->toBe(3)
            ->and(DB::table('failed_jobs')->value('exception'))->toContain('smtp 451 try again later');
    });

    it('leaves password reset delivery synchronous', function () {
        $user = ticketUser();

        $user->sendPasswordResetNotification('reset-token');

        expect(new ResetPassword('t'))->not->toBeInstanceOf(ShouldQueue::class)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and($this->sent)->toHaveCount(1);
    });
});

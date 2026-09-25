<?php

use App\Models\User;
use App\Notifications\TicketRepliedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/TicketTestHelpers.php';

/*
 * EPIC-010D WP0 characterization: H5 (assignee eligibility) and the reply-notification paths
 * that WP2's stale-assignee rule (§13) changes.
 *
 * Owners of assignment: single = Operator\TicketController::assign, bulk =
 * Operator\TicketBulkController::update (action=assign); both call TicketService::assign, the
 * only writer of tickets.assignee_id besides factories.
 *
 * Prefixes: BASELINE (keep), DEFECT Hn (CURRENT BROKEN behavior, flipped by the named WP).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
});

// ── H5 — single assignment ──────────────────────────────────────────────────

it('BASELINE H5: an operator assigns a tickets.assign holder, and the change is activity-logged', function () {
    $operator = ticketUser('operator');
    $eligible = ticketUser('operator');
    $ticket = ticketFor(ticketUser());

    $this->actingAs($operator)
        ->put(route('operator.tickets.assign', $ticket), ['assignee_id' => $eligible->id])
        ->assertRedirect()
        ->assertSessionHas('status', 'Ticket assignment updated.');

    expect($ticket->fresh()->assignee_id)->toBe($eligible->id);
    $log = Activity::forSubject($ticket)->sole();
    expect($log->description)->toBe('assigned ticket')
        ->and($log->causer_id)->toBe($operator->id)
        ->and($log->properties['assignee_id'])->toBe($eligible->id);
});

it('DEFECT H5 (WP2 flips to an assignee_id error with no change): the assignee can be any account without tickets.assign', function (string $kind) {
    $operator = ticketUser('operator');
    $ineligible = match ($kind) {
        'customer (user role)' => ticketUser(),
        'custom role without tickets.assign' => ticketCustomRoleUser('viewer_only', ['tickets.view']),
        'role-less account' => User::factory()->create(),
    };
    $ticket = ticketFor(ticketUser());

    expect($ineligible->can('tickets.assign'))->toBeFalse();

    $this->actingAs($operator)
        ->put(route('operator.tickets.assign', $ticket), ['assignee_id' => $ineligible->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($ticket->fresh()->assignee_id)->toBe($ineligible->id);
})->with(['customer (user role)', 'custom role without tickets.assign', 'role-less account']);

it('BASELINE H5: an empty assignee unassigns (single-ticket unassign stays allowed)', function () {
    $ticket = ticketFor(ticketUser(), ['assignee_id' => ticketUser('operator')->id]);

    $this->actingAs(ticketUser('operator'))
        ->put(route('operator.tickets.assign', $ticket), ['assignee_id' => ''])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($ticket->fresh()->assignee_id)->toBeNull();
});

it('BASELINE H5: a nonexistent assignee id is a validation error and changes nothing', function () {
    $current = ticketUser('operator');
    $ticket = ticketFor(ticketUser(), ['assignee_id' => $current->id]);

    $this->actingAs(ticketUser('operator'))
        ->put(route('operator.tickets.assign', $ticket), ['assignee_id' => 999_999_999])
        ->assertSessionHasErrors('assignee_id');

    expect($ticket->fresh()->assignee_id)->toBe($current->id)
        ->and(Activity::forSubject($ticket)->count())->toBe(0);
});

it('BASELINE H5: customers cannot reach single or bulk assignment', function () {
    $customer = ticketUser();
    $ticket = ticketFor($customer);

    $this->actingAs($customer)->put(route('operator.tickets.assign', $ticket), ['assignee_id' => $customer->id])->assertForbidden();
    $this->actingAs($customer)->post(route('operator.tickets.bulk'), [
        'ticket_ids' => [$ticket->id], 'action' => 'assign', 'assignee_id' => $customer->id,
    ])->assertForbidden();

    expect($ticket->fresh()->assignee_id)->toBeNull();
});

// ── H5 — bulk assignment ────────────────────────────────────────────────────

it('DEFECT H5 (WP2 flips to an assignee_id error with no ticket changed): bulk assignment accepts a customer for every selected ticket', function () {
    $customer = ticketUser();
    $t1 = ticketFor(ticketUser());
    $t2 = ticketFor(ticketUser());

    $this->actingAs(ticketUser('operator'))
        ->post(route('operator.tickets.bulk'), ['ticket_ids' => [$t1->id, $t2->id], 'action' => 'assign', 'assignee_id' => $customer->id])
        ->assertRedirect()
        ->assertSessionHas('status', 'Bulk action applied to 2 ticket(s).');

    expect([$t1->fresh()->assignee_id, $t2->fresh()->assignee_id])->toBe([$customer->id, $customer->id]);
});

// ── Notifications (EPIC-010D §10, §13) ──────────────────────────────────────

it('BASELINE notifications: reply notifications are queued and mail-only', function () {
    $reflection = new ReflectionClass(TicketRepliedNotification::class);
    expect($reflection->implementsInterface(ShouldQueue::class))->toBeTrue();

    $owner = ticketUser();
    $ticket = ticketFor($owner);
    $reply = ticketReply($ticket, ticketUser('operator'), false);
    expect((new TicketRepliedNotification($ticket, $reply))->via($owner))->toBe(['mail']);
});

it('BASELINE notifications: an owner reply notifies the assignee only; an internal note notifies nobody', function () {
    $owner = ticketUser();
    $assignee = ticketUser('operator');
    $ticket = ticketFor($owner, ['assignee_id' => $assignee->id]);

    $this->actingAs($owner)->post(route('tickets.replies.store', $ticket), ['body' => 'Owner follow-up']);
    Notification::assertSentToTimes($assignee, TicketRepliedNotification::class, 1);
    Notification::assertNotSentTo($owner, TicketRepliedNotification::class);

    $this->actingAs(ticketUser('operator'))->post(route('operator.tickets.replies.store', $ticket), ['body' => 'Note', 'is_internal' => '1']);
    Notification::assertSentToTimes($assignee, TicketRepliedNotification::class, 1);
    Notification::assertNotSentTo($owner, TicketRepliedNotification::class);
});

it('CHARACTERIZATION notifications: when the owner is also the assignee, a public reply by someone else notifies them twice', function () {
    $operatorOwner = ticketUser('operator');
    $ticket = ticketFor($operatorOwner, ['assignee_id' => $operatorOwner->id]);

    $this->actingAs(ticketUser('operator'))
        ->post(route('operator.tickets.replies.store', $ticket), ['body' => 'Colleague reply'])
        ->assertRedirect();

    Notification::assertSentToTimes($operatorOwner, TicketRepliedNotification::class, 2);
});

it('DEFECT H5 (WP2 flips via eligibility): a customer assignee receives public reply previews for another user\'s ticket', function () {
    $owner = ticketUser();
    $customerAssignee = ticketUser();
    $ticket = ticketFor($owner, ['assignee_id' => $customerAssignee->id]);

    $this->actingAs($owner)->post(route('tickets.replies.store', $ticket), ['body' => 'Private detail for support']);

    expect(Gate::forUser($customerAssignee)->allows('view', $ticket))->toBeFalse();
    Notification::assertSentTo($customerAssignee, TicketRepliedNotification::class,
        fn (TicketRepliedNotification $n) => str_contains($n->toMail($customerAssignee)->render(), 'Private detail for support'));
});

it('DEFECT stale assignee (WP2 §13 flips to not notified; no auto-unassign): an assignee who lost tickets.assign keeps the assignment and keeps getting reply previews', function () {
    $owner = ticketUser();
    $former = ticketUser('operator');
    $ticket = ticketFor($owner, ['assignee_id' => $former->id]);

    $former->syncRoles(['user']);
    $former->refresh();
    expect($former->can('tickets.assign'))->toBeFalse()
        ->and(Gate::forUser($former)->allows('view', $ticket))->toBeFalse();

    $this->actingAs($owner)->post(route('tickets.replies.store', $ticket), ['body' => 'Still going to the old assignee']);

    expect($ticket->fresh()->assignee_id)->toBe($former->id);
    Notification::assertSentToTimes($former, TicketRepliedNotification::class, 1);
});

it('CHARACTERIZATION notifications: the reply mail carries the ticket number and title, the author name, a 200-character body preview, and a link to the user show route', function () {
    $owner = ticketUser(attributes: ['name' => 'Olivia Owner']);
    $author = ticketUser('operator', ['name' => 'Oscar Operator']);
    $ticket = ticketFor($owner, ['title' => 'VPN drops']);
    $reply = ticketReply($ticket, $author, false, str_repeat('a', 250));

    $mail = (new TicketRepliedNotification($ticket, $reply))->toMail($owner);

    expect($mail->subject)->toBe("[{$ticket->ticket_number}] New reply: VPN drops")
        ->and($mail->greeting)->toBe('Hello Olivia Owner,')
        ->and($mail->introLines)->toContain('**Oscar Operator** wrote:')
        ->and($mail->introLines)->toContain(str_repeat('a', 200).'...')
        ->and($mail->actionUrl)->toBe(url("/tickets/{$ticket->id}"));
});

it('DEFECT H9 consequence (WP1 flips): the reply mail links an agent assignee to the user show route, which 403s for them', function () {
    $owner = ticketUser();
    $agent = ticketAgent();
    $ticket = ticketFor($owner, ['assignee_id' => $agent->id]);
    $reply = ticketReply($ticket, $owner, false);

    $link = (new TicketRepliedNotification($ticket, $reply))->toMail($agent)->actionUrl;

    expect($link)->toBe(route('tickets.show', $ticket));
    $this->actingAs($agent)->get($link)->assertForbidden();
});

<?php

use App\Models\User;
use App\Notifications\TicketRepliedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/TicketTestHelpers.php';

/*
 * EPIC-010D: H5 (assignee eligibility) and the reply-notification recipient rules (§13). WP0
 * characterized them; WP2 converted every defect test into the TARGET contract (pre-fix evidence:
 * EPIC-010D Amendment 1).
 *
 * Owners of assignment: single = Operator\TicketController::assign, bulk =
 * Operator\TicketBulkController::update (action=assign); both call TicketService::assign, the one
 * place eligibility (`tickets.assign`) is enforced.
 *
 * Prefixes: BASELINE (already correct, keep), TARGET (the WP2 contract).
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

it('TARGET H5: an ineligible assignee is a validation error; nothing changes and nothing is logged', function (string $kind) {
    $operator = ticketUser('operator');
    $ineligible = match ($kind) {
        'customer (user role)' => ticketUser(),
        'custom role without tickets.assign' => ticketCustomRoleUser('viewer_only', ['tickets.view']),
        'role-less account' => User::factory()->create(),
    };
    $current = ticketUser('operator');
    $ticket = ticketFor(ticketUser(), ['assignee_id' => $current->id]);

    expect($ineligible->can('tickets.assign'))->toBeFalse();

    $this->actingAs($operator)
        ->put(route('operator.tickets.assign', $ticket), ['assignee_id' => $ineligible->id])
        ->assertRedirect()
        ->assertSessionHasErrors('assignee_id');

    expect($ticket->fresh()->assignee_id)->toBe($current->id)
        ->and(Activity::forSubject($ticket)->count())->toBe(0);
})->with(['customer (user role)', 'custom role without tickets.assign', 'role-less account']);

it('TARGET H5: a tickets.assign holder is accepted whatever their role name (custom role, direct grant)', function (string $kind) {
    $eligible = match ($kind) {
        'custom role' => ticketAgent(),
        'direct grant on the user role' => tap(ticketUser())->givePermissionTo('tickets.assign'),
    };
    $ticket = ticketFor(ticketUser());

    $this->actingAs(ticketUser('operator'))
        ->put(route('operator.tickets.assign', $ticket), ['assignee_id' => $eligible->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($ticket->fresh()->assignee_id)->toBe($eligible->id);
})->with(['custom role', 'direct grant on the user role']);

it('TARGET H5 (stale assignee, D2): the stale assignment stays through unrelated actions, cannot be re-selected, and can still be cleared', function () {
    $operator = ticketUser('operator');
    $former = ticketUser('operator');
    $ticket = ticketFor(ticketUser(), ['assignee_id' => $former->id]);
    $former->syncRoles(['user']);
    $former->refresh();
    expect($former->can('tickets.assign'))->toBeFalse();

    // Unrelated action: the assignment is untouched (no auto-unassign).
    $this->actingAs($operator)->put(route('operator.tickets.status', $ticket), ['status' => 'in_progress'])->assertRedirect();
    expect($ticket->fresh()->assignee_id)->toBe($former->id);

    // Re-submitting the stale assignee fails eligibility, single and bulk; nothing is logged.
    $this->actingAs($operator)->put(route('operator.tickets.assign', $ticket), ['assignee_id' => $former->id])
        ->assertSessionHasErrors('assignee_id');
    $this->actingAs($operator)->post(route('operator.tickets.bulk'), ['ticket_ids' => [$ticket->id], 'action' => 'assign', 'assignee_id' => $former->id])
        ->assertSessionHasErrors('assignee_id');
    expect($ticket->fresh()->assignee_id)->toBe($former->id)
        ->and(Activity::forSubject($ticket)->count())->toBe(0);

    // Explicit single-ticket unassign is still allowed and logged as before.
    $this->actingAs($operator)->put(route('operator.tickets.assign', $ticket), ['assignee_id' => ''])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($ticket->fresh()->assignee_id)->toBeNull()
        ->and(Activity::forSubject($ticket)->sole()->properties['assignee_id'])->toBeNull();
});

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

it('TARGET H5: bulk assignment to an ineligible user is a validation error and no selected ticket changes', function () {
    $customer = ticketUser();
    $current = ticketUser('operator');
    $t1 = ticketFor(ticketUser(), ['assignee_id' => $current->id]);
    $t2 = ticketFor(ticketUser());

    $this->actingAs(ticketUser('operator'))
        ->post(route('operator.tickets.bulk'), ['ticket_ids' => [$t1->id, $t2->id], 'action' => 'assign', 'assignee_id' => $customer->id])
        ->assertRedirect()
        ->assertSessionHasErrors('assignee_id');

    expect([$t1->fresh()->assignee_id, $t2->fresh()->assignee_id])->toBe([$current->id, null])
        ->and(Activity::count())->toBe(0);
});

it('TARGET H5: bulk assignment to an eligible user assigns every selected ticket and logs each', function () {
    $agent = ticketAgent();
    $t1 = ticketFor(ticketUser());
    $t2 = ticketFor(ticketUser());

    $this->actingAs(ticketUser('operator'))
        ->post(route('operator.tickets.bulk'), ['ticket_ids' => [$t1->id, $t2->id], 'action' => 'assign', 'assignee_id' => $agent->id])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Bulk action applied to 2 ticket(s).');

    expect([$t1->fresh()->assignee_id, $t2->fresh()->assignee_id])->toBe([$agent->id, $agent->id])
        ->and(Activity::forSubject($t1)->count())->toBe(1)
        ->and(Activity::forSubject($t2)->count())->toBe(1);
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

it('TARGET notifications: when the owner is also the assignee, a public reply by someone else notifies them once', function () {
    $operatorOwner = ticketUser('operator');
    $ticket = ticketFor($operatorOwner, ['assignee_id' => $operatorOwner->id]);

    $this->actingAs(ticketUser('operator'))
        ->post(route('operator.tickets.replies.store', $ticket), ['body' => 'Colleague reply'])
        ->assertRedirect();

    Notification::assertSentToTimes($operatorOwner, TicketRepliedNotification::class, 1);
});

it('TARGET notifications: the author is never notified, even as both owner and assignee', function () {
    $operatorOwner = ticketUser('operator');
    $ticket = ticketFor($operatorOwner, ['assignee_id' => $operatorOwner->id]);

    $this->actingAs($operatorOwner)->post(route('operator.tickets.replies.store', $ticket), ['body' => 'My own reply']);

    Notification::assertNothingSent();
});

it('TARGET notifications: a distinct eligible owner and assignee each get exactly one', function () {
    $owner = ticketUser();
    $assignee = ticketAgent();
    $ticket = ticketFor($owner, ['assignee_id' => $assignee->id]);

    $this->actingAs(ticketUser('operator'))->post(route('operator.tickets.replies.store', $ticket), ['body' => 'Update']);

    Notification::assertSentToTimes($owner, TicketRepliedNotification::class, 1);
    Notification::assertSentToTimes($assignee, TicketRepliedNotification::class, 1);
});
it('TARGET H5: a customer assignee (unable to view the ticket) receives no reply preview', function () {
    $owner = ticketUser();
    $customerAssignee = ticketUser();
    $ticket = ticketFor($owner, ['assignee_id' => $customerAssignee->id]);

    $this->actingAs($owner)->post(route('tickets.replies.store', $ticket), ['body' => 'Private detail for support']);

    expect(Gate::forUser($customerAssignee)->allows('view', $ticket))->toBeFalse();
    Notification::assertNotSentTo($customerAssignee, TicketRepliedNotification::class);
    Notification::assertNothingSent();
});
it('TARGET stale assignee (D2): an assignee who lost tickets.assign keeps the assignment but gets no reply preview; the owner still does', function () {
    $owner = ticketUser();
    $former = ticketUser('operator');
    $ticket = ticketFor($owner, ['assignee_id' => $former->id]);

    $former->syncRoles(['user']);
    $former->refresh();
    expect($former->can('tickets.assign'))->toBeFalse()
        ->and(Gate::forUser($former)->allows('view', $ticket))->toBeFalse();

    $this->actingAs(ticketUser('operator'))->post(route('operator.tickets.replies.store', $ticket), ['body' => 'Not for the old assignee']);

    expect($ticket->fresh()->assignee_id)->toBe($former->id);
    Notification::assertNotSentTo($former, TicketRepliedNotification::class);
    Notification::assertSentToTimes($owner, TicketRepliedNotification::class, 1);
});

it('TARGET stale assignee (D2): a stale assignee who owns the ticket still gets exactly one notification', function () {
    $ownerAssignee = ticketUser('operator');
    $ticket = ticketFor($ownerAssignee, ['assignee_id' => $ownerAssignee->id]);
    $ownerAssignee->syncRoles(['user']);
    $ownerAssignee->refresh();
    expect($ownerAssignee->can('tickets.assign'))->toBeFalse();

    $this->actingAs(ticketUser('operator'))->post(route('operator.tickets.replies.store', $ticket), ['body' => 'Reply to the owner']);

    Notification::assertSentToTimes($ownerAssignee, TicketRepliedNotification::class, 1);
});

it('TARGET notifications: an internal note notifies neither owner nor assignee, stale or not', function () {
    $owner = ticketUser();
    $assignee = ticketAgent();
    $ticket = ticketFor($owner, ['assignee_id' => $assignee->id]);

    $this->actingAs(ticketUser('operator'))->post(route('operator.tickets.replies.store', $ticket), ['body' => 'Internal', 'is_internal' => '1']);

    Notification::assertNothingSent();
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

it('TARGET H9: an agent assignee can follow the reply mail link to the ticket', function () {
    $owner = ticketUser();
    $agent = ticketAgent();
    $ticket = ticketFor($owner, ['assignee_id' => $agent->id]);
    $reply = ticketReply($ticket, $owner, false);

    $link = (new TicketRepliedNotification($ticket, $reply))->toMail($agent)->actionUrl;

    expect($link)->toBe(route('tickets.show', $ticket));
    $this->actingAs($agent)->get($link)->assertOk();
});

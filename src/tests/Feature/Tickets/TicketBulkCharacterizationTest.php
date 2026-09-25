<?php

use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/TicketTestHelpers.php';

/*
 * EPIC-010D: H6 (malformed bulk and single-assign input) on POST /operator/tickets/bulk
 * (Operator\TicketBulkController::update) and PUT /operator/tickets/{ticket}/assign. WP0
 * characterized the four exception sites; WP2 converted them into validation contracts (pre-fix
 * evidence: EPIC-010D Amendment 1).
 *
 * Every malformed-input test asserts the full outcome: a redirect with session validation errors
 * (never a 500) and that no ticket, assignee or status-history row changed. Prefixes: BASELINE
 * (already correct, keep), TARGET (the WP2 contract), CHARACTERIZATION (deferred behavior recorded
 * as fact).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    $this->operator = ticketUser('operator');
});

/** Two open tickets assigned to one operator, plus a snapshot of their mutable state. */
function ticketBulkPair(): array
{
    $assignee = ticketUser('operator');
    $tickets = [ticketFor(ticketUser(), ['assignee_id' => $assignee->id]), ticketFor(ticketUser(), ['assignee_id' => $assignee->id])];

    return [$tickets, $assignee];
}

function ticketBulkState(array $tickets): array
{
    return [
        'rows' => Ticket::whereIn('id', array_map(fn ($t) => $t->id, $tickets))->orderBy('id')->get(['id', 'status', 'assignee_id'])->toArray(),
        'history' => TicketStatusHistory::count(),
    ];
}

it('TARGET H6: action=status without a valid status is a validation error, never a 500, and changes nothing', function (array $extra) {
    [$tickets] = ticketBulkPair();
    $before = ticketBulkState($tickets);

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_ids' => array_map(fn ($t) => $t->id, $tickets), 'action' => 'status', ...$extra])
        ->assertRedirect()
        ->assertSessionHasErrors('status');

    expect(ticketBulkState($tickets))->toBe($before);
})->with([
    'missing key' => [[]],
    'empty string' => [['status' => '']],
    'null' => [['status' => null]],
    'unknown value' => [['status' => 'archived']],
]);

it('BASELINE H6: a valid backend-only action=status applies the transition', function () {
    [$tickets] = ticketBulkPair();

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_ids' => array_map(fn ($t) => $t->id, $tickets), 'action' => 'status', 'status' => 'in_progress'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Ticket::whereIn('id', array_map(fn ($t) => $t->id, $tickets))->pluck('status')->unique()->all())->toBe(['in_progress']);
});

it('TARGET H6 (D4): action=assign without a real assignee is a validation error and never unassigns anything', function (array $extra) {
    [$tickets] = ticketBulkPair();
    $before = ticketBulkState($tickets);

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_ids' => array_map(fn ($t) => $t->id, $tickets), 'action' => 'assign', ...$extra])
        ->assertRedirect()
        ->assertSessionHasErrors('assignee_id')
        ->assertSessionMissing('status');

    expect(ticketBulkState($tickets))->toBe($before)
        ->and(Ticket::whereIn('id', array_map(fn ($t) => $t->id, $tickets))->whereNull('assignee_id')->count())->toBe(0);
})->with([
    'missing key' => [[]],
    'blank placeholder' => [['assignee_id' => '']],
    'null' => [['assignee_id' => null]],
    'not an id' => [['assignee_id' => 'abc']],
    'unknown user' => [['assignee_id' => 999_999_999]],
]);

it('BASELINE H6: invalid action, empty selection and an unknown ticket id are validation errors with no mutation', function (array $payload, string $errorKey) {
    [$tickets] = ticketBulkPair();
    $before = ticketBulkState($tickets);
    $ids = array_map(fn ($t) => $t->id, $tickets);

    $payload = array_map(fn ($value) => $value === 'IDS' ? $ids : ($value === 'IDS_PLUS_UNKNOWN' ? [...$ids, 999_999_999] : $value), $payload);

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), $payload)
        ->assertSessionHasErrors($errorKey);

    expect(ticketBulkState($tickets))->toBe($before);
})->with([
    'invalid action' => [['ticket_ids' => 'IDS', 'action' => 'delete'], 'action'],
    'missing action' => [['ticket_ids' => 'IDS'], 'action'],
    'empty ticket_ids' => [['ticket_ids' => [], 'action' => 'close'], 'ticket_ids'],
    'unknown ticket id in batch' => [['ticket_ids' => 'IDS_PLUS_UNKNOWN', 'action' => 'close'], 'ticket_ids.2'],
]);

it('CHARACTERIZATION H6: an impossible transition is skipped per ticket, yet the flash counts every selected ticket', function () {
    $open = ticketFor(ticketUser());
    $closed = Ticket::factory()->closed()->for(ticketUser(), 'user')->create();

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_ids' => [$open->id, $closed->id], 'action' => 'resolve'])
        ->assertRedirect()
        ->assertSessionHas('status', 'Bulk action applied to 2 ticket(s).');

    expect($open->fresh()->status)->toBe('resolved')
        ->and($closed->fresh()->status)->toBe('closed')
        ->and(TicketStatusHistory::where('ticket_id', $closed->id)->count())->toBe(0);
});

it('TARGET H6: single assign without an assignee key is a validation error, while an explicit blank still unassigns', function () {
    $assignee = ticketUser('operator');
    $ticket = ticketFor(ticketUser(), ['assignee_id' => $assignee->id]);

    $this->actingAs($this->operator)
        ->put(route('operator.tickets.assign', $ticket), [])
        ->assertRedirect()
        ->assertSessionHasErrors('assignee_id');
    expect($ticket->fresh()->assignee_id)->toBe($assignee->id);

    $this->actingAs($this->operator)
        ->put(route('operator.tickets.assign', $ticket), ['assignee_id' => ''])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    expect($ticket->fresh()->assignee_id)->toBeNull();
});

it('TARGET H6: the batch is all-or-nothing on a mid-loop failure, so a late error leaves no ticket half-changed', function () {
    [$tickets] = ticketBulkPair();
    $ids = array_map(fn ($t) => $t->id, $tickets);
    $before = ticketBulkState($tickets);
    $failOn = $tickets[1]->id;

    // Fail while transitioning the second ticket (after the first already changed inside the batch).
    TicketStatusHistory::creating(function ($history) use ($failOn) {
        if ($history->ticket_id === $failOn) {
            throw new RuntimeException('simulated failure');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->operator)->post(route('operator.tickets.bulk'), ['ticket_ids' => $ids, 'action' => 'close']))
        ->toThrow(RuntimeException::class);

    TicketStatusHistory::flushEventListeners();
    expect(ticketBulkState($tickets))->toBe($before);
});

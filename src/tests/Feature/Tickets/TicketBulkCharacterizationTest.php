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
        ->post(route('operator.tickets.bulk'), ['ticket_refs' => array_map(fn ($t) => $t->ticket_number, $tickets), 'action' => 'status', ...$extra])
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
        ->post(route('operator.tickets.bulk'), ['ticket_refs' => array_map(fn ($t) => $t->ticket_number, $tickets), 'action' => 'status', 'status' => 'in_progress'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Ticket::whereIn('id', array_map(fn ($t) => $t->id, $tickets))->pluck('status')->unique()->all())->toBe(['in_progress']);
});

it('TARGET H6 (D4): action=assign without a real assignee is a validation error and never unassigns anything', function (array $extra) {
    [$tickets] = ticketBulkPair();
    $before = ticketBulkState($tickets);

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_refs' => array_map(fn ($t) => $t->ticket_number, $tickets), 'action' => 'assign', ...$extra])
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

it('BASELINE H6: invalid action, empty selection and an unknown ticket reference are validation errors with no mutation', function (array $payload, string $errorKey) {
    [$tickets] = ticketBulkPair();
    $before = ticketBulkState($tickets);
    $refs = array_map(fn ($t) => $t->ticket_number, $tickets);

    $payload = array_map(fn ($value) => $value === 'REFS' ? $refs : ($value === 'REFS_PLUS_UNKNOWN' ? [...$refs, 'TK-ZZZZZ-ZZZZZ'] : $value), $payload);

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), $payload)
        ->assertSessionHasErrors($errorKey);

    expect(ticketBulkState($tickets))->toBe($before);
})->with([
    'invalid action' => [['ticket_refs' => 'REFS', 'action' => 'delete'], 'action'],
    'missing action' => [['ticket_refs' => 'REFS'], 'action'],
    'empty ticket_refs' => [['ticket_refs' => [], 'action' => 'close'], 'ticket_refs'],
    'unknown ticket reference in batch' => [['ticket_refs' => 'REFS_PLUS_UNKNOWN', 'action' => 'close'], 'ticket_refs'],
]);

it('HD-P1: an impossible transition is skipped per ticket and the flash reports applied and skipped separately', function () {
    $open = ticketFor(ticketUser());
    $closed = Ticket::factory()->closed()->for(ticketUser(), 'user')->create();

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_refs' => [$open->ticket_number, $closed->ticket_number], 'action' => 'resolve'])
        ->assertRedirect()
        ->assertSessionHas('status', fn ($m) => str_starts_with($m, '1 ticket updated. Skipped 1: '.$closed->ticket_number.' (Closed → Resolved is not allowed)'));

    expect($open->fresh()->status)->toBe('resolved')
        ->and($closed->fresh()->status)->toBe('closed')
        ->and(TicketStatusHistory::where('ticket_id', $closed->id)->count())->toBe(0);
});

it('HD-P1: a bulk action that changes nothing is reported as an error, not a success', function () {
    $closed = Ticket::factory()->closed()->for(ticketUser(), 'user')->create();

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_refs' => [$closed->ticket_number], 'action' => 'resolve'])
        ->assertRedirect()
        ->assertSessionMissing('status')
        ->assertSessionHasErrors('bulk');

    expect($closed->fresh()->status)->toBe('closed');
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
    $refs = array_map(fn ($t) => $t->ticket_number, $tickets);
    $before = ticketBulkState($tickets);
    $failOn = $tickets[1]->id;

    // Fail while transitioning the second ticket (after the first already changed inside the batch).
    TicketStatusHistory::creating(function ($history) use ($failOn) {
        if ($history->ticket_id === $failOn) {
            throw new RuntimeException('simulated failure');
        }
    });

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->operator)->post(route('operator.tickets.bulk'), ['ticket_refs' => $refs, 'action' => 'close']))
        ->toThrow(RuntimeException::class);

    TicketStatusHistory::flushEventListeners();
    expect(ticketBulkState($tickets))->toBe($before);
});

// ── C6-R: tickets are named by reference, never by the numeric key ───────────

it('C6-R: a bulk action names tickets by reference; the numeric key is not a ticket name any more', function () {
    [$tickets] = ticketBulkPair();
    $before = ticketBulkState($tickets);

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_ids' => array_map(fn ($t) => $t->id, $tickets), 'action' => 'close'])
        ->assertSessionHasErrors('ticket_refs');

    expect(ticketBulkState($tickets))->toBe($before);

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_refs' => array_map(fn ($t) => $t->ticket_number, $tickets), 'action' => 'close'])
        ->assertSessionHasNoErrors();

    expect(Ticket::whereIn('id', array_map(fn ($t) => $t->id, $tickets))->pluck('status')->unique()->all())->toBe(['closed']);
});

it('C6-R: a reference that is malformed or no longer exists rejects the whole bulk batch and changes nothing', function (string $bad) {
    [$tickets] = ticketBulkPair();
    $before = ticketBulkState($tickets);

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_refs' => [$tickets[0]->ticket_number, $bad], 'action' => 'close'])
        ->assertSessionHasErrors();

    expect(ticketBulkState($tickets))->toBe($before);
})->with(['unknown' => ['TK-ZZZZZ-ZZZZZ'], 'a legacy number' => ['TKT-0001'], 'a numeric id' => ['1'], 'lower case' => ['tk-zzzzz-zzzzz']]);

it('C6-R: a reference listed twice is applied once', function () {
    [$tickets] = ticketBulkPair();

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_refs' => [$tickets[0]->ticket_number, $tickets[0]->ticket_number], 'action' => 'close'])
        ->assertSessionHasNoErrors();

    expect($tickets[0]->fresh()->status)->toBe('closed')->and($tickets[1]->fresh()->status)->toBe('open');
});

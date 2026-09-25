<?php

use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/TicketTestHelpers.php';

/*
 * EPIC-010D WP0 characterization: H6 (malformed bulk and single-assign input) on
 * POST /operator/tickets/bulk (Operator\TicketBulkController::update) and
 * PUT /operator/tickets/{ticket}/assign.
 *
 * Every malformed-input test asserts the full current outcome: HTTP status, errors or flash, and
 * that no ticket, assignee or status history row changed. Prefixes: BASELINE (keep), DEFECT H6
 * (CURRENT BROKEN behavior, WP2 flips it to a validation error with no mutation).
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

it('DEFECT H6 (WP2 flips to a status error): action=status without a status key is a 500 and changes nothing', function () {
    [$tickets] = ticketBulkPair();
    $before = ticketBulkState($tickets);

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_ids' => array_map(fn ($t) => $t->id, $tickets), 'action' => 'status'])
        ->assertServerError();

    expect(ticketBulkState($tickets))->toBe($before);
});

it('DEFECT H6 (WP2 flips to a status error): action=status with an empty status is a 500 and changes nothing', function () {
    [$tickets] = ticketBulkPair();
    $before = ticketBulkState($tickets);

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_ids' => array_map(fn ($t) => $t->id, $tickets), 'action' => 'status', 'status' => ''])
        ->assertServerError();

    expect(ticketBulkState($tickets))->toBe($before);
});

it('BASELINE H6: an unknown status is a validation error and changes nothing', function () {
    [$tickets] = ticketBulkPair();
    $before = ticketBulkState($tickets);

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_ids' => array_map(fn ($t) => $t->id, $tickets), 'action' => 'status', 'status' => 'archived'])
        ->assertSessionHasErrors('status');

    expect(ticketBulkState($tickets))->toBe($before);
});

it('BASELINE H6: a valid backend-only action=status applies the transition', function () {
    [$tickets] = ticketBulkPair();

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_ids' => array_map(fn ($t) => $t->id, $tickets), 'action' => 'status', 'status' => 'in_progress'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Ticket::whereIn('id', array_map(fn ($t) => $t->id, $tickets))->pluck('status')->unique()->all())->toBe(['in_progress']);
});

it('DEFECT H6 (WP2 flips to an assignee_id error): action=assign without an assignee key is a 500 and changes nothing', function () {
    [$tickets] = ticketBulkPair();
    $before = ticketBulkState($tickets);

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_ids' => array_map(fn ($t) => $t->id, $tickets), 'action' => 'assign'])
        ->assertServerError();

    expect(ticketBulkState($tickets))->toBe($before);
});

it('DEFECT H6 (WP2 flips to an assignee_id error, no bulk unassign): action=assign with the empty placeholder assignee silently unassigns every selected ticket', function () {
    [$tickets, $assignee] = ticketBulkPair();

    $this->actingAs($this->operator)
        ->post(route('operator.tickets.bulk'), ['ticket_ids' => array_map(fn ($t) => $t->id, $tickets), 'action' => 'assign', 'assignee_id' => ''])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Bulk action applied to 2 ticket(s).');

    expect(Ticket::whereIn('id', array_map(fn ($t) => $t->id, $tickets))->pluck('assignee_id')->all())->toBe([null, null]);
});

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

it('DEFECT H6 (WP2 flips to an assignee_id error): single assign without an assignee key is a 500 and changes nothing', function () {
    $assignee = ticketUser('operator');
    $ticket = ticketFor(ticketUser(), ['assignee_id' => $assignee->id]);

    $this->actingAs($this->operator)
        ->put(route('operator.tickets.assign', $ticket), [])
        ->assertServerError();

    expect($ticket->fresh()->assignee_id)->toBe($assignee->id);
});

it('DEFECT H6 (WP2 removes these throw sites): the 500s are unguarded reads of keys validation did not guarantee', function (string $method, string $routeName, array $payload, string $exception, string $message) {
    [$tickets] = ticketBulkPair();
    $ids = array_map(fn ($t) => $t->id, $tickets);
    $url = $routeName === 'operator.tickets.assign' ? route($routeName, $tickets[0]) : route($routeName);
    $payload = $routeName === 'operator.tickets.bulk' ? ['ticket_ids' => $ids, ...$payload] : $payload;

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($this->operator)->{$method}($url, $payload))
        ->toThrow($exception, $message);
})->with([
    'bulk status, no key' => ['post', 'operator.tickets.bulk', ['action' => 'status'], ErrorException::class, 'Undefined array key "status"'],
    'bulk status, empty' => ['post', 'operator.tickets.bulk', ['action' => 'status', 'status' => ''], TypeError::class, 'must be of type string, null given'],
    'bulk assign, no key' => ['post', 'operator.tickets.bulk', ['action' => 'assign'], ErrorException::class, 'Undefined array key "assignee_id"'],
    'single assign, no key' => ['put', 'operator.tickets.assign', [], ErrorException::class, 'Undefined array key "assignee_id"'],
]);

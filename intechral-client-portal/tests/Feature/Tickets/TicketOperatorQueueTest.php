<?php

use App\Models\Ticket;
use App\Models\User;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

// ── Guests & unauthorised ────────────────────────────────────────────────────

it('redirects guests from operator queue to login', function () {
    $this->get(route('operator.tickets.index'))->assertRedirect('/login');
});

it('returns 403 for users without tickets.assign', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $this->actingAs($user)->get(route('operator.tickets.index'))->assertForbidden();
});

// ── Queue ────────────────────────────────────────────────────────────────────

it('shows all tickets on the operator queue', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $user = User::factory()->create();
    Ticket::factory()->for($user, 'user')->create(['title' => 'Alpha Ticket']);
    Ticket::factory()->for($user, 'user')->create(['title' => 'Beta Ticket']);

    $this->actingAs($operator)
        ->get(route('operator.tickets.index'))
        ->assertOk()
        ->assertSee('Alpha Ticket')
        ->assertSee('Beta Ticket');
});

it('filters the queue by status', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $user     = User::factory()->create();

    Ticket::factory()->for($user, 'user')->create(['title' => 'Open One', 'status' => 'open']);
    Ticket::factory()->for($user, 'user')->create(['title' => 'Closed One', 'status' => 'closed']);

    $this->actingAs($operator)
        ->get(route('operator.tickets.index', ['status' => 'open']))
        ->assertOk()
        ->assertSee('Open One')
        ->assertDontSee('Closed One');
});

it('filters the queue by priority', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $user     = User::factory()->create();

    Ticket::factory()->for($user, 'user')->create(['title' => 'Critical One', 'priority' => 'critical']);
    Ticket::factory()->for($user, 'user')->create(['title' => 'Low One', 'priority' => 'low']);

    $this->actingAs($operator)
        ->get(route('operator.tickets.index', ['priority' => 'critical']))
        ->assertOk()
        ->assertSee('Critical One')
        ->assertDontSee('Low One');
});

it('filters the queue by assignee', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $user     = User::factory()->create();
    $assignee = User::factory()->create();
    $assignee->assignRole('operator');

    Ticket::factory()->for($user, 'user')->assignedTo($assignee)->create(['title' => 'Assigned Ticket']);
    Ticket::factory()->for($user, 'user')->create(['title' => 'Unassigned Ticket']);

    $this->actingAs($operator)
        ->get(route('operator.tickets.index', ['assignee' => $assignee->id]))
        ->assertOk()
        ->assertSee('Assigned Ticket')
        ->assertDontSee('Unassigned Ticket');
});

it('shows operator ticket detail page', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $user     = User::factory()->create();
    $ticket   = Ticket::factory()->open()->for($user, 'user')->create(['title' => 'Detailed Ticket']);

    $this->actingAs($operator)
        ->get(route('operator.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('Detailed Ticket');
});

// ── Assignment ────────────────────────────────────────────────────────────────

it('allows operator to assign a ticket', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $assignee = User::factory()->create();
    $ticket   = Ticket::factory()->open()->for(User::factory()->create(), 'user')->create();

    $this->actingAs($operator)
        ->put(route('operator.tickets.assign', $ticket), ['assignee_id' => $assignee->id])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($ticket->fresh()->assignee_id)->toBe($assignee->id);
});

it('allows operator to unassign a ticket', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $assignee = User::factory()->create();
    $ticket   = Ticket::factory()->for(User::factory()->create(), 'user')->assignedTo($assignee)->create();

    $this->actingAs($operator)
        ->put(route('operator.tickets.assign', $ticket), ['assignee_id' => ''])
        ->assertRedirect();

    expect($ticket->fresh()->assignee_id)->toBeNull();
});

// ── Bulk actions ─────────────────────────────────────────────────────────────

it('bulk-assigns tickets to an operator', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $assignee = User::factory()->create();
    $user     = User::factory()->create();

    $t1 = Ticket::factory()->open()->for($user, 'user')->create();
    $t2 = Ticket::factory()->open()->for($user, 'user')->create();

    $this->actingAs($operator)
        ->post(route('operator.tickets.bulk'), [
            'ticket_ids'  => [$t1->id, $t2->id],
            'action'      => 'assign',
            'assignee_id' => $assignee->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($t1->fresh()->assignee_id)->toBe($assignee->id);
    expect($t2->fresh()->assignee_id)->toBe($assignee->id);
});

it('bulk-closes tickets', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $user     = User::factory()->create();

    $t1 = Ticket::factory()->resolved()->for($user, 'user')->create();
    $t2 = Ticket::factory()->resolved()->for($user, 'user')->create();

    $this->actingAs($operator)
        ->post(route('operator.tickets.bulk'), [
            'ticket_ids' => [$t1->id, $t2->id],
            'action'     => 'close',
        ])
        ->assertRedirect();

    expect($t1->fresh()->status)->toBe('closed');
    expect($t2->fresh()->status)->toBe('closed');
});

it('validates ticket_ids are required for bulk action', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $this->actingAs($operator)
        ->post(route('operator.tickets.bulk'), ['action' => 'close'])
        ->assertSessionHasErrors('ticket_ids');
});

// ── Search ────────────────────────────────────────────────────────────────────

it('searches tickets by title in operator queue', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $user     = User::factory()->create();

    Ticket::factory()->for($user, 'user')->create(['title' => 'Unique Search Term Alpha']);
    Ticket::factory()->for($user, 'user')->create(['title' => 'Another Ticket Beta']);

    $this->actingAs($operator)
        ->get(route('operator.tickets.index', ['search' => 'Alpha']))
        ->assertOk()
        ->assertSee('Unique Search Term Alpha')
        ->assertDontSee('Another Ticket Beta');
});

it('searches tickets by ticket number', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $user     = User::factory()->create();

    Ticket::factory()->for($user, 'user')->create(['ticket_number' => 'TKT-1234', 'title' => 'Number Ticket']);
    Ticket::factory()->for($user, 'user')->create(['ticket_number' => 'TKT-9999', 'title' => 'Other Ticket']);

    $this->actingAs($operator)
        ->get(route('operator.tickets.index', ['search' => 'TKT-1234']))
        ->assertOk()
        ->assertSee('Number Ticket')
        ->assertDontSee('Other Ticket');
});

<?php

use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

// ── Status transitions (service layer) ──────────────────────────────────────

it('transitions a ticket from open to in_progress', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $ticket = Ticket::factory()->open()->for($operator, 'user')->create();

    app(TicketService::class)->transition($ticket, $operator, 'in_progress');

    expect($ticket->fresh()->status)->toBe('in_progress');
});

it('records status history on transition', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $ticket = Ticket::factory()->open()->for($operator, 'user')->create();

    app(TicketService::class)->transition($ticket, $operator, 'in_progress');

    $history = $ticket->statusHistories()->latest()->first();
    expect($history->old_status)->toBe('open');
    expect($history->new_status)->toBe('in_progress');
    expect($history->user_id)->toBe($operator->id);
});

it('sets resolved_at when ticket is resolved', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $ticket = Ticket::factory()->state(['status' => 'in_progress'])->for($operator, 'user')->create();

    app(TicketService::class)->transition($ticket, $operator, 'resolved');

    expect($ticket->fresh()->resolved_at)->not->toBeNull();
});

it('sets closed_at when ticket is closed', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $ticket = Ticket::factory()->resolved()->for($operator, 'user')->create();

    app(TicketService::class)->transition($ticket, $operator, 'closed');

    expect($ticket->fresh()->closed_at)->not->toBeNull();
});

it('clears resolved_at and closed_at when re-opened', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $ticket = Ticket::factory()->closed()->for($operator, 'user')->create();

    app(TicketService::class)->transition($ticket, $operator, 'open');

    $fresh = $ticket->fresh();
    expect($fresh->status)->toBe('open');
    expect($fresh->resolved_at)->toBeNull();
    expect($fresh->closed_at)->toBeNull();
});

it('throws a ValidationException for an invalid transition', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $ticket = Ticket::factory()->closed()->for($operator, 'user')->create();

    // closed → resolved is not in the allowed transitions
    expect(fn () => app(TicketService::class)->transition($ticket, $operator, 'in_progress'))
        ->toThrow(ValidationException::class);
});

it('returns 500 or validation error for invalid status via HTTP', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $ticket = Ticket::factory()->open()->for($operator, 'user')->create();

    $this->actingAs($operator)
        ->put(route('operator.tickets.status', $ticket), ['status' => 'nonexistent'])
        ->assertSessionHasErrors('status');
});

// ── Operator HTTP transitions ────────────────────────────────────────────────

it('operator can update ticket status via the queue', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $ticket = Ticket::factory()->open()->for($operator, 'user')->create();

    $this->actingAs($operator)
        ->put(route('operator.tickets.status', $ticket), ['status' => 'in_progress'])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($ticket->fresh()->status)->toBe('in_progress');
});

// ── Auto-close ───────────────────────────────────────────────────────────────

it('auto-closes resolved tickets idle for more than 72 hours', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user, 'user')->create([
        'status' => 'resolved',
        'resolved_at' => now()->subHours(73),
    ]);

    $count = app(TicketService::class)->autoCloseResolved(72);

    expect($count)->toBe(1);
    expect($ticket->fresh()->status)->toBe('closed');
});

it('does not auto-close tickets resolved within the idle window', function () {
    $user = User::factory()->create();
    $ticket = Ticket::factory()->for($user, 'user')->create([
        'status' => 'resolved',
        'resolved_at' => now()->subHours(10),
    ]);

    $count = app(TicketService::class)->autoCloseResolved(72);

    expect($count)->toBe(0);
    expect($ticket->fresh()->status)->toBe('resolved');
});

// ── Overdue detection ────────────────────────────────────────────────────────

it('marks a ticket as overdue when sla_due_at is in the past', function () {
    $ticket = Ticket::factory()->overdue()->for(User::factory()->create(), 'user')->create();
    expect($ticket->isOverdue())->toBeTrue();
});

it('does not mark a resolved ticket as overdue even if sla has passed', function () {
    $ticket = Ticket::factory()->for(User::factory()->create(), 'user')->create([
        'status' => 'resolved',
        'sla_due_at' => now()->subHours(5),
        'resolved_at' => now()->subHour(),
    ]);
    expect($ticket->isOverdue())->toBeFalse();
});

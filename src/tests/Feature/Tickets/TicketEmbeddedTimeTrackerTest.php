<?php

use App\Models\Ticket;
use App\Models\TimeEntry;
use App\Models\User;

/*
 * EPIC-017 WP2 — "N2 continuity": the embedded Blade `x-time-tracker` is the operator's ticket timer
 * until WP3 replaces it with the React operator page's TimerControl and time summary.
 *
 * Originally (EPIC-011E WP7, §21) this guarded the ticket page against the project/task migration, and
 * it pinned the N1 DISCLOSURE: a customer opening their own ticket saw a coworker's name and hours. The
 * tracker now lives on the operator route only (`operator/tickets/show`), and the requester page carries
 * no time data at all (RequesterTicketPropsTest pins the exact keys and sentinels there).
 *
 * REMOVAL CONDITION: delete this embed, `components/time-tracker` and this file when WP3's React operator
 * ticket page ships its own timer and summary.
 */

require_once __DIR__.'/TicketTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

it('shows the operator the ticket time, the recent entries and an in-context Start Timer on the operator page', function () {
    $agent = ticketAgent();
    $other = User::factory()->create(['name' => 'Ticket Coworker'])->assignRole('operator');
    $ticket = Ticket::factory()->create(['user_id' => ticketUser()->id, 'status' => 'open']);
    TimeEntry::factory()->create(['user_id' => $other->id, 'ticket_id' => $ticket->id, 'duration_minutes' => 60, 'date' => today()]);

    $html = $this->actingAs($agent)->get(route('operator.tickets.show', $ticket))->assertOk()
        ->assertSee('Ticket Coworker')->assertSee('1h')->getContent();

    // The button element, not the script's selector for it (which is on the page either way).
    expect($html)->toMatch('/<button[^>]*data-time-tracker-start/');
});

it('shows a running timer of the operator as Stop, and not as Start', function () {
    $agent = ticketAgent();
    $ticket = Ticket::factory()->create(['user_id' => ticketUser()->id, 'status' => 'open']);
    $running = TimeEntry::factory()->running()->create(['user_id' => $agent->id, 'ticket_id' => $ticket->id]);

    $html = $this->actingAs($agent)->get(route('operator.tickets.show', $ticket))->assertOk()
        ->assertSee('data-entry-id="'.$running->id.'"', false)->getContent();

    expect($html)->toMatch('/<button[^>]*data-time-tracker-stop[^>]*data-entry-id="'.$running->id.'"|<button[^>]*data-entry-id="'.$running->id.'"[^>]*data-time-tracker-stop/')
        ->and($html)->not->toMatch('/<button[^>]*data-time-tracker-start/');
});

it('starts and stops the ticket timer through the existing endpoints the embed uses, without new authorization', function () {
    $agent = ticketAgent();
    $ticket = Ticket::factory()->create(['user_id' => ticketUser()->id, 'status' => 'open']);

    $entryId = $this->actingAs($agent)->postJson(route('time.timer.start'), ['ticket_id' => $ticket->id])
        ->assertOk()->assertJsonPath('context.id', $ticket->id)->json('id');
    $this->actingAs($agent)->postJson(route('time.timer.stop', $entryId))->assertOk();

    expect(TimeEntry::findOrFail($entryId)->only(['user_id', 'ticket_id']))->toBe(['user_id' => $agent->id, 'ticket_id' => $ticket->id]);
});

it('does not give the tracker to the requester: their page has no tracker, and starting a timer is refused (G1)', function () {
    $owner = ticketUser();
    $ticket = Ticket::factory()->create(['user_id' => $owner->id, 'status' => 'open']);
    TimeEntry::factory()->create(['user_id' => User::factory()->create(['name' => 'Ticket Coworker'])->id, 'ticket_id' => $ticket->id, 'duration_minutes' => 60, 'date' => today()]);

    $this->actingAs($owner)->get(route('tickets.show', $ticket))->assertOk()
        ->assertDontSee('Ticket Coworker')->assertDontSee('data-time-tracker', false);
    $this->actingAs($owner)->postJson(route('time.timer.start'), ['ticket_id' => $ticket->id])
        ->assertUnprocessable()->assertJsonValidationErrors('ticket_id');
});

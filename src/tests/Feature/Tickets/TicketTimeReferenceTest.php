<?php

use App\Models\Ticket;
use App\Models\TimeEntry;

require_once __DIR__.'/TicketTestHelpers.php';

/*
 * Release 0.9 C6-R (ADR-008), R3: time attribution still carries the internal numeric `ticket_id`, but only together with the ticket's
 * public reference. After a recovery a page, a running client-side timer or a saved form can hold an id that now belongs to a different
 * ticket; the pair is checked on every path that submits a ticket (timer start, manual entry, entry edit), so the stale id is refused
 * instead of attaching time to someone else's ticket. Eligibility (`tickets.assign` + view) is a separate rule and unchanged.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->agent = ticketAgent();
    $this->ticket = ticketFor(ticketUser(), ['assignee_id' => $this->agent->id]);
    $this->other = ticketFor(ticketUser(), ['assignee_id' => $this->agent->id]);
});

function referenceTimePayload(array $context): array
{
    return ['date' => today()->toDateString(), 'hours' => 1, 'description' => 'Ticket work', ...$context];
}

it('starts a timer when the id travels with its own reference', function () {
    $this->actingAs($this->agent)
        ->postJson(route('time.timer.start'), ['ticket_id' => $this->ticket->id, 'ticket_reference' => $this->ticket->ticket_number])
        ->assertOk()
        ->assertJsonPath('context.reference', $this->ticket->ticket_number);

    expect(TimeEntry::where('ticket_id', $this->ticket->id)->count())->toBe(1);
});

it('accepts the reference in any letter case', function () {
    $this->actingAs($this->agent)
        ->postJson(route('time.timer.start'), ['ticket_id' => $this->ticket->id, 'ticket_reference' => strtolower($this->ticket->ticket_number)])
        ->assertOk();
});

it('refuses a ticket id with no reference, a malformed one, or the reference of another ticket, writing nothing', function (?string $reference, bool $other) {
    $reference = $other ? $this->other->ticket_number : $reference;
    $payload = ['ticket_id' => $this->ticket->id, ...($reference === null ? [] : ['ticket_reference' => $reference])];

    $this->actingAs($this->agent)->postJson(route('time.timer.start'), $payload)->assertStatus(422)->assertJsonValidationErrors('ticket_id');
    $this->actingAs($this->agent)->post(route('time.store'), referenceTimePayload($payload))->assertSessionHasErrors('ticket_id');

    expect(TimeEntry::count())->toBe(0);
})->with([
    'no reference' => [null, false],
    'empty' => ['', false],
    'a legacy number' => ['TKT-0001', false],
    'the numeric id as a string' => ['1', false],
    'another ticket\'s reference' => [null, true],
    'a well-formed but unknown reference' => ['TK-ZZZZZ-ZZZZZ', false],
]);

it('refuses an id that now names a different ticket than the page was built for (the stale-id case)', function () {
    // The page was rendered for $this->ticket; after a recovery its numeric id was reissued to a different ticket. The page still sends
    // the OLD reference with the id: the pair no longer matches, whichever way round.
    $staleReference = $this->ticket->ticket_number;
    $reissued = $this->other;

    $this->actingAs($this->agent)
        ->postJson(route('time.timer.start'), ['ticket_id' => $reissued->id, 'ticket_reference' => $staleReference])
        ->assertStatus(422);

    expect(TimeEntry::count())->toBe(0);
});

it('checks the pair on a manual entry and on an edit that submits a ticket', function () {
    $entry = TimeEntry::factory()->create(['user_id' => $this->agent->id, 'date' => today()->toDateString()]);

    $this->actingAs($this->agent)->post(route('time.store'), referenceTimePayload(['ticket_id' => $this->ticket->id, 'ticket_reference' => $this->ticket->ticket_number]))
        ->assertSessionHasNoErrors();
    expect(TimeEntry::where('ticket_id', $this->ticket->id)->count())->toBe(1);

    $this->actingAs($this->agent)->put(route('time.update', $entry), referenceTimePayload(['ticket_id' => $this->ticket->id, 'ticket_reference' => $this->other->ticket_number]))
        ->assertSessionHasErrors('ticket_id');
    expect($entry->fresh()->ticket_id)->toBeNull();

    $this->actingAs($this->agent)->put(route('time.update', $entry), referenceTimePayload(['ticket_id' => $this->ticket->id, 'ticket_reference' => $this->ticket->ticket_number]))
        ->assertSessionHasNoErrors();
    expect($entry->fresh()->ticket_id)->toBe($this->ticket->id);
});

it('needs no reference when no ticket is submitted: project, task and cleared contexts are unchanged', function () {
    $entry = TimeEntry::factory()->create(['user_id' => $this->agent->id, 'ticket_id' => $this->ticket->id, 'date' => today()->toDateString()]);

    // Editing an unrelated field without resubmitting the context keeps the stored ticket.
    $this->actingAs($this->agent)->put(route('time.update', $entry), referenceTimePayload(['description' => 'Renamed']))->assertSessionHasNoErrors();
    expect($entry->fresh()->ticket_id)->toBe($this->ticket->id);

    // Moving it out of ticket context submits null and needs no reference.
    $this->actingAs($this->agent)->put(route('time.update', $entry), referenceTimePayload(['ticket_id' => null]))->assertSessionHasNoErrors();
    expect($entry->fresh()->ticket_id)->toBeNull();
});

it('offers the reference with each ticket option and context payload so the client can send it back', function () {
    $options = $this->actingAs($this->agent)->getJson(route('time.context.options', ['type' => 'ticket']))->assertOk()->json();

    expect(collect($options)->firstWhere('id', $this->ticket->id)['reference'] ?? null)->toBe($this->ticket->ticket_number);
});

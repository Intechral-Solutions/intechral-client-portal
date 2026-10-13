<?php

/*
 * EPIC-017 WP2 step 1 — N1: ticket-context time is operator work (O1).
 *
 * `AccessibleTimeContext` admits a ticket only for an actor who holds `tickets.assign` AND can view
 * the ticket; owning it is not enough. That covers every NEW attribution (timer start, manual
 * entry, the context selector) and, through TimeEntryController::update, an edit that keeps an
 * entry on its ticket without resubmitting it. The shell presentation plays no part.
 *
 * Existing entries, pinned here as PRESERVED current behaviour (not a ratified exception, see the
 * WP2 amendment): an owner may stop an already-running ticket timer, edit that running timer's
 * description, delete an owned entry, and move an owned entry OUT of ticket context, each subject
 * to ownership and the billing lock. Stopping a timer DOES store the duration it had accumulated
 * on its ticket, so it is not "no ticket time"; it starts no new timer, attributes nothing to a
 * ticket and changes no context. None of these paths checks `tickets.assign` or ticket visibility.
 * General project time is unchanged.
 */

use App\Models\TimeEntry;
use App\Models\TimeEntryBlock;
use App\Models\User;
use App\Rules\AccessibleTimeContext;
use App\Services\ProjectService;

require_once __DIR__.'/TicketTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

/** The full time state an actor could have touched, so a refusal can be shown to write nothing. */
function ticketTimeSnapshot(): array
{
    return [
        // Raw stored values (not cast objects), so two reads of an untouched row compare identical.
        'entries' => TimeEntry::orderBy('id')->get()->map(fn (TimeEntry $e) => array_intersect_key($e->getRawOriginal(), array_flip([
            'id', 'user_id', 'project_id', 'task_id', 'ticket_id', 'date', 'duration_minutes', 'description', 'billable', 'timer_started_at', 'updated_at',
        ])))->all(),
        'blocks' => TimeEntryBlock::count(),
    ];
}

function ticketTimeEntryPayload(array $context = []): array
{
    return ['date' => today()->toDateString(), 'hours' => 1, 'description' => 'Ticket work', ...$context];
}

// ── New ticket time: refused for every non-operator, the owner included ────

it('refuses a ticket timer to its own requester, writing nothing', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner);
    $before = ticketTimeSnapshot();

    $this->actingAs($owner)
        ->postJson(route('time.timer.start'), ['ticket_id' => $ticket->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ticket_id');

    expect(ticketTimeSnapshot())->toBe($before)
        ->and(TimeEntry::where('user_id', $owner->id)->running()->exists())->toBeFalse();
});

it('refuses a manual ticket entry to its own requester, writing nothing', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner);
    $before = ticketTimeSnapshot();

    $this->actingAs($owner)
        ->post(route('time.store'), ticketTimeEntryPayload(['ticket_id' => $ticket->id]))
        ->assertSessionHasErrors('ticket_id');

    expect(ticketTimeSnapshot())->toBe($before);
});

it('refuses a customer the ticket context for every other ticket too', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner);
    $stranger = ticketUser();

    $this->actingAs($stranger)
        ->postJson(route('time.timer.start'), ['ticket_id' => $ticket->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ticket_id');

    expect(TimeEntry::count())->toBe(0);
});

it('allows ticket time to an operator who can view the ticket, by capability and not by role', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner);
    $agent = ticketAgent();

    $this->actingAs($agent)
        ->postJson(route('time.timer.start'), ['ticket_id' => $ticket->id])
        ->assertOk()
        ->assertJsonPath('context.id', $ticket->id);

    $this->actingAs($agent)
        ->post(route('time.store'), ticketTimeEntryPayload(['ticket_id' => $ticket->id]))
        ->assertSessionHasNoErrors();

    expect(TimeEntry::where('user_id', $agent->id)->where('ticket_id', $ticket->id)->count())->toBe(2);
});

it('answers the rule from the capability alone: the owner is refused until they hold tickets.assign', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner);

    expect(AccessibleTimeContext::allows($owner, 'ticket', $ticket->id))->toBeFalse();

    $owner->givePermissionTo('tickets.assign');

    expect(AccessibleTimeContext::allows(User::findOrFail($owner->id), 'ticket', $ticket->id))->toBeTrue()
        ->and(AccessibleTimeContext::allows(ticketAgent(), 'ticket', 999_999))->toBeFalse();
});

it('offers no ticket in the context selector to a customer, even one recorded as the assignee', function () {
    $customer = ticketUser();
    // A legacy row: assignment eligibility (EPIC-010D H5) prevents this today, the selector must not rely on it.
    ticketFor(ticketUser(), ['assignee_id' => $customer->id, 'status' => 'open']);
    $ownTicket = ticketFor($customer, ['assignee_id' => $customer->id]);

    $this->actingAs($customer)
        ->getJson(route('time.context.options', ['type' => 'ticket']))
        ->assertOk()
        ->assertExactJson([]);

    $agent = ticketAgent();
    $ownTicket->update(['assignee_id' => $agent->id]);

    $this->actingAs($agent)
        ->getJson(route('time.context.options', ['type' => 'ticket']))
        ->assertOk()
        ->assertJsonPath('0.id', $ownTicket->id);
});

// ── Existing ticket-linked entries: editing records ticket time ────────────

it('refuses a customer edit that KEEPS an entry on its ticket, whether or not ticket_id is resubmitted', function (bool $resubmit) {
    $owner = ticketUser();
    $ticket = ticketFor($owner);
    $entry = TimeEntry::factory()->create(['user_id' => $owner->id, 'ticket_id' => $ticket->id, 'duration_minutes' => 30, 'date' => today()->toDateString()]);
    $before = ticketTimeSnapshot();

    $payload = ['date' => today()->toDateString(), 'hours' => 8, 'description' => 'Inflated'];
    if ($resubmit) {
        $payload['ticket_id'] = $ticket->id;
    }

    $this->actingAs($owner)
        ->put(route('time.update', $entry), $payload)
        ->assertSessionHasErrors('ticket_id');

    expect(ticketTimeSnapshot())->toBe($before);
})->with([
    'ticket_id omitted (the context is kept implicitly)' => [false],
    'ticket_id resubmitted unchanged' => [true],
]);

it('refuses a customer moving an existing entry INTO a ticket context', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner);
    $entry = TimeEntry::factory()->create(['user_id' => $owner->id, 'date' => today()->toDateString()]);
    $before = ticketTimeSnapshot();

    $this->actingAs($owner)
        ->put(route('time.update', $entry), ticketTimeEntryPayload(['ticket_id' => $ticket->id]))
        ->assertSessionHasErrors('ticket_id');

    expect(ticketTimeSnapshot())->toBe($before);
});

it('PRESERVED: a customer may move an existing entry OUT of ticket context, which records no ticket time', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner);
    $entry = TimeEntry::factory()->create(['user_id' => $owner->id, 'ticket_id' => $ticket->id, 'date' => today()->toDateString()]);

    $this->actingAs($owner)
        ->put(route('time.update', $entry), ticketTimeEntryPayload(['ticket_id' => null]))
        ->assertSessionHasNoErrors();

    expect($entry->fresh()->ticket_id)->toBeNull();
});

it('lets an operator edit their own ticket entry and keep it on the ticket', function () {
    $agent = ticketAgent();
    $ticket = ticketFor(ticketUser());
    $entry = TimeEntry::factory()->create(['user_id' => $agent->id, 'ticket_id' => $ticket->id, 'duration_minutes' => 30, 'date' => today()->toDateString()]);

    $this->actingAs($agent)
        ->put(route('time.update', $entry), ['date' => today()->toDateString(), 'hours' => 2, 'description' => 'Kept'])
        ->assertSessionHasNoErrors();

    expect($entry->fresh()->only(['ticket_id', 'duration_minutes']))->toBe(['ticket_id' => $ticket->id, 'duration_minutes' => 120]);
});

// ── PRESERVED: ending, describing and removing existing owned time ─────────
//
// Current behaviour for records that already exist, pinned so a change is deliberate. None of these
// paths checks `tickets.assign` or ticket visibility; each is bounded by ownership and the billing lock.

it('PRESERVED: a customer can still stop a ticket timer that was already running, so it never runs forever', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner);
    $running = TimeEntry::factory()->running()->create(['user_id' => $owner->id, 'ticket_id' => $ticket->id]);

    $this->actingAs($owner)->postJson(route('time.timer.stop', $running))->assertOk();

    expect($running->fresh()->timer_started_at)->toBeNull();
});

it('PRESERVED: stopping stores the accumulated duration on the same ticket, even after tickets.assign was revoked', function () {
    $operator = ticketAgent();
    $ticket = ticketFor(ticketUser());
    $running = TimeEntry::factory()->running()->create([
        'user_id' => $operator->id, 'ticket_id' => $ticket->id, 'billable' => false, 'timer_started_at' => now()->subHours(3),
    ]);

    $operator->syncRoles(['user']);
    $revoked = User::findOrFail($operator->id);
    expect(AccessibleTimeContext::allows($revoked, 'ticket', $ticket->id))->toBeFalse();

    $this->actingAs($revoked)->postJson(route('time.timer.stop', $running))->assertOk();

    // Not "no ticket time": the three elapsed hours are now stored. Nothing else about the entry moves.
    $stopped = $running->fresh();
    expect($stopped->duration_minutes)->toBeGreaterThanOrEqual(180)->toBeLessThan(190)
        ->and($stopped->timer_started_at)->toBeNull()
        ->and($stopped->only(['user_id', 'ticket_id', 'project_id', 'task_id', 'billable']))
        ->toBe(['user_id' => $operator->id, 'ticket_id' => $ticket->id, 'project_id' => null, 'task_id' => null, 'billable' => false]);
});

it('keeps the ownership and billing-lock protections on the existing-entry paths', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner);
    $stranger = ticketUser();
    $running = TimeEntry::factory()->running()->create(['user_id' => $owner->id, 'ticket_id' => $ticket->id]);
    // The model refuses to save a billed running timer, so lock one with a raw update: the service's own
    // guard must hold even for data that arrived some other way.
    $invoiced = TimeEntry::factory()->running()->create(['user_id' => $owner->id, 'ticket_id' => $ticket->id]);
    TimeEntry::whereKey($invoiced->id)->update(['billed' => true]);
    $billed = TimeEntry::factory()->billed()->create(['user_id' => $owner->id, 'ticket_id' => $ticket->id, 'duration_minutes' => 30, 'date' => today()->toDateString()]);
    $before = ticketTimeSnapshot();

    // Someone else's entry: stop, describe, move out and delete are all forbidden.
    $this->actingAs($stranger)->postJson(route('time.timer.stop', $running))->assertForbidden();
    $this->actingAs($stranger)->patchJson(route('time.timer.description', $running), ['description' => 'x'])->assertForbidden();
    $this->actingAs($stranger)->put(route('time.update', $running), ticketTimeEntryPayload(['ticket_id' => null]))->assertForbidden();
    $this->actingAs($stranger)->delete(route('time.destroy', $running))->assertForbidden();

    // The owner's own billing-locked entries are immutable on every one of those paths.
    $this->actingAs($owner)->postJson(route('time.timer.stop', $invoiced))->assertUnprocessable();
    $this->actingAs($owner)->patchJson(route('time.timer.description', $invoiced), ['description' => 'x'])->assertUnprocessable();
    $this->actingAs($owner)->put(route('time.update', $billed), ticketTimeEntryPayload(['ticket_id' => null, 'hours' => 5]))->assertSessionHasErrors('time_entry');
    $this->actingAs($owner)->delete(route('time.destroy', $billed))->assertSessionHasErrors('time_entry');

    expect(ticketTimeSnapshot())->toBe($before);
});

it('PRESERVED: a customer can still edit the description of a ticket timer that was already running, and nothing else', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner);
    $running = TimeEntry::factory()->running()->create(['user_id' => $owner->id, 'ticket_id' => $ticket->id, 'billable' => false]);
    $startedAt = $running->fresh()->timer_started_at;

    $this->actingAs($owner)
        ->patchJson(route('time.timer.description', $running), ['description' => 'Renamed'])
        ->assertOk();

    $edited = $running->fresh();
    expect($edited->only(['description', 'duration_minutes', 'ticket_id', 'billable']))
        ->toBe(['description' => 'Renamed', 'duration_minutes' => 0, 'ticket_id' => $ticket->id, 'billable' => false])
        ->and($edited->timer_started_at->equalTo($startedAt))->toBeTrue();
});

it('refuses the description endpoint for an entry whose timer is not running, so it cannot edit stopped ticket time', function () {
    $owner = ticketUser();
    $entry = TimeEntry::factory()->create(['user_id' => $owner->id, 'ticket_id' => ticketFor($owner)->id, 'description' => 'Original']);

    $this->actingAs($owner)
        ->patchJson(route('time.timer.description', $entry), ['description' => 'Changed'])
        ->assertUnprocessable();

    expect($entry->fresh()->description)->toBe('Original');
});

it('PRESERVED: a customer can still delete their own ticket-linked entry', function () {
    $owner = ticketUser();
    $ticket = ticketFor($owner);
    $entry = TimeEntry::factory()->create(['user_id' => $owner->id, 'ticket_id' => $ticket->id]);

    $this->actingAs($owner)->delete(route('time.destroy', $entry))->assertRedirect();

    expect(TimeEntry::whereKey($entry->id)->exists())->toBeFalse();
});

// ── General Time is unchanged ──────────────────────────────────────────────

it('PRESERVED: the same customer still logs project time on a project they belong to', function () {
    $customer = ticketUser();
    $project = app(ProjectService::class)->create(ticketUser('operator'), ['name' => 'Customer project']);
    $project->members()->attach($customer->id, ['role' => 'member']);

    $this->actingAs($customer)
        ->postJson(route('time.timer.start'), ['project_id' => $project->id])
        ->assertOk();
    $this->actingAs($customer)
        ->post(route('time.store'), ticketTimeEntryPayload(['project_id' => $project->id]))
        ->assertSessionHasNoErrors();

    expect(TimeEntry::where('user_id', $customer->id)->where('project_id', $project->id)->count())->toBe(2);
});

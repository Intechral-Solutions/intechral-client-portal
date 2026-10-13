<?php

use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketRepliedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/TicketTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    Storage::fake('local');
});

it('allows a user to reply to their own ticket', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $ticket = Ticket::factory()->open()->for($user, 'user')->create();

    $this->actingAs($user)
        ->post(route('tickets.replies.store', $ticket), ['body' => 'Here is more info.'])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($ticket->replies()->count())->toBe(1);
    expect($ticket->replies()->first()->body)->toBe('Here is more info.');
    expect($ticket->replies()->first()->is_internal)->toBeFalse();
});

it('notifies the ticket owner when an operator posts a public reply', function () {
    $owner = User::factory()->create();
    $owner->assignRole('user');
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $ticket = Ticket::factory()->open()->for($owner, 'user')->create();

    $this->actingAs($operator)
        ->post(route('tickets.replies.store', $ticket), ['body' => 'We are looking into this.'])
        ->assertRedirect();

    Notification::assertSentTo($owner, TicketRepliedNotification::class);
});

it('does not notify the owner for internal notes', function () {
    $owner = User::factory()->create();
    $owner->assignRole('user');
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $ticket = Ticket::factory()->open()->for($owner, 'user')->create();

    $this->actingAs($operator)
        ->post(route('tickets.replies.store', $ticket), [
            'body' => 'Internal note only.',
            'is_internal' => '1',
        ])
        ->assertRedirect();

    Notification::assertNotSentTo($owner, TicketRepliedNotification::class);
});

it('does not show internal notes to the ticket owner on the show page', function () {
    $owner = User::factory()->create();
    $owner->assignRole('user');
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $ticket = Ticket::factory()->open()->for($owner, 'user')->create();

    $ticket->replies()->create([
        'user_id' => $operator->id,
        'body' => 'SECRET_INTERNAL_NOTE',
        'is_internal' => true,
    ]);

    $page = $this->actingAs($owner)->get(route('tickets.show', $ticket))->assertOk();

    expect(json_encode(ticketOwnProps($page)))->not->toContain('SECRET_INTERNAL_NOTE');
});

it('shows internal notes to operators on the show page', function () {
    $owner = User::factory()->create();
    $owner->assignRole('user');
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $ticket = Ticket::factory()->open()->for($owner, 'user')->create();

    $ticket->replies()->create([
        'user_id' => $operator->id,
        'body' => 'SECRET_INTERNAL_NOTE',
        'is_internal' => true,
    ]);

    $this->actingAs($operator)
        ->get(route('operator.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('SECRET_INTERNAL_NOTE');
});

it('accepts attachments on replies', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $ticket = Ticket::factory()->open()->for($user, 'user')->create();
    // EPIC-017 WP2: the attachment rule reads the type from the bytes (§20.1), so the fixture is a
    // real PDF; a zero-filled fake()->create('*.pdf') is, correctly, not one.
    $file = ticketUpload('reply-doc.pdf');

    $this->actingAs($user)
        ->post(route('tickets.replies.store', $ticket), [
            'body' => 'See attached.',
            'attachments' => [$file],
        ]);

    $reply = $ticket->replies()->first();
    expect($reply->attachments()->count())->toBe(1);
    expect($reply->attachments()->first()->filename)->toBe('reply-doc.pdf');
});

it('validates reply body is required', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $ticket = Ticket::factory()->open()->for($user, 'user')->create();

    $this->actingAs($user)
        ->post(route('tickets.replies.store', $ticket), ['body' => ''])
        ->assertSessionHasErrors('body');
});

// EPIC-017 WP2 (independent review F3): the requester React page announces and focuses its own reply
// ("Reply sent."), so an Inertia reply carries no shared flash that would announce the same reply a second
// time. A plain form post (the operator's Blade page) keeps its "Reply added." flash.
it('flashes "Reply added." for a plain form post and none for an Inertia reply', function () {
    $user = ticketUser();
    $ticket = ticketFor($user);

    $this->actingAs($user)
        ->post(route('tickets.replies.store', $ticket), ['body' => 'Plain post.'])
        ->assertRedirect()
        ->assertSessionHas('status', 'Reply added.');

    $version = $this->actingAs($user)->get(route('tickets.index'))->viewData('page')['version'];

    $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->post(route('tickets.replies.store', $ticket), ['body' => 'Inertia reply.'])
        ->assertRedirect()
        ->assertSessionMissing('status');

    // Both replies are real: only the flash differs.
    expect($ticket->replies()->pluck('body')->all())->toBe(['Plain post.', 'Inertia reply.']);
});

it('flashes nothing for a refused Inertia reply either, so only the field error is shown', function () {
    $user = ticketUser();
    $ticket = ticketFor($user);
    $version = $this->actingAs($user)->get(route('tickets.index'))->viewData('page')['version'];

    $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
        ->post(route('tickets.replies.store', $ticket), ['body' => ''])
        ->assertSessionHasErrors('body')
        ->assertSessionMissing('status');
});

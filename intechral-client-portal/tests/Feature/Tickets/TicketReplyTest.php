<?php

use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketRepliedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    Storage::fake('local');
});

it('allows a user to reply to their own ticket', function () {
    $user   = User::factory()->create();
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
    $owner    = User::factory()->create();
    $owner->assignRole('user');
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $ticket   = Ticket::factory()->open()->for($owner, 'user')->create();

    $this->actingAs($operator)
        ->post(route('tickets.replies.store', $ticket), ['body' => 'We are looking into this.'])
        ->assertRedirect();

    Notification::assertSentTo($owner, TicketRepliedNotification::class);
});

it('does not notify the owner for internal notes', function () {
    $owner    = User::factory()->create();
    $owner->assignRole('user');
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $ticket   = Ticket::factory()->open()->for($owner, 'user')->create();

    $this->actingAs($operator)
        ->post(route('tickets.replies.store', $ticket), [
            'body'        => 'Internal note only.',
            'is_internal' => '1',
        ])
        ->assertRedirect();

    Notification::assertNotSentTo($owner, TicketRepliedNotification::class);
});

it('does not show internal notes to the ticket owner on the show page', function () {
    $owner    = User::factory()->create();
    $owner->assignRole('user');
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $ticket   = Ticket::factory()->open()->for($owner, 'user')->create();

    $ticket->replies()->create([
        'user_id'     => $operator->id,
        'body'        => 'SECRET_INTERNAL_NOTE',
        'is_internal' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('tickets.show', $ticket))
        ->assertOk()
        ->assertDontSee('SECRET_INTERNAL_NOTE');
});

it('shows internal notes to operators on the show page', function () {
    $owner    = User::factory()->create();
    $owner->assignRole('user');
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $ticket   = Ticket::factory()->open()->for($owner, 'user')->create();

    $ticket->replies()->create([
        'user_id'     => $operator->id,
        'body'        => 'SECRET_INTERNAL_NOTE',
        'is_internal' => true,
    ]);

    $this->actingAs($operator)
        ->get(route('operator.tickets.show', $ticket))
        ->assertOk()
        ->assertSee('SECRET_INTERNAL_NOTE');
});

it('accepts attachments on replies', function () {
    $user   = User::factory()->create();
    $user->assignRole('user');
    $ticket = Ticket::factory()->open()->for($user, 'user')->create();
    $file   = UploadedFile::fake()->create('reply-doc.pdf', 256, 'application/pdf');

    $this->actingAs($user)
        ->post(route('tickets.replies.store', $ticket), [
            'body'        => 'See attached.',
            'attachments' => [$file],
        ]);

    $reply = $ticket->replies()->first();
    expect($reply->attachments()->count())->toBe(1);
    expect($reply->attachments()->first()->filename)->toBe('reply-doc.pdf');
});

it('validates reply body is required', function () {
    $user   = User::factory()->create();
    $user->assignRole('user');
    $ticket = Ticket::factory()->open()->for($user, 'user')->create();

    $this->actingAs($user)
        ->post(route('tickets.replies.store', $ticket), ['body' => ''])
        ->assertSessionHasErrors('body');
});

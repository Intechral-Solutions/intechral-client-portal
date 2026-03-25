<?php

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use App\Notifications\TicketCreatedNotification;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    Notification::fake();
    Storage::fake('local');
});

// ── Guests ──────────────────────────────────────────────────────────────────

it('redirects guests from tickets index to login', function () {
    $this->get(route('tickets.index'))->assertRedirect('/login');
});

it('redirects guests from ticket create to login', function () {
    $this->get(route('tickets.create'))->assertRedirect('/login');
});

// ── Permissions ──────────────────────────────────────────────────────────────

it('returns 403 for users without tickets.view', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('tickets.index'))->assertForbidden();
});

it('returns 403 for users without tickets.create on create form', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('tickets.view');
    $this->actingAs($user)->get(route('tickets.create'))->assertForbidden();
});

// ── Submission ───────────────────────────────────────────────────────────────

it('allows a user with tickets.create to view the create form', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $this->actingAs($user)->get(route('tickets.create'))->assertOk();
});

it('creates a ticket with a TKT-XXXX number', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->post(route('tickets.store'), [
        'title'       => 'My first ticket',
        'description' => 'Something is broken.',
        'category'    => 'Technical',
        'priority'    => 'high',
    ])->assertRedirect();

    $ticket = Ticket::first();
    expect($ticket)->not->toBeNull();
    expect($ticket->ticket_number)->toMatch('/^TKT-\d{4}$/');
    expect($ticket->title)->toBe('My first ticket');
    expect($ticket->user_id)->toBe($user->id);
});

it('sets SLA due date based on priority', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->post(route('tickets.store'), [
        'title'       => 'Critical issue',
        'description' => 'Urgent.',
        'category'    => 'Technical',
        'priority'    => 'critical',
    ]);

    $ticket = Ticket::first();
    // Critical SLA = 4 hours — should be roughly now + 4h
    expect($ticket->sla_due_at->diffInHours(now()))->toBeLessThanOrEqual(4);
    expect($ticket->sla_due_at->isFuture())->toBeTrue();
});

it('sends a confirmation email after ticket creation', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->post(route('tickets.store'), [
        'title'       => 'Notify me',
        'description' => 'Please help.',
        'category'    => 'General',
        'priority'    => 'medium',
    ]);

    Notification::assertSentTo($user, TicketCreatedNotification::class);
});

it('accepts file attachments on ticket submission', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $file = UploadedFile::fake()->create('document.pdf', 512, 'application/pdf');

    $this->actingAs($user)->post(route('tickets.store'), [
        'title'         => 'Ticket with attachment',
        'description'   => 'See attached.',
        'category'      => 'General',
        'priority'      => 'low',
        'attachments'   => [$file],
    ]);

    $ticket = Ticket::first();
    expect($ticket->attachments()->count())->toBe(1);
    expect($ticket->attachments()->first()->filename)->toBe('document.pdf');
});

it('rejects attachments larger than 20 MB', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $file = UploadedFile::fake()->create('huge.zip', 21_000, 'application/zip');

    $this->actingAs($user)->post(route('tickets.store'), [
        'title'         => 'Big file',
        'description'   => 'Oops.',
        'category'      => 'General',
        'priority'      => 'low',
        'attachments'   => [$file],
    ])->assertSessionHasErrors('attachments.*');
});

it('validates required fields on ticket creation', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    $this->actingAs($user)->post(route('tickets.store'), [])
        ->assertSessionHasErrors(['title', 'description', 'category', 'priority']);
});

// ── Viewing ──────────────────────────────────────────────────────────────────

it('shows users their own tickets on the index', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    Ticket::factory()->for($user, 'user')->create(['title' => 'My Ticket']);
    Ticket::factory()->create(['title' => 'Someone Elses Ticket']);

    $this->actingAs($user)
        ->get(route('tickets.index'))
        ->assertOk()
        ->assertSee('My Ticket')
        ->assertDontSee('Someone Elses Ticket');
});

it('allows users to view their own ticket', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $ticket = Ticket::factory()->for($user, 'user')->create();

    $this->actingAs($user)->get(route('tickets.show', $ticket))->assertOk();
});

it('prevents users from viewing another users ticket', function () {
    $user  = User::factory()->create();
    $user->assignRole('user');
    $other = User::factory()->create();
    $ticket = Ticket::factory()->for($other, 'user')->create();

    $this->actingAs($user)->get(route('tickets.show', $ticket))->assertForbidden();
});

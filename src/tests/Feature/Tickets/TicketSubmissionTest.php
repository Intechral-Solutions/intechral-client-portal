<?php

use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/TicketTestHelpers.php';

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
        'title' => 'My first ticket',
        'description' => 'Something is broken.',
        'category' => 'Technical',
        'priority' => 'high',
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
        'title' => 'Critical issue',
        'description' => 'Urgent.',
        'category' => 'Technical',
        'priority' => 'critical',
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
        'title' => 'Notify me',
        'description' => 'Please help.',
        'category' => 'General',
        'priority' => 'medium',
    ]);

    Notification::assertSentTo($user, TicketCreatedNotification::class);
});

it('accepts file attachments on ticket submission', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    // EPIC-017 WP2: the attachment rule reads the type from the bytes (§20.1), so the fixture is a
    // real PDF; a zero-filled fake()->create('*.pdf') is, correctly, not one.
    $file = ticketUpload('document.pdf');

    $this->actingAs($user)->post(route('tickets.store'), [
        'title' => 'Ticket with attachment',
        'description' => 'See attached.',
        'category' => 'General',
        'priority' => 'low',
        'attachments' => [$file],
    ]);

    $ticket = Ticket::first();
    expect($ticket->attachments()->count())->toBe(1);
    expect($ticket->attachments()->first()->filename)->toBe('document.pdf');
});

it('rejects attachments larger than 20 MB', function () {
    $user = User::factory()->create();
    $user->assignRole('user');

    // EPIC-017 WP2: a REAL PDF just over the limit, so the refusal can only be the size rule (a
    // zero-filled fake would now also fail the type rule and prove nothing about size).
    $file = UploadedFile::fake()->createWithContent('huge.pdf', ticketFileBytes('pdf').str_repeat('0', 20_481 * 1024));

    $this->actingAs($user)->post(route('tickets.store'), [
        'title' => 'Big file',
        'description' => 'Oops.',
        'category' => 'General',
        'priority' => 'low',
        'attachments' => [$file],
    ])->assertSessionHasErrors(['attachments.0' => 'The attachments.0 field must not be greater than 20480 kilobytes.']);

    expect(Ticket::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
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

    expect(ticketListedTitles($user))->toBe(['My Ticket']);
});

it('allows users to view their own ticket', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $ticket = Ticket::factory()->for($user, 'user')->create();

    $this->actingAs($user)->get(route('tickets.show', $ticket))->assertOk();
});

it('prevents users from viewing another users ticket', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $other = User::factory()->create();
    $ticket = Ticket::factory()->for($other, 'user')->create();

    $this->actingAs($user)->get(route('tickets.show', $ticket))->assertForbidden();
});

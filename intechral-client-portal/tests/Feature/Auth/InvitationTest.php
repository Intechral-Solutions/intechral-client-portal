<?php

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seedRolesAndPermissions();
});

test('operator can send an invitation', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $service = app(InvitationService::class);
    $invitation = $service->invite('newuser@example.com', $operator);

    expect($invitation->email)->toBe('newuser@example.com')
        ->and($invitation->status)->toBe('pending')
        ->and($invitation->expires_at->isFuture())->toBeTrue();

    Notification::assertSentOnDemand(InvitationNotification::class);
});

test('sending a new invitation invalidates the previous one', function () {
    $operator = User::factory()->create();
    $service = app(InvitationService::class);

    $first = $service->invite('user@example.com', $operator);
    $service->invite('user@example.com', $operator);

    expect($first->fresh()->status)->toBe('expired');
});

test('invitation show page renders for a valid token', function () {
    $invitation = Invitation::factory()->pending()->create();

    $this->get("/invitation/{$invitation->token}")
        ->assertOk()
        ->assertViewIs('auth.register')
        ->assertViewHas('invitation');
});

test('expired invitation shows error page', function () {
    $invitation = Invitation::factory()->expired()->create();

    $this->get("/invitation/{$invitation->token}")
        ->assertOk()
        ->assertViewIs('auth.invitation-invalid');
});

test('user can register via a valid invitation', function () {
    $invitation = Invitation::factory()->pending()->create(['email' => 'invited@example.com']);

    $this->post("/invitation/{$invitation->token}", [
        'name'                  => 'New User',
        'password'              => 'Str0ng!Password99',
        'password_confirmation' => 'Str0ng!Password99',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticated();

    $user = User::where('email', 'invited@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->hasRole('user'))->toBeTrue();

    expect($invitation->fresh()->status)->toBe('accepted');
});

test('email is always taken from invitation, not form input', function () {
    $invitation = Invitation::factory()->pending()->create(['email' => 'correct@example.com']);

    $this->post("/invitation/{$invitation->token}", [
        'name'                  => 'New User',
        'password'              => 'Str0ng!Password99',
        'password_confirmation' => 'Str0ng!Password99',
    ])->assertRedirect('/dashboard');

    expect(User::where('email', 'correct@example.com')->exists())->toBeTrue();
});

test('password must meet complexity requirements', function () {
    $invitation = Invitation::factory()->pending()->create();

    $this->post("/invitation/{$invitation->token}", [
        'name'                  => 'New User',
        'password'              => 'weak',
        'password_confirmation' => 'weak',
    ])->assertSessionHasErrors('password');
});
